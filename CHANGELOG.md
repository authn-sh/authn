# Changelog

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
