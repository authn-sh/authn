<?php

declare(strict_types=1);

use App\Jobs\Webhooks\DispatchWebhookDelivery;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\Session;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEvent;
use App\Services\Sessions\SessionLifecycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Http\Sessions\SessionsTestSupport;

uses(RefreshDatabase::class);

it('session.created carries the active organization block when the session has one', function (): void {
    Bus::fake([DispatchWebhookDelivery::class]);
    $f = SessionsTestSupport::bootEnv();
    WebhookEndpoint::create([
        'environment_id' => $f['env']->id,
        'url' => 'https://example.test/hook',
        'signing_secret' => WebhookEndpoint::mintSecret(),
        'enabled_event_types' => ['session.*'],
        'enabled' => true,
    ]);

    $user = new User(['environment_id' => $f['env']->id, 'username' => 'op']);
    $user->save();
    $org = Organization::create(['environment_id' => $f['env']->id, 'name' => 'Acme', 'slug' => 'acme-active']);
    $admin = Role::query()->withoutGlobalScopes()->where('environment_id', $f['env']->id)->where('key', 'org:admin')->firstOrFail();
    OrganizationMembership::create([
        'environment_id' => $f['env']->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role_id' => $admin->id,
    ]);
    $client = Client::create(['environment_id' => $f['env']->id]);
    $session = Session::create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
        'status' => Session::STATUS_ACTIVE,
        'last_active_organization_id' => $org->id,
    ]);

    app(SessionLifecycle::class)->notifyCreated($session->fresh());

    $event = WebhookEvent::query()->where('type', 'session.created')->latest('id')->first();
    expect($event)->not->toBeNull();
    expect($event->data['organization'])->not->toBeNull();
    expect($event->data['organization']['id'])->toBe($org->id);
    expect($event->data['organization']['slug'])->toBe('acme-active');
    expect($event->data['organization']['role'])->toBe('org:admin');
    expect($event->data['organization']['permissions'])->toContain('org:sys_profile:manage');
});

it('session.created omits the organization block when no active org', function (): void {
    Bus::fake([DispatchWebhookDelivery::class]);
    $f = SessionsTestSupport::bootEnv();
    WebhookEndpoint::create([
        'environment_id' => $f['env']->id,
        'url' => 'https://example.test/hook',
        'signing_secret' => WebhookEndpoint::mintSecret(),
        'enabled_event_types' => ['*'],
        'enabled' => true,
    ]);

    $user = new User(['environment_id' => $f['env']->id, 'username' => 'plain']);
    $user->save();
    $client = Client::create(['environment_id' => $f['env']->id]);
    $session = Session::create([
        'environment_id' => $f['env']->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
        'status' => Session::STATUS_ACTIVE,
    ]);

    app(SessionLifecycle::class)->notifyCreated($session->fresh());

    $event = WebhookEvent::query()->where('type', 'session.created')->latest('id')->first();
    expect($event)->not->toBeNull();
    expect($event->data['organization'])->toBeNull();
});
