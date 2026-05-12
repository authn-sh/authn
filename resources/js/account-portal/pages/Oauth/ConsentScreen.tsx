import { useState } from 'react'
import { useBootstrap } from '../../bootstrap'

type Scope = { name: string; label: string }
type Application = { id: string; name: string }

type Props = {
    request_id: string
    application: Application
    scopes: Scope[]
}

/**
 * v0.7 OAuth provider mode — consent screen mounted at
 * `/oauth/consent/{request_id}`. The Account Portal lands here from AU-6's
 * /oauth/authorize when the user lacks a covering AuthorizationGrant.
 *
 * Accept POSTs to `/oauth/consent/{request_id}/accept` and follows the
 * server-issued `redirect_url` (carries `code=&state=` back to the
 * application's `callback_url`). Deny POSTs to `…/deny` and follows the
 * `error=access_denied` redirect.
 */
export default function ConsentScreen(props: Props) {
    const { ready, env } = useBootstrap()
    const [busy, setBusy] = useState<'accept' | 'deny' | null>(null)
    const [error, setError] = useState<string | null>(null)

    if (!ready) return <p>Loading…</p>

    const post = async (path: 'accept' | 'deny') => {
        setBusy(path)
        setError(null)
        try {
            const res = await fetch(`${env?.fapi_url ?? ''}/oauth/consent/${props.request_id}/${path}`, {
                method: 'POST',
                credentials: 'include',
                headers: { Accept: 'application/json' },
            })
            if (!res.ok) throw new Error(`HTTP ${res.status}`)
            const body = await res.json()
            const target = typeof body?.redirect_url === 'string' ? body.redirect_url : null
            if (!target) throw new Error('No redirect_url in response')
            window.location.href = target
        } catch (err) {
            setError((err as Error).message)
            setBusy(null)
        }
    }

    return (
        <main data-testid="authn-oauth-consent" style={{ maxWidth: 480, margin: '64px auto', padding: 24, fontFamily: 'system-ui, sans-serif' }}>
            <h1 style={{ marginTop: 0 }}>{props.application.name} wants to access your account</h1>
            <p style={{ color: '#475569' }}>By accepting, you grant {props.application.name} the following permissions:</p>

            <ul style={{ listStyle: 'none', padding: 0, marginTop: 16 }}>
                {props.scopes.map(s => (
                    <li key={s.name} data-testid={`authn-oauth-consent-scope-${s.name}`} style={{ padding: '8px 0', borderBottom: '1px solid #e5e7eb' }}>
                        <span style={{ fontWeight: 500 }}>{s.label}</span>
                        <code style={{ marginLeft: 8, fontSize: 12, color: '#94a3b8' }}>{s.name}</code>
                    </li>
                ))}
            </ul>

            {error && <p style={{ color: '#b91c1c', marginTop: 16 }}>{error}</p>}

            <div style={{ marginTop: 24, display: 'flex', gap: 12, justifyContent: 'flex-end' }}>
                <button
                    type="button"
                    onClick={() => post('deny')}
                    disabled={busy !== null}
                    data-testid="authn-oauth-consent-deny"
                    style={{ padding: '8px 16px', background: 'transparent', border: '1px solid #cbd5e1', borderRadius: 4 }}
                >
                    {busy === 'deny' ? 'Denying…' : 'Deny'}
                </button>
                <button
                    type="button"
                    onClick={() => post('accept')}
                    disabled={busy !== null}
                    data-testid="authn-oauth-consent-accept"
                    style={{ padding: '8px 16px', background: '#0a84ff', color: '#fff', border: 'none', borderRadius: 4 }}
                >
                    {busy === 'accept' ? 'Accepting…' : 'Allow access'}
                </button>
            </div>
        </main>
    )
}
