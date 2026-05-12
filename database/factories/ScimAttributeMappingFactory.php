<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ScimAttributeMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScimAttributeMapping>
 */
class ScimAttributeMappingFactory extends Factory
{
    /**
     * @var class-string<ScimAttributeMapping>
     */
    protected $model = ScimAttributeMapping::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // environment_id + organization_id supplied by caller.
            'enterprise_connection_id' => null,
            'source_attribute' => 'userName',
            'target_attribute' => 'email_address',
            'transform' => null,
        ];
    }
}
