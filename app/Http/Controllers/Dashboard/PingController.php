<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Placeholder Dashboard handler. Real Dashboard pages land in AU-17.
 */
final class PingController
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'surface' => 'dashboard',
        ]);
    }
}
