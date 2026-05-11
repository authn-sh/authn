# Changelog

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
