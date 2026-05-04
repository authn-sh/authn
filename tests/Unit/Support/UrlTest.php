<?php

declare(strict_types=1);

use App\Models\Environment;
use App\Models\Project;
use App\Support\Url;
use Tests\TestCase;

uses(TestCase::class);

function envFixture(): Environment
{
    $env = new Environment([
        'project_id' => 'prj_01HKX9SY9V7H7TF8C8K7J9X4ZB',
        'kind' => 'production',
        'slug' => 'acme',
        'routing_label' => 'acme',
    ]);
    $project = new Project([
        'name' => 'Acme',
        'slug' => 'acme',
        'is_system' => false,
    ]);
    $env->setRelation('project', $project);

    return $env;
}

function adminEnvFixture(): Environment
{
    $env = new Environment([
        'project_id' => 'prj_01HKX9SY9V7H7TF8C8K7J9X4ZA',
        'kind' => 'production',
        'slug' => Project::SYSTEM_SLUG,
        'routing_label' => null,
    ]);
    $project = new Project([
        'name' => 'authn.sh admin',
        'slug' => Project::SYSTEM_SLUG,
        'is_system' => true,
    ]);
    $env->setRelation('project', $project);

    return $env;
}

beforeEach(function (): void {
    config([
        'authn.app_host' => 'authn.local',
        'authn.app_scheme' => 'https',
        'authn.app_port_suffix' => '',
    ]);
});

describe('subdomain mode', function (): void {
    beforeEach(function (): void {
        config([
            'authn.routing_mode' => 'subdomain',
            'authn.bapi_host' => 'api.authn.local',
            'authn.dashboard_host' => 'dashboard.authn.local',
        ]);
    });

    it('builds BAPI URLs against api.{app_host}', function (): void {
        expect(Url::bapi('/v1/users'))->toBe('https://api.authn.local/v1/users');
        expect(Url::bapi(''))->toBe('https://api.authn.local');
    });

    it('builds Dashboard URLs against dashboard.{app_host}', function (): void {
        expect(Url::dashboard('/users'))->toBe('https://dashboard.authn.local/users');
    });

    it('builds FAPI URLs against the env frontend_api_host', function (): void {
        expect(Url::fapi(envFixture(), '/v1/client'))->toBe('https://acme.authn.local/v1/client');
    });

    it('builds Account Portal URLs at the env host root', function (): void {
        expect(Url::accountPortal(envFixture(), '/sign-in'))->toBe('https://acme.authn.local/sign-in');
    });
});

describe('path mode', function (): void {
    beforeEach(function (): void {
        config([
            'authn.routing_mode' => 'path',
            'authn.bapi_host' => 'authn.local',
            'authn.dashboard_host' => 'authn.local',
        ]);
    });

    it('builds BAPI URLs under /api', function (): void {
        expect(Url::bapi('/v1/users'))->toBe('https://authn.local/api/v1/users');
    });

    it('builds Dashboard URLs under /dashboard', function (): void {
        expect(Url::dashboard('/users'))->toBe('https://authn.local/dashboard/users');
    });

    it('builds FAPI URLs under /{env_slug}', function (): void {
        expect(Url::fapi(envFixture(), '/v1/client'))->toBe('https://authn.local/acme/v1/client');
    });

    it('builds Account Portal URLs under /{env_slug}/account', function (): void {
        expect(Url::accountPortal(envFixture(), '/sign-in'))->toBe('https://authn.local/acme/account/sign-in');
    });

    it('builds admin FAPI URLs at the bare root (no /_admin prefix)', function (): void {
        expect(Url::fapi(adminEnvFixture(), '/v1/client'))->toBe('https://authn.local/v1/client');
    });

    it('builds admin Account Portal URLs at the bare root (no /_admin/account prefix)', function (): void {
        expect(Url::accountPortal(adminEnvFixture(), '/sign-in'))->toBe('https://authn.local/sign-in');
    });
});
