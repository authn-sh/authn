<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Models\Environment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Placeholder BAPI handler. Confirms the request was dispatched into the
 * BAPI route group and that AuthenticateBapiKey resolved an environment.
 * Real BAPI endpoints land in AU-13.
 */
final class PingController
{
    public function __invoke(Request $request): JsonResponse
    {
        $env = app(Environment::class);

        return response()->json([
            'status' => 'ok',
            'surface' => 'bapi',
            'environment_id' => $env->id,
            'project_id' => $env->project_id,
        ]);
    }
}
