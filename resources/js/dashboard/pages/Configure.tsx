import { Link, useForm, usePage } from '@inertiajs/react'
import { useDashboard, useDashboardUrl } from '../shared'

type MultiFactorSettings = {
    totp: { enabled: boolean }
    backup_codes: { enabled: boolean; default_count: number }
    phone_code?: { enabled: boolean }
}

type AttributesSettings = {
    phone_number: 'required' | 'optional' | 'off'
}

type SmsSettings = {
    driver: 'twilio' | 'vonage' | null
    from_number: string | null
    twilio_account_sid: string | null
    twilio_auth_token_set: boolean
    vonage_api_key: string | null
    vonage_api_secret_set: boolean
}

type SmsTemplate = {
    id: string
    slug: string
    body: string
    delivered_by_us: boolean
    from_number_override: string | null
}

type OauthProvider = {
    id: string
    provider_kind: 'preset' | 'custom_oidc' | 'custom_oauth2'
    provider_key: string
    name: string
    enabled: boolean
    allow_sign_in: boolean
    allow_sign_up: boolean
    block_email_subaddresses: boolean
    client_id: string
    client_secret_set: boolean
    scopes: string[]
    attribute_mapping: Record<string, string>
    additional_authorization_params: Record<string, unknown>
    issuer: string | null
    authorization_endpoint: string | null
    token_endpoint: string | null
    userinfo_endpoint: string | null
    redirect_uri: string
}

type AppearanceShape = {
    variables?: Record<string, string>
    elements?: Record<string, string>
    layout?: Record<string, unknown>
}

type LocalizationShape = {
    default_locale: string
    fallback_locale: string
    supported_locales: string[]
    overrides: Record<string, Record<string, string>>
}

type LocalizationCanonical = {
    shipped_locales: string[]
    fallback_locale: string
    keys: string[]
    en_us_catalog: Record<string, string>
}

type Props = {
    section: string
    user_settings: Record<string, unknown>
    allowed_origins: string[]
    appearance: AppearanceShape
    signup_mode: string
    multi_factor: MultiFactorSettings
    attributes: AttributesSettings
    sms: SmsSettings
    sms_templates: SmsTemplate[]
    oauth_providers: OauthProvider[]
    oauth_preset_keys: string[]
    localization: LocalizationShape
    localization_canonical: LocalizationCanonical
}

const SECTIONS = [
    { slug: 'attributes', label: 'Attributes' },
    { slug: 'multi-factor', label: 'Multi-factor' },
    { slug: 'sms', label: 'SMS' },
    { slug: 'social-providers', label: 'Social providers' },
    { slug: 'appearance', label: 'Appearance' },
    { slug: 'localization', label: 'Localization' },
] as const

export default function Configure(props: Props) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const baseConfigure = active_project && active_environment
        ? `/${active_project.slug}/${active_environment.slug}/configure`
        : '/configure'

    return (
        <div>
            <h1>Configure</h1>
            <nav style={{ display: 'flex', gap: 12, marginBottom: 24, borderBottom: '1px solid #e5e7eb', paddingBottom: 8 }}>
                {SECTIONS.map(s => (
                    <Link
                        key={s.slug}
                        href={url(`${baseConfigure}/${s.slug}`)}
                        style={{
                            padding: '6px 10px',
                            borderRadius: 6,
                            background: props.section === s.slug ? '#0f172a' : 'transparent',
                            color: props.section === s.slug ? '#fff' : '#0f172a',
                            textDecoration: 'none',
                        }}
                    >
                        {s.label}
                    </Link>
                ))}
            </nav>
            {props.section === 'attributes' && <AttributesSection attributes={props.attributes} signupMode={props.signup_mode} />}
            {props.section === 'multi-factor' && <MultiFactorSection multiFactor={props.multi_factor} />}
            {props.section === 'sms' && <SmsSection sms={props.sms} smsTemplates={props.sms_templates} />}
            {props.section === 'social-providers' && <SocialProvidersSection providers={props.oauth_providers} presetKeys={props.oauth_preset_keys} />}
            {props.section === 'appearance' && <AppearanceSection appearance={props.appearance} />}
            {props.section === 'localization' && (
                <LocalizationSection
                    localization={props.localization}
                    canonical={props.localization_canonical}
                />
            )}
            {!['attributes', 'multi-factor', 'sms', 'social-providers', 'appearance', 'localization'].includes(props.section) && (
                <pre style={{ background: '#f1f5f9', padding: 12, borderRadius: 6 }}>
                    {JSON.stringify(props.user_settings, null, 2)}
                </pre>
            )}
        </div>
    )
}

function AttributesSection({ attributes, signupMode }: { attributes: AttributesSettings; signupMode: string }) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const { props: pageProps } = usePage<{ flash?: { attributes_saved?: boolean } }>()
    const form = useForm({ phone_number: attributes.phone_number })

    if (!active_project || !active_environment) {
        return <p>No active environment.</p>
    }
    const action = url(`/${active_project.slug}/${active_environment.slug}/configure/attributes`)

    return (
        <section>
            <h2>Attributes</h2>
            <p>Sign-up mode: <strong>{signupMode}</strong></p>
            {pageProps.flash?.attributes_saved && (
                <div style={{ padding: 12, background: '#dcfce7', borderRadius: 6, marginBottom: 16 }}>Saved.</div>
            )}
            <form
                onSubmit={(e) => {
                    e.preventDefault()
                    form.patch(action, { preserveScroll: true })
                }}
            >
                <fieldset style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    <legend><strong>Phone number</strong></legend>
                    {(['required', 'optional', 'off'] as const).map(opt => (
                        <label key={opt} style={{ display: 'block', marginBottom: 4 }}>
                            <input
                                type="radio"
                                name="phone_number"
                                value={opt}
                                checked={form.data.phone_number === opt}
                                onChange={() => form.setData('phone_number', opt)}
                                style={{ marginRight: 6 }}
                            />
                            {opt}
                        </label>
                    ))}
                </fieldset>
                <button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save'}
                </button>
            </form>
        </section>
    )
}

function MultiFactorSection({ multiFactor }: { multiFactor: MultiFactorSettings }) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const { props: pageProps } = usePage<{ flash?: { multi_factor_saved?: boolean } }>()
    const form = useForm({
        totp: { enabled: multiFactor.totp.enabled },
        backup_codes: {
            enabled: multiFactor.backup_codes.enabled,
            default_count: multiFactor.backup_codes.default_count,
        },
    })

    if (!active_project || !active_environment) {
        return <p>No active environment.</p>
    }
    const action = url(`/${active_project.slug}/${active_environment.slug}/configure/multi-factor`)

    return (
        <section>
            <h2>Multi-factor authentication</h2>
            <p>Per-environment toggles. Disabling a strategy keeps existing enrollment rows but stops them from completing sign-in.</p>
            {pageProps.flash?.multi_factor_saved && (
                <div style={{ padding: 12, background: '#dcfce7', borderRadius: 6, marginBottom: 16 }}>
                    Saved.
                </div>
            )}
            <form
                onSubmit={(e) => {
                    e.preventDefault()
                    form.patch(action, { preserveScroll: true })
                }}
            >
                <fieldset style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    <legend><strong>TOTP (authenticator app)</strong></legend>
                    <label style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                        <input
                            type="checkbox"
                            checked={form.data.totp.enabled}
                            onChange={(e) => form.setData('totp', { enabled: e.target.checked })}
                        />
                        Allow users to enroll an authenticator app as a second factor.
                    </label>
                    {form.errors['totp.enabled'] && <span style={{ color: '#c00', fontSize: 12 }}>{form.errors['totp.enabled']}</span>}
                </fieldset>
                <fieldset style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    <legend><strong>Backup codes</strong></legend>
                    <label style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 8 }}>
                        <input
                            type="checkbox"
                            checked={form.data.backup_codes.enabled}
                            onChange={(e) => form.setData('backup_codes', { ...form.data.backup_codes, enabled: e.target.checked })}
                        />
                        Allow users to generate single-use recovery codes.
                    </label>
                    <label style={{ display: 'block' }}>
                        Codes per regeneration (4–24)
                        <input
                            type="number"
                            min={4}
                            max={24}
                            value={form.data.backup_codes.default_count}
                            onChange={(e) => form.setData('backup_codes', { ...form.data.backup_codes, default_count: Number(e.target.value) })}
                            style={{ display: 'block', marginTop: 4, padding: 6, width: 120 }}
                        />
                    </label>
                    {form.errors['backup_codes.default_count'] && <span style={{ color: '#c00', fontSize: 12 }}>{form.errors['backup_codes.default_count']}</span>}
                </fieldset>
                <button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save'}
                </button>
            </form>
        </section>
    )
}

function SmsSection({ sms, smsTemplates }: { sms: SmsSettings; smsTemplates: SmsTemplate[] }) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const { props: pageProps } = usePage<{ flash?: { sms_saved?: boolean; sms_test_dispatched?: boolean; sms_template_saved?: boolean } }>()

    const settingsForm = useForm({
        driver: sms.driver ?? '',
        from_number: sms.from_number ?? '',
        twilio: {
            account_sid: sms.twilio_account_sid ?? '',
            auth_token: '',
        },
        vonage: {
            api_key: sms.vonage_api_key ?? '',
            api_secret: '',
        },
    })

    const testForm = useForm({ to_number: '' })

    if (!active_project || !active_environment) {
        return <p>No active environment.</p>
    }
    const base = `/${active_project.slug}/${active_environment.slug}/configure`

    return (
        <section>
            <h2>SMS</h2>
            <p>Outbound SMS provider for phone-number verification + phone-MFA codes.</p>
            {pageProps.flash?.sms_saved && (
                <div style={{ padding: 12, background: '#dcfce7', borderRadius: 6, marginBottom: 16 }}>Saved.</div>
            )}
            {pageProps.flash?.sms_test_dispatched && (
                <div style={{ padding: 12, background: '#dbeafe', borderRadius: 6, marginBottom: 16 }}>Test SMS dispatched.</div>
            )}

            <form
                onSubmit={(e) => {
                    e.preventDefault()
                    settingsForm.patch(url(`${base}/sms`), { preserveScroll: true })
                }}
            >
                <fieldset style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    <legend><strong>Driver</strong></legend>
                    {(['', 'twilio', 'vonage'] as const).map(opt => (
                        <label key={opt || 'null'} style={{ display: 'block', marginBottom: 4 }}>
                            <input
                                type="radio"
                                name="driver"
                                value={opt}
                                checked={settingsForm.data.driver === opt}
                                onChange={() => settingsForm.setData('driver', opt)}
                                style={{ marginRight: 6 }}
                            />
                            {opt === '' ? 'Disabled (no-op)' : opt}
                        </label>
                    ))}
                </fieldset>

                <label style={{ display: 'block', marginBottom: 16 }}>
                    From number (E.164)
                    <input
                        type="text"
                        value={settingsForm.data.from_number}
                        onChange={(e) => settingsForm.setData('from_number', e.target.value)}
                        placeholder="+15555550100"
                        style={{ display: 'block', marginTop: 4, padding: 6, width: 240 }}
                    />
                </label>

                {settingsForm.data.driver === 'twilio' && (
                    <fieldset style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                        <legend><strong>Twilio</strong></legend>
                        <label style={{ display: 'block', marginBottom: 8 }}>
                            Account SID
                            <input
                                type="text"
                                value={settingsForm.data.twilio.account_sid}
                                onChange={(e) => settingsForm.setData('twilio', { ...settingsForm.data.twilio, account_sid: e.target.value })}
                                style={{ display: 'block', marginTop: 4, padding: 6, width: 360 }}
                            />
                        </label>
                        <label style={{ display: 'block' }}>
                            Auth token {sms.twilio_auth_token_set && <span style={{ color: '#64748b', fontSize: 12 }}>(••••, leave blank to keep)</span>}
                            <input
                                type="password"
                                value={settingsForm.data.twilio.auth_token}
                                onChange={(e) => settingsForm.setData('twilio', { ...settingsForm.data.twilio, auth_token: e.target.value })}
                                placeholder={sms.twilio_auth_token_set ? '•••• rotate' : 'auth token'}
                                style={{ display: 'block', marginTop: 4, padding: 6, width: 360 }}
                            />
                        </label>
                    </fieldset>
                )}

                {settingsForm.data.driver === 'vonage' && (
                    <fieldset style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                        <legend><strong>Vonage</strong></legend>
                        <label style={{ display: 'block', marginBottom: 8 }}>
                            API key
                            <input
                                type="text"
                                value={settingsForm.data.vonage.api_key}
                                onChange={(e) => settingsForm.setData('vonage', { ...settingsForm.data.vonage, api_key: e.target.value })}
                                style={{ display: 'block', marginTop: 4, padding: 6, width: 360 }}
                            />
                        </label>
                        <label style={{ display: 'block' }}>
                            API secret {sms.vonage_api_secret_set && <span style={{ color: '#64748b', fontSize: 12 }}>(••••, leave blank to keep)</span>}
                            <input
                                type="password"
                                value={settingsForm.data.vonage.api_secret}
                                onChange={(e) => settingsForm.setData('vonage', { ...settingsForm.data.vonage, api_secret: e.target.value })}
                                placeholder={sms.vonage_api_secret_set ? '•••• rotate' : 'api secret'}
                                style={{ display: 'block', marginTop: 4, padding: 6, width: 360 }}
                            />
                        </label>
                    </fieldset>
                )}

                <button type="submit" disabled={settingsForm.processing}>
                    {settingsForm.processing ? 'Saving…' : 'Save'}
                </button>
            </form>

            <fieldset style={{ marginTop: 24, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                <legend><strong>Send test SMS</strong></legend>
                <form
                    onSubmit={(e) => {
                        e.preventDefault()
                        testForm.post(url(`${base}/sms/test`), { preserveScroll: true, onSuccess: () => testForm.reset() })
                    }}
                >
                    <label style={{ display: 'block', marginBottom: 8 }}>
                        Recipient (E.164)
                        <input
                            type="text"
                            value={testForm.data.to_number}
                            onChange={(e) => testForm.setData('to_number', e.target.value)}
                            placeholder="+15555550100"
                            style={{ display: 'block', marginTop: 4, padding: 6, width: 240 }}
                        />
                    </label>
                    <button type="submit" disabled={testForm.processing}>
                        {testForm.processing ? 'Sending…' : 'Send test'}
                    </button>
                </form>
            </fieldset>

            <h3 style={{ marginTop: 24 }}>Templates</h3>
            {smsTemplates.map(t => (
                <SmsTemplateRow key={t.id} template={t} />
            ))}
        </section>
    )
}

function SmsTemplateRow({ template }: { template: SmsTemplate }) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const form = useForm({
        body: template.body,
        delivered_by_us: template.delivered_by_us,
        from_number_override: template.from_number_override ?? '',
    })

    if (!active_project || !active_environment) {
        return null
    }
    const action = url(`/${active_project.slug}/${active_environment.slug}/configure/sms-templates/${template.slug}`)

    return (
        <details style={{ marginBottom: 12, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
            <summary><strong>{template.slug}</strong></summary>
            <form
                onSubmit={(e) => {
                    e.preventDefault()
                    form.patch(action, { preserveScroll: true })
                }}
                style={{ marginTop: 8 }}
            >
                <label style={{ display: 'block', marginBottom: 8 }}>
                    Body (Liquid)
                    <textarea
                        value={form.data.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                        rows={4}
                        style={{ display: 'block', marginTop: 4, padding: 6, width: '100%' }}
                    />
                </label>
                <label style={{ display: 'flex', gap: 6, alignItems: 'center', marginBottom: 8 }}>
                    <input
                        type="checkbox"
                        checked={form.data.delivered_by_us}
                        onChange={(e) => form.setData('delivered_by_us', e.target.checked)}
                    />
                    Send via the configured driver (otherwise emit `sms.created` webhook only).
                </label>
                <label style={{ display: 'block', marginBottom: 8 }}>
                    From number override
                    <input
                        type="text"
                        value={form.data.from_number_override}
                        onChange={(e) => form.setData('from_number_override', e.target.value)}
                        placeholder="+1…"
                        style={{ display: 'block', marginTop: 4, padding: 6, width: 240 }}
                    />
                </label>
                <button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save'}
                </button>
            </form>
        </details>
    )
}

function SocialProvidersSection({ providers, presetKeys }: { providers: OauthProvider[]; presetKeys: string[] }) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const { props: pageProps } = usePage<{ flash?: { oauth_provider_saved?: boolean; oauth_provider_test?: { provider_id: string; userinfo_status: number | null; errors: string[] } } }>()

    if (!active_project || !active_environment) {
        return <p>No active environment.</p>
    }
    const base = `/${active_project.slug}/${active_environment.slug}/configure`

    const presetsConfigured = new Set(providers.filter(p => p.provider_kind === 'preset').map(p => p.provider_key))
    const presetsMissing = presetKeys.filter(k => !presetsConfigured.has(k))

    return (
        <section>
            <h2>Social providers</h2>
            <p>Per-environment OAuth IdPs. Toggle a preset on/off, or add a custom OIDC / OAuth2 IdP.</p>
            {pageProps.flash?.oauth_provider_saved && (
                <div style={{ padding: 12, background: '#dcfce7', borderRadius: 6, marginBottom: 16 }}>Saved.</div>
            )}
            {pageProps.flash?.oauth_provider_test && (
                <div style={{ padding: 12, background: '#dbeafe', borderRadius: 6, marginBottom: 16 }}>
                    Test result for {pageProps.flash.oauth_provider_test.provider_id}: userinfo status {pageProps.flash.oauth_provider_test.userinfo_status ?? 'n/a'}
                    {pageProps.flash.oauth_provider_test.errors.length > 0 && (
                        <ul>{pageProps.flash.oauth_provider_test.errors.map((e, i) => <li key={i}>{e}</li>)}</ul>
                    )}
                </div>
            )}

            {providers.length === 0 && presetsMissing.length === presetKeys.length && (
                <p style={{ color: '#64748b' }}>No social providers configured. Pick a preset below or add a custom IdP.</p>
            )}

            {providers.map(p => <ProviderRow key={p.id} provider={p} base={base} url={url} />)}

            {presetsMissing.length > 0 && (
                <fieldset style={{ marginTop: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    <legend><strong>Add preset</strong></legend>
                    {presetsMissing.map(k => <PresetCreateForm key={k} providerKey={k} base={base} url={url} />)}
                </fieldset>
            )}

            <CustomOidcWizard base={base} url={url} />
            <CustomOauth2Wizard base={base} url={url} />
        </section>
    )
}

function ProviderRow({ provider, base, url }: { provider: OauthProvider; base: string; url: (s: string) => string }) {
    const form = useForm({
        name: provider.name,
        enabled: provider.enabled,
        allow_sign_in: provider.allow_sign_in,
        allow_sign_up: provider.allow_sign_up,
        block_email_subaddresses: provider.block_email_subaddresses,
        client_id: provider.client_id,
        client_secret: '',
        scopes: provider.scopes.join(' '),
    })
    const action = url(`${base}/oauth-providers/${provider.id}`)

    return (
        <details style={{ marginBottom: 12, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
            <summary>
                <strong>{provider.name}</strong> — <code>{provider.provider_key}</code> ({provider.provider_kind})
                {provider.enabled ? ' · enabled' : ' · disabled'}
            </summary>
            <p style={{ marginTop: 8, fontSize: 13, color: '#64748b' }}>
                Redirect URL (paste into the IdP's allow-list): <code>{provider.redirect_uri}</code>
            </p>
            <form
                onSubmit={(e) => {
                    e.preventDefault()
                    const payload: Record<string, unknown> = {
                        name: form.data.name,
                        enabled: form.data.enabled,
                        allow_sign_in: form.data.allow_sign_in,
                        allow_sign_up: form.data.allow_sign_up,
                        block_email_subaddresses: form.data.block_email_subaddresses,
                        client_id: form.data.client_id,
                        scopes: form.data.scopes.trim() === '' ? [] : form.data.scopes.trim().split(/\s+/),
                    }
                    if (form.data.client_secret !== '') {
                        payload.client_secret = form.data.client_secret
                    }
                    form.transform(() => payload).patch(action, { preserveScroll: true })
                }}
            >
                <label style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 8 }}>
                    <input type="checkbox" checked={form.data.enabled} onChange={e => form.setData('enabled', e.target.checked)} />
                    Enabled
                </label>
                <label style={{ display: 'block', marginBottom: 8 }}>
                    Display name
                    <input
                        type="text"
                        value={form.data.name}
                        onChange={e => form.setData('name', e.target.value)}
                        style={{ display: 'block', marginTop: 4, padding: 6, width: 320 }}
                    />
                </label>
                <label style={{ display: 'block', marginBottom: 8 }}>
                    client_id
                    <input
                        type="text"
                        value={form.data.client_id}
                        onChange={e => form.setData('client_id', e.target.value)}
                        style={{ display: 'block', marginTop: 4, padding: 6, width: 360 }}
                    />
                </label>
                <label style={{ display: 'block', marginBottom: 8 }}>
                    client_secret {provider.client_secret_set && <span style={{ color: '#64748b', fontSize: 12 }}>(••••, leave blank to keep)</span>}
                    <input
                        type="password"
                        value={form.data.client_secret}
                        onChange={e => form.setData('client_secret', e.target.value)}
                        placeholder={provider.client_secret_set ? '•••• rotate' : ''}
                        style={{ display: 'block', marginTop: 4, padding: 6, width: 360 }}
                    />
                </label>
                <label style={{ display: 'block', marginBottom: 8 }}>
                    Scopes (space-separated)
                    <input
                        type="text"
                        value={form.data.scopes}
                        onChange={e => form.setData('scopes', e.target.value)}
                        style={{ display: 'block', marginTop: 4, padding: 6, width: 360 }}
                    />
                </label>
                <label style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 8 }}>
                    <input type="checkbox" checked={form.data.allow_sign_in} onChange={e => form.setData('allow_sign_in', e.target.checked)} />
                    Allow sign-in
                </label>
                <label style={{ display: 'flex', gap: 8, alignItems: 'center', marginBottom: 8 }}>
                    <input type="checkbox" checked={form.data.allow_sign_up} onChange={e => form.setData('allow_sign_up', e.target.checked)} />
                    Allow sign-up
                </label>
                <button type="submit" disabled={form.processing} style={{ marginRight: 8 }}>
                    {form.processing ? 'Saving…' : 'Save'}
                </button>
                <TestButton providerId={provider.id} base={base} url={url} />
                <DeleteButton providerId={provider.id} base={base} url={url} />
            </form>
        </details>
    )
}

function TestButton({ providerId, base, url }: { providerId: string; base: string; url: (s: string) => string }) {
    const form = useForm({})
    return (
        <button
            type="button"
            disabled={form.processing}
            onClick={() => form.post(url(`${base}/oauth-providers/${providerId}/test`), { preserveScroll: true })}
            style={{ marginRight: 8 }}
        >
            Test
        </button>
    )
}

function DeleteButton({ providerId, base, url }: { providerId: string; base: string; url: (s: string) => string }) {
    const form = useForm({})
    return (
        <button
            type="button"
            disabled={form.processing}
            onClick={() => {
                if (!confirm('Delete this OAuth provider?')) return
                form.delete(url(`${base}/oauth-providers/${providerId}`), { preserveScroll: true })
            }}
        >
            Delete
        </button>
    )
}

function PresetCreateForm({ providerKey, base, url }: { providerKey: string; base: string; url: (s: string) => string }) {
    const form = useForm({
        provider_kind: 'preset',
        provider_key: providerKey,
        name: providerKey.charAt(0).toUpperCase() + providerKey.slice(1),
        client_id: '',
        client_secret: '',
        enabled: true,
    })

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault()
                form.post(url(`${base}/oauth-providers`), { preserveScroll: true })
            }}
            style={{ marginBottom: 8 }}
        >
            <strong>{providerKey}</strong>
            <input
                type="text"
                value={form.data.client_id}
                onChange={e => form.setData('client_id', e.target.value)}
                placeholder="client_id"
                style={{ marginLeft: 8, padding: 4, width: 240 }}
            />
            <input
                type="password"
                value={form.data.client_secret}
                onChange={e => form.setData('client_secret', e.target.value)}
                placeholder="client_secret"
                style={{ marginLeft: 8, padding: 4, width: 240 }}
            />
            <button type="submit" disabled={form.processing} style={{ marginLeft: 8 }}>
                {form.processing ? 'Adding…' : 'Add'}
            </button>
        </form>
    )
}

function CustomOidcWizard({ base, url }: { base: string; url: (s: string) => string }) {
    const form = useForm({
        provider_kind: 'custom_oidc',
        provider_key: '',
        name: '',
        client_id: '',
        client_secret: '',
        issuer: '',
    })
    return (
        <details style={{ marginTop: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
            <summary><strong>Add custom OIDC</strong></summary>
            <form
                onSubmit={(e) => {
                    e.preventDefault()
                    form.post(url(`${base}/oauth-providers`), { preserveScroll: true })
                }}
                style={{ marginTop: 8 }}
            >
                <input type="text" placeholder="provider_key" value={form.data.provider_key} onChange={e => form.setData('provider_key', e.target.value)} style={{ display: 'block', marginBottom: 8, padding: 6, width: 240 }} />
                <input type="text" placeholder="display name" value={form.data.name} onChange={e => form.setData('name', e.target.value)} style={{ display: 'block', marginBottom: 8, padding: 6, width: 320 }} />
                <input type="url" placeholder="https://idp.example.com (issuer)" value={form.data.issuer} onChange={e => form.setData('issuer', e.target.value)} style={{ display: 'block', marginBottom: 8, padding: 6, width: 360 }} />
                <input type="text" placeholder="client_id" value={form.data.client_id} onChange={e => form.setData('client_id', e.target.value)} style={{ display: 'block', marginBottom: 8, padding: 6, width: 360 }} />
                <input type="password" placeholder="client_secret" value={form.data.client_secret} onChange={e => form.setData('client_secret', e.target.value)} style={{ display: 'block', marginBottom: 8, padding: 6, width: 360 }} />
                <button type="submit" disabled={form.processing}>
                    {form.processing ? 'Adding…' : 'Discover + add'}
                </button>
            </form>
        </details>
    )
}

function CustomOauth2Wizard({ base, url }: { base: string; url: (s: string) => string }) {
    const form = useForm({
        provider_kind: 'custom_oauth2',
        provider_key: '',
        name: '',
        client_id: '',
        client_secret: '',
        authorization_endpoint: '',
        token_endpoint: '',
        userinfo_endpoint: '',
        userinfo_method: 'GET',
        userinfo_auth: 'bearer',
    })
    return (
        <details style={{ marginTop: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
            <summary><strong>Add custom OAuth2</strong></summary>
            <form
                onSubmit={(e) => {
                    e.preventDefault()
                    form.post(url(`${base}/oauth-providers`), { preserveScroll: true })
                }}
                style={{ marginTop: 8 }}
            >
                <input type="text" placeholder="provider_key" value={form.data.provider_key} onChange={e => form.setData('provider_key', e.target.value)} style={{ display: 'block', marginBottom: 8, padding: 6, width: 240 }} />
                <input type="text" placeholder="display name" value={form.data.name} onChange={e => form.setData('name', e.target.value)} style={{ display: 'block', marginBottom: 8, padding: 6, width: 320 }} />
                <input type="text" placeholder="client_id" value={form.data.client_id} onChange={e => form.setData('client_id', e.target.value)} style={{ display: 'block', marginBottom: 8, padding: 6, width: 360 }} />
                <input type="password" placeholder="client_secret" value={form.data.client_secret} onChange={e => form.setData('client_secret', e.target.value)} style={{ display: 'block', marginBottom: 8, padding: 6, width: 360 }} />
                <input type="url" placeholder="authorization_endpoint" value={form.data.authorization_endpoint} onChange={e => form.setData('authorization_endpoint', e.target.value)} style={{ display: 'block', marginBottom: 8, padding: 6, width: 360 }} />
                <input type="url" placeholder="token_endpoint" value={form.data.token_endpoint} onChange={e => form.setData('token_endpoint', e.target.value)} style={{ display: 'block', marginBottom: 8, padding: 6, width: 360 }} />
                <input type="url" placeholder="userinfo_endpoint" value={form.data.userinfo_endpoint} onChange={e => form.setData('userinfo_endpoint', e.target.value)} style={{ display: 'block', marginBottom: 8, padding: 6, width: 360 }} />
                <select value={form.data.userinfo_method} onChange={e => form.setData('userinfo_method', e.target.value)} style={{ marginRight: 8, padding: 6 }}>
                    <option value="GET">GET</option>
                    <option value="POST">POST</option>
                </select>
                <select value={form.data.userinfo_auth} onChange={e => form.setData('userinfo_auth', e.target.value)} style={{ marginRight: 8, padding: 6 }}>
                    <option value="bearer">bearer</option>
                    <option value="basic">basic</option>
                    <option value="query">query</option>
                </select>
                <button type="submit" disabled={form.processing}>
                    {form.processing ? 'Adding…' : 'Add'}
                </button>
            </form>
        </details>
    )
}

const VARIABLE_KEYS = [
    'colorPrimary',
    'colorBackground',
    'colorText',
    'colorTextOnPrimary',
    'colorInputBackground',
    'colorInputText',
    'colorDanger',
    'colorSuccess',
    'colorWarning',
    'colorNeutral',
    'fontFamily',
    'fontFamilyButtons',
    'fontSize',
    'borderRadius',
    'spacingUnit',
] as const

const LAYOUT_KEYS = [
    'logoImageUrl',
    'logoLinkUrl',
    'socialButtonsPlacement',
    'socialButtonsVariant',
    'showOptionalFields',
    'privacyPageUrl',
    'termsPageUrl',
    'helpPageUrl',
    'animations',
] as const

const CANONICAL_ELEMENT_KEYS = [
    'signIn.root',
    'signIn.card',
    'signIn.header',
    'signIn.title',
    'signIn.formButtonPrimary',
    'signIn.formFieldInput',
    'signIn.identifierField',
    'signIn.passwordField',
    'signIn.socialButtonsRoot',
    'signIn.socialButton',
    'signUp.root',
    'signUp.card',
    'signUp.header',
    'signUp.title',
    'signUp.formButtonPrimary',
    'signUp.formFieldInput',
    'userProfile.root',
    'userProfile.section',
    'userProfile.sectionTitle',
    'userProfile.row',
    'userButton.root',
    'userButton.avatar',
    'userButton.menu',
    'organizationProfile.root',
    'organizationProfile.section',
    'organizationProfile.sectionTitle',
    'organizationSwitcher.root',
    'organizationSwitcher.trigger',
    'organizationSwitcher.menu',
    'button.primary',
    'button.secondary',
    'button.ghost',
    'button.danger',
    'card.root',
    'dialog.overlay',
    'dialog.content',
    'dialog.title',
    'dialog.description',
    'input.root',
    'label.root',
    'field.root',
    'form.root',
    'helperText.root',
    'badge.root',
    'alert.root',
    'avatar.root',
    'tooltip.content',
] as const

type ElementDiff = {
    added: string[]
    changed: string[]
    removed: string[]
}

function diffElementMaps(before: Record<string, string>, after: Record<string, string>): ElementDiff {
    const added: string[] = []
    const changed: string[] = []
    const removed: string[] = []
    for (const k of Object.keys(after)) {
        if (!(k in before)) added.push(k)
        else if (before[k] !== after[k]) changed.push(k)
    }
    for (const k of Object.keys(before)) {
        if (!(k in after)) removed.push(k)
    }
    return { added: added.sort(), changed: changed.sort(), removed: removed.sort() }
}

/**
 * Insert a `"<key>": "",` snippet when the user presses Tab after typing a partial
 * canonical key. Pure UX nicety — survives without it. No-op when the line under
 * the caret doesn't start with a `"`.
 */
function insertSnippetOnTab(
    event: React.KeyboardEvent<HTMLTextAreaElement>,
    text: string,
    apply: (next: string, cursor: number) => void,
): void {
    if (event.key !== 'Tab' || event.shiftKey) return
    const ta = event.currentTarget
    const caret = ta.selectionStart
    if (caret !== ta.selectionEnd) return
    const before = text.slice(0, caret)
    const after = text.slice(caret)
    const lineStart = before.lastIndexOf('\n') + 1
    const currentLine = before.slice(lineStart).trimStart()
    if (!currentLine.startsWith('"')) return
    const partial = currentLine.slice(1).replace(/"[^"]*$/, '')
    const match = CANONICAL_ELEMENT_KEYS.find(k => k.startsWith(partial))
    if (!match || match === partial) return
    event.preventDefault()
    const completion = match.slice(partial.length) + '": "",'
    const cursor = caret + completion.length - 3
    apply(before + completion + after, cursor)
}

/**
 * Extract `{variable}` tokens from a translation string. Used to verify that
 * an override on `key` carries the same tokens the canonical default expects.
 */
function extractPlaceholders(value: string): string[] {
    const matches = value.match(/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/g)
    if (!matches) return []
    return Array.from(new Set(matches.map(m => m.slice(1, -1)))).sort()
}

/**
 * Locales we plan to ship after v0.6 — rendered as disabled "Coming soon"
 * chips in the Supported-locales picker so operators can see what's on deck
 * without us shipping empty catalogs.
 */
const PLANNED_LOCALES = ['it-IT', 'nl-NL', 'pl-PL', 'ru-RU', 'tr-TR', 'zh-TW']

function AppearanceSection({ appearance }: { appearance: AppearanceShape }) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const { props: pageProps } = usePage<{ flash?: { appearance_saved?: boolean } }>()

    const initialElementsObj = (appearance.elements ?? {}) as Record<string, string>
    const initial = {
        variables: { ...(appearance.variables ?? {}) } as Record<string, string>,
        elements: JSON.stringify(initialElementsObj, null, 2),
        layout: { ...((appearance.layout ?? {}) as Record<string, unknown>) },
    }
    const form = useForm(initial)

    if (!active_project || !active_environment) {
        return <p>No active environment.</p>
    }
    const base = `/${active_project.slug}/${active_environment.slug}/configure`

    const parsedElements = (() => {
        try {
            const v = JSON.parse(form.data.elements || '{}')
            return v && typeof v === 'object' && !Array.isArray(v)
                ? { ok: true as const, value: v as Record<string, string> }
                : { ok: false as const, error: 'Elements must be a JSON object.' }
        } catch (err) {
            return { ok: false as const, error: (err as Error).message }
        }
    })()

    const unknownKeys = parsedElements.ok
        ? Object.keys(parsedElements.value).filter(k => !CANONICAL_ELEMENT_KEYS.includes(k as (typeof CANONICAL_ELEMENT_KEYS)[number]))
        : []

    const diff = parsedElements.ok ? diffElementMaps(initialElementsObj, parsedElements.value) : null

    const onSubmit = (e: React.FormEvent) => {
        e.preventDefault()
        if (!parsedElements.ok) {
            form.setError('elements', parsedElements.error)
            return
        }
        form.transform(() => ({
            variables: form.data.variables,
            elements: parsedElements.value,
            layout: form.data.layout,
        })).patch(url(`${base}/appearance`), { preserveScroll: true })
    }

    return (
        <div>
            <h2>Appearance</h2>
            <p style={{ color: '#475569', marginBottom: 16 }}>
                Operator-configured visual customisation. Values flow to <code>GET /v1/environment</code>
                and into the bundled component visuals.
            </p>
            {pageProps.flash?.appearance_saved && (
                <p style={{ color: '#15803d', marginBottom: 12 }}>Appearance saved.</p>
            )}
            <form onSubmit={onSubmit}>
                <details open style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    <summary><strong>Variables</strong> (CSS design tokens)</summary>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 8, marginTop: 8 }}>
                        {VARIABLE_KEYS.map(k => (
                            <label key={k} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <span style={{ flexBasis: 180, color: '#475569' }}>{k}</span>
                                <input
                                    type={k.startsWith('color') ? 'text' : 'text'}
                                    value={form.data.variables[k] ?? ''}
                                    onChange={e => form.setData('variables', { ...form.data.variables, [k]: e.target.value })}
                                    placeholder={k.startsWith('color') ? '#0a84ff or rgb(...)' : ''}
                                    style={{ flex: 1, padding: 6 }}
                                />
                            </label>
                        ))}
                    </div>
                </details>

                <details open style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    <summary>
                        <strong>Elements</strong> (className override map, JSON) — <code>{CANONICAL_ELEMENT_KEYS.length}</code> known slots
                    </summary>
                    <p style={{ color: '#475569', fontSize: 12, marginTop: 8, marginBottom: 8 }}>
                        Type <code>"signIn.root":</code> (or any key from the list below) and an autocomplete chip will appear.
                        Unknown keys still save, but raise a warning so typos don't ship silently.
                    </p>
                    <textarea
                        value={form.data.elements}
                        onChange={e => form.setData('elements', e.target.value)}
                        onKeyDown={e => insertSnippetOnTab(e, form.data.elements, (next, cursor) => {
                            form.setData('elements', next)
                            requestAnimationFrame(() => {
                                const ta = e.currentTarget
                                ta.setSelectionRange(cursor, cursor)
                            })
                        })}
                        spellCheck={false}
                        rows={12}
                        list="canonical-element-keys"
                        style={{ width: '100%', fontFamily: 'monospace', padding: 8, marginTop: 4 }}
                    />
                    <datalist id="canonical-element-keys">
                        {CANONICAL_ELEMENT_KEYS.map(k => (<option key={k} value={k} />))}
                    </datalist>
                    {!parsedElements.ok && (
                        <p style={{ color: '#b91c1c', marginTop: 4 }}>JSON parse error: {parsedElements.error}</p>
                    )}
                    {form.errors.elements && <p style={{ color: '#b91c1c' }}>{form.errors.elements}</p>}
                    {parsedElements.ok && unknownKeys.length > 0 && (
                        <div style={{ marginTop: 8, padding: 8, background: '#fef3c7', borderRadius: 4, fontSize: 12 }}>
                            <strong>Unknown element keys</strong> (will save but may be ignored by SDKs):
                            <ul style={{ margin: '4px 0 0 16px' }}>
                                {unknownKeys.map(k => (<li key={k}><code>{k}</code></li>))}
                            </ul>
                        </div>
                    )}
                    {diff && (diff.added.length + diff.changed.length + diff.removed.length > 0) && (
                        <div style={{ marginTop: 8, padding: 8, background: '#eff6ff', borderRadius: 4, fontSize: 12 }}>
                            <strong>Pending changes:</strong>{' '}
                            <span style={{ color: '#166534' }}>+{diff.added.length}</span>{' / '}
                            <span style={{ color: '#92400e' }}>~{diff.changed.length}</span>{' / '}
                            <span style={{ color: '#991b1b' }}>-{diff.removed.length}</span>
                            <details style={{ marginTop: 4 }}>
                                <summary>Show diff</summary>
                                <ul style={{ margin: '4px 0 0 16px', listStyle: 'none', paddingLeft: 0 }}>
                                    {diff.added.map(k => (<li key={'a-' + k} style={{ color: '#166534' }}>+ {k}: <code>{parsedElements.value[k]}</code></li>))}
                                    {diff.changed.map(k => (
                                        <li key={'c-' + k} style={{ color: '#92400e' }}>
                                            ~ {k}: <code>{initialElementsObj[k]}</code> → <code>{parsedElements.value[k]}</code>
                                        </li>
                                    ))}
                                    {diff.removed.map(k => (<li key={'r-' + k} style={{ color: '#991b1b' }}>- {k}: <code>{initialElementsObj[k]}</code></li>))}
                                </ul>
                            </details>
                        </div>
                    )}
                </details>

                <details style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    <summary><strong>Layout</strong></summary>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 8, marginTop: 8 }}>
                        {LAYOUT_KEYS.map(k => {
                            const isBool = k === 'showOptionalFields' || k === 'animations'
                            const isSelect = k === 'socialButtonsPlacement' || k === 'socialButtonsVariant'
                            const value = form.data.layout[k]
                            if (isBool) {
                                return (
                                    <label key={k} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                        <input
                                            type="checkbox"
                                            checked={Boolean(value)}
                                            onChange={e => form.setData('layout', { ...form.data.layout, [k]: e.target.checked })}
                                        />
                                        <span>{k}</span>
                                    </label>
                                )
                            }
                            if (isSelect && k === 'socialButtonsPlacement') {
                                return (
                                    <label key={k} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                        <span style={{ flexBasis: 180, color: '#475569' }}>{k}</span>
                                        <select
                                            value={typeof value === 'string' ? value : ''}
                                            onChange={e => form.setData('layout', { ...form.data.layout, [k]: e.target.value })}
                                            style={{ flex: 1, padding: 6 }}
                                        >
                                            <option value="">(default)</option>
                                            <option value="top">top</option>
                                            <option value="bottom">bottom</option>
                                        </select>
                                    </label>
                                )
                            }
                            if (isSelect && k === 'socialButtonsVariant') {
                                return (
                                    <label key={k} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                        <span style={{ flexBasis: 180, color: '#475569' }}>{k}</span>
                                        <select
                                            value={typeof value === 'string' ? value : ''}
                                            onChange={e => form.setData('layout', { ...form.data.layout, [k]: e.target.value })}
                                            style={{ flex: 1, padding: 6 }}
                                        >
                                            <option value="">(default)</option>
                                            <option value="iconButton">iconButton</option>
                                            <option value="blockButton">blockButton</option>
                                        </select>
                                    </label>
                                )
                            }
                            return (
                                <label key={k} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                    <span style={{ flexBasis: 180, color: '#475569' }}>{k}</span>
                                    <input
                                        type={k.endsWith('Url') ? 'url' : 'text'}
                                        value={typeof value === 'string' ? value : ''}
                                        onChange={e => form.setData('layout', { ...form.data.layout, [k]: e.target.value })}
                                        style={{ flex: 1, padding: 6 }}
                                    />
                                </label>
                            )
                        })}
                    </div>
                </details>

                <button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save appearance'}
                </button>
            </form>
        </div>
    )
}

function LocalizationSection({
    localization,
    canonical,
}: {
    localization: LocalizationShape
    canonical: LocalizationCanonical
}) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const { props: pageProps } = usePage<{ flash?: { localization_saved?: boolean } }>()

    const form = useForm({
        default_locale: localization.default_locale,
        fallback_locale: localization.fallback_locale,
        supported_locales: [...localization.supported_locales],
        overrides: { ...localization.overrides } as Record<string, Record<string, string>>,
    })

    if (!active_project || !active_environment) {
        return <p>No active environment.</p>
    }
    const base = `/${active_project.slug}/${active_environment.slug}/configure`

    const toggleSupported = (locale: string) => {
        const has = form.data.supported_locales.includes(locale)
        const next = has
            ? form.data.supported_locales.filter(l => l !== locale)
            : [...form.data.supported_locales, locale]
        form.setData('supported_locales', next)
    }

    const setOverride = (locale: string, key: string, value: string) => {
        const localeOverrides = { ...(form.data.overrides[locale] ?? {}) }
        if (value === '') {
            delete localeOverrides[key]
        } else {
            localeOverrides[key] = value
        }
        form.setData('overrides', { ...form.data.overrides, [locale]: localeOverrides })
    }

    /**
     * Compute per-(locale, key) placeholder warnings: any locale override that
     * drops a `{variable}` token the canonical en-US default requires is flagged.
     * Returns `Map<locale, Map<key, missingTokens[]>>`.
     */
    const placeholderWarnings = (() => {
        const expectedByKey: Record<string, string[]> = {}
        for (const k of canonical.keys) {
            expectedByKey[k] = extractPlaceholders(canonical.en_us_catalog[k] ?? '')
        }
        const map: Record<string, Record<string, string[]>> = {}
        for (const [locale, perKey] of Object.entries(form.data.overrides)) {
            for (const [key, override] of Object.entries(perKey)) {
                const expected = expectedByKey[key] ?? []
                if (expected.length === 0) continue
                const got = extractPlaceholders(String(override))
                const missing = expected.filter(t => !got.includes(t))
                if (missing.length > 0) {
                    map[locale] ??= {}
                    map[locale][key] = missing
                }
            }
        }
        return map
    })()

    const placeholderWarningCount = Object.values(placeholderWarnings)
        .reduce((acc, perKey) => acc + Object.keys(perKey).length, 0)

    /**
     * Diff against the persisted localization shape: counts of locales toggled
     * on/off plus override cells added / changed / removed across every locale.
     */
    const localizationDiff = (() => {
        const before = localization.overrides as Record<string, Record<string, string>>
        const after = form.data.overrides
        let added = 0, changed = 0, removed = 0
        const allLocales = new Set([...Object.keys(before), ...Object.keys(after)])
        for (const locale of allLocales) {
            const b = before[locale] ?? {}
            const a = after[locale] ?? {}
            for (const k of Object.keys(a)) {
                if (!(k in b)) added++
                else if (b[k] !== a[k]) changed++
            }
            for (const k of Object.keys(b)) {
                if (!(k in a)) removed++
            }
        }
        const localeAdds = form.data.supported_locales.filter(l => !localization.supported_locales.includes(l))
        const localeRemoves = localization.supported_locales.filter(l => !form.data.supported_locales.includes(l))
        return { added, changed, removed, localeAdds, localeRemoves }
    })()

    const onSubmit = (e: React.FormEvent) => {
        e.preventDefault()
        form.patch(url(`${base}/localization`), { preserveScroll: true })
    }

    return (
        <div>
            <h2>Localization</h2>
            <p style={{ color: '#475569', marginBottom: 16 }}>
                Per-environment locale set + operator string overrides. Defaults ship with the SDK;
                this surface stores overrides only.
            </p>
            {pageProps.flash?.localization_saved && (
                <p style={{ color: '#15803d', marginBottom: 12 }}>Localization saved.</p>
            )}
            <form onSubmit={onSubmit}>
                <div style={{ marginBottom: 16, display: 'flex', gap: 24 }}>
                    <label>
                        <strong>Default locale</strong>
                        <select
                            value={form.data.default_locale}
                            onChange={e => form.setData('default_locale', e.target.value)}
                            style={{ display: 'block', marginTop: 4, padding: 6, minWidth: 160 }}
                        >
                            {form.data.supported_locales.map(l => (
                                <option key={l} value={l}>{l}</option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <strong>Fallback locale</strong>
                        <select
                            value={form.data.fallback_locale}
                            onChange={e => form.setData('fallback_locale', e.target.value)}
                            style={{ display: 'block', marginTop: 4, padding: 6, minWidth: 160 }}
                        >
                            {form.data.supported_locales.map(l => (
                                <option key={l} value={l}>{l}</option>
                            ))}
                        </select>
                    </label>
                </div>

                <details open style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    <summary><strong>Supported locales</strong></summary>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12, marginTop: 8 }}>
                        {canonical.shipped_locales.map(l => (
                            <label key={l} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                <input
                                    type="checkbox"
                                    checked={form.data.supported_locales.includes(l)}
                                    onChange={() => toggleSupported(l)}
                                />
                                <span>{l}</span>
                            </label>
                        ))}
                        {PLANNED_LOCALES.filter(l => !canonical.shipped_locales.includes(l)).map(l => (
                            <span
                                key={l}
                                title="Translation catalog ships in a future release."
                                style={{ display: 'inline-flex', alignItems: 'center', gap: 4, color: '#94a3b8', cursor: 'not-allowed' }}
                            >
                                {l}
                                <span style={{
                                    fontSize: 10,
                                    padding: '1px 6px',
                                    borderRadius: 10,
                                    background: '#f1f5f9',
                                    color: '#64748b',
                                    textTransform: 'uppercase',
                                    letterSpacing: 0.4,
                                }}>
                                    Coming soon
                                </span>
                            </span>
                        ))}
                    </div>
                </details>

                <details style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    <summary><strong>Overrides</strong> ({canonical.keys.length} canonical keys; only filled cells are persisted)</summary>
                    {form.errors.overrides && <p style={{ color: '#b91c1c' }}>{form.errors.overrides}</p>}
                    <div style={{ marginTop: 8, maxHeight: 600, overflow: 'auto' }}>
                        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                            <thead>
                                <tr>
                                    <th style={{ textAlign: 'left', padding: 6, borderBottom: '1px solid #e5e7eb', position: 'sticky', top: 0, background: '#fff' }}>Key</th>
                                    <th style={{ textAlign: 'left', padding: 6, borderBottom: '1px solid #e5e7eb', position: 'sticky', top: 0, background: '#fff' }}>en-US (default)</th>
                                    {form.data.supported_locales.filter(l => l !== 'en-US').map(l => (
                                        <th key={l} style={{ textAlign: 'left', padding: 6, borderBottom: '1px solid #e5e7eb', position: 'sticky', top: 0, background: '#fff' }}>{l}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {canonical.keys.map(key => (
                                    <tr key={key}>
                                        <td style={{ padding: 6, fontFamily: 'monospace', color: '#475569', verticalAlign: 'top' }}>{key}</td>
                                        <td style={{ padding: 6, color: '#0f172a', verticalAlign: 'top' }}>
                                            {canonical.en_us_catalog[key]}
                                        </td>
                                        {form.data.supported_locales.filter(l => l !== 'en-US').map(l => {
                                            const missing = placeholderWarnings[l]?.[key]
                                            const hasMissing = (missing?.length ?? 0) > 0
                                            return (
                                                <td key={l} style={{ padding: 6, verticalAlign: 'top' }}>
                                                    <input
                                                        type="text"
                                                        value={form.data.overrides[l]?.[key] ?? ''}
                                                        onChange={e => setOverride(l, key, e.target.value)}
                                                        style={{
                                                            width: '100%',
                                                            padding: 4,
                                                            border: hasMissing ? '1px solid #f59e0b' : '1px solid #d1d5db',
                                                            background: hasMissing ? '#fffbeb' : '#fff',
                                                        }}
                                                        title={hasMissing ? `Missing placeholder tokens: ${missing!.map(m => '{' + m + '}').join(', ')}` : undefined}
                                                    />
                                                    {hasMissing && (
                                                        <p style={{ color: '#b45309', fontSize: 11, margin: '2px 0 0' }}>
                                                            Missing: {missing!.map(m => `{${m}}`).join(', ')}
                                                        </p>
                                                    )}
                                                </td>
                                            )
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </details>

                {(localizationDiff.added + localizationDiff.changed + localizationDiff.removed > 0
                    || localizationDiff.localeAdds.length + localizationDiff.localeRemoves.length > 0) && (
                    <div style={{ marginBottom: 12, padding: 8, background: '#eff6ff', borderRadius: 4, fontSize: 12 }}>
                        <strong>Pending changes:</strong>{' '}
                        <span style={{ color: '#166534' }}>+{localizationDiff.added}</span>{' / '}
                        <span style={{ color: '#92400e' }}>~{localizationDiff.changed}</span>{' / '}
                        <span style={{ color: '#991b1b' }}>-{localizationDiff.removed}</span>
                        {(localizationDiff.localeAdds.length > 0 || localizationDiff.localeRemoves.length > 0) && (
                            <>
                                {' · '}
                                {localizationDiff.localeAdds.length > 0 && (
                                    <span style={{ color: '#166534' }}>+locales: {localizationDiff.localeAdds.join(', ')}</span>
                                )}
                                {localizationDiff.localeAdds.length > 0 && localizationDiff.localeRemoves.length > 0 && '; '}
                                {localizationDiff.localeRemoves.length > 0 && (
                                    <span style={{ color: '#991b1b' }}>-locales: {localizationDiff.localeRemoves.join(', ')}</span>
                                )}
                            </>
                        )}
                    </div>
                )}
                {placeholderWarningCount > 0 && (
                    <p style={{ color: '#b45309', fontSize: 12, marginBottom: 8 }}>
                        {placeholderWarningCount} placeholder warning{placeholderWarningCount === 1 ? '' : 's'}.{' '}
                        Overrides that drop a <code>{'{variable}'}</code> token from the canonical string will still save,
                        but the rendered string may show a literal <code>{'{variable}'}</code>.
                    </p>
                )}
                <button type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save localization'}
                </button>
            </form>
        </div>
    )
}
