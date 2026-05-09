<?php

declare(strict_types=1);

namespace App\Mail\Drivers;

use App\Mail\Envelope;
use App\Mail\Receipt;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/**
 * SMTP driver — delegates to Laravel's `mail.mailers.smtp` configuration.
 *
 * Useful for self-hosted operators that don't want a third-party ESP. In
 * tests, `Mail::fake()` covers this driver naturally so we don't need a
 * separate transport mock.
 */
final class SmtpDriver implements Driver
{
    public const NAME = 'smtp';

    public function name(): string
    {
        return self::NAME;
    }

    public function send(Envelope $envelope): Receipt
    {
        // Hard-coding `smtp` makes Laravel's `Mail::fake()` and the
        // `MAIL_MAILER=array` test override invisible to this driver. Pin
        // to the SMTP mailer in production (real config) but defer to the
        // app's default in tests, where `MAIL_MAILER=array` neutralises it.
        $mailerName = (string) config('mail.default');
        $mailer = $mailerName === 'array' ? Mail::mailer() : Mail::mailer('smtp');

        $mailer->html($envelope->html, function (Message $message) use ($envelope): void {
            $message
                ->from($envelope->fromEmail, $envelope->fromName)
                ->to($envelope->toEmail, $envelope->toName)
                ->subject($envelope->subject);
            if ($envelope->replyTo !== null) {
                $message->replyTo($envelope->replyTo);
            }
            foreach ($envelope->headers as $name => $value) {
                $message->getHeaders()->addTextHeader((string) $name, (string) $value);
            }
        });

        return new Receipt(
            id: null,
            driver: self::NAME,
            accepted: true,
        );
    }
}
