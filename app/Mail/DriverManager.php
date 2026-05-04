<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Drivers\Driver;
use App\Mail\Drivers\PostmarkDriver;
use App\Mail\Drivers\ResendDriver;
use App\Mail\Drivers\SesDriver;
use App\Mail\Drivers\SmtpDriver;
use App\Models\Environment;
use InvalidArgumentException;

/**
 * Resolves the Driver instance for a given Environment. The env's
 * `user_settings.mail.driver` overrides the global default
 * (`config('authn-mail.default_driver')`).
 */
final class DriverManager
{
    private const NAME_TO_CLASS = [
        ResendDriver::NAME => ResendDriver::class,
        PostmarkDriver::NAME => PostmarkDriver::class,
        SesDriver::NAME => SesDriver::class,
        SmtpDriver::NAME => SmtpDriver::class,
    ];

    public function for(Environment $environment): Driver
    {
        $userSettings = is_array($environment->user_settings) ? $environment->user_settings : [];
        $mailCfg = is_array($userSettings['mail'] ?? null) ? $userSettings['mail'] : [];
        $name = (string) ($mailCfg['driver'] ?? config('authn-mail.default_driver'));

        return $this->resolve($name);
    }

    public function resolve(string $name): Driver
    {
        if (! isset(self::NAME_TO_CLASS[$name])) {
            throw new InvalidArgumentException("Unknown mail driver: {$name}");
        }

        return app(self::NAME_TO_CLASS[$name]);
    }
}
