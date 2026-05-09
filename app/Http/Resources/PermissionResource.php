<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Permission;

final class PermissionResource
{
    public static function from(Permission $permission): array
    {
        return [
            'object' => 'permission',
            'id' => $permission->id,
            'key' => $permission->key,
            'name' => $permission->name,
            'description' => $permission->description,
            'is_system' => (bool) $permission->is_system,
            'created_at' => $permission->created_at?->getTimestampMs(),
            'updated_at' => $permission->updated_at?->getTimestampMs(),
        ];
    }
}
