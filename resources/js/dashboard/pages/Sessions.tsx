import { Badge } from '@authn-sh/ui'
import { Page } from '../components/Page'
import { PageTabs, type PageTab } from '../components/PageTabs'

type Props = {
    sessions: Array<{ id: string; user_id: string; status: string }>
}

const TABS: PageTab[] = [
    { value: 'users', label: 'Users', path: '/users' },
    { value: 'invitations', label: 'Invitations', path: '/users/invitations' },
    { value: 'sessions', label: 'Sessions', path: '/users/sessions' },
]

export default function Sessions({ sessions }: Props) {
    return (
        <Page
            title="Users"
            subtitle={`${sessions.length} live session${sessions.length === 1 ? '' : 's'}.`}
            tabs={<PageTabs tabs={TABS} active="sessions" />}
        >
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
        </Page>
    )
}
