<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Client;
use App\Models\Session;
use App\Models\SignInAttempt;

/**
 * The Client snapshot returned to the SDK. Mirrors PLAN §8.2.
 *
 * v0.1: only `sessions` is populated — `sign_in` and `sign_up` arrive
 * with AU-9 / AU-10 once those state machines land.
 */
final class ClientResource
{
    public static function from(?Client $client): array
    {
        if ($client === null) {
            return [
                'object' => 'client',
                'id' => null,
                'sessions' => [],
                'sign_in' => null,
                'sign_up' => null,
                'last_active_session_id' => null,
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        $sessions = $client->sessions()
            ->whereIn('status', Session::LIVE_STATUSES)
            ->latest('last_active_at')
            ->get()
            ->map(fn (Session $session) => self::sessionShape($session))
            ->all();

        $signIn = null;
        if ($client->current_sign_in_attempt_id !== null) {
            $attempt = SignInAttempt::query()
                ->withoutGlobalScopes()
                ->where('id', $client->current_sign_in_attempt_id)
                ->first();
            if ($attempt !== null && ! in_array($attempt->status, [SignInAttempt::STATUS_COMPLETE, SignInAttempt::STATUS_ABANDONED], true)) {
                $signIn = SignInResource::from($attempt);
            }
        }

        return [
            'object' => 'client',
            'id' => $client->id,
            'sessions' => $sessions,
            'sign_in' => $signIn,
            'sign_up' => null,                      // populated in AU-10
            'last_active_session_id' => $client->last_active_session_id,
            'created_at' => $client->created_at?->getTimestampMs(),
            'updated_at' => $client->updated_at?->getTimestampMs(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function sessionShape(Session $session): array
    {
        return [
            'object' => 'session',
            'id' => $session->id,
            'status' => $session->status,
            'last_active_at' => $session->last_active_at?->getTimestampMs(),
            'expire_at' => $session->expire_at->getTimestampMs(),
            'abandon_at' => $session->abandon_at?->getTimestampMs(),
            'last_active_organization_id' => $session->last_active_organization_id,
            'actor' => $session->actor,
            'user_id' => $session->user_id,
        ];
    }
}
