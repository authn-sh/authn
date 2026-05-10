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

type Props = {
    section: string
    user_settings: Record<string, unknown>
    allowed_origins: string[]
    appearance: Record<string, unknown>
    signup_mode: string
    multi_factor: MultiFactorSettings
    attributes: AttributesSettings
    sms: SmsSettings
    sms_templates: SmsTemplate[]
    oauth_providers: OauthProvider[]
    oauth_preset_keys: string[]
}

const SECTIONS = [
    { slug: 'attributes', label: 'Attributes' },
    { slug: 'multi-factor', label: 'Multi-factor' },
    { slug: 'sms', label: 'SMS' },
    { slug: 'social-providers', label: 'Social providers' },
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
            {!['attributes', 'multi-factor', 'sms', 'social-providers'].includes(props.section) && (
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
