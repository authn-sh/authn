import { Badge } from '@authn-sh/ui'
import { Page } from '../components/Page'
import { PageTabs, type PageTab } from '../components/PageTabs'

type Props = {
    invitations: Array<{ id: string; email_address: string; status: string }>
}

const TABS: PageTab[] = [
    { value: 'users', label: 'Users', path: '/users' },
    { value: 'invitations', label: 'Invitations', path: '/users/invitations' },
    { value: 'sessions', label: 'Sessions', path: '/users/sessions' },
]

export default function Invitations({ invitations }: Props) {
    return (
        <Page
            title="Users"
            subtitle={`${invitations.length} invitation${invitations.length === 1 ? '' : 's'}.`}
            tabs={<PageTabs tabs={TABS} active="invitations" />}
        >
            <div className="authn-table-wrap">
                <table className="authn-table">
                    <thead>
                        <tr><th>Email</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        {invitations.length === 0 && (
                            <tr>
                                <td colSpan={2}>
                                    <div className="authn-empty-state">No invitations.</div>
                                </td>
                            </tr>
                        )}
                        {invitations.map((i) => (
                            <tr key={i.id}>
                                <td>{i.email_address}</td>
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
        </Page>
    )
}
