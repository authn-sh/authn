<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\JwtTemplate;

/**
 * BAPI shape for `JwtTemplate`. Mirrors OA-1's `JwtTemplate` schema —
 * `additionalProperties: false`, so we emit exactly the spec-narrow set
 * and never `custom_signing_key` (writeOnly per spec, encrypted at rest
 * per the model `$hidden` array).
 */
final class JwtTemplateResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(JwtTemplate $template): array
    {
        return [
            'id' => $template->id,
            'object' => 'jwt_template',
            'name' => $template->name,
            'claims' => is_array($template->claims) ? $template->claims : [],
            'lifetime' => $template->lifetime,
            'allowed_clock_skew' => $template->allowed_clock_skew,
            'signing_algorithm' => $template->signing_algorithm,
            'created_at' => $template->created_at?->getTimestampMs(),
            'updated_at' => $template->updated_at?->getTimestampMs(),
        ];
    }
}
