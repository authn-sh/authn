<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\Environment;
use App\Models\Project;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Dashboard-side preview minting. Operators editing the Customization
 * Appearance editor click "Preview" — we cache the draft appearance
 * payload under a short-lived (5-minute) random token and return a
 * signed URL pointing at the Account-Portal preview route. The iframe
 * mounted in the editor loads that URL; the preview route swaps the
 * env's stored appearance for the cached draft when rendering
 * `<SignIn />` so operators see the unsaved changes against the real
 * bundled component, without mutating any env state.
 *
 *   POST /{project_slug}/{env_slug}/configure/appearance/preview
 *
 * Returns `{ preview_url: string, expires_at_ms: int }`.
 */
final class AppearancePreviewController
{
    public const TTL_SECONDS = 300; // 5 minutes per spec §11.6.6.

    public function store(Request $request, string $projectSlug, string $envSlug): JsonResponse
    {
        $env = $this->resolveEnv($projectSlug, $envSlug);
        if ($env === null) {
            return response()->json([
                'errors' => [['code' => 'environment_not_found', 'message' => "No env matches {$projectSlug}/{$envSlug}."]],
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'variables' => 'sometimes|array',
            'elements' => 'sometimes|array',
            'layout' => 'sometimes|array',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $this->flat($validator->errors()->toArray())], 422);
        }

        $draft = [
            'variables' => $this->scalarMap((array) $request->input('variables', [])),
            'elements' => $this->scalarMap((array) $request->input('elements', [])),
            'layout' => $this->scalarMap((array) $request->input('layout', [])),
        ];

        $token = Str::random(48);
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);
        DB::table('appearance_preview_drafts')->insert([
            'token' => $token,
            'environment_id' => $env->id,
            'draft' => json_encode($draft, JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Opportunistic GC — every mint sweeps drafts that aged out so the
        // table stays small even when the dashboard editor is being driven
        // hard. Cheap on Postgres (indexed range scan on expires_at).
        DB::table('appearance_preview_drafts')->where('expires_at', '<', now())->delete();

        return response()->json([
            'preview_url' => Url::accountPortal($env, '/_preview/appearance/'.$token),
            'expires_at_ms' => (int) ($expiresAt->getTimestampMs()),
        ]);
    }

    private function resolveEnv(string $projectSlug, string $envSlug): ?Environment
    {
        $project = Project::query()->where('slug', $projectSlug)->first();
        if ($project === null) {
            return null;
        }

        return Environment::query()
            ->where('project_id', $project->id)
            ->where('slug', $envSlug)
            ->first();
    }

    /**
     * @param  array<int|string, mixed>  $in
     * @return array<string, scalar>
     */
    private function scalarMap(array $in): array
    {
        $out = [];
        foreach ($in as $k => $v) {
            if (! is_string($k)) {
                continue;
            }
            if (is_string($v) || is_bool($v) || is_int($v) || is_float($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<array{code: string, message: string, long_message: string, meta?: array{param: string}}>
     */
    private function flat(array $errors): array
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

        return $flat;
    }
}
