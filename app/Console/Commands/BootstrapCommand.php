<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tenancy\BootstrapService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class BootstrapCommand extends Command
{
    protected $signature = 'authn:bootstrap
        {--email= : Email of the first operator. Defaults to the AUTHN_BOOTSTRAP_ADMIN_EMAIL env var.}
        {--password= : Plaintext password for the first operator. Defaults to the AUTHN_BOOTSTRAP_ADMIN_PASSWORD env var.}
        {--workspace= : Display name for the first workspace organization. Defaults to AUTHN_BOOTSTRAP_WORKSPACE_NAME or "My workspace".}';

    protected $description = 'Provision the reserved _admin system project, the first workspace, the first operator, and the initial signing + API keys. Idempotent — re-runs are no-ops.';

    public function handle(BootstrapService $service): int
    {
        try {
            $result = $service->run([
                'admin_email' => (string) ($this->option('email') ?: env('AUTHN_BOOTSTRAP_ADMIN_EMAIL', '')),
                'admin_password' => (string) ($this->option('password') ?: env('AUTHN_BOOTSTRAP_ADMIN_PASSWORD', '')),
                'workspace_name' => (string) ($this->option('workspace') ?: env('AUTHN_BOOTSTRAP_WORKSPACE_NAME', 'My workspace')),
                'app_url' => (string) config('app.url'),
            ]);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result === null) {
            $this->components->info('_admin already exists — bootstrap is a no-op.');

            return self::SUCCESS;
        }

        $this->components->info('authn.sh bootstrap complete.');
        $this->newLine();
        $this->components->twoColumnDetail('Project', $result['project']->slug.' ('.$result['project']->id.')');
        $this->components->twoColumnDetail('Environment', $result['environment']->kind.' ('.$result['environment']->id.')');
        $this->components->twoColumnDetail('FAPI host', $result['environment']->frontend_api_host);
        $this->components->twoColumnDetail('Workspace', $result['workspace']->name.' ('.$result['workspace']->id.')');
        $this->components->twoColumnDetail('Operator', $result['operator']->email.' ('.$result['operator']->id.')');
        $this->newLine();

        $this->components->warn('The API keys below are shown ONCE. Copy them now — they cannot be retrieved later.');
        $this->newLine();
        $this->components->twoColumnDetail('Publishable key', $result['publishable_key']);
        $this->components->twoColumnDetail('Secret key', $result['secret_key']);
        $this->newLine();

        return self::SUCCESS;
    }
}
