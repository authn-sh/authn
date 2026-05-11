<?php

declare(strict_types=1);

namespace App\Auth\Passkey;

use App\Auth\Passkey\Exceptions\PasskeyAssertionInvalid;
use App\Auth\Passkey\Exceptions\PasskeyAttestationInvalid;
use App\Auth\Passkey\Exceptions\PasskeyException;
use App\Auth\Passkey\Exceptions\PasskeyOriginMismatch;
use App\Auth\Passkey\Exceptions\PasskeyReplayed;
use App\Auth\Passkey\Exceptions\PasskeyUserHandleMismatch;
use App\Models\Challenge;
use App\Models\Environment;
use App\Models\Passkey;
use App\Models\User;
use App\Support\Base64Url;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Thin wrapper around `web-auth/webauthn-lib` for the four WebAuthn
 * ceremonies the FAPI / Account Portal need:
 *   - build registration options (begin) + verify attestation (complete)
 *   - build authentication options (begin) + verify assertion (complete)
 *
 * Per-environment RP-ID and origin allowlist come from `RpConfigResolver`.
 * The webauthn challenge bytes are persisted on `Challenge.nonce` as
 * base64url and retrieved on the matching complete-* call.
 *
 * @phpstan-type PkccoArray array<string, mixed>
 * @phpstan-type PkcroArray array<string, mixed>
 */
final class PasskeyService
{
    /** Supported COSE algorithm identifiers (ES256 + RS256). */
    private const COSE_ES256 = -7;

    private const COSE_RS256 = -257;

    private SerializerInterface $serializer;

    public function __construct(private readonly RpConfigResolver $rp)
    {
        $this->serializer = (new WebauthnSerializerFactory(
            new AttestationStatementSupportManager([new NoneAttestationStatementSupport]),
        ))->create();
    }

    /**
     * Builds the `creationOptions` blob the SDK hands to
     * `navigator.credentials.create({ publicKey })`. The challenge bytes are
     * written to `Challenge.nonce` (base64url) so the matching
     * `verifyAttestation` call can replay them.
     *
     * @param  list<string>  $excludeCredentialIds  raw bytes of the user's already-enrolled credential ids
     * @return PkccoArray
     */
    public function buildRegistrationOptions(
        Environment $environment,
        User $user,
        Challenge $challenge,
        array $excludeCredentialIds = [],
    ): array {
        $challengeBytes = random_bytes(32);
        $challenge->forceFill(['nonce' => Base64Url::encode($challengeBytes)])->save();

        $userEntity = PublicKeyCredentialUserEntity::create(
            $this->userHandleDisplay($user),
            $user->id,
            $this->userHandleDisplayName($user),
        );

        $exclude = [];
        foreach ($excludeCredentialIds as $id) {
            $exclude[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                $id,
            );
        }

        $options = PublicKeyCredentialCreationOptions::create(
            rp: $this->rp->rpEntity($environment),
            user: $userEntity,
            challenge: $challengeBytes,
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create('public-key', self::COSE_ES256),
                PublicKeyCredentialParameters::create('public-key', self::COSE_RS256),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $exclude,
            timeout: 60_000,
        );

        $json = $this->serializer->serialize($options, 'json', [
            'jsonEncodeOptions' => JSON_UNESCAPED_SLASHES,
        ]);

        /** @var PkccoArray $decoded */
        $decoded = json_decode($json, true);

        return $decoded;
    }

    /**
     * Verifies an attestation response against the matching challenge,
     * creates a verified `Passkey` row, and flips the Challenge to
     * `verified`. Throws `PasskeyAttestationInvalid`,
     * `PasskeyOriginMismatch`, or `PasskeyReplayed` on validation failure.
     *
     * @param  array<string, mixed>  $attestation  WebAuthn `AuthenticatorAttestationResponseJSON`
     */
    public function verifyAttestation(
        Environment $environment,
        User $user,
        Challenge $challenge,
        array $attestation,
        ?string $nickname,
    ): Passkey {
        $options = $this->reconstructCreationOptions($environment, $user, $challenge);
        $credential = $this->deserializePublicKeyCredential($attestation);

        $response = $credential->response;
        if (! $response instanceof AuthenticatorAttestationResponse) {
            throw new PasskeyAttestationInvalid('Expected an attestation response.');
        }

        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins($this->rp->allowedOrigins($environment));
        $validator = AuthenticatorAttestationResponseValidator::create($factory->creationCeremony());

        try {
            $record = $validator->check($response, $options, $this->rp->rpId($environment));
        } catch (AuthenticatorResponseVerificationException $e) {
            throw $this->translateAttestationException($e);
        } catch (Throwable $e) {
            throw new PasskeyAttestationInvalid($e->getMessage(), previous: $e);
        }

        $credentialId = $record->publicKeyCredentialId;
        $credentialIdHash = hash('sha256', $credentialId, true);

        if (Passkey::query()
            ->where('credential_id_hash', $credentialIdHash)
            ->withTrashed()
            ->exists()) {
            throw new PasskeyReplayed('Credential is already enrolled.');
        }

        $passkey = Passkey::create([
            'user_id' => $user->id,
            'credential_id' => $credentialId,
            'credential_id_hash' => $credentialIdHash,
            'public_key' => $record->credentialPublicKey,
            'sign_count' => $record->counter,
            'transports' => $this->extractTransports($attestation),
            'aaguid' => $this->extractAaguid($record),
            'nickname' => $nickname !== '' && $nickname !== null ? $nickname : null,
            'verified_at' => now(),
        ]);

        $challenge->forceFill(['status' => Challenge::STATUS_VERIFIED])->save();

        return $passkey;
    }

    /**
     * Builds the `requestOptions` blob the SDK hands to
     * `navigator.credentials.get({ publicKey })`. Caller passes the User
     * the assertion is bound to (matched by username/email lookup before
     * the ceremony starts); the `allowCredentials` list narrows the
     * authenticator picker to that user's verified passkeys.
     *
     * @return PkcroArray
     */
    public function buildAuthenticationOptions(
        Environment $environment,
        Challenge $challenge,
        ?User $user = null,
    ): array {
        $challengeBytes = random_bytes(32);
        $challenge->forceFill(['nonce' => Base64Url::encode($challengeBytes)])->save();

        $allowed = [];
        if ($user !== null) {
            $passkeys = $user->passkeys()->whereNotNull('verified_at')->get();
            foreach ($passkeys as $passkey) {
                $allowed[] = PublicKeyCredentialDescriptor::create(
                    PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    $passkey->credential_id,
                    $passkey->transports ?? [],
                );
            }
        }

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: $challengeBytes,
            rpId: $this->rp->rpId($environment),
            allowCredentials: $allowed,
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED,
            timeout: 60_000,
        );

        $json = $this->serializer->serialize($options, 'json', [
            'jsonEncodeOptions' => JSON_UNESCAPED_SLASHES,
        ]);
        /** @var PkcroArray $decoded */
        $decoded = json_decode($json, true);

        return $decoded;
    }

    /**
     * Verifies an assertion response, bumps `sign_count`, updates
     * `last_used_at`, and returns the matched Passkey. Throws
     * `PasskeyAssertionInvalid`, `PasskeyOriginMismatch`, `PasskeyReplayed`,
     * or `PasskeyUserHandleMismatch` on failure.
     *
     * @param  array<string, mixed>  $assertion  WebAuthn `AuthenticatorAssertionResponseJSON`
     */
    public function verifyAssertion(
        Environment $environment,
        Challenge $challenge,
        User $resolvedUser,
        array $assertion,
    ): Passkey {
        $options = $this->reconstructRequestOptions($environment, $challenge);
        $credential = $this->deserializePublicKeyCredential($assertion);

        $response = $credential->response;
        if (! $response instanceof AuthenticatorAssertionResponse) {
            throw new PasskeyAssertionInvalid('Expected an assertion response.');
        }

        $credentialId = $credential->rawId;
        $hash = hash('sha256', $credentialId, true);
        $passkey = Passkey::query()
            ->where('user_id', $resolvedUser->id)
            ->where('credential_id_hash', $hash)
            ->whereNotNull('verified_at')
            ->first();
        if ($passkey === null) {
            throw new PasskeyAssertionInvalid('No matching credential for this user.');
        }

        if ($response->userHandle !== null && $response->userHandle !== '' && $response->userHandle !== $resolvedUser->id) {
            throw new PasskeyUserHandleMismatch('userHandle does not match the resolved user.');
        }

        $record = $this->credentialRecordFor($passkey);

        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins($this->rp->allowedOrigins($environment));
        $validator = AuthenticatorAssertionResponseValidator::create($factory->requestCeremony());

        try {
            $updated = $validator->check(
                $record,
                $response,
                $options,
                $this->rp->rpId($environment),
                $resolvedUser->id,
            );
        } catch (AuthenticatorResponseVerificationException $e) {
            throw $this->translateAssertionException($e);
        } catch (Throwable $e) {
            throw new PasskeyAssertionInvalid($e->getMessage(), previous: $e);
        }

        $passkey->forceFill([
            'sign_count' => $updated->counter,
            'last_used_at' => now(),
        ])->save();
        $challenge->forceFill(['status' => Challenge::STATUS_VERIFIED])->save();

        return $passkey;
    }

    /**
     * Reconstruct the PKCCO used at begin-time so we can re-validate the
     * client's attestation against the same RP / challenge / user. We
     * don't persist the full options blob (the only mutable bit is the
     * challenge nonce); RP-ID + user identity are recoverable from the
     * env + the Challenge's resolved User.
     */
    private function reconstructCreationOptions(
        Environment $environment,
        User $user,
        Challenge $challenge,
    ): PublicKeyCredentialCreationOptions {
        $challengeBytes = Base64Url::decode((string) $challenge->nonce);
        $userEntity = PublicKeyCredentialUserEntity::create(
            $this->userHandleDisplay($user),
            $user->id,
            $this->userHandleDisplayName($user),
        );

        return PublicKeyCredentialCreationOptions::create(
            rp: $this->rp->rpEntity($environment),
            user: $userEntity,
            challenge: $challengeBytes,
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create('public-key', self::COSE_ES256),
                PublicKeyCredentialParameters::create('public-key', self::COSE_RS256),
            ],
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
        );
    }

    private function reconstructRequestOptions(
        Environment $environment,
        Challenge $challenge,
    ): PublicKeyCredentialRequestOptions {
        return PublicKeyCredentialRequestOptions::create(
            challenge: Base64Url::decode((string) $challenge->nonce),
            rpId: $this->rp->rpId($environment),
        );
    }

    /**
     * @param  array<string, mixed>  $clientResponse
     */
    private function deserializePublicKeyCredential(array $clientResponse): PublicKeyCredential
    {
        try {
            $json = json_encode($clientResponse, JSON_THROW_ON_ERROR);

            return $this->serializer->deserialize($json, PublicKeyCredential::class, 'json');
        } catch (Throwable $e) {
            throw new PasskeyAttestationInvalid('Malformed credential payload: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @param  array<string, mixed>  $attestation
     * @return list<string>
     */
    private function extractTransports(array $attestation): array
    {
        $response = is_array($attestation['response'] ?? null) ? $attestation['response'] : [];
        $transports = is_array($response['transports'] ?? null) ? $response['transports'] : [];

        $out = [];
        foreach ($transports as $t) {
            if (is_string($t) && in_array($t, Passkey::TRANSPORTS, true)) {
                $out[] = $t;
            }
        }

        return array_values(array_unique($out));
    }

    private function extractAaguid(CredentialRecord $record): ?string
    {
        $aaguid = $record->aaguid;
        if ($aaguid === null) {
            return null;
        }
        $str = (string) $aaguid;

        return $str === '' || $str === '00000000-0000-0000-0000-000000000000' ? null : $str;
    }

    private function userHandleDisplay(User $user): string
    {
        if ($user->username !== null && $user->username !== '') {
            return (string) $user->username;
        }
        $primaryEmail = $user->primaryEmailAddress()->withoutGlobalScopes()->first();
        if ($primaryEmail !== null && $primaryEmail->email_address !== '') {
            return (string) $primaryEmail->email_address;
        }

        return $user->id;
    }

    private function userHandleDisplayName(User $user): string
    {
        $name = trim((string) $user->first_name.' '.(string) $user->last_name);

        return $name !== '' ? $name : $this->userHandleDisplay($user);
    }

    /**
     * Rebuild a CredentialRecord from our Passkey row's columns. The
     * webauthn-lib assertion validator needs the same shape it produced
     * during registration; we reconstruct it from persisted columns
     * (credential_id / public_key / sign_count / transports / aaguid)
     * rather than persisting the whole CredentialRecord blob.
     */
    private function credentialRecordFor(Passkey $passkey): CredentialRecord
    {
        try {
            $aaguid = $passkey->aaguid !== null && $passkey->aaguid !== ''
                ? Uuid::fromString($passkey->aaguid)
                : Uuid::fromString('00000000-0000-0000-0000-000000000000');
        } catch (Throwable $e) {
            throw new PasskeyAssertionInvalid('Stored aaguid is malformed.', previous: $e);
        }

        return new CredentialRecord(
            publicKeyCredentialId: (string) $passkey->credential_id,
            type: 'public-key',
            transports: is_array($passkey->transports) ? array_values(array_map('strval', $passkey->transports)) : [],
            attestationType: 'none',
            trustPath: new EmptyTrustPath,
            aaguid: $aaguid,
            credentialPublicKey: (string) $passkey->public_key,
            userHandle: (string) $passkey->user_id,
            counter: (int) $passkey->sign_count,
        );
    }

    private function translateAttestationException(AuthenticatorResponseVerificationException $e): PasskeyException
    {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'origin')) {
            return new PasskeyOriginMismatch($e->getMessage(), previous: $e);
        }

        return new PasskeyAttestationInvalid($e->getMessage(), previous: $e);
    }

    private function translateAssertionException(AuthenticatorResponseVerificationException $e): PasskeyException
    {
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'counter') || str_contains($msg, 'sign count')) {
            return new PasskeyReplayed($e->getMessage(), previous: $e);
        }
        if (str_contains($msg, 'origin')) {
            return new PasskeyOriginMismatch($e->getMessage(), previous: $e);
        }
        if (str_contains($msg, 'user handle')) {
            return new PasskeyUserHandleMismatch($e->getMessage(), previous: $e);
        }

        return new PasskeyAssertionInvalid($e->getMessage(), previous: $e);
    }
}
