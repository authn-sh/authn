<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fapi;

use App\Localization\CanonicalSchema;
use App\Localization\Localizer;
use App\Models\Environment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public, unauthenticated, CORS-open catalog endpoint. Serves the merged
 * canonical-default ⊕ operator-overrides catalog for one locale with
 * heavy CDN-friendly cache headers + an ETag that mirrors
 * `Environment.localization.override_etag`.
 */
final class LocalizationController
{
    public function __construct(private readonly Localizer $localizer) {}

    public function show(Request $request): JsonResponse|Response
    {
        $locale = (string) $request->route('locale');
        $env = app(Environment::class);
        $localization = is_array($env->localization) ? $env->localization : [];
        $supported = is_array($localization['supported_locales'] ?? null)
            ? array_map('strval', $localization['supported_locales'])
            : ['en-US'];

        if (! in_array($locale, $supported, true)
            && ! in_array($locale, CanonicalSchema::SHIPPED_LOCALES, true)) {
            return response()->json([
                'errors' => [[
                    'code' => 'unsupported_locale',
                    'message' => "Locale {$locale} is not enabled for this environment.",
                ]],
            ], 404);
        }

        $etag = '"'.$env->localization_override_etag.'"';
        $ifNoneMatch = (string) $request->headers->get('If-None-Match', '');
        if ($ifNoneMatch !== '' && $this->etagMatches($ifNoneMatch, $etag)) {
            return response('', 304)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=3600');
        }

        $catalog = $this->localizer->catalog($env, $locale);
        ksort($catalog);

        return response()->json([
            'locale' => $locale,
            'catalog' => (object) $catalog,
        ])
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=3600');
    }

    private function etagMatches(string $header, string $etag): bool
    {
        foreach (explode(',', $header) as $candidate) {
            if (trim($candidate) === $etag) {
                return true;
            }
        }

        return false;
    }
}
