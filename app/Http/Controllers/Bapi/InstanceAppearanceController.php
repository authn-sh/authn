<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Models\Environment;
use App\Webhooks\Emitter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 *   GET    /v1/instance/appearance     — read the current Appearance blob
 *   PUT    /v1/instance/appearance     — full replace; missing keys clear
 *   PATCH  /v1/instance/appearance     — deep merge; explicit nulls clear
 *
 * The blob lives at `Environment.appearance` (`jsonb`, default
 * `{"variables":{},"elements":{},"layout":{}}`). The SDK fetches the
 * read-through copy via `/v1/environment`.
 */
final class InstanceAppearanceController
{
    private const TOP_LEVEL_KEYS = ['variables', 'elements', 'layout'];

    public function show(): JsonResponse
    {
        return response()->json($this->payload(app(Environment::class)));
    }

    public function replace(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $before = $this->normalise($env->appearance);

        $next = $this->validateBlob($request);
        $env->forceFill(['appearance' => $next])->save();
        $this->emitUpdated($env, $before, $next);

        return response()->json($this->payload($env->refresh()));
    }

    public function patch(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $before = $this->normalise($env->appearance);
        $patch = $this->validateBlob($request);

        $next = $before;
        foreach (self::TOP_LEVEL_KEYS as $axis) {
            $patchAxis = isset($patch[$axis]) && is_array($patch[$axis]) ? $patch[$axis] : [];
            $existing = isset($next[$axis]) && is_array($next[$axis]) ? $next[$axis] : [];
            foreach ($patchAxis as $key => $value) {
                if ($value === null) {
                    unset($existing[$key]);

                    continue;
                }
                $existing[$key] = $value;
            }
            $next[$axis] = $existing;
        }

        $env->forceFill(['appearance' => $next])->save();
        $this->emitUpdated($env, $before, $next);

        return response()->json($this->payload($env->refresh()));
    }

    /**
     * @return array{variables: array<string, mixed>, elements: array<string, mixed>, layout: array<string, mixed>}
     */
    private function validateBlob(Request $request): array
    {
        $body = $request->all();
        Validator::make($body, [
            'variables' => 'sometimes|array',
            'elements' => 'sometimes|array',
            'layout' => 'sometimes|array',
        ])->validate();

        $out = Environment::defaultAppearance();
        foreach (self::TOP_LEVEL_KEYS as $axis) {
            if (isset($body[$axis]) && is_array($body[$axis])) {
                $out[$axis] = $body[$axis];
            }
        }

        return $out;
    }

    /**
     * @param  mixed  $stored
     * @return array{variables: array<string, mixed>, elements: array<string, mixed>, layout: array<string, mixed>}
     */
    private function normalise($stored): array
    {
        $base = Environment::defaultAppearance();
        if (! is_array($stored)) {
            return $base;
        }
        foreach (self::TOP_LEVEL_KEYS as $axis) {
            if (isset($stored[$axis]) && is_array($stored[$axis])) {
                $base[$axis] = $stored[$axis];
            }
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Environment $env): array
    {
        $blob = $this->normalise($env->appearance);

        return [
            'variables' => (object) $blob['variables'],
            'elements' => (object) $blob['elements'],
            'layout' => (object) $blob['layout'],
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function emitUpdated(Environment $env, array $before, array $after): void
    {
        if ($before == $after) {
            return;
        }

        Log::info('instance.config.appearance_updated', [
            'environment_id' => $env->id,
        ]);
        app(Emitter::class)->emit(
            'instance.config.appearance_updated',
            [
                'environment_id' => $env->id,
                'before' => $before,
                'after' => $after,
            ],
            $env,
        );
    }
}
