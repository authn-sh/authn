import { usePage } from '@inertiajs/react'
import { Alert, Badge } from '@authn-sh/ui'

type Props = {
    keys: Array<{ id: string; kind: string; prefix: string; name: string | null }>
}

export default function ApiKeys({ keys }: Props) {
    const { props } = usePage<{ flash?: { rotated_secret?: string } }>()
    return (
        <>
            <div className="authn-page-header">
                <h1 className="authn-page-title">API keys</h1>
            </div>
            <div className="authn-stack">
                {props.flash?.rotated_secret && (
                    <Alert tone="warning">
                        Save this secret now — it won't be shown again: <code>{props.flash.rotated_secret}</code>
                    </Alert>
                )}
                <div className="authn-table-wrap">
                    <table className="authn-table">
                        <thead>
                            <tr><th>Prefix</th><th>Kind</th><th>Name</th></tr>
                        </thead>
                        <tbody>
                            {keys.length === 0 && (
                                <tr>
                                    <td colSpan={3}>
                                        <div className="authn-empty-state">No API keys yet.</div>
                                    </td>
                                </tr>
                            )}
                            {keys.map((k) => (
                                <tr key={k.id}>
                                    <td><code>{k.prefix}…</code></td>
                                    <td><Badge tone={k.kind === 'secret' ? 'danger' : 'neutral'}>{k.kind}</Badge></td>
                                    <td>{k.name ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    )
}
