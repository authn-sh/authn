<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Bapi;

use App\Models\Environment;
use App\Models\Passkey;
use App\Models\User;
use Tests\TestCase;

final class PasskeyAdminTest extends TestCase
{
    public function test_index_returns_passkeys_scoped_to_the_environment(): void
    {
        $boot = BapiTestSupport::bootEnv('pkadmin');
        app()->instance(Environment::class, $boot['env']);
        $user = User::create(['environment_id' => $boot['env']->id]);
        Passkey::factory()->create(['user_id' => $user->id, 'nickname' => 'A']);
        Passkey::factory()->create(['user_id' => $user->id, 'nickname' => 'B']);

        $resp = $this->getJson(
            BapiTestSupport::url('/passkeys'),
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $this->assertSame(2, $resp->json('total_count'));
        $this->assertCount(2, $resp->json('data'));
    }

    public function test_index_filters_by_user_id(): void
    {
        $boot = BapiTestSupport::bootEnv('pkfilter');
        app()->instance(Environment::class, $boot['env']);
        $a = User::create(['environment_id' => $boot['env']->id]);
        $b = User::create(['environment_id' => $boot['env']->id]);
        Passkey::factory()->create(['user_id' => $a->id]);
        Passkey::factory()->create(['user_id' => $a->id]);
        Passkey::factory()->create(['user_id' => $b->id]);

        $resp = $this->getJson(
            BapiTestSupport::url('/passkeys?user_id='.$a->id),
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $this->assertSame(2, $resp->json('total_count'));
    }

    public function test_show_returns_a_single_passkey(): void
    {
        $boot = BapiTestSupport::bootEnv('pkshow');
        app()->instance(Environment::class, $boot['env']);
        $user = User::create(['environment_id' => $boot['env']->id]);
        $passkey = Passkey::factory()->create(['user_id' => $user->id, 'nickname' => 'YubiKey']);

        $resp = $this->getJson(
            BapiTestSupport::url('/passkeys/'.$passkey->id),
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $resp->assertJson(['object' => 'passkey', 'id' => $passkey->id, 'nickname' => 'YubiKey']);
    }

    public function test_show_404_for_passkey_in_another_env(): void
    {
        // Different first-char slugs so the seeded test API keys don't collide.
        $bootA = BapiTestSupport::bootEnv('alpha');
        $bootB = BapiTestSupport::bootEnv('bravo');
        $user = User::create(['environment_id' => $bootB['env']->id]);
        $passkey = Passkey::factory()->create(['user_id' => $user->id]);

        // Use env A's bearer to read env B's passkey — must 404 (env scoping).
        $resp = $this->getJson(
            'http://api.authn.local/v1/passkeys/'.$passkey->id,
            BapiTestSupport::headers($bootA['token']),
        );

        $resp->assertStatus(404);
    }

    public function test_update_renames_the_passkey(): void
    {
        $boot = BapiTestSupport::bootEnv('pkrename');
        app()->instance(Environment::class, $boot['env']);
        $user = User::create(['environment_id' => $boot['env']->id]);
        $passkey = Passkey::factory()->create(['user_id' => $user->id, 'nickname' => 'Old']);

        $resp = $this->patchJson(
            BapiTestSupport::url('/passkeys/'.$passkey->id),
            ['nickname' => 'Renamed by admin'],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $resp->assertJsonPath('nickname', 'Renamed by admin');
        $this->assertSame('Renamed by admin', $passkey->fresh()->nickname);
    }

    public function test_update_422_when_nickname_missing(): void
    {
        $boot = BapiTestSupport::bootEnv('pkmissing');
        app()->instance(Environment::class, $boot['env']);
        $user = User::create(['environment_id' => $boot['env']->id]);
        $passkey = Passkey::factory()->create(['user_id' => $user->id]);

        $resp = $this->patchJson(
            BapiTestSupport::url('/passkeys/'.$passkey->id),
            [],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertStatus(422);
    }

    public function test_destroy_soft_removes_the_passkey(): void
    {
        $boot = BapiTestSupport::bootEnv('pkdel');
        app()->instance(Environment::class, $boot['env']);
        $user = User::create(['environment_id' => $boot['env']->id]);
        $passkey = Passkey::factory()->create(['user_id' => $user->id]);

        $resp = $this->deleteJson(
            BapiTestSupport::url('/passkeys/'.$passkey->id),
            [],
            BapiTestSupport::headers($boot['token']),
        );

        $resp->assertOk();
        $this->assertNull(Passkey::query()->find($passkey->id));
        $this->assertNotNull(Passkey::withTrashed()->find($passkey->id)?->removed_at);
    }
}
