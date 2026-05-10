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

    public const TRANSFER_NOT_SUPPORTED = 'transfer_not_supported';

    public const MFA_NOT_ENABLED = 'mfa_not_enabled';

    public const MFA_ALREADY_VERIFIED = 'mfa_already_verified';

    public const TOTP_NOT_FOUND = 'totp_not_found';

    public const STRATEGY_NOT_SUPPORTED = 'strategy_not_supported';

    public const PREPARE_NOT_REQUIRED = 'prepare_not_required';

    public const NEEDS_NEW_PASSWORD = 'needs_new_password';

    public const NOT_IN_NEEDS_NEW_PASSWORD_STATE = 'not_in_needs_new_password_state';

    public const SIGN_IN_NOT_FOUND = 'sign_in_not_found';

    public const SIGN_IN_ABANDONED = 'sign_in_abandoned';

    public const SIGN_IN_ALREADY_COMPLETE = 'sign_in_already_complete';

    // Sign-up

    public const FORM_PARAM_UNKNOWN = 'form_param_unknown';

    public const FORM_IDENTIFIER_NOT_ALLOWED = 'form_identifier_not_allowed';

    public const FORM_IDENTIFIER_DISPOSABLE = 'form_identifier_disposable';

    public const TEST_IDENTIFIER_FORBIDDEN = 'test_identifier_forbidden';

    public const LEGAL_ACCEPTED_REQUIRED = 'legal_accepted_required';

    public const SIGN_UP_NOT_FOUND = 'sign_up_not_found';

    public const SIGN_UP_ABANDONED = 'sign_up_abandoned';

    public const SIGN_UP_ALREADY_COMPLETE = 'sign_up_already_complete';

    public const NO_VERIFICATION_IN_PROGRESS = 'no_verification_in_progress';

    public const SIGN_UP_NOT_READY = 'sign_up_not_ready';

    // /v1/me

    public const ACTOR_SESSION_FORBIDDEN = 'actor_session_forbidden';

    public const DELETE_SELF_DISABLED = 'delete_self_disabled';

    public const EMAIL_NOT_FOUND = 'email_not_found';

    public const EMAIL_NOT_VERIFIED = 'email_not_verified';

    public const PRIMARY_EMAIL_NOT_REMOVABLE = 'primary_email_not_removable';
}
