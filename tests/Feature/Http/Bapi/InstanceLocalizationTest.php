<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Bapi;

use App\Models\Environment;
use Tests\TestCase;

final class InstanceLocalizationTest extends TestCase
{
    public function test_show_returns_the_default_locale_set(): void
    {
        $boot = BapiTestSupport::bootEnv('lone');
        app()->instance(Environment::class, $boot['env']);

        $resp = $this->getJson(
            BapiTestSupport::url('/instance/localization'),
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $resp->assertJson([
            'default_locale' => 'en-US',
            'fallback_locale' => 'en-US',
            'supported_locales' => ['en-US', 'pt-BR', 'es-ES', 'fr-FR', 'de-DE'],
        ]);
    }

    public function test_put_replaces_the_whole_blob(): void
    {
        $boot = BapiTestSupport::bootEnv('ltwo');
        app()->instance(Environment::class, $boot['env']);

        $resp = $this->putJson(
            BapiTestSupport::url('/instance/localization'),
            [
                'default_locale' => 'pt-BR',
                'fallback_locale' => 'en-US',
                'supported_locales' => ['en-US', 'pt-BR'],
                'overrides' => [
                    'pt-BR' => ['signIn.start.title' => 'Bem-vindo à {applicationName}'],
                ],
            ],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $resp->assertJson([
            'default_locale' => 'pt-BR',
            'supported_locales' => ['en-US', 'pt-BR'],
        ]);
        $this->assertSame(
            ['signIn.start.title' => 'Bem-vindo à {applicationName}'],
            $resp->json('overrides.pt-BR'),
        );
    }

    public function test_patch_sparse_merges_per_locale_overrides(): void
    {
        $boot = BapiTestSupport::bootEnv('lthree');
        app()->instance(Environment::class, $boot['env']);

        $boot['env']->forceFill([
            'localization' => [
                'default_locale' => 'en-US',
                'fallback_locale' => 'en-US',
                'supported_locales' => ['en-US', 'pt-BR'],
                'overrides' => [
                    'en-US' => [
                        'signIn.start.title' => 'Welcome to Acme',
                        'formButtonPrimary' => 'Submit',
                    ],
                ],
            ],
        ])->save();

        $resp = $this->patchJson(
            BapiTestSupport::url('/instance/localization'),
            [
                'overrides' => [
                    'en-US' => [
                        'formButtonPrimary' => 'Continue',
                        'signIn.start.title' => null,
                    ],
                ],
            ],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $this->assertSame(
            ['formButtonPrimary' => 'Continue'],
            $resp->json('overrides.en-US'),
        );
    }

    public function test_put_rejects_unknown_localization_keys(): void
    {
        $boot = BapiTestSupport::bootEnv('lfour');
        app()->instance(Environment::class, $boot['env']);

        $resp = $this->putJson(
            BapiTestSupport::url('/instance/localization'),
            [
                'default_locale' => 'en-US',
                'fallback_locale' => 'en-US',
                'supported_locales' => ['en-US'],
                'overrides' => [
                    'en-US' => ['signIn.this.is.not.a.key' => 'Hi'],
                ],
            ],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertStatus(422);
    }

    public function test_put_rejects_unsupported_locale(): void
    {
        $boot = BapiTestSupport::bootEnv('lfive');
        app()->instance(Environment::class, $boot['env']);

        $resp = $this->putJson(
            BapiTestSupport::url('/instance/localization'),
            [
                'default_locale' => 'en-US',
                'fallback_locale' => 'en-US',
                // ja-JP is not bundled + no overrides supplied.
                'supported_locales' => ['en-US', 'ja-JP'],
                'overrides' => [],
            ],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertStatus(422);
    }

    public function test_put_surfaces_missing_placeholder_warning(): void
    {
        $boot = BapiTestSupport::bootEnv('lsix');
        app()->instance(Environment::class, $boot['env']);

        $resp = $this->putJson(
            BapiTestSupport::url('/instance/localization'),
            [
                'default_locale' => 'en-US',
                'fallback_locale' => 'en-US',
                'supported_locales' => ['en-US'],
                'overrides' => [
                    // Canonical en-US has `{applicationName}` — we drop it.
                    'en-US' => ['signIn.start.title' => 'Welcome'],
                ],
            ],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $warning = (string) $resp->headers->get('Warning');
        $this->assertStringContainsString('missing-placeholder applicationName', $warning);
        $this->assertStringContainsString('signIn.start.title', $warning);
    }
}
