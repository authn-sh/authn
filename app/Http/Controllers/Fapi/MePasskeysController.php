<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Auth\Passkey\Exceptions\PasskeyException;
use App\Auth\Passkey\PasskeyService;
use App\Http\Resources\ChallengeResource;
use App\Http\Resources\ClientResource;
use App\Http\Resources\PasskeyResource;
use App\Models\Challenge;
use App\Models\Client;
use App\Models\Environment;
use App\Models\Passkey;
use App\Models\Session;
use App\Models\User;
use App\Models\Verification;
use App\Settings\PasskeySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FAPI passkey CRUD per OA-2:
 *   GET    /v1/me/passkeys
 *   POST   /v1/me/passkeys/begin-registration
 *   POST   /v1/me/passkeys/complete-registration/{challenge_id}
 *   PATCH  /v1/me/passkeys/{passkey_id}
 *   DELETE /v1/me/passkeys/{passkey_id}
 *
 * Per the v0.3 strict-semantic MFA contract, the
 * `authentication_strategies.passkey.enabled` toggle gates *new
 * enrolment* only; existing passkeys remain usable for sign-in even
 * when the toggle is off (AU-4's strategy resolver enforces that).
 */
final class MePasskeysController
{
    public function __construct(private readonly PasskeyService $passkeys) {}

    public function index(): JsonResponse
    {
        $user = app(User::class);
        $rows = Passkey::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Passkey $p): array => PasskeyResource::from($p))->all(),
            'total_count' => $rows->count(),
        ])->header('Cache-Control', 'no-store');
    }

    public function beginRegistration(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $user = app(User::class);
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, 'actor_session_forbidden', 'Impersonation sessions cannot enrol passkeys.');
        }

        $settings = PasskeySettings::fromUserSettings(is_array($env->user_settings) ? $env->user_settings : []);
        if (! $settings->enabled) {
            return $this->error(422, 'passkey_not_supported', 'Passkey enrolment is disabled for this environment.');
        }

        $nickname = $request->input('nickname');
        if ($nickname !== null && (! is_string($nickname) || strlen($nickname) > 100)) {
            return $this->error(422, 'form_param_format_invalid', 'nickname must be a string up to 100 characters.');
        }

        $excludeIds = Passkey::query()
            ->where('user_id', $user->id)
            ->whereNotNull('verified_at')
            ->get()
            ->map(fn (Passkey $p): string => (string) $p->credential_id)
            ->all();

        $challenge = DB::transaction(function () use ($env, $user, $nickname) {
            $verification = Verification::create([
                'environment_id' => $env->id,
                'verifiable_type' => Challenge::PARENT_USER,
                'verifiable_id' => $user->id,
                'strategy' => Verification::STRATEGY_PASSKEY,
                'status' => Verification::STATUS_UNVERIFIED,
                'expire_at' => now()->addMinutes(10),
            ]);

            return Challenge::create([
                'environment_id' => $env->id,
                'parent_type' => Challenge::PARENT_USER,
                'parent_id' => $user->id,
                'step' => Challenge::STEP_SINGLE,
                'strategy' => Verification::STRATEGY_PASSKEY,
                'status' => Challenge::STATUS_PENDING,
                'verification_id' => $verification->id,
                'expire_at' => now()->addMinutes(10),
                'metadata' => is_string($nickname) ? ['passkey_nickname' => $nickname] : [],
            ]);
        });

        $options = $this->passkeys->buildRegistrationOptions($env, $user, $challenge->refresh(), $excludeIds);

        $shape = ChallengeResource::from($challenge->fresh());
        $shape['creation_options'] = self::camelToSnake($options);

        return response()->json($shape, 201)->header('Cache-Control', 'no-store');
    }

    public function completeRegistration(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $user = app(User::class);
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, 'actor_session_forbidden', 'Impersonation sessions cannot enrol passkeys.');
        }

        $challengeId = (string) $request->route('challenge_id');
        $challenge = Challenge::query()
            ->where('id', $challengeId)
            ->where('parent_type', Challenge::PARENT_USER)
            ->where('parent_id', $user->id)
            ->where('strategy', Verification::STRATEGY_PASSKEY)
            ->first();
        if ($challenge === null) {
            return $this->error(404, 'resource_not_found', 'Challenge not found.');
        }
        if ($challenge->status !== Challenge::STATUS_PENDING || $challenge->isExpired()) {
            return $this->error(422, 'challenge_already_consumed', 'Challenge is no longer live.');
        }

        $attestation = $request->input('attestation');
        if (! is_array($attestation)) {
            return $this->error(422, 'form_param_format_invalid', 'attestation must be an object.');
        }
        $completeNickname = $request->input('nickname');
        if ($completeNickname !== null && (! is_string($completeNickname) || strlen($completeNickname) > 100)) {
            return $this->error(422, 'form_param_format_invalid', 'nickname must be a string up to 100 characters.');
        }
        $beginNickname = is_array($challenge->metadata) ? ($challenge->metadata['passkey_nickname'] ?? null) : null;
        $resolvedNickname = is_string($completeNickname) && $completeNickname !== ''
            ? $completeNickname
            : (is_string($beginNickname) && $beginNickname !== '' ? $beginNickname : null);

        try {
            $passkey = $this->passkeys->verifyAttestation($env, $user, $challenge, $attestation, $resolvedNickname);
        } catch (PasskeyException $e) {
            $challenge->forceFill([
                'status' => Challenge::STATUS_FAILED,
                'error_code' => $e->code(),
            ])->save();

            return $this->error(422, $e->code(), $e->getMessage());
        }

        Verification::query()
            ->where('id', $challenge->verification_id)
            ->update(['status' => Verification::STATUS_VERIFIED, 'verified_at' => now()]);

        $metadata = is_array($challenge->metadata) ? $challenge->metadata : [];
        unset($metadata['passkey_nickname']);
        $challenge->forceFill(['metadata' => $metadata])->save();

        Log::info('auth.passkey.registered', [
            'user_id' => $user->id,
            'environment_id' => $env->id,
            'passkey_id' => $passkey->id,
            'surface' => 'fapi',
        ]);

        return $this->clientEnvelope(PasskeyResource::from($passkey->fresh()), 201);
    }

    public function update(Request $request): JsonResponse
    {
        $passkeyId = (string) $request->route('passkey_id');
        $user = app(User::class);
        $passkey = Passkey::query()->where('user_id', $user->id)->where('id', $passkeyId)->first();
        if ($passkey === null) {
            return $this->error(404, 'resource_not_found', 'Passkey not found.');
        }

        $nickname = $request->input('nickname');
        if (! is_string($nickname) || strlen($nickname) > 100) {
            return $this->error(422, 'form_param_format_invalid', 'nickname must be a string up to 100 characters.');
        }

        $passkey->forceFill(['nickname' => $nickname])->save();

        return $this->clientEnvelope(PasskeyResource::from($passkey->fresh()));
    }

    public function destroy(Request $request): JsonResponse
    {
        $passkeyId = (string) $request->route('passkey_id');
        $user = app(User::class);
        $passkey = Passkey::query()->where('user_id', $user->id)->where('id', $passkeyId)->first();
        if ($passkey === null) {
            return $this->error(404, 'resource_not_found', 'Passkey not found.');
        }

        $shape = PasskeyResource::from($passkey);
        $passkey->delete();

        Log::info('auth.passkey.removed', [
            'user_id' => $user->id,
            'environment_id' => app(Environment::class)->id,
            'passkey_id' => $passkey->id,
            'surface' => 'fapi',
        ]);

        return $this->clientEnvelope($shape);
    }

    /* -------------------- helpers -------------------- */

    private function clientEnvelope(mixed $body, int $status = 200): JsonResponse
    {
        $client = app()->bound(Client::class) ? app(Client::class) : null;

        return response()->json([
            'response' => $body,
            'client' => ClientResource::from($client?->fresh()),
        ], $status)->header('Cache-Control', 'no-store');
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [[
                'code' => $code,
                'message' => $message,
                'long_message' => $message,
                'meta' => [],
            ]],
            'trace_id' => null,
        ], $status);
    }

    /**
     * webauthn-lib serializes options to camelCase JSON; the OA-2
     * `PasskeyCreationOptions` schema is snake_case. Translate the
     * known key set without touching nested arrays' values.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function camelToSnake(array $options): array
    {
        $rename = [
            'pubKeyCredParams' => 'pub_key_cred_params',
            'authenticatorSelection' => 'authenticator_selection',
            'excludeCredentials' => 'exclude_credentials',
            'allowCredentials' => 'allow_credentials',
            'rpId' => 'rp_id',
        ];
        foreach ($rename as $camel => $snake) {
            if (array_key_exists($camel, $options)) {
                $options[$snake] = $options[$camel];
                unset($options[$camel]);
            }
        }
        if (isset($options['authenticator_selection']) && is_array($options['authenticator_selection'])) {
            $sel = $options['authenticator_selection'];
            $selRename = [
                'residentKey' => 'resident_key',
                'userVerification' => 'user_verification',
                'authenticatorAttachment' => 'authenticator_attachment',
                'requireResidentKey' => 'require_resident_key',
            ];
            foreach ($selRename as $camel => $snake) {
                if (array_key_exists($camel, $sel)) {
                    $sel[$snake] = $sel[$camel];
                    unset($sel[$camel]);
                }
            }
            $options['authenticator_selection'] = $sel;
        }
        if (isset($options['user']) && is_array($options['user']) && array_key_exists('displayName', $options['user'])) {
            $options['user']['display_name'] = $options['user']['displayName'];
            unset($options['user']['displayName']);
        }

        return $options;
    }
}
