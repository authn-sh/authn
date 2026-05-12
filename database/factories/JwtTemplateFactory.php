<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\JwtTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JwtTemplate>
 */
class JwtTemplateFactory extends Factory
{
    /**
     * @var class-string<JwtTemplate>
     */
    protected $model = JwtTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // environment_id supplied by caller.
            'name' => 'tmpl-'.$this->faker->unique()->lexify('????????'),
            'claims' => [
                'sub' => '{{user.id}}',
                'email' => '{{user.primary_email}}',
            ],
            'lifetime' => 60,
            'allowed_clock_skew' => 5,
            'signing_algorithm' => JwtTemplate::ALG_RS256,
            'custom_signing_key' => null,
        ];
    }

    public function withCustomSigningKey(string $pem): self
    {
        return $this->state(fn (array $attributes): array => ['custom_signing_key' => $pem]);
    }

    public function hs256(): self
    {
        return $this->state(fn (array $attributes): array => ['signing_algorithm' => JwtTemplate::ALG_HS256]);
    }
}
