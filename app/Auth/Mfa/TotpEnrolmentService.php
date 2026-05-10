<?php

declare(strict_types=1);

namespace App\Auth\Mfa;

use App\Models\EmailAddress;
use App\Models\Environment;
use App\Models\TotpSecret;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

/**
 * RFC 6238 TOTP enrolment + verification. Uses pragmarx/google2fa for the
 * step math and bacon/bacon-qr-code for the inline-SVG QR rendering. The
 * issuer / label conventions follow the openapi `TotpSecret` example.
 *
 * Replay protection: every successful verify stamps `last_used_step` on
 * the secret row; subsequent attempts at the same or earlier step are
 * rejected so a captured 60-second-window code can't be replayed.
 */
final readonly class TotpEnrolmentService
{
    public const VERIFY_WINDOW_STEPS = 1;

    public const SECRET_LENGTH_BITS = 160;

    public function __construct(
        private Google2FA $google2fa = new Google2FA,
    ) {
        $this->google2fa->setWindow(self::VERIFY_WINDOW_STEPS);
    }

    /**
     * Mint (or overwrite the unverified) `TotpSecret` for `$user`. Caller
     * is responsible for the per-env strategy gate (see
     * `MultiFactorSettings::strategyEnabled('totp')`) and for refusing the
     * call when a verified row already exists.
     */
    public function start(User $user, Environment $environment): TotpSecret
    {
        TotpSecret::query()
            ->where('user_id', $user->id)
            ->whereNull('verified_at')
            ->delete();

        $secret = $this->google2fa->generateSecretKey(32);

        return TotpSecret::create([
            'environment_id' => $environment->id,
            'user_id' => $user->id,
            'secret' => $secret,
        ]);
    }

    /**
     * Verify the supplied 6-digit `$code` against `$secret`. Honours the
     * `±1 step` window and rejects replays via `last_used_step`. On
     * success: stamps `verified_at`, flips the `User` MFA flags, and
     * persists `last_used_step` so the same code can't be reused.
     */
    public function verify(TotpSecret $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $matchedStep = $this->google2fa->verifyKeyNewer(
            $secret->secret,
            $code,
            $secret->last_used_step,
            self::VERIFY_WINDOW_STEPS,
        );
        if ($matchedStep === false) {
            return false;
        }

        $secret->last_used_step = (int) $matchedStep;
        if (! $secret->isVerified()) {
            $secret->verified_at = now();
        }
        $secret->save();

        return true;
    }

    /**
     * Drop the user's TotpSecret row. Caller flips the `User` MFA flags.
     */
    public function remove(User $user): bool
    {
        $deleted = TotpSecret::query()
            ->where('user_id', $user->id)
            ->delete();

        return $deleted > 0;
    }

    /**
     * Build the otpauth:// URI suitable for direct hand-off to an
     * authenticator app. Issuer is `<env_slug>.<app_host>`; label is
     * `<issuer>:<email>` per the openapi `TotpSecret` example.
     */
    public function otpauthUri(TotpSecret $secret, Environment $environment, ?EmailAddress $primaryEmail): string
    {
        $issuer = $environment->slug.'.'.((string) config('authn.app_host', 'authn.local'));
        $accountName = $primaryEmail?->email_address ?? $secret->user_id;

        return $this->google2fa->getQRCodeUrl($issuer, $accountName, $secret->secret);
    }

    /**
     * Render `$otpauthUri` as an inline-SVG `data:image/svg+xml;base64,…`
     * QR. Pure-PHP, no GD/imagick requirement.
     */
    public function qrCodeDataUrl(string $otpauthUri): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(192, 1),
            new SvgImageBackEnd,
        );
        $writer = new Writer($renderer);
        $svg = $writer->writeString($otpauthUri);
        if ($svg === '') {
            throw new RuntimeException('QR rendering produced an empty payload.');
        }

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
