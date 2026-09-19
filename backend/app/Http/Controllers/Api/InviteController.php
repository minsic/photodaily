<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptInviteRequest;
use App\Http\Requests\StoreInviteRequest;
use App\Http\Resources\UserResource;
use App\Models\Invite;
use App\Models\User;
use App\Notifications\FamilyInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteController extends Controller
{
    private const INVALID_INVITE = 'Invito non valido, già utilizzato o scaduto.';

    public function store(StoreInviteRequest $request): JsonResponse
    {
        $user = $request->user();
        $family = $user->family;
        $email = $request->validated('email');
        $token = Str::random(64);

        $invite = DB::transaction(function () use ($family, $user, $email, $token) {
            // Un nuovo invito sostituisce quelli ancora pendenti per la stessa email.
            $family->invites()->where('email', $email)->whereNull('accepted_at')->delete();

            return $family->invites()->create([
                'email' => $email,
                'token' => Invite::hashToken($token),
                'invited_by' => $user->id,
                'expires_at' => now()->addDays(config('photodaily.invite_ttl_days')),
            ]);
        });

        $url = $family->inviteUrl($token);

        Notification::route('mail', $email)->notify(new FamilyInvitation($invite, $url));

        return response()->json([
            'data' => [
                'id' => $invite->id,
                'email' => $invite->email,
                'expires_at' => $invite->expires_at,
                // Utile all'admin per condividere il link anche fuori dall'email.
                'url' => $url,
            ],
        ], 201);
    }

    /**
     * Dati minimi per la pagina di accettazione del frontend.
     */
    public function show(string $token): JsonResponse
    {
        $invite = Invite::query()->pendingWithToken($token)->with('family')->first();

        abort_if(! $invite, 404, self::INVALID_INVITE);

        return response()->json([
            'data' => [
                'email' => $invite->email,
                'family' => ['name' => $invite->family->name],
                'expires_at' => $invite->expires_at,
            ],
        ]);
    }

    public function accept(AcceptInviteRequest $request, string $token): JsonResponse
    {
        $user = DB::transaction(function () use ($request, $token) {
            $invite = Invite::query()->pendingWithToken($token)->lockForUpdate()->first();

            abort_if(! $invite, 404, self::INVALID_INVITE);

            if (User::query()->where('email', $invite->email)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'Questo indirizzo email ha già un account PhotoDaily.',
                ]);
            }

            $user = User::create([
                'family_id' => $invite->family_id,
                'name' => $request->validated('name'),
                'email' => $invite->email,
                'password' => $request->validated('password'),
                'role' => Role::Member,
            ]);

            $invite->update(['accepted_at' => now()]);

            return $user;
        });

        return response()->json([
            'token' => $user->createToken($request->validated('device_name') ?? 'spa')->plainTextToken,
            'user' => UserResource::make($user->load('family.plan')),
        ], 201);
    }
}
