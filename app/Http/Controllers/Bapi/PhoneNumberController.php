<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Http\Resources\PhoneNumberResource;
use App\Models\Environment;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Webhooks\Emitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BAPI admin phone-numbers (OA-9 / authn#150).
 *
 *   GET    /v1/phone-numbers              — list (filter by user_id)
 *   POST   /v1/phone-numbers              — admin-create (links to user_id)
 *   GET    /v1/phone-numbers/{id}
 *   PATCH  /v1/phone-numbers/{id}         — toggle verified / primary / MFA flags
 *   DELETE /v1/phone-numbers/{id}         — 409 on reserved_for_second_factor
 */
final class PhoneNumberController
{
    public function index(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $query = PhoneNumber::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->orderBy('created_at', 'desc');
        if (is_string($request->input('user_id'))) {
            $query->where('user_id', (string) $request->input('user_id'));
        }

        $limit = max(1, min(500, (int) $request->input('limit', 50)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (PhoneNumber $p) => PhoneNumberResource::from($p))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $env = app(Environment::class);

        $userId = (string) $request->input('user_id', '');
        $phoneNumber = (string) $request->input('phone_number', '');
        if ($userId === '' || $phoneNumber === '') {
            return $this->error(422, 'form_param_nil', 'user_id and phone_number are required.');
        }
        if (preg_match('/^\+[1-9][0-9]{6,14}$/', $phoneNumber) !== 1) {
            return $this->error(422, 'form_param_format_invalid', 'phone_number must be E.164 (e.g. +15555550100).');
        }

        $user = User::query()->withoutGlobalScopes()
            ->where('id', $userId)
            ->where('environment_id', $env->id)
            ->first();
        if ($user === null) {
            return $this->error(404, 'user_not_found', 'No user matches that id in this environment.');
        }

        $duplicate = PhoneNumber::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('phone_number', $phoneNumber)
            ->exists();
        if ($duplicate) {
            return $this->error(409, 'phone_number_in_use', 'That phone number is already attached to a user in this environment.');
        }

        $verified = (bool) $request->input('verified', false);
        $primary = (bool) $request->input('primary', false);

        $row = DB::transaction(function () use ($env, $user, $phoneNumber, $verified, $primary): PhoneNumber {
            if ($primary) {
                PhoneNumber::query()->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->where('user_id', $user->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }
            $row = PhoneNumber::query()->withoutGlobalScopes()->create([
                'environment_id' => $env->id,
                'user_id' => $user->id,
                'phone_number' => $phoneNumber,
                'verified_at' => $verified ? now() : null,
                'is_primary' => $primary,
                'reserved_for_second_factor' => false,
                'default_second_factor' => false,
            ]);
            if ($primary) {
                $user->forceFill(['primary_phone_number_id' => $row->id])->save();
            }

            return $row;
        });

        Log::info('audit:bapi.phone_number.created', [
            'environment_id' => $env->id,
            'phone_number_id' => $row->id,
            'user_id' => $user->id,
            'verified' => $verified,
            'primary' => $primary,
        ]);

        app(Emitter::class)->emit('phoneNumber.created', PhoneNumberResource::from($row), $env);
        if ($verified) {
            app(Emitter::class)->emit('phoneNumber.verified', PhoneNumberResource::from($row), $env);
        }

        return response()->json(PhoneNumberResource::from($row), 201);
    }

    public function show(string $phoneNumberId): JsonResponse
    {
        $row = $this->find($phoneNumberId);
        if ($row === null) {
            return $this->error(404, 'phone_number_not_found', 'No phone number matches that id in this environment.');
        }

        return response()->json(PhoneNumberResource::from($row))
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, string $phoneNumberId): JsonResponse
    {
        $env = app(Environment::class);
        $row = $this->find($phoneNumberId);
        if ($row === null) {
            return $this->error(404, 'phone_number_not_found', 'No phone number matches that id in this environment.');
        }

        $wasUnverified = $row->verified_at === null;

        $payload = [];
        if ($request->has('verified')) {
            $payload['verified_at'] = (bool) $request->input('verified') ? ($row->verified_at ?? now()) : null;
        }
        if ($request->has('is_primary')) {
            $payload['is_primary'] = (bool) $request->input('is_primary');
        }
        if ($request->has('reserved_for_second_factor')) {
            $payload['reserved_for_second_factor'] = (bool) $request->input('reserved_for_second_factor');
        }
        if ($request->has('default_second_factor')) {
            $payload['default_second_factor'] = (bool) $request->input('default_second_factor');
        }

        if ($payload === []) {
            return response()->json(PhoneNumberResource::from($row));
        }

        DB::transaction(function () use ($env, $row, $payload): void {
            if (($payload['is_primary'] ?? null) === true) {
                PhoneNumber::query()->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->where('user_id', $row->user_id)
                    ->where('id', '!=', $row->id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
                $row->user?->forceFill(['primary_phone_number_id' => $row->id])->save();
            }
            if (($payload['default_second_factor'] ?? null) === true) {
                PhoneNumber::query()->withoutGlobalScopes()
                    ->where('environment_id', $env->id)
                    ->where('user_id', $row->user_id)
                    ->where('id', '!=', $row->id)
                    ->where('default_second_factor', true)
                    ->update(['default_second_factor' => false]);
            }
            $row->forceFill($payload)->save();
        });

        $row->refresh();

        Log::info('audit:bapi.phone_number.updated', [
            'environment_id' => $env->id,
            'phone_number_id' => $row->id,
        ]);

        if ($wasUnverified && $row->verified_at !== null) {
            app(Emitter::class)->emit('phoneNumber.verified', PhoneNumberResource::from($row), $env);
        }

        return response()->json(PhoneNumberResource::from($row));
    }

    public function destroy(string $phoneNumberId): JsonResponse
    {
        $env = app(Environment::class);
        $row = $this->find($phoneNumberId);
        if ($row === null) {
            return $this->error(404, 'phone_number_not_found', 'No phone number matches that id in this environment.');
        }

        if ($row->reserved_for_second_factor) {
            return $this->error(409, 'phone_reserved_for_second_factor', 'This phone is currently committed to MFA. Clear reserved_for_second_factor first.');
        }

        $snapshot = PhoneNumberResource::from($row);
        $row->delete();

        Log::info('audit:bapi.phone_number.removed', [
            'environment_id' => $env->id,
            'phone_number_id' => $phoneNumberId,
        ]);

        app(Emitter::class)->emit('phoneNumber.removed', $snapshot, $env);

        return response()->json(null, 204);
    }

    private function find(string $phoneNumberId): ?PhoneNumber
    {
        $env = app(Environment::class);

        return PhoneNumber::query()->withoutGlobalScopes()
            ->where('id', $phoneNumberId)
            ->where('environment_id', $env->id)
            ->first();
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [['code' => $code, 'long_message' => $message, 'message' => $message]],
        ], $status);
    }
}
