import { useForm, usePage } from '@inertiajs/react'
import * as React from 'react'
import { Button, Input } from '@authn-sh/ui'
import { AuthenticationConfigurePage } from '../../../components/AuthenticationConfigurePage'
import { useDashboard, useDashboardUrl } from '../../../shared'

type EnterpriseConnection = {
    id: string
    protocol: 'saml' | 'oidc'
    name: string
    enabled: boolean
    organization_id: string | null
    domains: string[]
    default_role: string | null
    saml_idp_entity_id: string | null
    saml_sso_url: string | null
    saml_signing_algorithm: string | null
    oidc_issuer: string | null
    oidc_client_id: string | null
    oidc_scopes: string[]
    linked_accounts_count: number
    created_at: number | null
}

type Props = { enterprise_connections: EnterpriseConnection[] }

export default function EnterpriseSso({ enterprise_connections }: Props) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const { props: pageProps } = usePage<{ flash?: {
        enterprise_connection_saved?: boolean
        enterprise_connection_deleted?: boolean
    } }>()

    if (!active_project || !active_environment) {
        return <p>No active environment.</p>
    }
    const base = `/${active_project.slug}/${active_environment.slug}/configure`

    const instanceConnections = enterprise_connections.filter((c) => c.organization_id === null)
    const orgConnections = enterprise_connections.filter((c) => c.organization_id !== null)

    return (
        <AuthenticationConfigurePage active="enterprise-sso">
            <p style={{ color: '#475569', marginBottom: 16 }}>
                SAML + OIDC connections shared by every org in this environment.
                Per-org connections live in <code>&lt;OrganizationProfile /&gt;</code> on the embedded SDK side.
            </p>

            {pageProps.flash?.enterprise_connection_saved && (
                <p style={{ color: '#15803d', marginBottom: 12 }}>Connection saved.</p>
            )}
            {pageProps.flash?.enterprise_connection_deleted && (
                <p style={{ color: '#15803d', marginBottom: 12 }}>Connection deleted.</p>
            )}

            <h3 style={{ marginTop: 12 }}>Instance-wide connections</h3>
            {instanceConnections.length === 0 ? (
                <p style={{ color: '#64748b' }}>No instance-wide connections yet.</p>
            ) : (
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, marginBottom: 16 }}>
                    <thead>
                        <tr style={{ borderBottom: '1px solid #e5e7eb' }}>
                            <th style={{ textAlign: 'left', padding: 6 }}>Name</th>
                            <th style={{ textAlign: 'left', padding: 6 }}>Protocol</th>
                            <th style={{ textAlign: 'left', padding: 6 }}>Domains</th>
                            <th style={{ textAlign: 'left', padding: 6 }}>Linked</th>
                            <th style={{ textAlign: 'left', padding: 6 }}>Enabled</th>
                            <th style={{ textAlign: 'right', padding: 6 }}></th>
                        </tr>
                    </thead>
                    <tbody>
                        {instanceConnections.map((c) => (
                            <tr key={c.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                <td style={{ padding: 6 }}>{c.name}</td>
                                <td style={{ padding: 6 }}><code>{c.protocol}</code></td>
                                <td style={{ padding: 6, color: '#475569' }}>{c.domains.join(', ') || '—'}</td>
                                <td style={{ padding: 6 }}>{c.linked_accounts_count}</td>
                                <td style={{ padding: 6 }}>{c.enabled ? 'Yes' : 'No'}</td>
                                <td style={{ padding: 6, textAlign: 'right' }}>
                                    <DeleteConnectionButton id={c.id} action={url(`${base}/enterprise-connections/${c.id}`)} disabled={c.linked_accounts_count > 0} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            <h3 style={{ marginTop: 24 }}>Org-scoped connections</h3>
            {orgConnections.length === 0 ? (
                <p style={{ color: '#64748b' }}>
                    No org-scoped connections. Org admins configure these in <code>&lt;OrganizationProfile /&gt;</code>.
                </p>
            ) : (
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, marginBottom: 16 }}>
                    <thead>
                        <tr style={{ borderBottom: '1px solid #e5e7eb' }}>
                            <th style={{ textAlign: 'left', padding: 6 }}>Name</th>
                            <th style={{ textAlign: 'left', padding: 6 }}>Org</th>
                            <th style={{ textAlign: 'left', padding: 6 }}>Protocol</th>
                            <th style={{ textAlign: 'left', padding: 6 }}>Linked</th>
                            <th style={{ textAlign: 'left', padding: 6 }}>Enabled</th>
                        </tr>
                    </thead>
                    <tbody>
                        {orgConnections.map((c) => (
                            <tr key={c.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                <td style={{ padding: 6 }}>{c.name}</td>
                                <td style={{ padding: 6, fontFamily: 'monospace', color: '#475569' }}>{c.organization_id}</td>
                                <td style={{ padding: 6 }}><code>{c.protocol}</code></td>
                                <td style={{ padding: 6 }}>{c.linked_accounts_count}</td>
                                <td style={{ padding: 6 }}>{c.enabled ? 'Yes' : 'No'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            <details open style={{ marginTop: 24, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                <summary><strong>Add an instance-wide connection</strong></summary>
                <AddEnterpriseConnectionForm action={url(`${base}/enterprise-connections`)} />
            </details>
        </AuthenticationConfigurePage>
    )
}

function AddEnterpriseConnectionForm({ action }: { action: string }) {
    const form = useForm<{
        protocol: 'saml' | 'oidc'
        name: string
        domains_csv: string
        default_role: string
        enabled: boolean
        saml_idp_entity_id: string
        saml_sso_url: string
        saml_idp_certificate: string
        saml_signing_algorithm: 'RSA_SHA256' | 'RSA_SHA384' | 'RSA_SHA512'
        oidc_issuer: string
        oidc_client_id: string
        oidc_client_secret: string
        oidc_scopes_csv: string
    }>({
        protocol: 'saml',
        name: '',
        domains_csv: '',
        default_role: 'org:member',
        enabled: true,
        saml_idp_entity_id: '',
        saml_sso_url: '',
        saml_idp_certificate: '',
        saml_signing_algorithm: 'RSA_SHA256',
        oidc_issuer: '',
        oidc_client_id: '',
        oidc_client_secret: '',
        oidc_scopes_csv: 'openid,email,profile',
    })

    const onSubmit = (e: React.FormEvent) => {
        e.preventDefault()
        const payload: Record<string, unknown> = {
            protocol: form.data.protocol,
            name: form.data.name,
            domains: form.data.domains_csv.split(',').map((s) => s.trim()).filter(Boolean),
            default_role: form.data.default_role || null,
            enabled: form.data.enabled,
        }
        if (form.data.protocol === 'saml') {
            Object.assign(payload, {
                saml_idp_entity_id: form.data.saml_idp_entity_id,
                saml_sso_url: form.data.saml_sso_url,
                saml_idp_certificate: form.data.saml_idp_certificate,
                saml_signing_algorithm: form.data.saml_signing_algorithm,
            })
        } else {
            Object.assign(payload, {
                oidc_issuer: form.data.oidc_issuer,
                oidc_client_id: form.data.oidc_client_id,
                oidc_client_secret: form.data.oidc_client_secret,
                oidc_scopes: form.data.oidc_scopes_csv.split(',').map((s) => s.trim()).filter(Boolean),
            })
        }
        form.transform(() => payload).post(action, { preserveScroll: true })
    }

    return (
        <form onSubmit={onSubmit} style={{ marginTop: 8 }}>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 12, marginBottom: 12 }}>
                <label>
                    <strong>Protocol</strong>
                    <select
                        value={form.data.protocol}
                        onChange={(e) => form.setData('protocol', e.target.value as 'saml' | 'oidc')}
                        style={{ display: 'block', marginTop: 4, padding: 6, minWidth: 160 }}
                    >
                        <option value="saml">SAML 2.0</option>
                        <option value="oidc">OIDC</option>
                    </select>
                </label>
                <label>
                    <strong>Name</strong>
                    <Input
                        type="text"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        placeholder="Acme — Okta"
                        style={{ display: 'block', marginTop: 4, padding: 6, width: '100%' }}
                    />
                </label>
                <label>
                    <strong>Domains (comma-separated)</strong>
                    <Input
                        type="text"
                        value={form.data.domains_csv}
                        onChange={(e) => form.setData('domains_csv', e.target.value)}
                        placeholder="acme.com, corp.acme.com"
                        style={{ display: 'block', marginTop: 4, padding: 6, width: '100%' }}
                    />
                </label>
                <label>
                    <strong>Default org role</strong>
                    <Input
                        type="text"
                        value={form.data.default_role}
                        onChange={(e) => form.setData('default_role', e.target.value)}
                        placeholder="org:member"
                        style={{ display: 'block', marginTop: 4, padding: 6, width: '100%' }}
                    />
                </label>
            </div>

            {form.data.protocol === 'saml' ? (
                <section className="authn-section">
                    <h3 className="authn-section-title">SAML</h3>
                    <div className="authn-section-body">
                        <label style={{ display: 'block', marginBottom: 8 }}>
                            IdP entity ID
                            <Input
                                type="text"
                                value={form.data.saml_idp_entity_id}
                                onChange={(e) => form.setData('saml_idp_entity_id', e.target.value)}
                                style={{ display: 'block', marginTop: 4, padding: 6, width: '100%' }}
                            />
                        </label>
                        <label style={{ display: 'block', marginBottom: 8 }}>
                            SSO URL
                            <Input
                                type="url"
                                value={form.data.saml_sso_url}
                                onChange={(e) => form.setData('saml_sso_url', e.target.value)}
                                style={{ display: 'block', marginTop: 4, padding: 6, width: '100%' }}
                            />
                        </label>
                        <label style={{ display: 'block', marginBottom: 8 }}>
                            IdP X.509 certificate
                            <textarea
                                value={form.data.saml_idp_certificate}
                                onChange={(e) => form.setData('saml_idp_certificate', e.target.value)}
                                rows={6}
                                placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"
                                style={{ display: 'block', marginTop: 4, padding: 6, width: '100%', fontFamily: 'monospace' }}
                            />
                        </label>
                        <label>
                            Signing algorithm
                            <select
                                value={form.data.saml_signing_algorithm}
                                onChange={(e) => form.setData('saml_signing_algorithm', e.target.value as 'RSA_SHA256' | 'RSA_SHA384' | 'RSA_SHA512')}
                                style={{ display: 'block', marginTop: 4, padding: 6, minWidth: 200 }}
                            >
                                <option value="RSA_SHA256">RSA_SHA256</option>
                                <option value="RSA_SHA384">RSA_SHA384</option>
                                <option value="RSA_SHA512">RSA_SHA512</option>
                            </select>
                        </label>
                    </div>
                </section>
            ) : (
                <section className="authn-section">
                    <h3 className="authn-section-title">OIDC</h3>
                    <div className="authn-section-body">
                        <label style={{ display: 'block', marginBottom: 8 }}>
                            Issuer URL
                            <Input
                                type="url"
                                value={form.data.oidc_issuer}
                                onChange={(e) => form.setData('oidc_issuer', e.target.value)}
                                placeholder="https://idp.acme.com"
                                style={{ display: 'block', marginTop: 4, padding: 6, width: '100%' }}
                            />
                        </label>
                        <label style={{ display: 'block', marginBottom: 8 }}>
                            Client ID
                            <Input
                                type="text"
                                value={form.data.oidc_client_id}
                                onChange={(e) => form.setData('oidc_client_id', e.target.value)}
                                style={{ display: 'block', marginTop: 4, padding: 6, width: '100%' }}
                            />
                        </label>
                        <label style={{ display: 'block', marginBottom: 8 }}>
                            Client secret
                            <Input
                                type="password"
                                value={form.data.oidc_client_secret}
                                onChange={(e) => form.setData('oidc_client_secret', e.target.value)}
                                style={{ display: 'block', marginTop: 4, padding: 6, width: '100%' }}
                            />
                        </label>
                        <label>
                            Scopes (comma-separated)
                            <Input
                                type="text"
                                value={form.data.oidc_scopes_csv}
                                onChange={(e) => form.setData('oidc_scopes_csv', e.target.value)}
                                style={{ display: 'block', marginTop: 4, padding: 6, width: '100%' }}
                            />
                        </label>
                    </div>
                </section>
            )}

            <label style={{ display: 'block', marginBottom: 12 }}>
                <input
                    type="checkbox"
                    checked={form.data.enabled}
                    onChange={(e) => form.setData('enabled', e.target.checked)}
                    style={{ marginRight: 6 }}
                />
                Enabled
            </label>

            <Button type="submit" loading={form.processing}>
                {form.processing ? 'Saving…' : 'Add connection'}
            </Button>
        </form>
    )
}

function DeleteConnectionButton({ id, action, disabled }: { id: string; action: string; disabled: boolean }) {
    const form = useForm({})
    const onDelete = (e: React.MouseEvent) => {
        e.preventDefault()
        if (disabled) return
        if (!confirm(`Delete connection ${id}?`)) return
        form.delete(action, { preserveScroll: true })
    }

    return (
        <Button
            type="button"
            onClick={onDelete}
            loading={disabled || form.processing}
            title={disabled ? 'Has linked accounts — unlink users first.' : undefined}
            style={{ padding: '4px 10px', background: disabled ? '#e5e7eb' : '#fee2e2', color: disabled ? '#94a3b8' : '#b91c1c', border: 'none', borderRadius: 4, cursor: disabled ? 'not-allowed' : 'pointer' }}
        >
            Delete
        </Button>
    )
}
