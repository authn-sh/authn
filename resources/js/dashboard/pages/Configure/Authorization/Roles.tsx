import { Badge } from '@authn-sh/ui'
import { AuthorizationConfigurePage } from '../../../components/AuthorizationConfigurePage'

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

type Props = { roles: Role[] }

export default function Roles({ roles }: Props) {
    return (
        <AuthorizationConfigurePage active="roles">
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
        </AuthorizationConfigurePage>
    )
}
