import { Link } from '@inertiajs/react'
import { Badge } from '@authn-sh/ui'
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

const FACETS = [
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

export default function AuditLog({ note, filter, entries }: Props) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const base = active_project && active_environment
        ? `/${active_project.slug}/${active_environment.slug}/audit-log`
        : '/audit-log'

    return (
        <>
            <div className="authn-page-header">
                <div>
                    <h1 className="authn-page-title">Audit log</h1>
                    <p className="authn-page-subtitle">{note}</p>
                </div>
            </div>
            <nav className="authn-chip-group">
                {FACETS.map((f) => {
                    const active = filter === f.slug
                    const href = f.slug === '' ? url(base) : url(`${base}?type=${encodeURIComponent(f.slug)}`)
                    return (
                        <Link
                            key={f.slug || 'all'}
                            href={href}
                            className="authn-chip"
                            {...(active ? { 'aria-current': 'true' as const } : {})}
                        >
                            {f.label}
                        </Link>
                    )
                })}
            </nav>
            <div className="authn-table-wrap">
                <table className="authn-table">
                    <thead>
                        <tr>
                            <th style={{ width: 220 }}>At</th>
                            <th style={{ width: 260 }}>Type</th>
                            <th>Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        {entries.length === 0 && (
                            <tr>
                                <td colSpan={3}>
                                    <div className="authn-empty-state">No events match the current filter.</div>
                                </td>
                            </tr>
                        )}
                        {entries.map((e) => (
                            <tr key={e.id} style={{ verticalAlign: 'top' }}>
                                <td>
                                    <code>{e.created_at ? new Date(e.created_at).toISOString() : '—'}</code>
                                    {e.was_test && <Badge tone="warning" style={{ marginLeft: 6 }}>test</Badge>}
                                </td>
                                <td><code>{e.type}</code></td>
                                <td>
                                    <details>
                                        <summary style={{ cursor: 'pointer', color: 'var(--authn-color-text-secondary)' }}>
                                            view payload
                                        </summary>
                                        <pre className="authn-json-block">{JSON.stringify(e.data, null, 2)}</pre>
                                    </details>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    )
}
