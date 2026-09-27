<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveHostFamily;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Notifications\EmailChangeRequested;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cambio dell'email con cui si entra: si chiede con la password attuale, si
 * conferma col link mandato al nuovo indirizzo (24 ore). Al vecchio arriva
 * un avviso, così un cambio non voluto non passa inosservato.
 */
class EmailChangeController extends Controller
{
    private const TTL_HOURS = 24;

    public function request(Request $request): UserResource
    {
        $user = $request->user();
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            // Prima "è già la tua", che per la propria email è il messaggio giusto.
            'email' => ['required', 'email', 'max:255', Rule::notIn([$user->email]), Rule::unique('users', 'email')],
            'password_attuale' => ['required', 'string'],
        ], [
            'email.unique' => 'Questa email è già usata da un altro account.',
            'email.not_in' => 'È già la tua email.',
        ]);

        if (! Hash::check($data['password_attuale'], $user->password)) {
            throw ValidationException::withMessages(['password_attuale' => 'La password attuale non è corretta.']);
        }

        $token = Str::random(64);

        $user->forceFill([
            'pending_email' => $data['email'],
            'pending_email_token' => hash('sha256', $token),
            'pending_email_expires_at' => now()->addHours(self::TTL_HOURS),
        ])->save();

        Notification::route('mail', $data['email'])->notify(new ConfirmEmailChange($user, $token));
        $user->notify(new EmailChangeRequested($data['email']));

        return UserResource::make($user->load('family.plan'));
    }

    public function cancel(Request $request): UserResource
    {
        $user = $request->user();
        $user->forceFill(['pending_email' => null, 'pending_email_token' => null, 'pending_email_expires_at' => null])->save();

        return UserResource::make($user->load('family.plan'));
    }

    /**
     * Dal link dell'email: basta il token, anche da un dispositivo dove non
     * si è entrati. Sull'indirizzo di un'altra famiglia non vale.
     */
    public function confirm(Request $request): JsonResponse
    {
        $token = (string) $request->validate(['token' => ['required', 'string']])['token'];

        $email = DB::transaction(function () use ($request, $token) {
            $user = User::query()
                ->where('pending_email_token', hash('sha256', $token))
                ->where('pending_email_expires_at', '>', now())
                ->lockForUpdate()
                ->first();
            $hostFamily = ResolveHostFamily::from($request);

            if ($user === null || ($hostFamily !== null && $user->family_id !== $hostFamily->id)) {
                throw ValidationException::withMessages(['token' => 'Il link non è valido o è scaduto: chiedi di nuovo il cambio dalle impostazioni.']);
            }

            if (User::query()->where('email', $user->pending_email)->exists()) {
                throw ValidationException::withMessages(['token' => 'Nel frattempo questa email è stata usata da un altro account.']);
            }

            $old = $user->email;

            $user->forceFill([
                'email' => $user->pending_email,
                'pending_email' => null,
                'pending_email_token' => null,
                'pending_email_expires_at' => null,
            ])->save();

            // Un link di "password dimenticata" chiesto col vecchio indirizzo non vale più.
            DB::table(config('auth.passwords.users.table'))->where('email', $old)->delete();

            return $user->email;
        });

        return response()->json(['data' => ['email' => $email]]);
    }
}
