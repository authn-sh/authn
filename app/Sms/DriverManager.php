<?php

declare(strict_types=1);

namespace App\Sms;

use App\Models\Environment;
use App\Sms\Drivers\NullDriver;
use App\Sms\Drivers\SmsDriver;
use App\Sms\Drivers\TwilioDriver;
use App\Sms\Drivers\VonageDriver;
use Closure;
use InvalidArgumentException;

/**
 * Resolves the SmsDriver instance for a given Environment. The env's
 * `user_settings.sms.driver` overrides the global default
 * (`config('authn-sms.default_driver')`).
 *
 * Mirror of `App\Mail\DriverManager`.
 */
final class DriverManager
{
    /**
     * @var array<string, class-string<SmsDriver>>
     */
    private const NAME_TO_CLASS = [
        TwilioDriver::NAME => TwilioDriver::class,
        VonageDriver::NAME => VonageDriver::class,
        NullDriver::NAME => NullDriver::class,
    ];

    /**
     * Operator-supplied factories keyed by driver name. Lets tests +
     * the Dashboard "Send test SMS" path register one-off drivers
     * without touching the static map.
     *
     * @var array<string, Closure(): SmsDriver>
     */
    private array $extensions = [];

    public function for(Environment $environment): SmsDriver
    {
        $userSettings = is_array($environment->user_settings) ? $environment->user_settings : [];
        $smsCfg = is_array($userSettings['sms'] ?? null) ? $userSettings['sms'] : [];
        $name = (string) ($smsCfg['driver'] ?? config('authn-sms.default_driver'));

        return $this->resolve($name);
    }

    public function resolve(string $name): SmsDriver
    {
        if (isset($this->extensions[$name])) {
            return ($this->extensions[$name])();
        }

        if (! isset(self::NAME_TO_CLASS[$name])) {
            throw new InvalidArgumentException("Unknown SMS driver: {$name}");
        }

        return app(self::NAME_TO_CLASS[$name]);
    }

    /**
     * Register a driver factory under `$name`. Subsequent `resolve()`
     * calls return the factory's product.
     *
     * @param  Closure(): SmsDriver  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->extensions[$name] = $factory;
    }
}
