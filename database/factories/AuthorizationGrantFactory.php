<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuthorizationGrant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthorizationGrant>
 */
class AuthorizationGrantFactory extends Factory
{
    /**
     * @var class-string<AuthorizationGrant>
     */
    protected $model = AuthorizationGrant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $scopes = ['openid', 'profile', 'email'];

        return [
            // environment_id, oauth_application_id, user_id supplied by caller.
            'scopes' => $scopes,
            'scopes_hash' => AuthorizationGrant::hashScopes($scopes),
            'granted_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function revoked(): self
    {
        return $this->state(fn (array $attributes): array => ['revoked_at' => now()]);
    }
}
