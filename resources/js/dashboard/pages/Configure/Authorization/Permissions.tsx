import { Badge } from '@authn-sh/ui'
import { AuthorizationConfigurePage } from '../../../components/AuthorizationConfigurePage'

type Permission = {
    id: string
    key: string
    name: string
    description: string | null
    is_system: boolean
}

type Props = { permissions: Permission[] }

export default function Permissions({ permissions }: Props) {
    return (
        <AuthorizationConfigurePage active="permissions">
            <div className="authn-table-wrap">
                <table className="authn-table">
                    <thead>
                        <tr><th>Key</th><th>Name</th><th>Scope</th></tr>
                    </thead>
                    <tbody>
                        {permissions.length === 0 && (
                            <tr><td colSpan={3}><div className="authn-empty-state">No permissions registered.</div></td></tr>
                        )}
                        {permissions.map((p) => (
                            <tr key={p.id}>
                                <td><code>{p.key}</code></td>
                                <td>{p.name}</td>
                                <td>{p.is_system ? <Badge>system</Badge> : <Badge tone="success">custom</Badge>}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AuthorizationConfigurePage>
    )
}
