import { Link } from '@inertiajs/react'
import { Page } from '../components/Page'

type Org = {
    id: string
    name: string
    slug: string
    members_count: number
    pending_invitations_count: number
    admin_delete_enabled: boolean
}

type Props = {
    organizations: Org[]
    query: string
    dashboard_prefix?: string
    active_project?: { slug: string }
    active_environment?: { slug: string }
}

export default function Organizations({ organizations, query, dashboard_prefix, active_project, active_environment }: Props) {
    const base = `${dashboard_prefix ?? ''}/${active_project?.slug ?? ''}/${active_environment?.slug ?? ''}/organizations`

    return (
        <Page title="Organizations">
            <div className="authn-filter-bar">
                <span>Filter:</span>
                <code>{query || '—'}</code>
            </div>
            <div className="authn-table-wrap">
                <table className="authn-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Slug</th>
                            <th>Members</th>
                            <th>Pending invites</th>
                        </tr>
                    </thead>
                    <tbody>
                        {organizations.length === 0 && (
                            <tr>
                                <td colSpan={4}>
                                    <div className="authn-empty-state">No organizations yet.</div>
                                </td>
                            </tr>
                        )}
                        {organizations.map((o) => (
                            <tr key={o.id}>
                                <td>
                                    <Link href={`${base}/${o.id}`} className="authn-text-link">
                                        {o.name}
                                    </Link>
                                </td>
                                <td><code>{o.slug}</code></td>
                                <td>{o.members_count}</td>
                                <td>{o.pending_invitations_count}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Page>
    )
}
