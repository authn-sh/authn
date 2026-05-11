<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Bapi;

use App\Models\PhoneNumber;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    Bus::fake();
});

it('lists phone numbers filtered by user_id', function (): void {
    $boot = BapiTestSupport::bootEnv('acmeph');
    $user = User::create(['environment_id' => $boot['env']->id]);
    PhoneNumber::query()->withoutGlobalScopes()->create([
        'environment_id' => $boot['env']->id,
        'user_id' => $user->id,
        'phone_number' => '+15555550101',
        'verified_at' => now(),
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($boot['token']))
        ->withoutOpenApiAssertions()
        ->get(BapiTestSupport::url('/phone-numbers?user_id='.$user->id));
    $r->assertOk();
    expect($r->json('data'))->toHaveCount(1);
    expect($r->json('data.0.phone_number'))->toBe('+15555550101');
    expect($r->json('data.0.verified'))->toBeTrue();
});

it('creates an admin phone with verified + primary shortcuts', function (): void {
    $boot = BapiTestSupport::bootEnv('acmeph2');
    $user = User::create(['environment_id' => $boot['env']->id]);

    $r = $this->withHeaders(BapiTestSupport::headers($boot['token']))
        ->withoutOpenApiAssertions()
        ->postJson(BapiTestSupport::url('/phone-numbers'), [
            'user_id' => $user->id,
            'phone_number' => '+15555550102',
            'verified' => true,
            'primary' => true,
        ]);
    $r->assertCreated();
    expect($r->json('verified'))->toBeTrue();
    expect($r->json('is_primary'))->toBeTrue();
    expect($user->fresh()->primary_phone_number_id)->toBe($r->json('id'));
});

it('refuses delete when reserved_for_second_factor is set', function (): void {
    $boot = BapiTestSupport::bootEnv('acmeph3');
    $user = User::create(['environment_id' => $boot['env']->id]);
    $row = PhoneNumber::query()->withoutGlobalScopes()->create([
        'environment_id' => $boot['env']->id,
        'user_id' => $user->id,
        'phone_number' => '+15555550103',
        'verified_at' => now(),
        'reserved_for_second_factor' => true,
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($boot['token']))
        ->withoutOpenApiAssertions()
        ->deleteJson(BapiTestSupport::url('/phone-numbers/'.$row->id));
    $r->assertStatus(409);
    expect($r->json('errors.0.code'))->toBe('phone_reserved_for_second_factor');
    expect(PhoneNumber::query()->withoutGlobalScopes()->where('id', $row->id)->exists())->toBeTrue();
});

it('rejects non-E.164 phone formats on store', function (): void {
    $boot = BapiTestSupport::bootEnv('acmeph4');
    $user = User::create(['environment_id' => $boot['env']->id]);

    $r = $this->withHeaders(BapiTestSupport::headers($boot['token']))
        ->withoutOpenApiAssertions()
        ->postJson(BapiTestSupport::url('/phone-numbers'), [
            'user_id' => $user->id,
            'phone_number' => '555-1234',
        ]);
    $r->assertStatus(422);
    expect($r->json('errors.0.code'))->toBe('form_param_format_invalid');
});
