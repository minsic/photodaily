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
use App\Services\DiaryHandoff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    public function __construct(private readonly DiaryHandoff $handoff) {}

    /**
     * Crea un diario privato sul piano gratuito e il suo primo amministratore.
     *
     * Solo dall'indirizzo principale. La sessione si apre sul sottodominio
     * nuovo, con il codice monouso di DiaryHandoff da scambiare con /api/entra.
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

        return response()->json([
            'data' => [
                'url' => $user->family->url(),
                'handoff_url' => $this->handoff->url($user, welcome: true),
            ],
        ], 201);
    }

    /**
     * Da photodaily.app al proprio diario, già dentro: il sito principale non
     * mostra diari, rimanda ciascuno al suo indirizzo.
     */
    public function toDiary(Request $request): JsonResponse
    {
        abort_unless($this->onMainHost($request), 404);

        $user = $request->user();

        return response()->json([
            'data' => ['handoff_url' => $this->handoff->url($user, fromToken: $user->currentAccessToken()->getKey())],
        ]);
    }

    /**
     * Scambia il codice con una sessione, una volta sola e solo
     * sull'indirizzo della famiglia dell'account.
     */
    public function handoff(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:100']]);

        $data = $this->handoff->redeem((string) $request->input('code'));
        $user = $data === null ? null : User::query()->find($data['user']);
        $hostFamily = ResolveHostFamily::from($request);

        if ($user === null || $hostFamily === null || $user->family_id !== $hostFamily->id || $hostFamily->isSuspended()) {
            throw ValidationException::withMessages([
                'code' => 'Link scaduto o già usato: entra con email e password.',
            ]);
        }

        $token = $user->createToken($request->string('device_name')->limit(255)->value() ?: 'spa');

        if ($data['from_token'] ?? null) {
            $token->accessToken->forceFill(['linked_token_id' => $data['from_token']])->save();
        }

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => UserResource::make($user->load('family.plan')),
            // Diario appena creato: il frontend propone la prima foto.
            'benvenuto' => $data['welcome'],
        ]);
    }

    private function onMainHost(Request $request): bool
    {
        return Family::normalizeHost($request->getHost()) === Family::mainHost();
    }
}
