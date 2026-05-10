<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Auth\ErrorCodes;
use App\Http\Resources\ClientResource;
use App\Http\Resources\PhoneNumberResource;
use App\Models\Client;
use App\Models\Environment;
use App\Models\PhoneNumber;
use App\Models\Session;
use App\Models\User;
use App\Webhooks\Emitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `/v1/me/phone-numbers` — end-user phone-number management. Mirror of
 * the v0.1 `/v1/me/email-addresses` surface; verification reuses the
 * v0.2 Challenge dance (AU-9 lights up the `phone_code` strategy).
 *
 * Hard-deletes (no soft-delete on PhoneNumber); 409 on delete-while-MFA.
 */
final class MePhoneNumberController
{
    /**
     * Loose E.164 — leading +, 8-15 digits. We don't try to validate
     * country code semantics here; the SMS driver returns a clean
     * error if Twilio / Vonage rejects the number.
     */
    private const E164_PATTERN = '/^\+[1-9][0-9]{7,14}$/';

    public function index(): JsonResponse
    {
        $user = app(User::class);
        $rows = $user->phoneNumbers()->withoutGlobalScopes()->get();

        return response()->json([
            'data' => $rows->map(fn (PhoneNumber $p) => PhoneNumberResource::from($p))->all(),
            'total_count' => $rows->count(),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot add phone numbers.');
        }
        $env = app(Environment::class);
        $user = app(User::class);

        $number = $request->input('phone_number');
        if (! is_string($number) || preg_match(self::E164_PATTERN, $number) !== 1) {
            return $this->error(422, ErrorCodes::FORM_PARAM_FORMAT_INVALID, 'phone_number must be E.164 (e.g. "+15555550100").');
        }

        $exists = PhoneNumber::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('phone_number', $number)
            ->exists();
        if ($exists) {
            return $this->error(422, ErrorCodes::FORM_IDENTIFIER_EXISTS, 'That phone number is already registered.');
        }

        $row = PhoneNumber::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'user_id' => $user->id,
            'phone_number' => $number,
            'is_primary' => false,
        ]);

        $fresh = $row->fresh();
        app(Emitter::class)->emit('phoneNumber.created', PhoneNumberResource::from($fresh), $env);

        return $this->clientEnvelope(PhoneNumberResource::from($fresh), 201);
    }

    public function show(Request $request): JsonResponse
    {
        $row = $this->load($request);
        if ($row instanceof JsonResponse) {
            return $row;
        }

        return response()->json(PhoneNumberResource::from($row))->header('Cache-Control', 'no-store');
    }

    public function update(Request $request): JsonResponse
    {
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot mutate phone numbers.');
        }
        $row = $this->load($request);
        if ($row instanceof JsonResponse) {
            return $row;
        }

        if ($request->has('is_primary') && $request->boolean('is_primary') === true) {
            if (! $row->isVerified()) {
                return $this->error(422, ErrorCodes::PHONE_NOT_VERIFIED, 'Cannot set an unverified phone number as primary.');
            }
            $row->forceFill(['is_primary' => true])->save();
        }

        if ($request->has('reserved_for_second_factor')) {
            $row->forceFill(['reserved_for_second_factor' => $request->boolean('reserved_for_second_factor')])->save();
        }

        if ($request->has('default_second_factor') && $request->boolean('default_second_factor') === true) {
            if (! $row->isVerified()) {
                return $this->error(422, ErrorCodes::PHONE_NOT_VERIFIED, 'Cannot set an unverified phone number as default second factor.');
            }
            $row->forceFill(['default_second_factor' => true])->save();
        }

        return $this->clientEnvelope(PhoneNumberResource::from($row->fresh()));
    }

    public function destroy(Request $request): JsonResponse
    {
        $session = app(Session::class);
        if ($session->isImpersonation()) {
            return $this->error(403, ErrorCodes::ACTOR_SESSION_FORBIDDEN, 'Impersonation sessions cannot delete phone numbers.');
        }
        $row = $this->load($request);
        if ($row instanceof JsonResponse) {
            return $row;
        }

        if ($row->reserved_for_second_factor) {
            return $this->error(409, ErrorCodes::PHONE_RESERVED_FOR_SECOND_FACTOR, 'Phone is reserved for second-factor MFA. Toggle reserved_for_second_factor off first.');
        }

        $snapshot = PhoneNumberResource::from($row);
        $row->delete();

        $env = app()->bound(Environment::class) ? app(Environment::class) : null;
        app(Emitter::class)->emit('phoneNumber.removed', $snapshot, $env);

        return response()->json(null, 204);
    }

    private function load(Request $request): PhoneNumber|JsonResponse
    {
        $id = (string) $request->route('phone_number_id');
        $user = app(User::class);
        $row = PhoneNumber::query()
            ->withoutGlobalScopes()
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();
        if ($row === null) {
            return $this->error(404, ErrorCodes::PHONE_NOT_FOUND, 'Phone number not found on this user.');
        }

        return $row;
    }

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
            'errors' => [['code' => $code, 'message' => $message, 'long_message' => $message, 'meta' => []]],
            'trace_id' => null,
        ], $status);
    }
}
