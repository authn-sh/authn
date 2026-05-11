<?php

declare(strict_types=1);

namespace App\Auth\Oauth\Presets;

/**
 * Each preset returns the canonical endpoints + default scopes and
 * attribute-mapping for one IdP. Implementations are pure value
 * functions — no DB access, no HTTP calls — so the resolver can mix
 * them with stored OauthProvider state cheaply.
 */
interface PresetContract
{
    /**
     * Stable key used as the `provider_key` on seeded rows and the
     * suffix in `oauth_<key>` strategy strings.
     */
    public function key(): string;

    /**
     * Operator-facing display name (rendered in Configure → Social
     * providers + on the SignIn social buttons).
     */
    public function name(): string;

    public function authorizationEndpoint(): string;

    public function tokenEndpoint(): string;

    public function userinfoEndpoint(): string;

    public function jwksUri(): ?string;

    /**
     * The expected `iss` claim on id_tokens this IdP issues. Used by the
     * id_token validator to reject tokens replayed from a different IdP.
     * Returns `null` for presets that don't issue an id_token (e.g. GitHub).
     */
    public function issuer(): ?string;

    /**
     * Default OAuth scopes requested when the operator hasn't
     * overridden them. Always includes `openid` for OIDC presets.
     *
     * @return array<int, string>
     */
    public function defaultScopes(): array;

    /**
     * `<our shape> => <userinfo claim path>` map applied when the
     * stored `attribute_mapping` is empty.
     *
     * @return array<string, string>
     */
    public function defaultAttributeMapping(): array;

    /**
     * Provider-specific authorization-URL params (`response_mode`,
     * tenant overrides, …).
     *
     * @return array<string, mixed>
     */
    public function additionalAuthorizationParams(): array;

    /**
     * @return list<string>
     */
    public function idTokenSigningAlgs(): array;

    /**
     * `GET` (default) or `POST`. Apple uses POST.
     */
    public function userinfoMethod(): string;

    /**
     * `bearer` (default), `basic`, or `query` — how the access token
     * is presented to userinfo.
     */
    public function userinfoAuth(): string;
}
