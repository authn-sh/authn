type Props = {
    counts: {
        users: number
        sessions_active: number
        invitations_pending: number
    }
}

export default function Overview({ counts }: Props) {
    return (
        <>
            <div className="authn-page-header">
                <div>
                    <h1 className="authn-page-title">Overview</h1>
                    <p className="authn-page-subtitle">A snapshot of the active environment.</p>
                </div>
            </div>
            <div className="authn-stats-grid">
                <div className="authn-stat">
                    <p className="authn-stat-label">Users</p>
                    <p className="authn-stat-value">{counts.users.toLocaleString()}</p>
                </div>
                <div className="authn-stat">
                    <p className="authn-stat-label">Active sessions</p>
                    <p className="authn-stat-value">{counts.sessions_active.toLocaleString()}</p>
                </div>
                <div className="authn-stat">
                    <p className="authn-stat-label">Pending invitations</p>
                    <p className="authn-stat-value">{counts.invitations_pending.toLocaleString()}</p>
                </div>
            </div>
        </>
    )
}
