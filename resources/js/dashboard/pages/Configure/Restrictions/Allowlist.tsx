import { RestrictionsConfigurePage } from '../../../components/RestrictionsConfigurePage'

type AllowlistRow = { id: string; identifier: string; notify: boolean }
type Props = { rows: AllowlistRow[] }

export default function Allowlist({ rows }: Props) {
    return (
        <RestrictionsConfigurePage active="allowlist">
            <div className="authn-table-wrap">
                <table className="authn-table">
                    <thead>
                        <tr><th>Identifier</th><th>Notify</th></tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={2}>
                                    <div className="authn-empty-state">No allowlist entries.</div>
                                </td>
                            </tr>
                        )}
                        {rows.map((r) => (
                            <tr key={r.id}>
                                <td><code>{r.identifier}</code></td>
                                <td>{r.notify ? 'yes' : 'no'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </RestrictionsConfigurePage>
    )
}
