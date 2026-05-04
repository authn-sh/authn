<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\EmailTemplate;
use App\Models\Environment;
use DateTimeInterface;

/**
 * Renders an EmailTemplate against a variable bag.
 *
 * Variable lookup is `{{var}}` / `{{var.path}}` only — no full Liquid
 * expressions in v0.1. Anything under `user.*` (or any value supplied via
 * the `escape` channel) is HTML-escaped before substitution to keep
 * operator-supplied templates XSS-safe even when end-user data flows in.
 *
 * Plain-text fallback is derived from the rendered HTML by stripping tags
 * and collapsing whitespace.
 */
final class Renderer
{
    /**
     * Standard variables every template can reference even if the caller
     * didn't supply them. Pulled from the Environment, not user input, so
     * they never need escaping.
     *
     * @return array<string, mixed>
     */
    public function defaults(Environment $environment): array
    {
        $appearance = is_array($environment->appearance) ? $environment->appearance : [];

        return [
            'app' => [
                'name' => (string) ($appearance['application_name'] ?? config('app.name', 'Authn')),
                'support_email' => $appearance['support_email'] ?? null,
                'brand_color' => $appearance['brand_color'] ?? null,
                'logo_url' => $appearance['logo_url'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $vars  Caller-supplied bag, merged on top of `defaults()`.
     */
    public function render(EmailTemplate $template, Environment $environment, array $vars): RenderedEmail
    {
        $merged = $this->mergeRecursive($this->defaults($environment), $vars);

        $subject = $this->substitute((string) $template->subject, $merged);
        $html = $this->substitute((string) ($template->body_html ?? $template->body_markup), $merged);
        $text = $this->htmlToText($html);

        return new RenderedEmail($subject, $html, $text);
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function substitute(string $source, array $vars): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_.-]*)\s*\}\}/',
            function (array $m) use ($vars): string {
                $value = $this->lookup($vars, $m[1]);
                if (! is_scalar($value) && $value !== null) {
                    return '';
                }
                $shouldEscape = str_starts_with($m[1], 'user.') || str_contains($m[1], '_unsafe');

                return $shouldEscape
                    ? htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    : (string) $value;
            },
            $source,
        );
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    private function lookup(array $vars, string $path): mixed
    {
        $cursor = $vars;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    private function htmlToText(string $html): string
    {
        $withSpacedBreaks = preg_replace('/<\s*(br|p|div|tr|h\d)\s*\/?>/i', "\n", $html) ?? $html;
        $stripped = strip_tags($withSpacedBreaks);
        $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $lines = array_map('trim', explode("\n", $decoded));
        $compact = [];
        foreach ($lines as $line) {
            if ($line === '' && end($compact) === '') {
                continue;
            }
            $compact[] = $line;
        }

        return trim(implode("\n", $compact));
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private function mergeRecursive(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeRecursive($base[$key], $value);

                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Convenience formatter used by the jobs to build the
     * `{{expires_at_human}}` placeholder consistently.
     */
    public static function humanExpires(DateTimeInterface $expiresAt): string
    {
        $seconds = max(0, $expiresAt->getTimestamp() - now()->getTimestamp());
        if ($seconds < 60) {
            return "in {$seconds} seconds";
        }
        $minutes = (int) round($seconds / 60);
        if ($minutes < 60) {
            return "in {$minutes} minutes";
        }
        $hours = (int) round($minutes / 60);
        if ($hours < 24) {
            return "in {$hours} hours";
        }
        $days = (int) round($hours / 24);

        return "in {$days} days";
    }
}
