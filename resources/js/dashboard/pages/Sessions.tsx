import { Badge } from '@authn-sh/ui'

type Props = {
    sessions: Array<{ id: string; user_id: string; status: string }>
}

export default function Sessions({ sessions }: Props) {
    return (
        <>
            <div className="authn-page-header">
                <div>
                    <h1 className="authn-page-title">Sessions</h1>
                    <p className="authn-page-subtitle">{sessions.length} live session{sessions.length === 1 ? '' : 's'}.</p>
                </div>
            </div>
            <div className="authn-table-wrap">
                <table className="authn-table">
                    <thead>
                        <tr><th>ID</th><th>User</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        {sessions.length === 0 && (
                            <tr>
                                <td colSpan={3}>
                                    <div className="authn-empty-state">No active sessions.</div>
                                </td>
                            </tr>
                        )}
                        {sessions.map((s) => (
                            <tr key={s.id}>
                                <td><code>{s.id}</code></td>
                                <td><code>{s.user_id}</code></td>
                                <td><Badge tone={s.status === 'active' ? 'success' : 'neutral'}>{s.status}</Badge></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </>
    )
}
