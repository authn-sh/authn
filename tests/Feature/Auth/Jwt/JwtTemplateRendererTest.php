<?php

declare(strict_types=1);

use App\Auth\Jwt\JwtTemplateNotFound;
use App\Auth\Jwt\JwtTemplateRenderer;
use App\Models\Client;
use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\JwtTemplate;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\Role;
use App\Models\Session;
use App\Models\User;
use App\Services\Keys\SigningKeyGenerator;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;

function bootJwtTemplateEnv(string $slug = 'jwt'): Environment
{
    $project = Project::create(['name' => 'P', 'slug' => 'p-'.$slug]);
    $env = Environment::create([
        'project_id' => $project->id,
        'kind' => Environment::KIND_PRODUCTION,
        'slug' => $slug,
        'routing_label' => $slug,
        'allowed_origins' => [],
    ]);
    (new SigningKeyGenerator)->generate($env);

    return $env;
}

function makeUserAndSession(Environment $env, array $userAttrs = [], array $sessionAttrs = []): array
{
    $user = User::create(array_merge([
        'environment_id' => $env->id,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
    ], $userAttrs));
    EmailAddress::create([
        'environment_id' => $env->id,
        'user_id' => $user->id,
        'email_address' => 'ada@example.test',
        'verified_at' => now(),
        'is_primary' => true,
    ]);
    $client = Client::create(['environment_id' => $env->id]);
    $session = Session::create(array_merge([
        'environment_id' => $env->id,
        'client_id' => $client->id,
        'user_id' => $user->id,
        'status' => Session::STATUS_ACTIVE,
    ], $sessionAttrs));

    return ['user' => $user->refresh(), 'session' => $session->refresh()];
}

it('renders simple {{user.id}} / {{user.email}} placeholders using the env signing key', function (): void {
    $env = bootJwtTemplateEnv();
    ['user' => $user, 'session' => $session] = makeUserAndSession($env);

    $template = JwtTemplate::factory()->create([
        'environment_id' => $env->id,
        'name' => 'api-access',
        'claims' => [
            'sub' => '{{user.id}}',
            'email' => '{{user.primary_email}}',
            'session_id' => '{{session.id}}',
        ],
    ]);

    $jwt = (new JwtTemplateRenderer)->render($template, $user, $session);
    $parsed = (new Parser(new JoseEncoder))->parse($jwt);

    expect($parsed->claims()->get('sub'))->toBe($user->id);
    expect($parsed->claims()->get('email'))->toBe('ada@example.test');
    expect($parsed->claims()->get('session_id'))->toBe($session->id);
});

it('renders org-scoped placeholders when an Organization is supplied', function (): void {
    $env = bootJwtTemplateEnv();
    ['user' => $user, 'session' => $session] = makeUserAndSession($env);
    $org = Organization::create(['environment_id' => $env->id, 'name' => 'Acme', 'slug' => 'acme']);
    $role = Role::withoutGlobalScopes()->where('environment_id', $env->id)->where('key', 'org:admin')->firstOrFail();
    OrganizationMembership::create([
        'environment_id' => $env->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'role_id' => $role->id,
    ]);

    $template = JwtTemplate::factory()->create([
        'environment_id' => $env->id,
        'name' => 'org-token',
        'claims' => [
            'sub' => '{{user.id}}',
            'org' => '{{org.slug}}',
            'role' => '{{org.role}}',
        ],
    ]);

    $jwt = (new JwtTemplateRenderer)->render($template, $user, $session, $org);
    $parsed = (new Parser(new JoseEncoder))->parse($jwt);

    expect($parsed->claims()->get('org'))->toBe('acme');
    expect($parsed->claims()->get('role'))->toBe('org:admin');
});

it('falls back to an empty string when a placeholder path is missing', function (): void {
    $env = bootJwtTemplateEnv();
    ['user' => $user, 'session' => $session] = makeUserAndSession($env);

    $template = JwtTemplate::factory()->create([
        'environment_id' => $env->id,
        'claims' => [
            'sub' => '{{user.id}}',
            'department' => '{{user.public_metadata.department}}',
        ],
    ]);

    $jwt = (new JwtTemplateRenderer)->render($template, $user, $session);
    $parsed = (new Parser(new JoseEncoder))->parse($jwt);

    expect($parsed->claims()->get('department'))->toBe('');
});

it('rejects {{user.private_metadata.*}} access by throwing PRIVATE_METADATA_REJECTED', function (): void {
    $env = bootJwtTemplateEnv();
    ['user' => $user, 'session' => $session] = makeUserAndSession($env);

    $template = JwtTemplate::factory()->create([
        'environment_id' => $env->id,
        'claims' => ['sub' => '{{user.id}}', 'pm' => '{{user.private_metadata.foo}}'],
    ]);

    expect(fn () => (new JwtTemplateRenderer)->render($template, $user, $session))
        ->toThrow(InvalidArgumentException::class, JwtTemplateRenderer::PRIVATE_METADATA_REJECTED);
});

it('honours the template lifetime when minting expiry', function (): void {
    $env = bootJwtTemplateEnv();
    ['user' => $user, 'session' => $session] = makeUserAndSession($env);

    $template = JwtTemplate::factory()->create([
        'environment_id' => $env->id,
        'lifetime' => 600,
        'allowed_clock_skew' => 30,
        'claims' => ['sub' => '{{user.id}}'],
    ]);

    $before = now();
    $jwt = (new JwtTemplateRenderer)->render($template, $user, $session);
    $parsed = (new Parser(new JoseEncoder))->parse($jwt);
    $exp = $parsed->claims()->get('exp');
    $iat = $parsed->claims()->get('iat');
    $nbf = $parsed->claims()->get('nbf');

    expect($exp->getTimestamp() - $iat->getTimestamp())->toBe(600);
    expect($iat->getTimestamp() - $nbf->getTimestamp())->toBe(30);
    expect($exp->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp() + 599);
});

it('renders nested array claims with placeholders resolved at every leaf', function (): void {
    $env = bootJwtTemplateEnv();
    ['user' => $user, 'session' => $session] = makeUserAndSession($env);

    $template = JwtTemplate::factory()->create([
        'environment_id' => $env->id,
        'claims' => [
            'sub' => '{{user.id}}',
            'profile' => [
                'first_name' => '{{user.first_name}}',
                'email' => '{{user.email}}',
            ],
        ],
    ]);

    $jwt = (new JwtTemplateRenderer)->render($template, $user, $session);
    $parsed = (new Parser(new JoseEncoder))->parse($jwt);

    $profile = (array) $parsed->claims()->get('profile');
    expect($profile['first_name'])->toBe('Ada');
    expect($profile['email'])->toBe('ada@example.test');
});

it('hooks into Session::getToken({template}) and throws JwtTemplateNotFound for unknown names', function (): void {
    $env = bootJwtTemplateEnv();
    ['user' => $user, 'session' => $session] = makeUserAndSession($env);

    JwtTemplate::factory()->create([
        'environment_id' => $env->id,
        'name' => 'api',
        'claims' => ['sub' => '{{user.id}}', 'kind' => 'api'],
    ]);

    $jwt = $session->getToken('api');
    $parsed = (new Parser(new JoseEncoder))->parse($jwt);
    expect($parsed->claims()->get('sub'))->toBe($user->id);
    expect($parsed->claims()->get('kind'))->toBe('api');

    expect(fn () => $session->getToken('does-not-exist'))->toThrow(JwtTemplateNotFound::class);
});

it('signs with a custom_signing_key when the template supplies one and sets the kid to the template id', function (): void {
    $env = bootJwtTemplateEnv();
    ['user' => $user, 'session' => $session] = makeUserAndSession($env);

    // Generate an RSA keypair for the test.
    $keyRes = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    openssl_pkey_export($keyRes, $privatePem);

    $template = JwtTemplate::factory()->withCustomSigningKey($privatePem)->create([
        'environment_id' => $env->id,
        'claims' => ['sub' => '{{user.id}}'],
    ]);

    $jwt = (new JwtTemplateRenderer)->render($template, $user, $session);
    $parsed = (new Parser(new JoseEncoder))->parse($jwt);
    expect($parsed->headers()->get('kid'))->toBe($template->id);
});

it('returns the env-default session token when template is null (legacy path)', function (): void {
    $env = bootJwtTemplateEnv();
    ['user' => $user, 'session' => $session] = makeUserAndSession($env);

    $jwt = $session->getToken();
    $parsed = (new Parser(new JoseEncoder))->parse($jwt);
    // v=2 is the v0.1 default session-token shape marker; v0.7 templates
    // intentionally omit it so consumers can tell the two paths apart.
    expect($parsed->claims()->get('v'))->toBe(2);
});
