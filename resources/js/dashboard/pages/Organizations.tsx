import { Link } from '@inertiajs/react'

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
        <div>
            <h1>Organizations</h1>
            <p>Filter: <code>{query || '—'}</code></p>
            <table>
                <thead>
                    <tr><th>Name</th><th>Slug</th><th>Members</th><th>Pending invites</th></tr>
                </thead>
                <tbody>
                    {organizations.map((o) => (
                        <tr key={o.id}>
                            <td><Link href={`${base}/${o.id}`}>{o.name}</Link></td>
                            <td><code>{o.slug}</code></td>
                            <td>{o.members_count}</td>
                            <td>{o.pending_invitations_count}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    )
}
