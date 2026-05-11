<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Passkey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Passkey>
 */
class PasskeyFactory extends Factory
{
    /**
     * @var class-string<Passkey>
     */
    protected $model = Passkey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $credentialId = random_bytes(32);

        return [
            // user_id must be supplied by the caller — User has no factory.
            'credential_id' => $credentialId,
            'credential_id_hash' => hash('sha256', $credentialId, true),
            'public_key' => random_bytes(77),
            'sign_count' => 0,
            'transports' => $this->faker->randomElements(
                [Passkey::TRANSPORT_INTERNAL, Passkey::TRANSPORT_HYBRID, Passkey::TRANSPORT_USB],
                $this->faker->numberBetween(1, 2),
            ),
            'aaguid' => $this->faker->uuid(),
            'nickname' => $this->faker->randomElement(['MacBook Pro', 'iPhone', 'YubiKey', null]),
            'verified_at' => now(),
        ];
    }

    public function unverified(): self
    {
        return $this->state(fn (array $attributes): array => ['verified_at' => null]);
    }
}
