<?php

declare(strict_types=1);

namespace App\Auth\Jwt;

use RuntimeException;

/**
 * Thrown when `Session::getToken($name)` resolves no `JwtTemplate` row
 * by `(environment_id, name)`. Surfaces as a 404
 * `template_not_found` error on FAPI / BAPI session-token endpoints.
 */
final class JwtTemplateNotFound extends RuntimeException
{
    public function __construct(public readonly string $templateName)
    {
        parent::__construct("JwtTemplate `{$templateName}` not found in this environment.");
    }
}
