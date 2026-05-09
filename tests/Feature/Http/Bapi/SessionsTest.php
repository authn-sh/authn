<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Session;
use App\Models\User;
use Tests\Feature\Http\Bapi\BapiTestSupport;


it('lists, shows, revokes, mints tokens for sessions', function (): void {
    $f = BapiTestSupport::bootEnv();
    $user = User::create(['environment_id' => $f['env']->id]);
    $client = Client::create(['environment_id' => $f['env']->id]);
    $session = Session::create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
        'status' => Session::STATUS_ACTIVE,
    ]);

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/sessions'))
        ->assertOk()->assertJsonPath('total_count', 1);

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url("/sessions/{$session->id}"))
        ->assertOk()->assertJsonPath('id', $session->id);

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url("/sessions/{$session->id}/tokens"))
        ->assertOk()->assertJsonStructure(['jwt', 'expires_at', 'kid']);

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url("/sessions/{$session->id}/revoke"))
        ->assertOk()->assertJsonPath('status', 'revoked');
});

it('filters sessions by user_id and status', function (): void {
    $f = BapiTestSupport::bootEnv();
    $client = Client::create(['environment_id' => $f['env']->id]);
    foreach (['a', 'b'] as $tag) {
        $user = User::create(['environment_id' => $f['env']->id]);
        Session::create([
            'environment_id' => $f['env']->id,
            'client_id' => $client->id,
            'user_id' => $user->id,
            'status' => Session::STATUS_ACTIVE,
        ]);
    }
    $someUser = User::query()->withoutGlobalScopes()->latest('id')->first();

    $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/sessions?user_id='.$someUser->id))
        ->assertOk()->assertJsonPath('total_count', 1);
});
