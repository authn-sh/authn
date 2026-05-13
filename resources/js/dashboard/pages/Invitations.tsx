import { Badge } from '@authn-sh/ui'

type Props = {
    invitations: Array<{ id: string; email_address: string; status: string }>
}

export default function Invitations({ invitations }: Props) {
    return (
        <>
            <div className="authn-page-header">
                <div>
                    <h1 className="authn-page-title">Invitations</h1>
                    <p className="authn-page-subtitle">{invitations.length} invitation{invitations.length === 1 ? '' : 's'}.</p>
                </div>
            </div>
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
        </>
    )
}
