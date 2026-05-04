import { usePage } from '@inertiajs/react'

type Endpoint = { id: string; url: string; enabled: boolean; signing_secret_prefix: string }
type Delivery = { id: string; webhook_endpoint_id: string; status: string; response_status: number | null }

type Props = {
    endpoints: Endpoint[]
    deliveries: Delivery[]
}

export default function Webhooks({ endpoints, deliveries }: Props) {
    const { props } = usePage<{ flash?: { signing_secret?: string } }>()
    return (
        <div>
            <h1>Webhooks</h1>
            {props.flash?.signing_secret && (
                <div style={{ padding: 12, background: '#fef9c3', borderRadius: 6, marginBottom: 16 }}>
                    Save this signing secret — it won't be shown again: <code>{props.flash.signing_secret}</code>
                </div>
            )}
            <h2>Endpoints</h2>
            <ul>
                {endpoints.map(e => (
                    <li key={e.id}>{e.url} — {e.enabled ? 'enabled' : 'disabled'} ({e.signing_secret_prefix})</li>
                ))}
            </ul>
            <h2>Recent deliveries</h2>
            <table>
                <thead><tr><th>Delivery</th><th>Status</th><th>HTTP</th></tr></thead>
                <tbody>
                    {deliveries.map(d => (
                        <tr key={d.id}>
                            <td><code>{d.id}</code></td>
                            <td>{d.status}</td>
                            <td>{d.response_status ?? '—'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    )
}
