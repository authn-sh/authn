import { useForm, usePage } from '@inertiajs/react'
import * as React from 'react'
import { Button, Input } from '@authn-sh/ui'
import { AuthenticationConfigurePage } from '../../../components/AuthenticationConfigurePage'
import { useDashboard, useDashboardUrl } from '../../../shared'

type JwtTemplateRow = {
    id: string
    name: string
    claims: Record<string, unknown>
    lifetime: number
    allowed_clock_skew: number
    signing_algorithm: 'RS256' | 'ES256' | 'HS256'
    has_custom_signing_key: boolean
    last_used_at: number | null
    created_at: number | null
}

type Props = { jwt_templates: JwtTemplateRow[] }

export default function JwtTemplates({ jwt_templates }: Props) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const { props: pageProps } = usePage<{ flash?: { jwt_template_saved?: boolean; jwt_template_deleted?: boolean } }>()
    const [adding, setAdding] = React.useState(false)
    const [editing, setEditing] = React.useState<string | null>(null)

    if (!active_project || !active_environment) {
        return <p>No active environment.</p>
    }
    const base = `/${active_project.slug}/${active_environment.slug}/configure`

    return (
        <AuthenticationConfigurePage active="jwt-templates">
            <p style={{ color: '#475569', marginBottom: 16 }}>
                Named JWT shapes for <code>Session.getToken({'{template}'})</code>. Each template renders
                Liquid-style <code>{'{{user.id}}'}</code> placeholders against the active user / session / org.
            </p>
            {pageProps.flash?.jwt_template_saved && (
                <p style={{ color: '#15803d', marginBottom: 12 }}>Template saved.</p>
            )}
            {pageProps.flash?.jwt_template_deleted && (
                <p style={{ color: '#15803d', marginBottom: 12 }}>Template deleted.</p>
            )}

            {jwt_templates.length === 0 ? (
                <p style={{ color: '#64748b' }}>No JWT templates yet.</p>
            ) : (
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, marginBottom: 16 }}>
                    <thead>
                        <tr style={{ borderBottom: '1px solid #e5e7eb' }}>
                            <th style={{ textAlign: 'left', padding: 6 }}>Name</th>
                            <th style={{ textAlign: 'left', padding: 6 }}>Algorithm</th>
                            <th style={{ textAlign: 'left', padding: 6 }}>Lifetime</th>
                            <th style={{ textAlign: 'left', padding: 6 }}>Last used</th>
                            <th style={{ textAlign: 'right', padding: 6 }}></th>
                        </tr>
                    </thead>
                    <tbody>
                        {jwt_templates.map((t) => (
                            <tr key={t.id} style={{ borderBottom: '1px solid #f1f5f9' }}>
                                <td style={{ padding: 6 }}><code>{t.name}</code></td>
                                <td style={{ padding: 6 }}>{t.signing_algorithm}{t.has_custom_signing_key && ' (custom key)'}</td>
                                <td style={{ padding: 6 }}>{t.lifetime}s</td>
                                <td style={{ padding: 6, color: '#475569' }}>{t.last_used_at ? new Date(t.last_used_at).toISOString() : '—'}</td>
                                <td style={{ padding: 6, textAlign: 'right' }}>
                                    <Button type="button" onClick={() => setEditing(editing === t.id ? null : t.id)} style={{ marginRight: 6 }}>
                                        {editing === t.id ? 'Close' : 'Edit'}
                                    </Button>
                                    <DeleteJwtTemplateButton id={t.id} action={url(`${base}/jwt-templates/${t.id}`)} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            {editing && (
                <JwtTemplateForm
                    template={jwt_templates.find((t) => t.id === editing) ?? null}
                    saveUrl={url(`${base}/jwt-templates/${editing}`)}
                    onDone={() => setEditing(null)}
                />
            )}

            {adding ? (
                <JwtTemplateForm
                    template={null}
                    saveUrl={url(`${base}/jwt-templates`)}
                    onDone={() => setAdding(false)}
                />
            ) : (
                <Button variant="secondary" type="button" onClick={() => setAdding(true)} style={{ marginTop: 8 }}>
                    Add JWT template
                </Button>
            )}
        </AuthenticationConfigurePage>
    )
}

function JwtTemplateForm({ template, saveUrl, onDone }: { template: JwtTemplateRow | null; saveUrl: string; onDone: () => void }) {
    const isEdit = template !== null
    const form = useForm({
        name: template?.name ?? '',
        claims: JSON.stringify(template?.claims ?? { sub: '{{user.id}}' }, null, 2),
        lifetime: template?.lifetime ?? 60,
        allowed_clock_skew: template?.allowed_clock_skew ?? 5,
        signing_algorithm: template?.signing_algorithm ?? 'RS256',
        custom_signing_key: '',
    })
    const [parseError, setParseError] = React.useState<string | null>(null)

    const onSubmit = (e: React.FormEvent) => {
        e.preventDefault()
        let parsedClaims: Record<string, unknown>
        try {
            const v = JSON.parse(form.data.claims || '{}')
            if (typeof v !== 'object' || v === null || Array.isArray(v)) {
                throw new Error('claims must be a JSON object.')
            }
            parsedClaims = v
        } catch (err) {
            setParseError((err as Error).message)
            return
        }
        setParseError(null)
        const payload = form.transform(() => ({
            name: form.data.name,
            claims: parsedClaims,
            lifetime: Number(form.data.lifetime) || 60,
            allowed_clock_skew: Number(form.data.allowed_clock_skew) || 5,
            signing_algorithm: form.data.signing_algorithm,
            custom_signing_key: form.data.custom_signing_key === '' ? null : form.data.custom_signing_key,
        }))
        if (isEdit) {
            payload.patch(saveUrl, { preserveScroll: true, onSuccess: onDone })
        } else {
            payload.post(saveUrl, { preserveScroll: true, onSuccess: onDone })
        }
    }

    return (
        <form onSubmit={onSubmit} style={{ marginTop: 8, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
            <h3 style={{ marginTop: 0 }}>{isEdit ? 'Edit JWT template' : 'New JWT template'}</h3>
            <label style={{ display: 'block', marginBottom: 8 }}>
                <span style={{ display: 'block', color: '#475569' }}>Name (slug)</span>
                <Input
                    type="text"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    placeholder="supabase"
                    loading={isEdit}
                    style={{ width: '100%', padding: 6 }}
                />
                {form.errors.name && <p style={{ color: '#b91c1c' }}>{form.errors.name}</p>}
            </label>

            <label style={{ display: 'block', marginBottom: 8 }}>
                <span style={{ display: 'block', color: '#475569' }}>Claims (JSON with {`{{user.id}}`}-style placeholders)</span>
                <textarea
                    value={form.data.claims}
                    onChange={(e) => form.setData('claims', e.target.value)}
                    rows={10}
                    style={{ width: '100%', fontFamily: 'monospace', padding: 6 }}
                />
                {parseError && <p style={{ color: '#b91c1c' }}>{parseError}</p>}
                {form.errors.claims && <p style={{ color: '#b91c1c' }}>{form.errors.claims}</p>}
            </label>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 12, marginBottom: 8 }}>
                <label>
                    <span style={{ display: 'block', color: '#475569' }}>Lifetime (s)</span>
                    <Input type="number" min={1} max={86400} value={form.data.lifetime} onChange={(e) => form.setData('lifetime', Number(e.target.value))} style={{ width: '100%', padding: 6 }} />
                </label>
                <label>
                    <span style={{ display: 'block', color: '#475569' }}>Allowed clock skew (s)</span>
                    <Input type="number" min={0} max={300} value={form.data.allowed_clock_skew} onChange={(e) => form.setData('allowed_clock_skew', Number(e.target.value))} style={{ width: '100%', padding: 6 }} />
                </label>
                <label>
                    <span style={{ display: 'block', color: '#475569' }}>Algorithm</span>
                    <select
                        value={form.data.signing_algorithm}
                        onChange={(e) => form.setData('signing_algorithm', e.target.value as JwtTemplateRow['signing_algorithm'])}
                        disabled={isEdit}
                        style={{ width: '100%', padding: 6 }}
                    >
                        <option value="RS256">RS256</option>
                        <option value="ES256">ES256</option>
                        <option value="HS256">HS256</option>
                    </select>
                </label>
            </div>

            <label style={{ display: 'block', marginBottom: 8 }}>
                <span style={{ display: 'block', color: '#475569' }}>
                    Custom signing key (PEM / base64 secret — leave blank to use the env signing key)
                </span>
                <textarea
                    value={form.data.custom_signing_key}
                    onChange={(e) => form.setData('custom_signing_key', e.target.value)}
                    rows={4}
                    placeholder="-----BEGIN PRIVATE KEY-----..."
                    style={{ width: '100%', fontFamily: 'monospace', padding: 6 }}
                />
            </label>

            <div style={{ display: 'flex', gap: 8 }}>
                <Button variant="secondary" type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : isEdit ? 'Save changes' : 'Create template'}
                </Button>
                <Button type="button" onClick={onDone}>Cancel</Button>
            </div>
        </form>
    )
}

function DeleteJwtTemplateButton({ id: _id, action }: { id: string; action: string }) {
    const form = useForm({})
    const onDelete = () => {
        if (!window.confirm('Delete this JWT template? Refused if it was used to mint a token within the grace window (40h).')) return
        form.delete(action, { preserveScroll: true })
    }

    return (
        <Button variant="secondary"
            type="button"
            onClick={onDelete}
            loading={form.processing}
            style={{ padding: '4px 10px', background: '#fee2e2', color: '#b91c1c', border: 'none', borderRadius: 4 }}
        >
            Delete
        </Button>
    )
}
