<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Models\Environment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Placeholder FAPI handler. Confirms the request was dispatched into the
 * FAPI route group and that ResolveProjectFromHost resolved an environment.
 * Real FAPI endpoints land in AU-8 / AU-9 / AU-10 / AU-11 / AU-12.
 */
final class PingController
{
    public function __invoke(Request $request): JsonResponse
    {
        $env = app(Environment::class);

        return response()->json([
            'status' => 'ok',
            'surface' => 'fapi',
            'environment_id' => $env->id,
            'environment_slug' => $env->slug,
            'project_id' => $env->project_id,
        ]);
    }
}
