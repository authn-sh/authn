<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\EmailAddress;
use App\Models\EmailTemplate;
use App\Models\Environment;
use App\Models\Verification;
use App\Webhooks\Emitter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Shared dispatch path for every Send*Email job.
 *
 *   1. Test-mode short-circuit (`+authn_test@…` recipient OR
 *      `Environment.user_settings.test_mode = enabled`) → log + bail.
 *   2. Per-(email_id, verification_id) debounce — at most one driver call
 *      per `config('authn-mail.debounce_seconds')` window.
 *   3. Template lookup; bail with a log line if not seeded.
 *   4. Render → if `delivered_by_us = false`, fire the
 *      `email.created` audit event for AU-15 to ship to webhooks; otherwise
 *      hand the envelope to the env's driver.
 *
 * Returns the Receipt (or null when test-mode / debounced).
 */
final class EmailPipeline
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly DriverManager $drivers,
        private readonly Emitter $emitter,
    ) {}

    /**
     * @param  array<string, mixed>  $vars
     */
    public function dispatch(
        Environment $environment,
        string $templateSlug,
        string $toEmail,
        ?string $toName,
        array $vars,
        ?EmailAddress $emailAddress = null,
        ?Verification $verification = null,
    ): ?Receipt {
        if ($this->isTestRecipient($toEmail) || $this->isEnvTestMode($environment)) {
            Log::info('mail_skipped_test_mode', [
                'environment_id' => $environment->id,
                'template' => $templateSlug,
                'to' => $toEmail,
            ]);

            return null;
        }

        if ($emailAddress !== null && $verification !== null) {
            $cacheKey = sprintf('mail:debounce:%s:%s', $emailAddress->id, $verification->id);
            if (! Cache::add($cacheKey, 1, (int) config('authn-mail.debounce_seconds', 60))) {
                Log::info('mail_skipped_debounced', [
                    'environment_id' => $environment->id,
                    'template' => $templateSlug,
                    'email_id' => $emailAddress->id,
                    'verification_id' => $verification->id,
                ]);

                return null;
            }
        }

        $template = EmailTemplate::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $environment->id)
            ->where('slug', $templateSlug)
            ->first();
        if ($template === null) {
            Log::warning('mail_template_missing', [
                'environment_id' => $environment->id,
                'template' => $templateSlug,
            ]);

            return null;
        }

        $rendered = $this->renderer->render($template, $environment, $vars);

        $envelope = new Envelope(
            fromEmail: (string) (config('authn-mail.default_from.email') ?? 'noreply@authn.local'),
            fromName: (string) ($template->from_email_name ?? config('authn-mail.default_from.name', 'Authn')),
            toEmail: $toEmail,
            toName: $toName,
            subject: $rendered->subject,
            html: $rendered->html,
            text: $rendered->text,
            replyTo: $template->reply_to_email_name,
            headers: ['X-Authn-Template' => $template->slug],
        );

        if (! $template->delivered_by_us) {
            // Webhook-only delivery: the operator's pipeline ships the email.
            // We emit the structured event here AND keep the audit log
            // breadcrumb so the integration is greppable.
            Log::info('email.created (delivered_by_us=false)', [
                'environment_id' => $environment->id,
                'template' => $template->slug,
                'to' => $toEmail,
                'subject' => $rendered->subject,
            ]);
            $this->emitter->emit('email.created', $this->emailEventPayload($template, $envelope, $rendered, deliveredByUs: false), $environment);

            return new Receipt(id: null, driver: 'webhook', accepted: true, meta: ['template' => $template->slug]);
        }

        $receipt = $this->drivers->for($environment)->send($envelope);
        $this->emitter->emit('email.created', $this->emailEventPayload($template, $envelope, $rendered, deliveredByUs: true, receipt: $receipt), $environment);

        return $receipt;
    }

    /**
     * @return array<string, mixed>
     */
    private function emailEventPayload(EmailTemplate $template, Envelope $envelope, RenderedEmail $rendered, bool $deliveredByUs, ?Receipt $receipt = null): array
    {
        return [
            'object' => 'email',
            'template_slug' => $template->slug,
            'to_email_address' => $envelope->toEmail,
            'from_email_name' => $envelope->fromName,
            'subject' => $rendered->subject,
            'delivered_by_us' => $deliveredByUs,
            'driver' => $receipt?->driver,
            'accepted' => $receipt?->accepted,
            'provider_message_id' => $receipt?->id,
        ];
    }

    private function isTestRecipient(string $email): bool
    {
        // Clerk-style test affordance (PLAN §9.11). The full identifier
        // detection (incl. phone + alt suffixes) lights up in AU-18; this is
        // the v0.1 cut.
        return str_contains($email, '+authn_test');
    }

    private function isEnvTestMode(Environment $environment): bool
    {
        $userSettings = is_array($environment->user_settings) ? $environment->user_settings : [];

        return ($userSettings['test_mode'] ?? null) === 'enabled';
    }
}
