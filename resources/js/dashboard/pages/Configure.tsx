import { Link, useForm, usePage } from '@inertiajs/react'
import { useDashboard, useDashboardUrl } from '../shared'

type MultiFactorSettings = {
    totp: { enabled: boolean }
    backup_codes: { enabled: boolean; default_count: number }
}

type Props = {
    section: string
    user_settings: Record<string, unknown>
    allowed_origins: string[]
    appearance: Record<string, unknown>
    signup_mode: string
    multi_factor: MultiFactorSettings
}

const SECTIONS = [
    { slug: 'attributes', label: 'Attributes' },
    { slug: 'multi-factor', label: 'Multi-factor' },
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
            {props.section === 'multi-factor'
                ? <MultiFactorSection multiFactor={props.multi_factor} />
                : <AttributesPlaceholder section={props.section} userSettings={props.user_settings} signupMode={props.signup_mode} />}
        </div>
    )
}

function AttributesPlaceholder({ section, userSettings, signupMode }: { section: string; userSettings: Record<string, unknown>; signupMode: string }) {
    return (
        <>
            <h2>{section}</h2>
            <p>Sign-up mode: <strong>{signupMode}</strong></p>
            <pre style={{ background: '#f1f5f9', padding: 12, borderRadius: 6 }}>
                {JSON.stringify(userSettings, null, 2)}
            </pre>
        </>
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
