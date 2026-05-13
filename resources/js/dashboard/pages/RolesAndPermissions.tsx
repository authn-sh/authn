import { Badge } from '@authn-sh/ui'

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
        <>
            <div className="authn-page-header">
                <h1 className="authn-page-title">Roles &amp; permissions</h1>
            </div>
            <div className="authn-stack">
                <section className="authn-stack">
                    <h2 className="authn-page-subtitle" style={{ fontSize: '1rem', fontWeight: 600 }}>Roles</h2>
                    <div className="authn-table-wrap">
                        <table className="authn-table">
                            <thead>
                                <tr><th>Key</th><th>Name</th><th>Permissions</th><th>Flags</th></tr>
                            </thead>
                            <tbody>
                                {roles.length === 0 && (
                                    <tr><td colSpan={4}><div className="authn-empty-state">No roles defined.</div></td></tr>
                                )}
                                {roles.map((r) => (
                                    <tr key={r.id}>
                                        <td><code>{r.key}</code></td>
                                        <td>{r.name}</td>
                                        <td>{r.permissions.length}</td>
                                        <td style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                                            {r.is_system && <Badge>system</Badge>}
                                            {r.is_default && <Badge tone="success">default</Badge>}
                                            {r.is_creator_eligible && <Badge tone="warning">creator</Badge>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
                <section className="authn-stack">
                    <h2 className="authn-page-subtitle" style={{ fontSize: '1rem', fontWeight: 600 }}>System permissions</h2>
                    <div className="authn-table-wrap">
                        <table className="authn-table">
                            <thead>
                                <tr><th>Key</th><th>Name</th></tr>
                            </thead>
                            <tbody>
                                {permissions.length === 0 && (
                                    <tr><td colSpan={2}><div className="authn-empty-state">No permissions registered.</div></td></tr>
                                )}
                                {permissions.map((p) => (
                                    <tr key={p.id}>
                                        <td><code>{p.key}</code></td>
                                        <td>{p.name}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </>
    )
}
