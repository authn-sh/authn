<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Http\Resources\JwtTemplateResource;
use App\Models\Environment;
use App\Models\JwtTemplate;
use App\Webhooks\Emitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * BAPI `/v1/jwt-templates` CRUD (OA-1). Operator-scoped: bearer-secret
 * authenticated, env-pinned by the same middleware that drives every
 * other BAPI route.
 *
 *   GET    /v1/jwt-templates
 *   POST   /v1/jwt-templates
 *   GET    /v1/jwt-templates/{jwt_template_id}
 *   PATCH  /v1/jwt-templates/{jwt_template_id}
 *   DELETE /v1/jwt-templates/{jwt_template_id}
 *
 * `name` and `signing_algorithm` are immutable on PATCH per spec —
 * sending a different value returns 422. DELETE soft-removes via the
 * `removed_at` column and refuses (`409 jwt_template_in_use`) when the
 * template was used to mint a token within the grace window
 * (max(lifetime + allowed_clock_skew, 40h)).
 */
final class JwtTemplateController
{
    /** RFC 5322-shaped slug per the openapi spec. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/';

    public function index(Request $request): JsonResponse
    {
        $env = app(Environment::class);

        $query = JwtTemplate::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->whereNull('removed_at')
            ->orderBy('created_at');

        $limit = max(1, min(500, (int) $request->input('limit', 50)));
        $offset = max(0, (int) $request->input('offset', 0));
        $total = (clone $query)->count();
        $rows = $query->skip($offset)->take($limit)->get();

        return response()->json([
            'data' => $rows->map(fn (JwtTemplate $t) => JwtTemplateResource::from($t))->all(),
            'total_count' => $total,
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request): JsonResponse
    {
        $env = app(Environment::class);

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:64', 'regex:'.self::NAME_PATTERN],
            'claims' => ['required', 'array'],
            'lifetime' => ['sometimes', 'integer', 'between:1,86400'],
            'allowed_clock_skew' => ['sometimes', 'integer', 'between:0,300'],
            'signing_algorithm' => ['sometimes', 'string', 'in:RS256,ES256,HS256'],
            'custom_signing_key' => ['sometimes', 'nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }
        $data = $validator->validated();

        $duplicate = JwtTemplate::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('name', $data['name'])
            ->whereNull('removed_at')
            ->exists();
        if ($duplicate) {
            return $this->error(409, 'jwt_template_name_taken',
                "Another JwtTemplate in this environment already uses the name `{$data['name']}`.");
        }

        $row = JwtTemplate::query()->withoutGlobalScopes()->create([
            'environment_id' => $env->id,
            'name' => $data['name'],
            'claims' => $data['claims'],
            'lifetime' => $data['lifetime'] ?? 60,
            'allowed_clock_skew' => $data['allowed_clock_skew'] ?? 5,
            'signing_algorithm' => $data['signing_algorithm'] ?? JwtTemplate::ALG_RS256,
            'custom_signing_key' => $data['custom_signing_key'] ?? null,
        ]);

        Log::info('audit:bapi.jwt_template.created', [
            'environment_id' => $env->id,
            'jwt_template_id' => $row->id,
        ]);
        app(Emitter::class)->emit('jwtTemplate.created', JwtTemplateResource::from($row->fresh()), $env);

        return response()->json(JwtTemplateResource::from($row->fresh()), 201)
            ->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $jwtTemplateId): JsonResponse
    {
        $row = $this->find($jwtTemplateId);
        if ($row === null) {
            return $this->notFound($jwtTemplateId);
        }

        return response()->json(JwtTemplateResource::from($row))->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, string $jwtTemplateId): JsonResponse
    {
        $env = app(Environment::class);
        $row = $this->find($jwtTemplateId);
        if ($row === null) {
            return $this->notFound($jwtTemplateId);
        }

        if ($request->has('name') && $request->input('name') !== $row->name) {
            return $this->error(422, 'name_immutable',
                'name cannot change after a JwtTemplate is created — re-create under a new name to rename.');
        }
        if ($request->has('signing_algorithm') && $request->input('signing_algorithm') !== $row->signing_algorithm) {
            return $this->error(422, 'signing_algorithm_immutable',
                'signing_algorithm cannot change after a JwtTemplate is created — re-create under a new name to migrate.');
        }

        $validator = Validator::make($request->all(), [
            'claims' => ['sometimes', 'array'],
            'lifetime' => ['sometimes', 'integer', 'between:1,86400'],
            'allowed_clock_skew' => ['sometimes', 'integer', 'between:0,300'],
            'custom_signing_key' => ['sometimes', 'nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return $this->validationError($validator->errors()->toArray());
        }
        $data = $validator->validated();

        $row->fill(array_intersect_key($data, array_flip(['claims', 'lifetime', 'allowed_clock_skew'])));
        if (array_key_exists('custom_signing_key', $data)) {
            // Explicit `null` reverts to the env signing key; any string
            // rotates to the supplied PEM / secret.
            $row->custom_signing_key = $data['custom_signing_key'];
        }
        $row->save();

        Log::info('audit:bapi.jwt_template.updated', [
            'environment_id' => $env->id,
            'jwt_template_id' => $row->id,
        ]);
        app(Emitter::class)->emit('jwtTemplate.updated', JwtTemplateResource::from($row->fresh()), $env);

        return response()->json(JwtTemplateResource::from($row->fresh()))->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, string $jwtTemplateId): JsonResponse
    {
        $env = app(Environment::class);
        $row = $this->find($jwtTemplateId);
        if ($row === null) {
            return $this->notFound($jwtTemplateId);
        }

        if ($row->last_used_at !== null) {
            $graceEndsAt = $row->last_used_at->copy()->addSeconds($row->deleteGraceSeconds());
            if ($graceEndsAt->isFuture()) {
                $retryAfter = (int) ceil($graceEndsAt->diffInRealSeconds(now()));

                return $this->error(409, 'jwt_template_in_use',
                    "Cannot delete — the template was used to mint a token at {$row->last_used_at}; ".
                    "long-lived verifier caches may still hold rendered tokens. Retry after {$retryAfter}s.",
                    ['Retry-After' => (string) max(1, $retryAfter)],
                );
            }
        }

        $snapshot = JwtTemplateResource::from($row);
        $row->delete();

        Log::info('audit:bapi.jwt_template.deleted', [
            'environment_id' => $env->id,
            'jwt_template_id' => $row->id,
        ]);
        app(Emitter::class)->emit('jwtTemplate.deleted', $snapshot, $env);

        return response()->json(null, 204);
    }

    private function find(string $jwtTemplateId): ?JwtTemplate
    {
        $env = app(Environment::class);

        // withoutGlobalScopes() drops both EnvironmentScope (we filter by
        // environment_id manually) and SoftDeletingScope — re-add the
        // soft-delete filter so tombstoned rows stay hidden from BAPI reads.
        return JwtTemplate::query()->withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('id', $jwtTemplateId)
            ->whereNull('removed_at')
            ->first();
    }

    private function notFound(string $id): JsonResponse
    {
        return $this->error(404, 'jwt_template_not_found', "JwtTemplate {$id} not found.");
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function error(int $status, string $code, string $message, array $headers = []): JsonResponse
    {
        $response = response()->json([
            'errors' => [['code' => $code, 'message' => $message, 'long_message' => $message]],
        ], $status)->header('Cache-Control', 'no-store');
        foreach ($headers as $key => $value) {
            $response->header($key, $value);
        }

        return $response;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function validationError(array $errors): JsonResponse
    {
        $flat = [];
        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $flat[] = [
                    'code' => 'form_param_invalid',
                    'message' => $message,
                    'long_message' => $message,
                    'meta' => ['param' => $field],
                ];
            }
        }

        return response()->json(['errors' => $flat], 422)->header('Cache-Control', 'no-store');
    }
}
