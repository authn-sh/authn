<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Role;

final class RoleResource
{
    public static function from(Role $role): array
    {
        return [
            'object' => 'role',
            'id' => $role->id,
            'key' => $role->key,
            'name' => $role->name,
            'description' => $role->description,
            'is_creator_eligible' => (bool) $role->is_creator_eligible,
            'is_default' => (bool) $role->is_default,
            'is_system' => (bool) $role->is_system,
            'permissions' => $role->permissions->pluck('key')->values()->all(),
            'created_at' => $role->created_at?->getTimestampMs(),
            'updated_at' => $role->updated_at?->getTimestampMs(),
        ];
    }
}
