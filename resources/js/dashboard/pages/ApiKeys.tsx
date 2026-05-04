import { usePage } from '@inertiajs/react'

type Props = {
    keys: Array<{ id: string; kind: string; prefix: string; name: string | null }>
}

export default function ApiKeys({ keys }: Props) {
    const { props } = usePage<{ flash?: { rotated_secret?: string } }>()
    return (
        <div>
            <h1>API keys</h1>
            {props.flash?.rotated_secret && (
                <div style={{ padding: 12, background: '#fef9c3', borderRadius: 6, marginBottom: 16 }}>
                    Save this secret now — it won't be shown again: <code>{props.flash.rotated_secret}</code>
                </div>
            )}
            <table>
                <thead><tr><th>Prefix</th><th>Kind</th><th>Name</th></tr></thead>
                <tbody>
                    {keys.map(k => (
                        <tr key={k.id}>
                            <td><code>{k.prefix}…</code></td>
                            <td>{k.kind}</td>
                            <td>{k.name ?? '—'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    )
}
