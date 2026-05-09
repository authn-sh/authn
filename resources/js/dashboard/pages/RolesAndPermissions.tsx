type Role = {
    id: string
    key: string
    name: string
    description: string | null
    is_system: boolean
    is_default: boolean
    is_creator_eligible: boolean
    permissions: string[]
}

type Permission = {
    id: string
    key: string
    name: string
    description: string | null
    is_system: boolean
}

type Props = {
    roles: Role[]
    permissions: Permission[]
}

export default function RolesAndPermissions({ roles, permissions }: Props) {
    return (
        <div>
            <h1>Roles &amp; permissions</h1>

            <section>
                <h2>Roles</h2>
                <table>
                    <thead><tr><th>Key</th><th>Name</th><th>Permissions</th><th>Flags</th></tr></thead>
                    <tbody>
                        {roles.map((r) => (
                            <tr key={r.id}>
                                <td><code>{r.key}</code></td>
                                <td>{r.name}</td>
                                <td>{r.permissions.length}</td>
                                <td>
                                    {r.is_system && <span>system </span>}
                                    {r.is_default && <span>default </span>}
                                    {r.is_creator_eligible && <span>creator</span>}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>

            <section>
                <h2>System permissions</h2>
                <table>
                    <thead><tr><th>Key</th><th>Name</th></tr></thead>
                    <tbody>
                        {permissions.map((p) => (
                            <tr key={p.id}>
                                <td><code>{p.key}</code></td>
                                <td>{p.name}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </div>
    )
}
