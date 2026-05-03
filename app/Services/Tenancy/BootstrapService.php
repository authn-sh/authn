<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\ApiKey;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\SigningKey;
use App\Models\User;
use App\Services\Keys\KeyGenerator;
use App\Services\Keys\SigningKeyGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * First-run platform setup.
 *
 * Provisions the reserved `_admin` system project, its production
 * environment, the first workspace organization, the first operator user,
 * an active signing key, and one publishable + one secret API key. The raw
 * plaintext API keys are returned to the caller exactly once — they are
 * never persisted in clear and never logged.
 *
 * The whole operation is idempotent: if the `_admin` project already
 * exists the service returns null.
 */
final class BootstrapService
{
    public function __construct(
        private readonly KeyGenerator $keyGenerator,
        private readonly SigningKeyGenerator $signingKeyGenerator,
    ) {}

    /**
     * @param  array{admin_email: string, admin_password: string, workspace_name?: string, app_url?: string}  $config
     * @return array{project: Project, environment: Environment, workspace: Organization, operator: User, secret_key: string, publishable_key: string}|null
     */
    public function run(array $config): ?array
    {
        if (Project::where('is_system', true)->exists()) {
            return null;
        }

        $email = $config['admin_email'] ?? '';
        $password = $config['admin_password'] ?? '';
        $workspaceName = $config['workspace_name'] ?? 'My workspace';
        $appUrl = $config['app_url'] ?? config('app.url');

        if ($email === '' || $password === '') {
            throw new InvalidArgumentException(
                'Bootstrap requires admin_email and admin_password. Set AUTHN_BOOTSTRAP_ADMIN_EMAIL and AUTHN_BOOTSTRAP_ADMIN_PASSWORD before running authn:bootstrap.'
            );
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Bootstrap admin email is not a valid email address: {$email}");
        }

        $appHost = parse_url((string) $appUrl, PHP_URL_HOST) ?: (string) config('authn.app_host', 'localhost');
        $routingMode = (string) config('authn.routing_mode', 'subdomain');

        return DB::transaction(function () use ($email, $password, $workspaceName, $appHost, $routingMode): array {
            $project = Project::create([
                'name' => 'authn.sh admin',
                'slug' => Project::SYSTEM_SLUG,
                'is_system' => true,
            ]);

            $envSlug = Project::SYSTEM_SLUG;
            $fapiHost = $routingMode === 'subdomain' ? $envSlug.'.'.$appHost : $appHost;

            $environment = Environment::create([
                'project_id' => $project->id,
                'kind' => Environment::KIND_PRODUCTION,
                'slug' => $envSlug,
                'frontend_api_host' => $fapiHost,
                'home_url' => sprintf('https://%s', $appHost),
                'allowed_origins' => [],
                'appearance' => [],
            ]);

            $workspace = Organization::create([
                'environment_id' => $environment->id,
                'name' => $workspaceName,
                'slug' => $this->workspaceSlug($workspaceName),
            ]);

            $operator = new User([
                'environment_id' => $environment->id,
                'has_image' => false,
                'delete_self_enabled' => true,
            ]);
            $operator->setPassword($password);
            $operator->save();

            $emailAddress = EmailAddress::query()->withoutGlobalScopes()->create([
                'environment_id' => $environment->id,
                'user_id' => $operator->id,
                'email_address' => Str::lower($email),
                'verified_at' => now(),
                'is_primary' => true,
            ]);
            $operator->forceFill(['primary_email_address_id' => $emailAddress->id])->saveQuietly();
            $operator->setRelation('primaryEmailAddress', $emailAddress);

            $workspace->update(['created_by_user_id' => $operator->id]);

            OrganizationMembership::create([
                'environment_id' => $environment->id,
                'organization_id' => $workspace->id,
                'user_id' => $operator->id,
                'role' => OrganizationMembership::ROLE_WORKSPACE_OWNER,
            ]);

            $this->signingKeyGenerator->generate($environment, SigningKey::STATUS_ACTIVE);

            $secretPlain = $this->keyGenerator->secretKey($environment);
            $publishablePlain = $this->keyGenerator->publishableKey($environment);

            ApiKey::create([
                'environment_id' => $environment->id,
                'kind' => ApiKey::KIND_SECRET,
                'prefix' => substr($secretPlain, 0, 16),
                'hashed_secret' => $this->keyGenerator->hash($secretPlain),
                'name' => 'Bootstrap secret key',
            ]);

            ApiKey::create([
                'environment_id' => $environment->id,
                'kind' => ApiKey::KIND_PUBLISHABLE,
                'prefix' => substr($publishablePlain, 0, 16),
                'hashed_secret' => $this->keyGenerator->hash($publishablePlain),
                'name' => 'Bootstrap publishable key',
            ]);

            return [
                'project' => $project,
                'environment' => $environment,
                'workspace' => $workspace,
                'operator' => $operator,
                'secret_key' => $secretPlain,
                'publishable_key' => $publishablePlain,
            ];
        });
    }

    private function workspaceSlug(string $name): string
    {
        $slug = Str::slug($name);
        if ($slug === '') {
            throw new RuntimeException('Could not derive a slug from the supplied workspace name.');
        }

        return Str::limit($slug, 60, '');
    }
}
