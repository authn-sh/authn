# Changelog

## [0.7.1] — 2026-05-15

Patch release: contract gaps + fail-open fixes flagged in the cross-repo critical review.

### Added

- **FAPI `DELETE /v1/me/password` (AU-17)** — clears the user's password hash after verifying `current_password`. Returns 422 when the environment requires a password (`user_settings.attributes.password.required = true`) or when the user has no password set. Rejects impersonation sessions.
- **FAPI `POST` + `DELETE /v1/me/profile-image` (AU-17)** — multipart upload (5 MiB cap, png/jpeg/webp via magic-byte sniff) + idempotent delete; re-uses BAPI `UsersController`'s storage path layout. Rejects impersonation sessions.
- **FAPI `/v1/organizations/{org}/domains` CRUD (AU-18)** — full `index / store / show / update / destroy` surface on a new `Fapi\OrganizationDomainController`. Gated by `org:sys_domains:read` (GET) and `org:sys_domains:manage` (POST/PATCH/DELETE). Mirrors the BAPI logic and emits `ClientResponseEnvelope` on writes.
- **SCIM 2.0 `/scim/v2/Users/{id}` PUT + PATCH (AU-16)** — RFC 7644 §3.5.1 full-replace + §3.5.2 PATCH. Supports `op=replace` against `active` (the canonical IdP deprovisioning op), `displayName`, `userName`, `externalId`, `locale`, `name.{givenName,familyName}`, plus no-path replace with a full resource. Webhook `scimUser.updated` + `audit:auth.enterprise_sso.scim_updated` log entry on every successful write. Groups POST/PUT/PATCH/DELETE retargeted to v0.8 (see #293).

### Removed

- **`Environment.sms` from `GET /v1/environment` (AU-19)** — `authn-sh/openapi#109` dropped the `sms` block from the FAPI spec (driver / credentials are system-only, never read by the SDK at boot). `EnvironmentResource` was still emitting it, producing 10 contract violations on every PR after the openapi merge. Drop the bootstrap projection and the corresponding helper.

## [0.7.0] — 2026-05-12

JWT templates + OAuth provider mode (authn.sh as IdP) + v0.5/v0.6 deferral cleanup.

### Added

- **JWT templates (AU-1, AU-3, AU-4)** — `JwtTemplate` model (`jtmpl_` prefix, env-scoped, JSON `claims` with Liquid-style placeholders, configurable `lifetime` / `allowed_clock_skew` / `signing_algorithm`, optional encrypted `custom_signing_key`). `JwtTemplateRenderer` resolves placeholders against User / Session / Organization snapshots; refuses `{{user.private_metadata.*}}` per PLAN §4.6. `Session::getToken({template})` wraps for SDK consumers. BAPI `/v1/jwt-templates` CRUD with delete-in-use rejection.
- **OAuth provider mode (AU-2, AU-5–AU-8, AU-9, AU-10, AU-11)** — authn.sh as an IdP:
  - `OauthApplication` (`oac_` prefix) + `AuthorizationGrant` (`authgrant_` prefix) models.
  - BAPI `/v1/oauth-applications` CRUD with `POST /{id}/rotate-secret` (one-shot plaintext).
  - FAPI `GET /oauth/authorize` (RFC 6749 §4.1 + OIDC §3.1.2.1) with PKCE for public clients; redirects to `/sign-in` (unauth), `/oauth/consent/{request_id}` (first-time), or `redirect_uri?code=…&state=…` (silent reuse).
  - FAPI `POST /oauth/token` (authorization_code + refresh_token grants, Basic / form / PKCE auth) + `POST /oauth/token_info` (RFC 7662 introspection). Access + id_token are RS256 JWTs signed against env's SigningKey, validate via `/.well-known/jwks.json`.
  - FAPI `GET /oauth/userinfo` (bearer-auth, scope-filtered claims: openid → sub; profile → name/given_name/family_name/preferred_username/picture; email → email/email_verified).
  - `GET /.well-known/openid-configuration` extended with the IdP-mode endpoints + grant types + PKCE methods.
  - Account Portal consent screen at `/oauth/consent/{request_id}` with operator brand + scope labels + accept/deny.
  - Account Portal `<UserProfile />` Authorized Apps panel + FAPI `/v1/me/authorized-apps` (list + revoke).
  - Dashboard Configure → JWT Templates + OAuth Applications subsections.
- **BAPI SCIM admin (AU-12, v0.6 carryover)** — `/v1/organizations/{org_id}/scim/{tokens,attribute-mappings,endpoint}` mirroring v0.6's per-org FAPI surface, bearer-secret authenticated. Closes the SP-2 v0.6 scope cut.
- **BAPI `/v1/enterprise-accounts`** — admin list / get / delete for `EnterpriseAccount` rows; fills the v0.6 implementation gap that sdk-php SP-1 flagged.
- **Dashboard Customization editor polish (AU-13, v0.5/v0.6 carryover)** — autocomplete + parse validation + unknown-key warnings + diff view on `appearance.elements`; placeholder validation warnings on `localization.overrides`; iframe preview backed by a 5-min-TTL signed-URL endpoint + `appearance_preview_drafts` table. Closes authn-sh/authn#187 (Monaco editor swap deferred to v0.8).
- **Passkey Dusk smoke suite (AU-14, v0.5 carryover)** — browser-driven WebAuthn enrollment + sign-in tests via Chrome's virtual authenticator (CDP `Authentication.enable`). Complements v0.5's fixture-only Pest coverage.
- **Audit log + webhook events (AU-15)** — `jwtTemplate.{created,updated,deleted}` (signing_key stripped), `oauthApplication.{created,updated,deleted}` (client_secret stripped, with `client_secret_rotated: true` flag on rotate), `authorizationGrant.{granted,revoked}` (per-row cascade emit on application delete). Audit-log entries `audit:auth.idp.{token_issued,token_introspected,app_revoked,app_secret_rotated}`.

### Changed

- `Verification::STRATEGIES` extended with `authorization_code` (transitional code grant tracker).
- `SessionTokenIssuer` honours `{template}` argument, validating against `JwtTemplate` row + rendering claims.

## [0.6.0] — 2026-05-12

### Added

- **Enterprise SSO (SAML + OIDC)** — unified `EnterpriseConnection` model (`entcon_`) carries both protocols. `EnterpriseAccount` (`entacc_`) links a User to a connection. BAPI `/v1/enterprise-connections` (instance-wide CRUD + dry-run probe) + FAPI `/v1/organizations/{org_id}/enterprise-connections` (per-org, gated on `org:sys_sso:manage`).
- **SAML engine** — `litesaml/lightsaml` integration. `SamlConnectionService` emits SP metadata, builds AuthnRequests, verifies SAMLResponses (signature against `saml_idp_certificate`, audience, `NotOnOrAfter` with 60s clock-skew tolerance).
- **OIDC engine** — `OidcConnectionService` handles discovery (5-min cache), PKCE-S256 authorize URL minting, token exchange, id_token JWS verification against IdP JWKS with `iss` / `aud` / `nonce` / `exp` / `iat` validation.
- **Enterprise sign-in flow** — `enterprise_sso` + `saml` first-factor strategies on `StrategyResolver`. Callback endpoints `GET /v1/enterprise-sso-callback` (OIDC) + `POST /v1/saml/{connection_id}/acs` (SAML ACS). Auto-joins the org via `OrganizationMembership` when the connection is org-scoped + has a `default_role`. Honours `OrganizationDomain.enrollment_mode` (`automatic_invitation` joins the matching org; `automatic_suggestion` surfaces it for later; `manual_invitation` is opt-in).
- **Domain-routed sign-in** — `SignIn.supported_strategies` narrows to `["enterprise_sso"]` when the identifier domain matches an enabled `EnterpriseConnection`; `SignIn.enterprise_connection_id` carries the matched id.
- **SCIM 2.0 server (RFC 7644)** — `/scim/v2/Users` (GET filter/pagination/projection + POST + GET-by-id + DELETE) and `/scim/v2/Groups` (read-only role-aggregated). Bearer-authenticated via `ScimToken` (one-time plaintext at issue).
- **Per-org SCIM management** — `/v1/organizations/{org_id}/scim/tokens` (list / issue / revoke), `/scim/attribute-mappings` (read / replace), `/scim/endpoint` (URL surface for IdP-side handoff). Gated by `org:sys_provisioning:manage`.
- **Transferable sign-up <-> sign-in handoff** — carryover from v0.5. `SignUp` for an existing identifier returns `status: "transferable"` with `target_flow: "sign_in"`; `SignIn` for an unknown identifier returns `status: "transferable"` with `target_flow: "sign_up"` (when `Environment.signup_mode` is `public`/`restricted` and there's no enabled OAuth provider). New snapshot booleans `transferable_to_signin` / `transferable_to_signup`.
- **Compact JWT claims** — session tokens emit `entcon` (`EnterpriseConnection.id`) + `entacc` (`EnterpriseAccount.id`) when the parent SignIn was verified via `enterprise_sso` / `saml`. Absent for every other strategy.
- **Dashboard Configure → Enterprise SSO** — instance-wide connection list + add-connection form (SAML + OIDC fields). Per-org connections shown read-only.
- **Dashboard Customization editor polish** — autocomplete + parse validation + unknown-key warnings + diff view on `appearance.elements`; placeholder validation warnings + cell-level diff summary on `localization.overrides`; "Coming soon" chips for planned-but-unshipped locales. (Monaco editor + signed-URL live-preview iframe deferred to v0.7 per authn-sh/authn#187.)
- **InstanceSetting toggles** — `authentication_strategies.enterprise_sso.enabled` (strict-semantic — gates new enrollments, never breaks existing accounts) + `multi_factor.enterprise_sso_counts_as_mfa` (when on and the IdP advertises MFA via OIDC `amr` or SAML `<AuthnContextClassRef>`, the session is stamped second-factor-satisfied).
- **Audit log + webhook events** — `enterpriseConnection.{created,updated,deleted}`, `enterpriseAccount.connected`, `scimToken.{issued,revoked}`, `scimUser.{provisioned,deprovisioned}`. Audit-log entries `auth.enterprise_sso.signin_succeeded` / `signin_failed` / `scim_provisioned` / `scim_deprovisioned`.

### Changed

- **SignUp duplicate-identifier handling** — shifted from `422 form_identifier_exists` to `200 status: "transferable" target_flow: "sign_in"`. Consumers should branch on `signUp.status === "transferable"` rather than catching the 422.
- `Verification::STRATEGIES` extends with `enterprise_sso` + `saml`. `SignInAttempt::STATUS_TRANSFERABLE` is now a valid status (existing transitions allow it from `needs_identifier` and `needs_first_factor`).
- `Challenge` resource exposes `enterprise_connection_id` (populated by AU-7 callbacks; `null` everywhere else).
- `SessionTokenIssuer` mints `entcon` + `entacc` claims when applicable.

## [0.5.0] — 2026-05-11

Account Portal v1: Passkeys + Theming + Localization + six new OAuth presets.

### Added

- **Passkeys (WebAuthn)** — full enrollment + sign-in.
  - `Passkey` model (`pkey_` prefix), `Verification::STRATEGY_PASSKEY`, `User.passkey_count` accessor, `User.passkeys` relation.
  - `web-auth/webauthn-lib` integration. `PasskeyService` builds registration/authentication options + verifies attestation/assertion. RP-ID derived from the env's FAPI host; origin allowlist from `Environment.allowed_origins`.
  - FAPI `/v1/me/passkeys` — list / begin-registration (Challenge with `creationOptions`) / complete-registration (consumes attestation) / rename / delete.
  - FAPI sign-in passkey path — `PasskeyStrategy` registered on `StrategyResolver`. `SignIn.supported_strategies` lists `passkey` for users with ≥1 verified passkey. Challenge carries `requestOptions`; answer consumes the assertion + bumps `sign_count` + `last_used_at`. Cross-flow `transferable` handoff deferred to v0.6.
  - BAPI `/v1/passkeys` admin surface — instance-scoped list / get / update-nickname / delete. Bearer-secret auth.
  - Account Portal `<UserProfile />` Security section: Passkeys panel (`<PasskeysPanel />` mounts `@authn-sh/sdk-react`'s `<UserProfilePasskeysPanel />`). Sign-in passkey button via `<SignIn />` auto-include.
  - Session JWTs carry two new compact claims: `pkv` (bool, true when first-factor was passkey + UV required) and `pkc` (int, snapshot of `User.passkey_count`).
- **Six new preset OAuth providers** — Discord, Facebook, LinkedIn (OIDC discovery), X, GitLab (OIDC), Slack (OIDC). Registered in `OauthProviderResolver` + seeded configured-but-disabled by `OauthProviderSeeder`. Each ships default scopes, `attribute_mapping`, and any per-provider `additional_authorization_params`.
- **Theming engine** — `Environment.appearance` JSONB column with `variables` / `elements` / `layout`. BAPI `/v1/instance/appearance` (GET / PUT / PATCH — deep merge). `EnvironmentResource` surfaces `appearance.etag` (sha256 cache key) on `GET /v1/environment`. Account Portal `AppearanceProvider` consumes via Inertia bootstrap.
- **Localization engine** — `Environment.localization` JSONB column with `default_locale`, `fallback_locale`, `supported_locales[]`, sparse `overrides` map.
  - BAPI `/v1/instance/localization` (GET / PUT / PATCH — sparse per-key override merge; `null` removes).
  - Public `GET /v1/localization/{locale}` returns the merged catalog (default ⊕ overrides) with `Cache-Control: public, max-age=300, stale-while-revalidate=3600` + `ETag: "<override_etag>"`. CORS-open, no auth.
  - Canonical default catalogs (`app/Localization/Defaults/<locale>.php`) for `en-US` / `pt-BR` / `es-ES` / `fr-FR` / `de-DE`. `CanonicalSchema` source-of-truth for override-key validation (`unknown_localization_key`) + placeholder validation. `Localizer` ICU MessageFormat renderer for Account Portal pages.
  - `LocaleProvider` resolves the active locale from URL / setLocale / `User.locale` / `navigator.languages` / env default and exposes `t(key, vars?)` to Inertia pages.
- **Dashboard Configure → Customization** — minimum-viable CRUD editors for Appearance + Localization, both writing through the new BAPI surfaces. The richer editor surface (Monaco JSON editor, live preview iframe, placeholder validation warnings, diff view) deferred to v0.6 — see authn-sh/authn#187.
- **InstanceSetting** — `authentication_strategies.passkey.enabled` toggle (per-env, strict-semantic — gates *enrollment* only; existing passkeys stay usable). `multi_factor.passkey_counts_as_mfa` toggle — when `true` and a session's first-factor was passkey with UV-required, the session is marked second-factor-satisfied without a separate Challenge.
- **Audit log + webhook events** — `passkey.added` / `passkey.removed` (`event.data: PasskeyResource`), `instance.config.appearance_updated` / `localization.updated` (carry `{previous, current, diff}`). Audit-log entries `auth.mfa.passkey_added` / `auth.mfa.passkey_removed`.
- **Pest end-to-end coverage** — passkey registration/sign-in fixture flows (with mocked navigator), six new OAuth preset registrations, appearance PATCH round-trip, localization sparse merge + unknown-key rejection, public localization endpoint cache-header correctness, dev-mode missing-key fallthrough.

### Changed

- `Environment` model gains `appearance` + `localization` JSONB columns. `EnvironmentObserver` seeds the defaults on env creation.
- `StrategyResolver` registers `passkey` alongside the v0.4 strategies.
- Session token issuer (`SessionTokenIssuer`) now emits `pkv` + `pkc` claims when the parent SignIn was authenticated by passkey.

## [0.4.0] — 2026-05-11

### Added

- **Social sign-in** — `OauthProvider` (`oauthp_`) + `ExternalAccount` (`ext_`) models + migrations. Three `provider_kind`s in a single shape: `preset` (Google / GitHub / Apple / Microsoft with default scopes + attribute mapping baked in), `custom_oidc` (issuer URL → discovered endpoints cached 5 min), `custom_oauth2` (admin supplies authorize / token / userinfo endpoints + `userinfo_method` + `userinfo_auth`). `redirect_uri` computed read-only as `https://<env_slug>.authn.sh/v1/oauth-callback/<provider_key>`. Encrypted `client_secret` at rest, never returned. `OauthProviderResolver` + `PresetRegistry` + `OauthProviderSeeder` seed presets per environment.
- **OAuth sign-in flow** — `oauth_<provider_key>` first-factor strategy plugged into `StrategyResolver`. `OauthCallbackController` at `/v1/oauth-callback/{provider_key}` exchanges authorization code → tokens → userinfo, applies `attribute_mapping`, finds-or-creates `User` + `ExternalAccount`, transitions parent SignIn / SignUp Challenge to `complete` (or `transferable` per PLAN §9.4 cross-flow). State JWT via `Crypt::encryptString` binds `client_id` + `attempt_id` + `verification_id` + `nonce` for the 10-minute window.
- **id_token JWS validation** (AU-6.1) — `IdTokenValidator` fetches + caches JWKS (1h TTL), validates id_token signature against per-provider JWKS (RS256/RS384/RS512), enforces issuer + audience + expiry. For preset / OIDC providers the validated id_token's `sub` + `email` + `email_verified` claims now win over userinfo — userinfo can no longer override security-critical claims if compromised.
- **BAPI `/v1/oauth-providers`** (CRUD) + `POST /v1/oauth-providers/{id}/test` (dry-run: builds authorize URL, HEAD-probes userinfo endpoint, surfaces 4xx/5xx). Audit-log + webhook events on create/update/delete.
- **PhoneNumber model** (`phn_`) + migrations — E.164 normalized, `verified_at`, `is_primary`, `reserved_for_second_factor`, `default_second_factor`, `linked_to_external_account_id`. `User` gains `primary_phone_number_id` + `phone_number_enabled`. `EmailAddress` gains `linked_to_external_account_id`.
- **FAPI `/v1/me/phone-numbers`** — CRUD + verify via the v0.2 Challenge dance (`strategy: "phone_code"`). 409 on delete when `reserved_for_second_factor = true`.
- **FAPI `/v1/me/external-accounts`** — read + delete. Delete does best-effort revoke against the provider's `revocation_endpoint` (when configured), then drops the local row + clears `EmailAddress.linked_to_external_account_id`. 403 during impersonation per PLAN §10.3.
- **BAPI `/v1/phone-numbers`** (admin CRUD) + **`/v1/external-accounts`** (admin read / delete). Admin-side `verified: true` + `primary: true` BAPI-privileged shortcuts on phone create. Single-primary invariant enforced.
- **`phone_code` strategy** — `PhoneCodeStrategy` plugged into `StrategyResolver`. Drives SMS-OTP for sign-up phone-attribute first-factor + sign-in second-factor MFA. Replay protection mirrors v0.2 `email_code` (5 wrong attempts → `failed`, 10-min expiry). `signInSecondFactorStrategies` narrows `phone_code` per-user enrollment.
- **Pivot to needs_second_factor on phone-only MFA enrolment** (AU-9.1) — `ChallengeController::userHasEnrolledSecondFactor` + `SignInController::shouldPivotToSecondFactor` now check for a verified+reserved `PhoneNumber` row. A user whose only second factor is SMS no longer silently bypasses MFA on first-factor success.
- **SMS engine** — `SmsDriverManager` extension point parallel to `MailDriverManager`. Drivers: `TwilioDriver` + `VonageDriver` + `NullDriver` (no-op for dev). Test-mode short-circuit for the reserved `+1 (555) 555-0100`–`0199` E.164 range per PLAN §9.11. `SendVerificationSms` / `SendInvitationSms` / `SendResetPasswordCodeSms` / `SendSmsTemplate` queue jobs.
- **SmsTemplate model + BAPI `/v1/sms-templates`** — three default slugs (`verification_code`, `reset_password_code`, `invitation`) seeded by `EnvironmentObserver` per environment. Liquid-style placeholder substitution shares the email template engine. CRUD + revert via BAPI; `Environment.sms` block exposes driver picker + per-driver credentials.
- **InstanceSetting** — `attributes.phone_number` flipped from `"off"`-only to the standard `required` / `optional` / `off` tri-state. `multi_factor.phone_code.enabled` toggle alongside the v0.3 `totp` / `backup_codes` blocks (strict semantic mirrors v0.3 — toggle gates *enrollment* only; already-enrolled users still complete their factor).
- **`EnvironmentResource.auth_config` v0.4 expansion** — `first_factors` now appends `phone_code` when `attributes.phone_number != "off"` and `oauth_<provider_key>` per enabled `OauthProvider`. `second_factors` picks up `phone_code` via `MultiFactorSettings::enabledStrategies()`. `oauth_providers[]` returns `{ provider_key, name, logo_url, strategy }` for the SDK's `<SocialButtons />` rendering.
- **Account Portal v0.4 surfaces** — `<UserProfile />` Account section gains Phone-numbers + Connected-accounts panels. `<SignIn />` + `<SignUp />` render social buttons + `/sso-callback` resume handler. `<SignUp />` gains `/verify-phone-number` step. All driven by `@authn-sh/sdk-react@0.4.x` via the `routing="virtual"` SDK component, mounted by the existing Inertia page wrappers.
- **Dashboard Configure → Social providers subsection** — preset toggle grid + custom OIDC wizard (issuer → preview discovered endpoints) + custom OAuth2 wizard (manual endpoints). PATCH `/configure/oauth-providers` shortcut route.
- **Dashboard Configure → SMS subsection** + **Phone toggle in Attributes** — driver picker (Twilio / Vonage / null), credentials form (write-only), `from_number` field, "Send test SMS" button. Phone-attribute tri-state radio in the existing Attributes subsection.
- **JWT session claims** — `pnv` (phone_number_verified, always emitted) + `dsf` (default_second_factor, emitted when non-null). `SessionTokenIssuer::mint()` picks `phone_code` when a verified phone is flagged `default_second_factor = true`, else `totp` when a verified `TotpSecret` exists, else omits. Consumed by `@authn-sh/sdk-php` + `@authn-sh/sdk-node` `VerifiedClaims`.
- **Audit log + webhook events** — `oauth_provider.created` / `…updated` / `…deleted`, `external_account.connected` / `…unlinked`, `phone_number.created` / `…verified` / `…removed`. Dashboard AuditLog page shows the latest 100 events with a facet filter.
- **End-to-end Pest coverage** (AU-16) — full OAuth sign-in dance (preset Google fixture + custom OIDC discovery cache hit/miss + transferable cross-flow), id_token tampered-userinfo override, phone-number add + verify + remove, phone_code MFA second factor, negative paths (state mismatch, scope drift, expired SMS code, unverified phone reserved for MFA).

### Changed

- `User->phoneNumbers()` + `User->primaryPhoneNumber()` relationships added. `UserResource` serialises `phone_numbers[]` + `primary_phone_number_id`. (`phone_number_enabled` stays a server-side flag — not in the public payload.)
- `OauthCallbackController` accepts `id_token` from the IdP's token response; when present + `jwks_uri` is configured, validates the JWS before consuming userinfo. Failure → `oauth_id_token_invalid` redirect, no user created.

## [0.3.0] — 2026-05-10

### Added

- **Multi-factor authentication** — `TotpSecret` + `BackupCode` models + migrations. Verification strategies `totp` (RFC 6238 TOTP) and `backup_code` (single-use Crockford-base32 `xxxx-xxxx`) plug into the v0.2 Challenge sub-resource as `step: "second"` rounds on SignIn.
- **TOTP enrollment** — `App\Auth\Mfa\TotpEnrolmentService`, `MeTotpController` at `/v1/me/totp` (start, verify, show, destroy). One-time secret + `otpauth://` URI + inline-SVG QR data URL on the enrollment response. Replay-safe via `last_used_step` advancement; ±1 step verification window. `pragmarx/google2fa` + `bacon/bacon-qr-code` dependencies.
- **Backup codes** — `App\Auth\Mfa\BackupCodesService`, `MeBackupCodesController` at `/v1/me/backup-codes` (regenerate, status, destroy). Argon2id-hashed at rest, plaintext exposed once via `BackupCodeBatch`. Default-10 codes, bounds 4..24. Single-use `tryConsume` returns a `ConsumeResult` enum (`OK | NOT_FOUND | ALREADY_USED`) so the API surfaces `form_code_already_used` distinctly from `form_code_incorrect`.
- **Second-factor Challenge wiring** — `TotpStrategy` + `BackupCodeStrategy` plugged into the existing `StrategyResolver`. `ChallengeController` pivots `SignInAttempt` to `NEEDS_SECOND_FACTOR` after first-factor success when the user has enrolled MFA. Inline answer-on-create supported (`POST /challenges { strategy: "totp", code }`) for SDK one-call flow.
- **`SignInResource::supportedStrategies`** narrows for `needs_second_factor` based on per-user enrollment (TotpSecret verified + unspent BackupCodes). **Strict semantic** (issue #109): env-level `multi_factor.{totp,backup_codes}.enabled` toggles control *enrollment* only — already-enrolled users must still complete their factor regardless of operator policy changes. The per-user nuke `DELETE /v1/users/{id}/mfa` is the explicit operator downgrade path.
- **BAPI MFA admin overrides** — `POST /v1/users/{user_id}/verify-totp` (operator-driven check; does not stamp `verified_at`), `DELETE /v1/users/{user_id}/mfa` (operator nuke; clears TotpSecret + BackupCode + flips three User flags + stamps `mfa_disabled_at`).
- **`MultiFactorSettings` value object** + `InstanceController::update` honours `multi_factor` patches inside `PATCH /v1/instance`. 4..24 bounds validation on `default_count`. `instance.config.multi_factor_updated` event on real change.
- **`Environment.auth_config.second_factors`** populated from per-env `MultiFactorSettings::enabledStrategies()` for the public Environment payload the SDK reads at boot.
- **Account Portal `<UserProfile />` Security section + `/sign-in/factor-two`** — picks up `<UserProfileSecuritySection />`, `<TotpEnrollDialog />`, `<BackupCodesDialog />`, `<RemoveMfaDialog />` from `@authn-sh/sdk-react@0.3.0`. SignIn pivots into the second-factor step automatically through `<SdkSignIn routing="virtual">`.
- **Dashboard Configure → Multi-factor subsection** — operator UI for the env's `totp.enabled` / `backup_codes.enabled` / `default_count` settings; PATCH `/configure/multi-factor` writes through directly (no BAPI round-trip).
- **Email templates** — `totp_enabled`, `mfa_disabled`, `backup_codes_generated` MJML defaults registered in `EmailTemplate::DEFAULT_TEMPLATES`. `EnvironmentObserver` seeds new envs automatically.
- **Audit log emission** (`Log::info` for now; will be promoted to DB-backed audit rows when the audit-log epic lands) — `auth.mfa.totp_enrolled`, `auth.mfa.totp_removed`, `auth.mfa.backup_codes_generated`.
- **`PruneMfaArtifacts` reaper** — scheduled daily; prunes abandoned-TOTP rows older than 24h and consumed BackupCode rows older than the env's `audit_log_retention_days`.
- **`@authn-sh/{sdk-js,sdk-react,types,ui}@0.3.0`** pinned in `package.json`.

### Changed

- Verification strategies enum extended with `totp` + `backup_code`. Renamed `STRATEGY_NOT_SUPPORTED_IN_V0_1` / `MFA_NOT_ENABLED_IN_V0_1` / `TRANSFER_NOT_SUPPORTED_IN_V0_1` ErrorCode constants to drop the version suffix.
- `BackupCode.used_at` column renamed to `consumed_at` to match the openapi spec (in-place migration edit per the alpha rule).

## [0.2.0] — 2026-05-10

### Added

- **Organizations** — `Organization`, `OrganizationMembership`, `OrganizationInvitation`, `OrganizationDomain`, `OrganizationMembershipRequest`, `Role`, `Permission`, `RolePermission` models + migrations. System permissions seed (13 keys) + default `org:admin` / `org:member` roles per environment. `User->hasOrgPermission()` helper and `Auth::guard('authn')->organizationPermission()` Laravel binding.
- **BAPI** organization endpoints — `/v1/organizations` CRUD + `/memberships` + `/invitations` + `/domains` + `/v1/roles` (custom role CRUD + `setPermissions`) + `/v1/permissions` (read).
- **FAPI** organization endpoints — user-scoped `/v1/organizations` create/get/update/leave, `/v1/me/organization-memberships`, `/v1/me/organization-invitations` (+ `/{id}/accept`), `/v1/me/organization-membership-requests`, and `PUT /v1/me/active-organization`.
- **Active organization on Session** — `last_active_organization_id` column, `org` JWT claim (`{id, slg, rol, per}`), `token_version` bump on active-org change so the SDK invalidates its cached token.
- **Domain enrollment automation** — `automatic_invitation` and `automatic_suggestion` modes, `OrganizationMembershipRequest` flow, sign-up domain match, DNS TXT verification job + daily recheck.
- **Email magic-link strategy** (`email_link`) for sign-in + sign-up, with `transferable` cross-flow handling and cross-device `__client` polling.
- **Webhook events** — `organization.*`, `organizationMembership.*`, `organizationInvitation.*`, `organizationDomain.*`, `role.*`, `permission.*`, plus the `organization` block on `session.created` / `session.touched`.
- **Account Portal Inertia wrappers** — `/organization`, `/organization-list`, `/create-organization`, organization-switcher in `/user`.
- **Dashboard** — Organizations panel, Roles & Permissions panel, Organization settings.
- **Email templates** — `organization-invitation` and `magic-link` MJML defaults, both editable via the Dashboard.
- **`Challenge` sub-resource** — unified verification dance hangs off SignIn / SignUp / EmailAddress / OrganizationDomain. Replaces the per-factor `prepare-*` / `attempt-*` pairs and the standalone `/me/email-addresses/{id}/{prepare,attempt}-verification` and `/organizations/{org}/domains/{did}/verify` endpoints.
- **`domain_dns_txt` strategy** — DNS TXT verification goes through the standard challenge dance with explicit `GET /challenges/{cid}` polling.
- **Auto-applied OpenAPI contract validation** in tests — every feature test response is validated against the bundled v0.2 spec; spec drift fails the offending test loudly.

### Changed

- **URL paths use kebab-case** for every BAPI + FAPI segment. Path parameter names (`{user_id}`, `{organization_id}`, …), query parameters, and JSON request/response field names stay snake_case.
- `EmailAddress` / `OrganizationDomain` resource shapes drop the nested `verification` blob; both expose flat `verified` + `current_challenge_id`.
- Account Portal + Dashboard pin to `@authn-sh/sdk-js@0.2.0` / `sdk-react@0.2.0` / `types@0.2.0` / `ui@0.2.0`.

### Removed

- Legacy SignIn / SignUp `prepare-*` / `attempt-*` controller actions, `reset-password`, MeController `prepareEmailVerification` / `attemptEmailVerification`, OrganizationDomainController `verify` — all superseded by the unified Challenge controllers.
- Standalone `Verification` resource on the API surface (the underlying Eloquent model still exists for cryptographic plumbing — only the API veneer changed).

## [0.1.0] — 2026-05-03

Initial release: tenancy spine (Project / Environment), end-user identity (User / EmailAddress / Verification), sign-in / sign-up state machines (password, email_code, ticket, reset_password_email_code), Sessions + JWT (RS256/ES256/ES384/ES512) + JWKS, FAPI bootstrap, BAPI surface (users, sessions, invitations, allowlist / blocklist identifiers, redirect URLs, instance settings, webhook endpoints + deliveries), Account Portal, Dashboard, Docker image, observability + audit log foundation.
