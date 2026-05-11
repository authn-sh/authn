<?php

declare(strict_types=1);

use App\Auth\Passkey\Exceptions\PasskeyAssertionInvalid;
use App\Auth\Passkey\Exceptions\PasskeyAttestationInvalid;
use App\Auth\Passkey\Exceptions\PasskeyNoCredentials;
use App\Auth\Passkey\Exceptions\PasskeyOriginMismatch;
use App\Auth\Passkey\Exceptions\PasskeyReplayed;
use App\Auth\Passkey\Exceptions\PasskeyUserHandleMismatch;

it('every passkey exception exposes its stable error code', function (): void {
    expect((new PasskeyOriginMismatch('x'))->code())->toBe('passkey_origin_mismatch');
    expect((new PasskeyReplayed('x'))->code())->toBe('passkey_replayed');
    expect((new PasskeyAttestationInvalid('x'))->code())->toBe('passkey_attestation_invalid');
    expect((new PasskeyAssertionInvalid('x'))->code())->toBe('passkey_assertion_invalid');
    expect((new PasskeyUserHandleMismatch('x'))->code())->toBe('passkey_user_handle_mismatch');
    expect((new PasskeyNoCredentials('x'))->code())->toBe('passkey_no_credentials');
});
