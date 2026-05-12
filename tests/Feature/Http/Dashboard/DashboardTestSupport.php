<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Dashboard;

use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\Role;
use App\Models\Session;
use App\Models\User;
use App\Services\Keys\SigningKeyGenerator;
use App\Services\Sessions\SessionTokenIssuer;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;

/**
 * Shared dashboard test plumbing: route loader + admin env + operator
 * session minting. Extracted from DashboardTest.php so paratest workers
 * running individual files (e.g. AppearancePreviewTest) pick the helpers
 * up without depending on file-scoped function declarations.
 */
final class DashboardTestSupport
{
    public static function reloadRoutes(): void
    {
        $router = app('router');
        $router->setRoutes(new RouteCollection);

        $routingMode = (string) config('authn.routing_mode', 'subdomain');
        $bapiHost = (string) config('authn.bapi_host');
        $dashboardHost = (string) config('authn.dashboard_host');
        $appHost = (string) config('authn.app_host');

        $bapi = Route::middleware('bapi')->prefix('v1');
        if ($routingMode === 'subdomain') {
            $bapi->domain($bapiHost);
        } else {
            $bapi->prefix('api/v1');
        }
        $bapi->group(base_path('routes/bapi.php'));

        $dashboard = Route::middleware('dashboard');
        if ($routingMode === 'subdomain') {
            $dashboard->domain($dashboardHost);
        } else {
            $dashboard->prefix('dashboard');
        }
        $dashboard->group(base_path('routes/dashboard.php'));

        $fapi = Route::middleware('fapi');
        if ($routingMode === 'subdomain') {
            $fapi->domain('{env_slug}.'.$appHost);
        } else {
            $fapi->prefix('{env_slug}');
        }
        $fapi->group(base_path('routes/fapi.php'));
    }

    /**
     * @return array{project: Project, env: Environment}
     */
    public static function bootAdminEnv(): array
    {
        config([
            'authn.routing_mode' => 'subdomain',
            'authn.app_host' => 'authn.local',
            'authn.bapi_host' => 'api.authn.local',
            'authn.dashboard_host' => 'dashboard.authn.local',
            'authn.app_scheme' => 'http',
            'authn.app_port_suffix' => '',
            'app.url' => 'http://authn.local',
        ]);
        self::reloadRoutes();

        $project = Project::create(['name' => 'authn.sh admin', 'slug' => Project::SYSTEM_SLUG, 'is_system' => true]);
        $env = Environment::create([
            'project_id' => $project->id,
            'kind' => Environment::KIND_PRODUCTION,
            'slug' => Project::SYSTEM_SLUG,
            'routing_label' => '_admin',
            'allowed_origins' => [],
        ]);
        (new SigningKeyGenerator)->generate($env);

        return ['project' => $project, 'env' => $env];
    }

    /**
     * @return array{user: User, workspace: Organization, session: Session, jwt: string}
     */
    public static function operatorWithMembership(Environment $env): array
    {
        $org = Organization::create([
            'environment_id' => $env->id,
            'name' => 'Acme Workspace',
            'slug' => 'acme-workspace',
        ]);
        $user = new User(['environment_id' => $env->id, 'first_name' => 'Op']);
        $user->save();
        EmailAddress::create([
            'environment_id' => $env->id,
            'user_id' => $user->id,
            'email_address' => 'op@example.com',
            'verified_at' => now(),
            'is_primary' => true,
        ]);
        $adminRole = Role::withoutGlobalScopes()
            ->where('environment_id', $env->id)
            ->where('key', 'org:admin')
            ->firstOrFail();
        OrganizationMembership::create([
            'environment_id' => $env->id,
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role_id' => $adminRole->id,
        ]);
        $client = Client::create(['environment_id' => $env->id]);
        $session = Session::create([
            'environment_id' => $env->id,
            'client_id' => $client->id,
            'user_id' => $user->id,
            'status' => Session::STATUS_ACTIVE,
        ]);
        $jwt = app(SessionTokenIssuer::class)->mint($session->fresh());

        return ['user' => $user, 'workspace' => $org, 'session' => $session, 'jwt' => $jwt['jwt']];
    }

    /**
     * @return array<string, string>
     */
    public static function headers(?string $jwt = null): array
    {
        return array_filter([
            'Host' => 'dashboard.authn.local',
            'Accept' => 'application/json',
            'X-Inertia' => 'true',
            'X-Inertia-Version' => '1',
            'Authorization' => $jwt !== null ? 'Bearer '.$jwt : null,
        ]);
    }
}
