import { Badge } from '@authn-sh/ui'
import { Page } from '../components/Page'
import { PageTabs, type PageTab } from '../components/PageTabs'

type Props = {
    users: Array<{ id: string; first_name: string | null; last_name: string | null; username: string | null; banned: boolean; locked: boolean }>
    query: string
}

const TABS: PageTab[] = [
    { value: 'users', label: 'Users', path: '/users' },
    { value: 'invitations', label: 'Invitations', path: '/users/invitations' },
    { value: 'sessions', label: 'Sessions', path: '/users/sessions' },
]

export default function Users({ users, query }: Props) {
    return (
        <Page title="Users" tabs={<PageTabs tabs={TABS} active="users" />}>
            <div className="authn-filter-bar">
                <span>Filter:</span>
                <code>{query || '—'}</code>
            </div>
            <div className="authn-table-wrap">
                <table className="authn-table">
                    <thead>
                        <tr><th>ID</th><th>Name</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        {users.length === 0 && (
                            <tr>
                                <td colSpan={3}>
                                    <div className="authn-empty-state">No users yet.</div>
                                </td>
                            </tr>
                        )}
                        {users.map((u) => {
                            const tone = u.banned ? 'danger' : u.locked ? 'warning' : 'success'
                            const label = u.banned ? 'banned' : u.locked ? 'locked' : 'active'
                            return (
                                <tr key={u.id}>
                                    <td><code>{u.id}</code></td>
                                    <td>{[u.first_name, u.last_name].filter(Boolean).join(' ') || u.username || '—'}</td>
                                    <td><Badge tone={tone}>{label}</Badge></td>
                                </tr>
                            )
                        })}
                    </tbody>
                </table>
            </div>
        </Page>
    )
}
