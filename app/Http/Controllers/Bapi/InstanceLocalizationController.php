<?php

declare(strict_types=1);

namespace App\Http\Controllers\Bapi;

use App\Localization\CanonicalSchema;
use App\Models\Environment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 *   GET    /v1/instance/localization     — read the operator's locale config
 *   PUT    /v1/instance/localization     — full replace
 *   PATCH  /v1/instance/localization     — per-locale sparse merge on overrides
 *
 * Stores **overrides only** on the row; canonical defaults ship with the
 * server bundle. AU-7's public catalog endpoint merges canonical defaults
 * with the operator's overrides at render time.
 */
final class InstanceLocalizationController
{
    public function show(): JsonResponse
    {
        return response()->json($this->payload(app(Environment::class)));
    }

    public function replace(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $next = $this->validateBlob($request, isPatch: false);
        $warnings = $this->collectPlaceholderWarnings($next['overrides']);

        $env->forceFill(['localization' => $next])->save();

        $response = response()->json($this->payload($env->refresh()));
        foreach ($warnings as $warning) {
            $response->headers->set('Warning', $warning, false);
        }

        return $response;
    }

    public function patch(Request $request): JsonResponse
    {
        $env = app(Environment::class);
        $stored = $this->normalise($env->localization);
        $patch = $this->validateBlob($request, isPatch: true);

        $next = $stored;
        foreach (['default_locale', 'fallback_locale', 'supported_locales'] as $field) {
            if (array_key_exists($field, $patch)) {
                $next[$field] = $patch[$field];
            }
        }
        $patchOverrides = $patch['overrides'] ?? [];
        foreach ($patchOverrides as $locale => $entries) {
            $existing = is_array($next['overrides'][$locale] ?? null) ? $next['overrides'][$locale] : [];
            foreach ($entries as $key => $value) {
                if ($value === null) {
                    unset($existing[$key]);

                    continue;
                }
                $existing[$key] = $value;
            }
            $next['overrides'][$locale] = $existing;
        }

        $this->assertCanonicalKeys($next);
        $this->assertLocaleConsistency($next);

        $warnings = $this->collectPlaceholderWarnings($next['overrides']);
        $env->forceFill(['localization' => $next])->save();

        $response = response()->json($this->payload($env->refresh()));
        foreach ($warnings as $warning) {
            $response->headers->set('Warning', $warning, false);
        }

        return $response;
    }

    /**
     * @return array{default_locale:string, fallback_locale:string, supported_locales:list<string>, overrides:array<string, array<string, ?string>>}
     */
    private function validateBlob(Request $request, bool $isPatch): array
    {
        $body = $request->all();
        $rules = [
            'default_locale' => ($isPatch ? 'sometimes|' : 'required|').'string',
            'fallback_locale' => ($isPatch ? 'sometimes|' : 'required|').'string',
            'supported_locales' => ($isPatch ? 'sometimes|' : 'required|').'array',
            'supported_locales.*' => 'string',
            'overrides' => 'sometimes|array',
            'overrides.*' => 'array',
        ];
        Validator::make($body, $rules)->validate();

        $blob = Environment::defaultLocalization();
        if (isset($body['default_locale'])) {
            $blob['default_locale'] = (string) $body['default_locale'];
        }
        if (isset($body['fallback_locale'])) {
            $blob['fallback_locale'] = (string) $body['fallback_locale'];
        }
        if (isset($body['supported_locales']) && is_array($body['supported_locales'])) {
            $blob['supported_locales'] = array_values(array_map('strval', $body['supported_locales']));
        }
        $blob['overrides'] = [];
        if (isset($body['overrides']) && is_array($body['overrides'])) {
            foreach ($body['overrides'] as $locale => $entries) {
                if (! is_string($locale) || ! is_array($entries)) {
                    continue;
                }
                $clean = [];
                foreach ($entries as $key => $value) {
                    if (! is_string($key)) {
                        continue;
                    }
                    if ($value === null || is_string($value)) {
                        $clean[$key] = $value;
                    }
                }
                $blob['overrides'][$locale] = $clean;
            }
        }

        if (! $isPatch) {
            $this->assertCanonicalKeys($blob);
            $this->assertLocaleConsistency($blob);
        }

        return $blob;
    }

    /**
     * @param  array{overrides:array<string, array<string, ?string>>, supported_locales:list<string>, default_locale:string, fallback_locale:string}  $blob
     */
    private function assertCanonicalKeys(array $blob): void
    {
        $canonicalKeys = CanonicalSchema::keys();
        $errors = [];
        foreach ($blob['overrides'] as $locale => $entries) {
            foreach ($entries as $key => $_value) {
                if (! in_array($key, $canonicalKeys, true)) {
                    $errors["overrides.{$locale}.{$key}"] = [
                        'code' => 'unknown_localization_key',
                        'message' => "Unknown localization key: {$key}.",
                    ];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array{overrides:array<string, array<string, ?string>>, supported_locales:list<string>, default_locale:string, fallback_locale:string}  $blob
     */
    private function assertLocaleConsistency(array $blob): void
    {
        $supported = $blob['supported_locales'];
        $errors = [];

        foreach ($supported as $locale) {
            if (in_array($locale, CanonicalSchema::SHIPPED_LOCALES, true)) {
                continue;
            }
            $overrides = is_array($blob['overrides'][$locale] ?? null) ? $blob['overrides'][$locale] : [];
            if ($overrides === []) {
                $errors["supported_locales.{$locale}"] = [
                    'code' => 'unsupported_locale',
                    'message' => "Locale {$locale} is not bundled and has no overrides supplied.",
                ];
            }
        }

        if (! in_array($blob['default_locale'], $supported, true)) {
            $errors['default_locale'] = [
                'code' => 'unsupported_locale',
                'message' => 'default_locale must be present in supported_locales.',
            ];
        }
        if (! in_array($blob['fallback_locale'], $supported, true)) {
            $errors['fallback_locale'] = [
                'code' => 'unsupported_locale',
                'message' => 'fallback_locale must be present in supported_locales.',
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, array<string, ?string>>  $overrides
     * @return list<string>
     */
    private function collectPlaceholderWarnings(array $overrides): array
    {
        $warnings = [];
        foreach ($overrides as $locale => $entries) {
            foreach ($entries as $key => $value) {
                if (! is_string($value)) {
                    continue;
                }
                $expected = CanonicalSchema::placeholdersIn((string) $key);
                $actual = CanonicalSchema::placeholdersInString($value);
                foreach ($expected as $token) {
                    if (! in_array($token, $actual, true)) {
                        $warnings[] = "299 - \"missing-placeholder {$token} at {$key} ({$locale})\"";
                    }
                }
            }
        }

        return $warnings;
    }

    /**
     * @param  mixed  $stored
     * @return array{default_locale:string, fallback_locale:string, supported_locales:list<string>, overrides:array<string, array<string, string>>}
     */
    private function normalise($stored): array
    {
        $defaults = Environment::defaultLocalization();
        if (! is_array($stored)) {
            return $defaults;
        }
        $merged = $defaults;
        if (is_string($stored['default_locale'] ?? null)) {
            $merged['default_locale'] = $stored['default_locale'];
        }
        if (is_string($stored['fallback_locale'] ?? null)) {
            $merged['fallback_locale'] = $stored['fallback_locale'];
        }
        if (is_array($stored['supported_locales'] ?? null)) {
            $merged['supported_locales'] = array_values(array_map('strval', $stored['supported_locales']));
        }
        $merged['overrides'] = [];
        if (is_array($stored['overrides'] ?? null)) {
            foreach ($stored['overrides'] as $locale => $entries) {
                if (! is_string($locale) || ! is_array($entries)) {
                    continue;
                }
                $clean = [];
                foreach ($entries as $key => $value) {
                    if (is_string($key) && is_string($value)) {
                        $clean[$key] = $value;
                    }
                }
                $merged['overrides'][$locale] = $clean;
            }
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Environment $env): array
    {
        $blob = $this->normalise($env->localization);

        $payloadOverrides = [];
        foreach ($blob['overrides'] as $locale => $entries) {
            $payloadOverrides[$locale] = (object) $entries;
        }

        return [
            'default_locale' => $blob['default_locale'],
            'fallback_locale' => $blob['fallback_locale'],
            'supported_locales' => $blob['supported_locales'],
            'overrides' => empty($payloadOverrides) ? (object) [] : $payloadOverrides,
        ];
    }
}
