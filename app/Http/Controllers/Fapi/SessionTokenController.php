<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Models\Client;
use App\Models\Session;
use App\Services\Sessions\SessionTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * `POST /v1/client/sessions/{sid}/tokens[/{template}]`
 *
 * Mints a fresh `__session` JWT for an active or pending session.
 * Refused for sessions that don't belong to the resolved Client, or
 * that have left the live status set.
 *
 * v0.1: only the implicit `default` template is honoured; any other
 * template name returns `template_not_found`. JWT templates land in v0.7.
 */
final class SessionTokenController
{
    public function __construct(private readonly SessionTokenIssuer $issuer) {}

    public function __invoke(Request $request): JsonResponse
    {
        $sid = (string) $request->route('sid');
        $template = $request->route('template');
        $template = is_string($template) ? $template : null;

        $client = app(Client::class);

        $session = Session::query()
            ->where('id', $sid)
            ->where('client_id', $client->id)
            ->first();

        if ($session === null) {
            return $this->error(404, 'session_not_found', 'No session matches that id on this device.');
        }

        if (! in_array($session->status, Session::LIVE_STATUSES, true)) {
            return $this->error(401, 'session_revoked', "Session is in status {$session->status}.");
        }

        try {
            $minted = $this->issuer->mint($session, $template, $request);
        } catch (InvalidArgumentException $e) {
            if (str_starts_with($e->getMessage(), 'template_not_found:')) {
                return $this->error(404, 'template_not_found', 'JWT template '.substr($e->getMessage(), strlen('template_not_found:')).' is not configured (custom JWT templates land in v0.7).');
            }
            throw $e;
        }

        return response()->json([
            'jwt' => $minted['jwt'],
            'expires_at' => $minted['expires_at'],
            'kid' => $minted['kid'],
        ]);
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
}
