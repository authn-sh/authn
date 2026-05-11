<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Bapi;

use App\Http\Resources\EnvironmentResource;
use App\Models\Environment;
use Tests\TestCase;

final class InstanceAppearanceTest extends TestCase
{
    public function test_show_returns_the_defaulted_blob(): void
    {
        $boot = BapiTestSupport::bootEnv('apone');
        app()->instance(Environment::class, $boot['env']);

        $resp = $this->getJson(
            BapiTestSupport::url('/instance/appearance'),
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $body = $resp->json();
        $this->assertSame(['variables', 'elements', 'layout'], array_keys($body));
        $this->assertSame([], (array) $body['variables']);
        $this->assertSame([], (array) $body['elements']);
        $this->assertSame([], (array) $body['layout']);
    }

    public function test_put_replaces_the_blob_entirely(): void
    {
        $boot = BapiTestSupport::bootEnv('aptwo');
        app()->instance(Environment::class, $boot['env']);

        $boot['env']->forceFill([
            'appearance' => [
                'variables' => ['colorPrimary' => '#aaaaaa'],
                'elements' => ['card' => 'shadow-xl'],
                'layout' => ['logoImageUrl' => 'https://old.example/logo.svg'],
            ],
        ])->save();

        $resp = $this->putJson(
            BapiTestSupport::url('/instance/appearance'),
            [
                'variables' => ['colorPrimary' => '#0a84ff'],
                'layout' => ['socialButtonsPlacement' => 'top'],
            ],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $body = $resp->json();
        $this->assertSame(['colorPrimary' => '#0a84ff'], $body['variables']);
        // PUT clears anything not supplied — elements goes back to {}.
        $this->assertSame([], (array) $body['elements']);
        $this->assertSame(['socialButtonsPlacement' => 'top'], $body['layout']);
    }

    public function test_patch_deep_merges_axes(): void
    {
        $boot = BapiTestSupport::bootEnv('apthree');
        app()->instance(Environment::class, $boot['env']);

        $boot['env']->forceFill([
            'appearance' => [
                'variables' => ['colorPrimary' => '#aaaaaa', 'fontFamily' => 'Inter'],
                'elements' => ['card' => 'shadow-xl'],
                'layout' => ['logoImageUrl' => 'https://example/logo.svg'],
            ],
        ])->save();

        $resp = $this->patchJson(
            BapiTestSupport::url('/instance/appearance'),
            ['variables' => ['colorPrimary' => '#0a84ff']],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $body = $resp->json();
        $this->assertSame(['colorPrimary' => '#0a84ff', 'fontFamily' => 'Inter'], $body['variables']);
        $this->assertSame(['card' => 'shadow-xl'], $body['elements']);
        $this->assertSame(['logoImageUrl' => 'https://example/logo.svg'], $body['layout']);
    }

    public function test_patch_clears_keys_when_value_is_null(): void
    {
        $boot = BapiTestSupport::bootEnv('apfour');
        app()->instance(Environment::class, $boot['env']);

        $boot['env']->forceFill([
            'appearance' => [
                'variables' => ['colorPrimary' => '#aaaaaa', 'fontFamily' => 'Inter'],
                'elements' => [],
                'layout' => [],
            ],
        ])->save();

        $resp = $this->patchJson(
            BapiTestSupport::url('/instance/appearance'),
            ['variables' => ['colorPrimary' => null]],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $this->assertSame(['fontFamily' => 'Inter'], $resp->json('variables'));
    }

    public function test_etag_changes_when_the_blob_changes(): void
    {
        $boot = BapiTestSupport::bootEnv('apfive');
        app()->instance(Environment::class, $boot['env']);

        $before = $boot['env']->appearance_etag;
        $boot['env']->forceFill([
            'appearance' => ['variables' => ['colorPrimary' => '#0a84ff'], 'elements' => [], 'layout' => []],
        ])->save();

        $after = $boot['env']->fresh()->appearance_etag;
        $this->assertNotSame($before, $after);
        $this->assertStringStartsWith('sha256:', $before);
        $this->assertStringStartsWith('sha256:', $after);
    }

    public function test_etag_is_stable_across_two_identical_puts(): void
    {
        $boot = BapiTestSupport::bootEnv('apsix');
        app()->instance(Environment::class, $boot['env']);

        $payload = [
            'variables' => ['colorPrimary' => '#0a84ff'],
            'layout' => ['socialButtonsPlacement' => 'top'],
        ];

        $this->putJson(BapiTestSupport::url('/instance/appearance'), $payload, BapiTestSupport::headers($boot['token']))->assertOk();
        $etag1 = $boot['env']->fresh()->appearance_etag;

        $this->putJson(BapiTestSupport::url('/instance/appearance'), $payload, BapiTestSupport::headers($boot['token']))->assertOk();
        $etag2 = $boot['env']->fresh()->appearance_etag;

        $this->assertSame($etag1, $etag2);
    }

    public function test_environment_endpoint_surfaces_appearance_with_etag(): void
    {
        $boot = BapiTestSupport::bootEnv('apseven');
        $env = $boot['env'];

        $env->forceFill([
            'appearance' => ['variables' => ['colorPrimary' => '#0a84ff'], 'elements' => [], 'layout' => []],
        ])->save();

        config([
            'authn.app_host' => 'authn.local',
            'authn.app_scheme' => 'http',
            'authn.routing_mode' => 'subdomain',
            'authn.app_port_suffix' => '',
        ]);

        $shape = EnvironmentResource::from($env->fresh());

        $this->assertArrayHasKey('appearance', $shape);
        $this->assertArrayHasKey('etag', $shape['appearance']);
        $this->assertStringStartsWith('sha256:', $shape['appearance']['etag']);
        $this->assertSame(['colorPrimary' => '#0a84ff'], (array) $shape['appearance']['variables']);
    }
}
