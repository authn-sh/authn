<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EnterpriseAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnterpriseAccount>
 */
class EnterpriseAccountFactory extends Factory
{
    /**
     * @var class-string<EnterpriseAccount>
     */
    protected $model = EnterpriseAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // environment_id, user_id, enterprise_connection_id supplied by caller.
            'provider_user_id' => 'idp-'.$this->faker->bothify('????????????????'),
            'email_address' => $this->faker->safeEmail(),
            'verified' => true,
            'public_metadata' => [],
            'id_token' => null,
            'linked_at' => now(),
            'last_signed_in_at' => now(),
        ];
    }
}
