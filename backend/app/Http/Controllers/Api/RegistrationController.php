<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveHostFamily;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Family;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    /** Quanto resta valido il codice per entrare nel diario appena creato. */
    private const HANDOFF_MINUTES = 5;

    /**
     * Crea un diario privato sul piano gratuito e il suo primo amministratore.
     *
     * Solo dall'indirizzo principale. La sessione si apre sul sottodominio
     * nuovo (il token vive nel localStorage di quell'origine): la risposta
     * porta lì con un codice monouso da scambiare con /api/entra.
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        abort_unless($this->onMainHost($request), 404);

        $user = DB::transaction(function () use ($request) {
            $family = Family::create([
                'slug' => $request->validated('slug'),
                'name' => $request->validated('family_name'),
                'timezone' => $request->validated('timezone') ?? config('photodaily.default_timezone'),
                'plan_id' => Plan::query()->where('slug', config('photodaily.signup_plan'))->firstOrFail()->id,
            ]);

            return User::create([
                'family_id' => $family->id,
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => $request->validated('password'),
                'role' => Role::Admin,
            ]);
        });

        $code = Str::random(48);
        Cache::put($this->handoffKey($code), $user->id, now()->addMinutes(self::HANDOFF_MINUTES));

        return response()->json([
            'data' => [
                'url' => $user->family->url(),
                // Nel frammento: non arriva al server né finisce nei log di Caddy.
                'handoff_url' => $user->family->url().'/entra#'.$code,
            ],
        ], 201);
    }

    /**
     * Scambia il codice della registrazione con una sessione, una volta sola
     * e solo sull'indirizzo della famiglia appena creata.
     */
    public function handoff(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:100']]);

        $userId = Cache::pull($this->handoffKey((string) $request->input('code')));
        $user = $userId === null ? null : User::query()->find($userId);
        $hostFamily = ResolveHostFamily::from($request);

        if ($user === null || $hostFamily === null || $user->family_id !== $hostFamily->id) {
            throw ValidationException::withMessages([
                'code' => 'Link scaduto o già usato: entra con email e password.',
            ]);
        }

        return response()->json([
            'token' => $user->createToken($request->string('device_name')->limit(255)->value() ?: 'spa')->plainTextToken,
            'user' => UserResource::make($user->load('family.plan')),
        ]);
    }

    private function onMainHost(Request $request): bool
    {
        return Family::normalizeHost($request->getHost()) === Family::mainHost();
    }

    private function handoffKey(string $code): string
    {
        return 'photodaily:ingresso:'.hash('sha256', $code);
    }
}
