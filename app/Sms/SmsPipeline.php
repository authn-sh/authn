<?php

declare(strict_types=1);

namespace App\Sms;

use App\Mail\Renderer;
use App\Models\Environment;
use App\Models\PhoneNumber;
use App\Models\SmsTemplate;
use App\Models\Verification;
use App\Webhooks\Emitter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Shared dispatch path for every Send*Sms job. Mirror of `EmailPipeline`.
 *
 *   1. Test-mode short-circuit — recipient in `+1 (555) 555-0100`–`0199`
 *      reserved range OR `Environment.user_settings.test_mode = enabled`
 *      → log + bail, no driver call.
 *   2. Per-(phone_id, verification_id) debounce.
 *   3. Template lookup; bail with a log line if not seeded.
 *   4. Render → if `delivered_by_us = false`, fire `sms.created` for
 *      AU-15 to ship via webhooks; otherwise hand to the env's driver.
 *
 * Returns the `SmsReceipt` (or null when test-mode / debounced).
 */
final class SmsPipeline
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
        string $toNumber,
        array $vars,
        ?PhoneNumber $phoneNumber = null,
        ?Verification $verification = null,
    ): ?SmsReceipt {
        if ($this->isReservedTestNumber($toNumber) || $this->isEnvTestMode($environment)) {
            Log::info('sms_skipped_test_mode', [
                'environment_id' => $environment->id,
                'template' => $templateSlug,
                'to' => $toNumber,
            ]);

            return null;
        }

        if ($phoneNumber !== null && $verification !== null) {
            $cacheKey = sprintf('sms:debounce:%s:%s', $phoneNumber->id, $verification->id);
            if (! Cache::add($cacheKey, 1, (int) config('authn-sms.debounce_seconds', 60))) {
                Log::info('sms_skipped_debounced', [
                    'environment_id' => $environment->id,
                    'template' => $templateSlug,
                    'phone_id' => $phoneNumber->id,
                    'verification_id' => $verification->id,
                ]);

                return null;
            }
        }

        $template = SmsTemplate::query()
            ->withoutGlobalScopes()
            ->where('environment_id', $environment->id)
            ->where('slug', $templateSlug)
            ->first();
        if ($template === null) {
            Log::warning('sms_template_missing', [
                'environment_id' => $environment->id,
                'template' => $templateSlug,
            ]);

            return null;
        }

        $body = $this->renderer->renderSms($template, $environment, $vars);

        $userSettings = is_array($environment->user_settings) ? $environment->user_settings : [];
        $smsCfg = is_array($userSettings['sms'] ?? null) ? $userSettings['sms'] : [];
        $envFromNumber = is_string($smsCfg['from_number'] ?? null) ? (string) $smsCfg['from_number'] : null;
        $globalFrom = (string) (config('authn-sms.default_from_number') ?? '');
        $fromNumber = $template->from_number_override ?: ($envFromNumber ?: $globalFrom);

        $envelope = new SmsEnvelope(
            toNumber: $toNumber,
            fromNumber: $fromNumber,
            body: $body,
            templateSlug: $template->slug,
        );

        if (! $template->delivered_by_us) {
            Log::info('sms.created (delivered_by_us=false)', [
                'environment_id' => $environment->id,
                'template' => $template->slug,
                'to' => $toNumber,
            ]);
            $this->emitter->emit('sms.created', $this->smsEventPayload($template, $envelope, deliveredByUs: false), $environment);

            return new SmsReceipt(id: null, driver: 'webhook', accepted: true, meta: ['template' => $template->slug]);
        }

        $receipt = $this->drivers->for($environment)->send($envelope);
        $this->emitter->emit('sms.created', $this->smsEventPayload($template, $envelope, deliveredByUs: true, receipt: $receipt), $environment);

        return $receipt;
    }

    /**
     * @return array<string, mixed>
     */
    private function smsEventPayload(SmsTemplate $template, SmsEnvelope $envelope, bool $deliveredByUs, ?SmsReceipt $receipt = null): array
    {
        return [
            'object' => 'sms',
            'template_slug' => $template->slug,
            'to_phone_number' => $envelope->toNumber,
            'from_phone_number' => $envelope->fromNumber,
            'body' => $envelope->body,
            'delivered_by_us' => $deliveredByUs,
            'driver' => $receipt?->driver,
            'accepted' => $receipt?->accepted,
            'provider_message_id' => $receipt?->id,
        ];
    }

    /**
     * Per PLAN §9.11 the `+1 (555) 555-0100`–`0199` E.164 range is
     * reserved for tests — sending an SMS there short-circuits to a
     * fixed code so the verification flow is exercisable end-to-end
     * without standing up a real gateway.
     */
    public static function isReservedTestNumber(string $toNumber): bool
    {
        // Strip whitespace + dashes the operator might have stored.
        $normalized = preg_replace('/[\s\-()]/', '', $toNumber) ?? $toNumber;

        // Reserved range: +1 555 555 0100 → +1 555 555 0199.
        return preg_match('/^\+15555550(1\d{2})$/', $normalized) === 1;
    }

    private function isEnvTestMode(Environment $environment): bool
    {
        $userSettings = is_array($environment->user_settings) ? $environment->user_settings : [];

        return ($userSettings['test_mode'] ?? null) === 'enabled';
    }
}
