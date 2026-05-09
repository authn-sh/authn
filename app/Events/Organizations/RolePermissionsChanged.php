<?php

declare(strict_types=1);

namespace App\Events\Organizations;

use App\Models\Role;
use Illuminate\Foundation\Events\Dispatchable;

final class RolePermissionsChanged
{
    use Dispatchable;

    /**
     * @param  list<string>  $permissionKeys
     */
    public function __construct(
        public readonly Role $role,
        public readonly array $permissionKeys,
    ) {}
}
