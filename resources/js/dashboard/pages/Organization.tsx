import { Badge } from '@authn-sh/ui'

type Props = {
    organization: {
        id: string
        name: string
        slug: string
        members_count: number
        pending_invitations_count: number
        max_allowed_memberships: number | null
        admin_delete_enabled: boolean
    }
    tab: string
    members: Array<{ id: string; user_id: string; role: string | null; username: string | null; first_name: string | null; last_name: string | null }>
    invitations: Array<{ id: string; email_address: string; role: string | null; status: string; expires_at: number | null }>
    membership_requests: Array<{ id: string; user_id: string; status: string; created_at: number | null }>
    domains: Array<{ id: string; name: string; verified: boolean; enrollment_mode: string; total_pending_invitations: number }>
}

export default function Organization({ organization, tab, members, invitations, membership_requests, domains }: Props) {
    return (
        <>
            <div className="authn-page-header">
                <div>
                    <h1 className="authn-page-title">{organization.name}</h1>
                    <p className="authn-page-subtitle">
                        <code>{organization.slug}</code> · {organization.members_count} members · {organization.pending_invitations_count} pending invites
                    </p>
                </div>
            </div>

            {tab === 'members' && (
                <div className="authn-table-wrap">
                    <table className="authn-table">
                        <thead>
                            <tr><th>User</th><th>Role</th></tr>
                        </thead>
                        <tbody>
                            {members.length === 0 && (
                                <tr><td colSpan={2}><div className="authn-empty-state">No members yet.</div></td></tr>
                            )}
                            {members.map((m) => (
                                <tr key={m.id}>
                                    <td>{m.username || [m.first_name, m.last_name].filter(Boolean).join(' ') || m.user_id}</td>
                                    <td>{m.role ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {tab === 'invitations' && (
                <div className="authn-table-wrap">
                    <table className="authn-table">
                        <thead>
                            <tr><th>Email</th><th>Role</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            {invitations.length === 0 && (
                                <tr><td colSpan={3}><div className="authn-empty-state">No pending invitations.</div></td></tr>
                            )}
                            {invitations.map((i) => (
                                <tr key={i.id}>
                                    <td>{i.email_address}</td>
                                    <td>{i.role ?? '—'}</td>
                                    <td>
                                        <Badge tone={i.status === 'pending' ? 'warning' : i.status === 'accepted' ? 'success' : 'neutral'}>
                                            {i.status}
                                        </Badge>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {tab === 'requests' && (
                <div className="authn-table-wrap">
                    <table className="authn-table">
                        <thead>
                            <tr><th>User</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            {membership_requests.length === 0 && (
                                <tr><td colSpan={2}><div className="authn-empty-state">No membership requests.</div></td></tr>
                            )}
                            {membership_requests.map((r) => (
                                <tr key={r.id}>
                                    <td><code>{r.user_id}</code></td>
                                    <td><Badge tone={r.status === 'pending' ? 'warning' : 'neutral'}>{r.status}</Badge></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {tab === 'domains' && (
                <div className="authn-table-wrap">
                    <table className="authn-table">
                        <thead>
                            <tr><th>Domain</th><th>Verified</th><th>Mode</th></tr>
                        </thead>
                        <tbody>
                            {domains.length === 0 && (
                                <tr><td colSpan={3}><div className="authn-empty-state">No domains configured.</div></td></tr>
                            )}
                            {domains.map((d) => (
                                <tr key={d.id}>
                                    <td>{d.name}</td>
                                    <td><Badge tone={d.verified ? 'success' : 'warning'}>{d.verified ? 'yes' : 'no'}</Badge></td>
                                    <td>{d.enrollment_mode}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </>
    )
}
