<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Default seeder is intentionally empty. The first-run setup of authn.sh is
 * driven by `php artisan authn:bootstrap`, which provisions the `_admin`
 * system project, the first workspace organization, the first operator user,
 * and the initial API key + signing key. See App\Console\Commands\BootstrapCommand.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // No-op.
    }
}
