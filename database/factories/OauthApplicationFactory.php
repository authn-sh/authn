<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OauthApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OauthApplication>
 */
class OauthApplicationFactory extends Factory
{
    /**
     * @var class-string<OauthApplication>
     */
    protected $model = OauthApplication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $secret = OauthApplication::mintClientSecret();

        return [
            // environment_id supplied by caller.
            'name' => 'App '.$this->faker->unique()->lexify('????????'),
            'client_id' => OauthApplication::mintClientId(),
            'hashed_client_secret' => $secret['hash'],
            'callback_urls' => ['https://example.test/oauth/callback'],
            'scopes' => ['openid', 'profile', 'email'],
            'is_public' => false,
        ];
    }

    public function public(): self
    {
        return $this->state(fn (array $attributes): array => [
            'is_public' => true,
            'hashed_client_secret' => null,
        ]);
    }
}
