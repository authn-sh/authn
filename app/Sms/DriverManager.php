<?php

declare(strict_types=1);

namespace App\Sms;

use App\Sms\Drivers\NullDriver;
use App\Sms\Drivers\SmsDriver;
use App\Sms\Drivers\TwilioDriver;
use App\Sms\Drivers\VonageDriver;
use Closure;
use InvalidArgumentException;

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

    /** @var array<string, Closure(): SmsDriver> */
    private array $extensions = [];

    public function default(): SmsDriver
    {
        return $this->resolve((string) config('authn-sms.default_driver'));
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
     * @param  Closure(): SmsDriver  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->extensions[$name] = $factory;
    }
}
