type Props = { rows: Array<{ id: string; identifier: string; notify: boolean }> }

export default function Allowlist({ rows }: Props) {
    return (
        <>
            <div className="authn-page-header">
                <div>
                    <h1 className="authn-page-title">Allowlist</h1>
                    <p className="authn-page-subtitle">{rows.length} {rows.length === 1 ? 'entry' : 'entries'}.</p>
                </div>
            </div>
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
        </>
    )
}
