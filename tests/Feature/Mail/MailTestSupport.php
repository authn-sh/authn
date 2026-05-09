<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Models\Verification;
use App\Services\Verification\VerificationManager;

final class MailTestSupport
{
    public static function bootEnv(array $userSettings = []): Environment
    {
        $project = Project::create(['name' => 'Mail-'.bin2hex(random_bytes(3)), 'slug' => 'mp-'.bin2hex(random_bytes(3))]);
        $env = Environment::create([
            'project_id' => $project->id,
            'kind' => Environment::KIND_PRODUCTION,
            'slug' => 'mail-'.bin2hex(random_bytes(3)),
            'routing_label' => 'mail-'.bin2hex(random_bytes(3)),
            'allowed_origins' => [],
            'user_settings' => $userSettings,
            'appearance' => ['application_name' => 'Acme'],
        ]);

        // EnvironmentObserver already seeded the active templates; nothing to do.
        return $env;
    }

    public static function makeUserWithEmail(Environment $env, string $email): array
    {
        $user = new User(['environment_id' => $env->id, 'first_name' => 'Alice']);
        $user->save();
        $row = EmailAddress::create([
            'environment_id' => $env->id,
            'user_id' => $user->id,
            'email_address' => $email,
            'verified_at' => now(),
            'is_primary' => true,
        ]);

        return ['user' => $user, 'email' => $row];
    }

    public static function startVerification(EmailAddress $email): Verification
    {
        $manager = app(VerificationManager::class);

        return $manager->start($email, Verification::STRATEGY_EMAIL_CODE, 600);
    }
}
