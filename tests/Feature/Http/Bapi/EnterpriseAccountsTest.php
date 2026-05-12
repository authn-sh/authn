<?php

declare(strict_types=1);

use App\Models\EnterpriseAccount;
use App\Models\EnterpriseConnection;
use App\Models\User;
use Tests\Feature\Http\Bapi\BapiTestSupport;

it('lists enterprise accounts in the env', function (): void {
    $f = BapiTestSupport::bootEnv();
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id]);
    $alice = User::create(['environment_id' => $f['env']->id]);
    $bob = User::create(['environment_id' => $f['env']->id]);
    EnterpriseAccount::factory()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $alice->id,
        'enterprise_connection_id' => $conn->id,
    ]);
    EnterpriseAccount::factory()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $bob->id,
        'enterprise_connection_id' => $conn->id,
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/enterprise-accounts'));

    $r->assertOk()
        ->assertJsonPath('total_count', 2)
        ->assertJsonPath('data.0.object', 'enterprise_account');
});

it('filters list by user_id', function (): void {
    $f = BapiTestSupport::bootEnv();
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id]);
    $alice = User::create(['environment_id' => $f['env']->id]);
    $bob = User::create(['environment_id' => $f['env']->id]);
    EnterpriseAccount::factory()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $alice->id,
        'enterprise_connection_id' => $conn->id,
    ]);
    EnterpriseAccount::factory()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $bob->id,
        'enterprise_connection_id' => $conn->id,
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/enterprise-accounts?user_id='.$alice->id));

    $r->assertOk()->assertJsonPath('total_count', 1);
});

it('filters list by enterprise_connection_id', function (): void {
    $f = BapiTestSupport::bootEnv();
    $connA = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id]);
    $connB = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id]);
    $user = User::create(['environment_id' => $f['env']->id]);
    EnterpriseAccount::factory()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $user->id,
        'enterprise_connection_id' => $connA->id,
    ]);
    EnterpriseAccount::factory()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $user->id,
        'enterprise_connection_id' => $connB->id,
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/enterprise-accounts?enterprise_connection_id='.$connA->id));

    $r->assertOk()->assertJsonPath('total_count', 1)->assertJsonPath('data.0.enterprise_connection_id', $connA->id);
});

it('returns 404 for unknown enterprise_account_id', function (): void {
    $f = BapiTestSupport::bootEnv();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/enterprise-accounts/entacc_nope'));

    $r->assertStatus(404)->assertJsonPath('errors.0.code', 'enterprise_account_not_found');
});

it('shows an enterprise account by id', function (): void {
    $f = BapiTestSupport::bootEnv();
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id]);
    $user = User::create(['environment_id' => $f['env']->id]);
    $account = EnterpriseAccount::factory()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $user->id,
        'enterprise_connection_id' => $conn->id,
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/enterprise-accounts/'.$account->id));

    $r->assertOk()
        ->assertJsonPath('id', $account->id)
        ->assertJsonPath('object', 'enterprise_account')
        ->assertJsonPath('enterprise_connection_id', $conn->id);
});

it('soft-deletes on destroy', function (): void {
    $f = BapiTestSupport::bootEnv();
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id]);
    $user = User::create(['environment_id' => $f['env']->id]);
    $account = EnterpriseAccount::factory()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $user->id,
        'enterprise_connection_id' => $conn->id,
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->deleteJson(BapiTestSupport::url('/enterprise-accounts/'.$account->id));

    $r->assertNoContent();
    expect(EnterpriseAccount::withTrashed()->withoutGlobalScopes()->where('id', $account->id)->first()?->removed_at)
        ->not->toBeNull();
});

it('never returns the encrypted id_token', function (): void {
    $f = BapiTestSupport::bootEnv();
    $conn = EnterpriseConnection::factory()->create(['environment_id' => $f['env']->id]);
    $user = User::create(['environment_id' => $f['env']->id]);
    $account = EnterpriseAccount::factory()->create([
        'environment_id' => $f['env']->id,
        'user_id' => $user->id,
        'enterprise_connection_id' => $conn->id,
        'id_token' => 'secret-id-token-value',
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/enterprise-accounts/'.$account->id));

    $r->assertOk();
    expect($r->json())->not->toHaveKey('id_token');
});
