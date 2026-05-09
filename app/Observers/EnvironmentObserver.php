<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Environment;
use App\Services\Tenancy\RoleSeeder;

/**
 * Seeds the system permissions + default roles into every freshly-minted
 * environment. The bootstrap service calls RoleSeeder directly inside its
 * own transaction so it keeps a handle on the seeded admin role for the
 * workspace owner membership; everywhere else (Dashboard creating a new
 * env, BAPI provisioning a new project, factories, …) the observer is the
 * sanctioned path.
 */
final class EnvironmentObserver
{
    public function __construct(private readonly RoleSeeder $seeder) {}

    public function created(Environment $environment): void
    {
        $this->seeder->seed($environment);
    }
}
