import { Link } from '@inertiajs/react'
import { useDashboard, useDashboardUrl } from '../shared'

type Entry = {
    id: string
    type: string
    was_test: boolean
    data: Record<string, unknown>
    created_at: number | null
}

type Props = {
    note: string
    filter: string
    entries: Entry[]
}

export default function AuditLog({ note, filter, entries }: Props) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const base = active_project && active_environment
        ? `/${active_project.slug}/${active_environment.slug}/audit-log`
        : '/audit-log'

    const facets = [
        { slug: '', label: 'all' },
        { slug: 'user', label: 'user' },
        { slug: 'session', label: 'session' },
        { slug: 'oauthProvider', label: 'oauth provider' },
        { slug: 'externalAccount', label: 'external account' },
        { slug: 'phoneNumber', label: 'phone number' },
        { slug: 'organization', label: 'organization' },
        { slug: 'sms', label: 'sms' },
        { slug: 'email', label: 'email' },
    ]

    return (
        <div>
            <h1>Audit log</h1>
            <p style={{ color: '#64748b' }}>{note}</p>
            <nav style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 16 }}>
                {facets.map(f => {
                    const active = filter === f.slug
                    const href = f.slug === '' ? url(base) : url(`${base}?type=${encodeURIComponent(f.slug)}`)
                    return (
                        <Link
                            key={f.slug || 'all'}
                            href={href}
                            style={{
                                padding: '4px 8px',
                                borderRadius: 4,
                                fontSize: 13,
                                background: active ? '#0f172a' : '#f1f5f9',
                                color: active ? '#fff' : '#0f172a',
                                textDecoration: 'none',
                            }}
                        >
                            {f.label}
                        </Link>
                    )
                })}
            </nav>
            {entries.length === 0 ? (
                <p style={{ color: '#94a3b8' }}>No events match the current filter.</p>
            ) : (
                <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                    <thead>
                        <tr style={{ textAlign: 'left', borderBottom: '1px solid #e2e8f0' }}>
                            <th style={{ padding: '6px 8px', width: 200 }}>at</th>
                            <th style={{ padding: '6px 8px', width: 240 }}>type</th>
                            <th style={{ padding: '6px 8px' }}>data</th>
                        </tr>
                    </thead>
                    <tbody>
                        {entries.map(e => (
                            <tr key={e.id} style={{ borderBottom: '1px solid #f1f5f9', verticalAlign: 'top' }}>
                                <td style={{ padding: '6px 8px', fontFamily: 'monospace', color: '#475569' }}>
                                    {e.created_at ? new Date(e.created_at).toISOString() : '—'}
                                    {e.was_test && <span style={{ marginLeft: 6, fontSize: 11, color: '#b45309' }}>test</span>}
                                </td>
                                <td style={{ padding: '6px 8px' }}><code>{e.type}</code></td>
                                <td style={{ padding: '6px 8px' }}>
                                    <details>
                                        <summary style={{ cursor: 'pointer', color: '#64748b' }}>view payload</summary>
                                        <pre style={{ fontSize: 12, marginTop: 4, padding: 8, background: '#f8fafc', borderRadius: 4, overflow: 'auto' }}>
                                            {JSON.stringify(e.data, null, 2)}
                                        </pre>
                                    </details>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    )
}
