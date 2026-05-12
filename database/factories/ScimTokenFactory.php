<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ScimToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScimToken>
 */
class ScimTokenFactory extends Factory
{
    /**
     * @var class-string<ScimToken>
     */
    protected $model = ScimToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $minted = ScimToken::mintPlaintext();

        return [
            // environment_id + created_by_user_id supplied by caller.
            'hashed_token' => $minted['hash'],
            'prefix' => $minted['prefix'],
            'organization_id' => null,
            'enterprise_connection_id' => null,
            'name' => 'CI sync token',
            'last_used_at' => null,
            'expires_at' => null,
            'revoked_at' => null,
        ];
    }

    public function revoked(): self
    {
        return $this->state(fn (array $attributes): array => ['revoked_at' => now()]);
    }

    public function expired(): self
    {
        return $this->state(fn (array $attributes): array => ['expires_at' => now()->subDay()]);
    }
}
