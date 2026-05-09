<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Client;
use App\Models\Session;
use App\Models\SessionActivity;
use App\Models\SignInAttempt;
use App\Models\SignUpAttempt;
use App\Models\User;

/**
 * The Client snapshot returned to the SDK. Mirrors PLAN §8.2.
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

        $signUp = null;
        if ($client->current_sign_up_attempt_id !== null) {
            $attempt = SignUpAttempt::query()
                ->withoutGlobalScopes()
                ->where('id', $client->current_sign_up_attempt_id)
                ->first();
            if ($attempt !== null && ! in_array($attempt->status, [SignUpAttempt::STATUS_COMPLETE, SignUpAttempt::STATUS_ABANDONED], true)) {
                $signUp = SignUpResource::from($attempt);
            }
        }

        return [
            'object' => 'client',
            'id' => $client->id,
            'sessions' => $sessions,
            'sign_in' => $signIn,
            'sign_up' => $signUp,
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
        $latestActivity = SessionActivity::query()
            ->where('session_id', $session->id)
            ->latest('id')
            ->first();

        $user = User::query()->withoutGlobalScopes()->where('id', $session->user_id)->first();

        return [
            'object' => 'session',
            'id' => $session->id,
            'client_id' => $session->client_id,
            'user_id' => $session->user_id,
            'status' => $session->status,
            'last_active_at' => ($session->last_active_at ?? $session->created_at ?? now())->getTimestampMs(),
            'expire_at' => $session->expire_at->getTimestampMs(),
            'abandon_at' => ($session->abandon_at ?? $session->expire_at)->getTimestampMs(),
            'last_active_organization_id' => $session->last_active_organization_id,
            'latest_activity' => self::activityShape($latestActivity),
            'user' => $user !== null ? UserResource::from($user) : null,
            'created_at' => $session->created_at?->getTimestampMs(),
            'updated_at' => $session->updated_at?->getTimestampMs(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function activityShape(?SessionActivity $activity): array
    {
        $rawId = $activity?->id ?? 0;
        $base = self::crockford((int) $rawId);
        $idPart = str_pad($base, 26, '0', STR_PAD_LEFT);

        if ($activity === null) {
            return [
                'object' => 'session_activity',
                'id' => 'sact_'.$idPart,
            ];
        }

        return [
            'object' => 'session_activity',
            'id' => 'sact_'.$idPart,
            'device_type' => $activity->device_type,
            'is_mobile' => (bool) $activity->is_mobile,
            'browser_name' => $activity->browser_name,
            'browser_version' => $activity->browser_version,
            'ip_address' => $activity->ip_address,
            'city' => $activity->city,
            'country' => $activity->country,
        ];
    }

    private static function crockford(int $n): string
    {
        if ($n === 0) {
            return '0';
        }
        $alpha = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $out = '';
        while ($n > 0) {
            $out = $alpha[$n % 32].$out;
            $n = intdiv($n, 32);
        }

        return $out;
    }
}
