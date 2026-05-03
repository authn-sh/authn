<?php

declare(strict_types=1);

namespace App\Services\Keys;

use App\Models\Environment;
use Illuminate\Support\Str;

/**
 * Generates the API-key plaintexts that customers paste into their backends
 * (`sk_test_…` / `sk_live_…`) and embed in browser SDKs (`pk_test_…` /
 * `pk_live_…`).
 *
 * The generator returns the raw secret once; callers hash it before storage
 * (see ApiKey.hashed_secret) and surface the plaintext in the response or
 * stdout exactly once.
 */
final class KeyGenerator
{
    /** Length of the random portion of a secret key (characters from Str::random). */
    private const SECRET_RANDOM_LENGTH = 32;

    public function secretKey(Environment $environment): string
    {
        $segment = $environment->keyEnvironmentSegment(); // 'test' | 'live'
        $random = Str::lower(Str::random(self::SECRET_RANDOM_LENGTH));

        return "sk_{$segment}_{$random}";
    }

    /**
     * Per PLAN §10 the publishable key encodes the FAPI URL: the base64 of
     * `{frontend_api_host}$`. Browsers / SDK loaders can decode it client-side
     * to discover the host without a separate config call.
     */
    public function publishableKey(Environment $environment): string
    {
        $segment = $environment->keyEnvironmentSegment();
        $payload = base64_encode($environment->frontend_api_host.'$');

        return "pk_{$segment}_{$payload}";
    }

    /**
     * SHA-256 of the plaintext key. Stored on `api_keys.hashed_secret`.
     */
    public function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
