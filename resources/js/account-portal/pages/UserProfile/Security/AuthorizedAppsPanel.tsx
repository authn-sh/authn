import { RedirectToSignIn, SignedIn, SignedOut, useAuthn } from '@authn-sh/sdk-react'
import { useEffect, useState } from 'react'
import { useBootstrap } from '../../../bootstrap'
import { useLocale } from '../../../LocaleProvider'

type AuthorizedApp = {
    id: string
    object: 'authorization_grant'
    oauth_application_id: string
    oauth_application_name: string
    scopes: string[]
    granted_at: number
    revoked_at: number | null
}

/**
 * Account Portal Security → Authorized Apps page. v0.7 ships the
 * backend `/v1/me/authorized-apps` surface; JS-2 swaps this for the
 * bundled `<UserProfileAuthorizedAppsPanel />` from sdk-react. Until
 * then this page fetches the list directly so operators have a working
 * panel from day one.
 */
export default function AuthorizedAppsPanel() {
    const { ready, env } = useBootstrap()
    const { t } = useLocale()
    const { authn } = useAuthn()
    const [rows, setRows] = useState<AuthorizedApp[] | null>(null)
    const [error, setError] = useState<string | null>(null)
    const [revokingId, setRevokingId] = useState<string | null>(null)

    useEffect(() => {
        if (!ready || !env?.fapi_url) return
        const headers: Record<string, string> = { Accept: 'application/json' }
        const token = authn?.session?.getCurrentToken?.()
        if (typeof token === 'string' && token) {
            headers['Authorization'] = `Bearer ${token}`
        }
        fetch(`${env.fapi_url}/v1/me/authorized-apps`, {
            credentials: 'include',
            headers,
        })
            .then(r => (r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`))))
            .then(body => setRows(Array.isArray(body.data) ? body.data : []))
            .catch(err => setError((err as Error).message))
    }, [ready, env?.fapi_url, authn])

    const revoke = async (grantId: string) => {
        setRevokingId(grantId)
        setError(null)
        try {
            const headers: Record<string, string> = { Accept: 'application/json' }
            const token = authn?.session?.getCurrentToken?.()
            if (typeof token === 'string' && token) {
                headers['Authorization'] = `Bearer ${token}`
            }
            const res = await fetch(`${env?.fapi_url}/v1/me/authorized-apps/${grantId}`, {
                method: 'DELETE',
                credentials: 'include',
                headers,
            })
            if (!res.ok) throw new Error(`HTTP ${res.status}`)
            setRows(prev => (prev ?? []).filter(r => r.id !== grantId))
        } catch (err) {
            setError((err as Error).message)
        } finally {
            setRevokingId(null)
        }
    }

    if (!ready) return <p>Loading…</p>

    return (
        <>
            <SignedIn>
                <h1>{t('userProfile.start.authorizedAppsSection.title') || 'Authorized apps'}</h1>
                {error && <p style={{ color: '#b91c1c' }}>{error}</p>}
                {rows === null && <p>Loading authorized apps…</p>}
                {rows !== null && rows.length === 0 && <p>No third-party apps have access to your account.</p>}
                {rows !== null && rows.length > 0 && (
                    <ul data-testid="authn-userprofile-authorized-apps" style={{ listStyle: 'none', padding: 0 }}>
                        {rows.map(row => (
                            <li
                                key={row.id}
                                data-testid={`authn-userprofile-authorized-apps-row-${row.id}`}
                                style={{
                                    display: 'flex',
                                    justifyContent: 'space-between',
                                    alignItems: 'center',
                                    padding: '12px 0',
                                    borderBottom: '1px solid #e5e7eb',
                                }}
                            >
                                <div>
                                    <div style={{ fontWeight: 600 }}>{row.oauth_application_name}</div>
                                    <div style={{ fontSize: 12, color: '#475569' }}>
                                        {row.scopes.join(' · ')}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => revoke(row.id)}
                                    disabled={revokingId === row.id}
                                    data-testid={`authn-userprofile-authorized-apps-revoke-${row.id}`}
                                >
                                    {revokingId === row.id ? 'Revoking…' : 'Revoke'}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </SignedIn>
            <SignedOut>
                <RedirectToSignIn />
            </SignedOut>
        </>
    )
}
