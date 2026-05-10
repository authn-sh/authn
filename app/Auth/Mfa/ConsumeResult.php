<?php

declare(strict_types=1);

namespace App\Auth\Mfa;

/**
 * Outcome of `BackupCodesService::tryConsume`. Distinct from a plain
 * boolean so AU-12's second-factor flow can surface
 * `form_code_already_used` separately from `form_code_incorrect`.
 */
enum ConsumeResult
{
    case Ok;

    case AlreadyUsed;

    case NotFound;
}
