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
        <div>
            <header>
                <h1>{organization.name}</h1>
                <p><code>{organization.slug}</code> · {organization.members_count} members · {organization.pending_invitations_count} pending invites</p>
            </header>

            {tab === 'members' && (
                <section>
                    <h2>Members</h2>
                    <table>
                        <thead><tr><th>User</th><th>Role</th></tr></thead>
                        <tbody>
                            {members.map((m) => (
                                <tr key={m.id}>
                                    <td>{m.username || [m.first_name, m.last_name].filter(Boolean).join(' ') || m.user_id}</td>
                                    <td>{m.role ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            )}

            {tab === 'invitations' && (
                <section>
                    <h2>Pending invitations</h2>
                    <table>
                        <thead><tr><th>Email</th><th>Role</th><th>Status</th></tr></thead>
                        <tbody>
                            {invitations.map((i) => (
                                <tr key={i.id}>
                                    <td>{i.email_address}</td>
                                    <td>{i.role ?? '—'}</td>
                                    <td>{i.status}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            )}

            {tab === 'requests' && (
                <section>
                    <h2>Membership requests</h2>
                    <table>
                        <thead><tr><th>User id</th><th>Status</th></tr></thead>
                        <tbody>
                            {membership_requests.map((r) => (
                                <tr key={r.id}>
                                    <td>{r.user_id}</td>
                                    <td>{r.status}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            )}

            {tab === 'domains' && (
                <section>
                    <h2>Domains</h2>
                    <table>
                        <thead><tr><th>Domain</th><th>Verified</th><th>Mode</th></tr></thead>
                        <tbody>
                            {domains.map((d) => (
                                <tr key={d.id}>
                                    <td>{d.name}</td>
                                    <td>{d.verified ? 'yes' : 'no'}</td>
                                    <td>{d.enrollment_mode}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </section>
            )}
        </div>
    )
}
