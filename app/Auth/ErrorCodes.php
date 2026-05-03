<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Centralized vocabulary for FAPI sign-in / sign-up error codes.
 *
 * Codes are stable strings that SDKs match on; never parse the
 * `message` field. Keep this list in lockstep with the openapi spec
 * so generated clients have an exhaustive enum.
 */
final class ErrorCodes
{
    // Form / parameter errors
    public const FORM_PARAM_NIL = 'form_param_nil';

    public const FORM_PARAM_FORMAT_INVALID = 'form_param_format_invalid';

    public const FORM_IDENTIFIER_NOT_FOUND = 'form_identifier_not_found';

    public const FORM_IDENTIFIER_EXISTS = 'form_identifier_exists';

    // Password
    public const FORM_PASSWORD_INCORRECT = 'form_password_incorrect';

    public const FORM_PASSWORD_PWNED = 'form_password_pwned';

    public const FORM_PASSWORD_VALIDATION_FAILED = 'form_password_validation_failed';

    // Verification
    public const FORM_CODE_INCORRECT = 'form_code_incorrect';

    public const VERIFICATION_EXPIRED = 'verification_expired';

    public const VERIFICATION_FAILED = 'verification_failed';

    public const VERIFICATION_ALREADY_VERIFIED = 'verification_already_verified';

    // Tickets / one-shot tokens
    public const TICKET_INVALID = 'ticket_invalid';

    public const TICKET_EXPIRED = 'ticket_expired';

    // Session / user state
    public const IDENTIFIER_ALREADY_SIGNED_IN = 'identifier_already_signed_in';

    public const USER_LOCKED = 'user_locked';

    public const USER_BANNED = 'user_banned';

    public const SESSION_REVOKED = 'session_revoked';

    // Captcha (full enforcement in AU-18)
    public const CAPTCHA_INVALID = 'captcha_invalid';

    // v0.1 stop signs for features that land later
    public const TRANSFER_NOT_SUPPORTED_IN_V0_1 = 'transfer_not_supported_in_v0_1';

    public const MFA_NOT_ENABLED_IN_V0_1 = 'mfa_not_enabled_in_v0_1';

    public const STRATEGY_NOT_SUPPORTED_IN_V0_1 = 'strategy_not_supported_in_v0_1';

    public const PREPARE_NOT_REQUIRED = 'prepare_not_required';

    public const NEEDS_NEW_PASSWORD = 'needs_new_password';

    public const NOT_IN_NEEDS_NEW_PASSWORD_STATE = 'not_in_needs_new_password_state';

    public const SIGN_IN_NOT_FOUND = 'sign_in_not_found';

    public const SIGN_IN_ABANDONED = 'sign_in_abandoned';

    public const SIGN_IN_ALREADY_COMPLETE = 'sign_in_already_complete';
}
