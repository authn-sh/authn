<?php

declare(strict_types=1);

namespace App\Events\Organizations;

use App\Models\Role;
use Illuminate\Foundation\Events\Dispatchable;

final class RoleCreated
{
    use Dispatchable;

    public function __construct(public readonly Role $role) {}
}
