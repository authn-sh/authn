# Changelog

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
