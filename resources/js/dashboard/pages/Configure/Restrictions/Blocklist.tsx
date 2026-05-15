import { RestrictionsConfigurePage } from '../../../components/RestrictionsConfigurePage'

type BlocklistRow = { id: string; identifier: string }
type Props = { rows: BlocklistRow[] }

export default function Blocklist({ rows }: Props) {
    return (
        <RestrictionsConfigurePage active="blocklist">
            <div className="authn-table-wrap">
                <table className="authn-table">
                    <thead>
                        <tr><th>Identifier</th></tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 && (
                            <tr>
                                <td>
                                    <div className="authn-empty-state">No blocklist entries.</div>
                                </td>
                            </tr>
                        )}
                        {rows.map((r) => (
                            <tr key={r.id}>
                                <td><code>{r.identifier}</code></td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </RestrictionsConfigurePage>
    )
}
