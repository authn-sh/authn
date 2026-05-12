<?php

declare(strict_types=1);

use App\Models\JwtTemplate;
use Tests\Feature\Http\Bapi\BapiTestSupport;

it('lists JWT templates filtered by env, paginated', function (): void {
    $f = BapiTestSupport::bootEnv();
    JwtTemplate::factory()->create(['environment_id' => $f['env']->id, 'name' => 'one']);
    JwtTemplate::factory()->create(['environment_id' => $f['env']->id, 'name' => 'two']);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/jwt-templates'));

    $r->assertOk()
        ->assertJsonPath('total_count', 2)
        ->assertJsonPath('data.0.object', 'jwt_template');
    expect($r->json('data.0.name'))->toBeIn(['one', 'two']);
});

it('creates a JWT template with defaults applied', function (): void {
    $f = BapiTestSupport::bootEnv();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/jwt-templates'), [
            'name' => 'supabase',
            'claims' => [
                'sub' => '{{user.id}}',
                'email' => '{{user.primary_email}}',
                'role' => 'authenticated',
            ],
        ]);

    $r->assertCreated()
        ->assertJsonPath('object', 'jwt_template')
        ->assertJsonPath('name', 'supabase')
        ->assertJsonPath('lifetime', 60)
        ->assertJsonPath('allowed_clock_skew', 5)
        ->assertJsonPath('signing_algorithm', 'RS256');
    expect($r->json('claims.role'))->toBe('authenticated');
    expect($r->headers->get('Cache-Control'))->toContain('no-store');
});

it('refuses a duplicate (env, name) with 409', function (): void {
    $f = BapiTestSupport::bootEnv();
    JwtTemplate::factory()->create(['environment_id' => $f['env']->id, 'name' => 'pinned']);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->postJson(BapiTestSupport::url('/jwt-templates'), [
            'name' => 'pinned',
            'claims' => ['sub' => '{{user.id}}'],
        ]);

    $r->assertStatus(409)->assertJsonPath('errors.0.code', 'jwt_template_name_taken');
});

it('rejects names that do not match the slug pattern with 422', function (): void {
    $f = BapiTestSupport::bootEnv();

    foreach (['NotLowercase', 'has space', '0starts-with-digit', str_repeat('a', 65)] as $bad) {
        $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
            ->postJson(BapiTestSupport::url('/jwt-templates'), [
                'name' => $bad,
                'claims' => ['sub' => '{{user.id}}'],
            ]);
        $r->assertStatus(422);
    }
});

it('shows + hides custom_signing_key on GET', function (): void {
    $f = BapiTestSupport::bootEnv();
    $template = JwtTemplate::factory()->withCustomSigningKey('--PEM--secret--')->create([
        'environment_id' => $f['env']->id,
        'name' => 'rotated',
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/jwt-templates/'.$template->id));

    $r->assertOk()->assertJsonPath('name', 'rotated');
    expect($r->json())->not->toHaveKey('custom_signing_key');
});

it('refuses PATCH that changes name or signing_algorithm with 422', function (): void {
    $f = BapiTestSupport::bootEnv();
    $template = JwtTemplate::factory()->create([
        'environment_id' => $f['env']->id,
        'name' => 'frozen',
        'signing_algorithm' => 'RS256',
    ]);

    $r1 = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/jwt-templates/'.$template->id), ['name' => 'renamed']);
    $r1->assertStatus(422)->assertJsonPath('errors.0.code', 'name_immutable');

    $r2 = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/jwt-templates/'.$template->id), ['signing_algorithm' => 'HS256']);
    $r2->assertStatus(422)->assertJsonPath('errors.0.code', 'signing_algorithm_immutable');
});

it('PATCH updates lifetime + claims and accepts no-op name / signing_algorithm', function (): void {
    $f = BapiTestSupport::bootEnv();
    $template = JwtTemplate::factory()->create([
        'environment_id' => $f['env']->id,
        'name' => 'apikit',
        'signing_algorithm' => 'RS256',
        'lifetime' => 60,
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/jwt-templates/'.$template->id), [
            'name' => 'apikit', // same name allowed (no-op)
            'signing_algorithm' => 'RS256', // same alg allowed
            'lifetime' => 900,
            'claims' => ['sub' => '{{user.external_id}}'],
        ]);

    $r->assertOk()->assertJsonPath('lifetime', 900);
    expect($r->json('claims.sub'))->toBe('{{user.external_id}}');
});

it('PATCH custom_signing_key:null reverts to the env default signing key', function (): void {
    $f = BapiTestSupport::bootEnv();
    $template = JwtTemplate::factory()->withCustomSigningKey('--initial--')->create([
        'environment_id' => $f['env']->id,
        'name' => 'revertible',
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->patchJson(BapiTestSupport::url('/jwt-templates/'.$template->id), [
            'custom_signing_key' => null,
        ]);

    $r->assertOk();
    expect($template->fresh()->custom_signing_key)->toBeNull();
});

it('DELETE soft-removes a never-used template and 404s subsequent GETs', function (): void {
    $f = BapiTestSupport::bootEnv();
    $template = JwtTemplate::factory()->create([
        'environment_id' => $f['env']->id,
        'name' => 'unused',
    ]);

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->deleteJson(BapiTestSupport::url('/jwt-templates/'.$template->id));
    $r->assertNoContent();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->getJson(BapiTestSupport::url('/jwt-templates/'.$template->id));
    $r->assertStatus(404)->assertJsonPath('errors.0.code', 'jwt_template_not_found');
});

it('DELETE refuses with 409 jwt_template_in_use while inside the grace window', function (): void {
    $f = BapiTestSupport::bootEnv();
    $template = JwtTemplate::factory()->create([
        'environment_id' => $f['env']->id,
        'name' => 'busy',
        'lifetime' => 600,
        'allowed_clock_skew' => 10,
    ]);
    // Stamp a recent render — well inside the 40h floor.
    $template->forceFill(['last_used_at' => now()->subMinutes(10)])->save();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->deleteJson(BapiTestSupport::url('/jwt-templates/'.$template->id));

    $r->assertStatus(409)
        ->assertJsonPath('errors.0.code', 'jwt_template_in_use');
    expect($r->headers->get('Retry-After'))->not->toBeNull();
});

it('DELETE allows deletion once the grace window has elapsed', function (): void {
    $f = BapiTestSupport::bootEnv();
    $template = JwtTemplate::factory()->create([
        'environment_id' => $f['env']->id,
        'name' => 'expired-grace',
        'lifetime' => 60,
        'allowed_clock_skew' => 5,
    ]);
    // Last use beyond the 40h floor — fine to drop.
    $template->forceFill(['last_used_at' => now()->subHours(41)])->save();

    $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
        ->deleteJson(BapiTestSupport::url('/jwt-templates/'.$template->id));

    $r->assertNoContent();
});

it('GET / PATCH / DELETE return 404 for a JwtTemplate in another env', function (): void {
    $f = BapiTestSupport::bootEnv('one');
    $other = BapiTestSupport::bootEnv('two');
    $template = JwtTemplate::factory()->create([
        'environment_id' => $other['env']->id,
        'name' => 'cross-env',
    ]);

    foreach (['GET', 'PATCH', 'DELETE'] as $method) {
        $r = $this->withHeaders(BapiTestSupport::headers($f['token']))
            ->json($method, BapiTestSupport::url('/jwt-templates/'.$template->id), $method === 'PATCH' ? ['lifetime' => 90] : []);
        $r->assertStatus(404)->assertJsonPath('errors.0.code', 'jwt_template_not_found');
    }
});
