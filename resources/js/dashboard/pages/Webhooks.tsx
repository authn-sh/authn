import { usePage } from '@inertiajs/react'
import { Alert, Badge } from '@authn-sh/ui'

type Endpoint = { id: string; url: string; enabled: boolean; signing_secret_prefix: string }
type Delivery = { id: string; webhook_endpoint_id: string; status: string; response_status: number | null }

type Props = {
    endpoints: Endpoint[]
    deliveries: Delivery[]
}

function deliveryTone(status: string): 'success' | 'danger' | 'warning' | 'neutral' {
    if (status === 'delivered' || status === 'success') return 'success'
    if (status === 'failed' || status === 'error') return 'danger'
    if (status === 'pending' || status === 'retrying') return 'warning'
    return 'neutral'
}

export default function Webhooks({ endpoints, deliveries }: Props) {
    const { props } = usePage<{ flash?: { signing_secret?: string } }>()
    return (
        <>
            <div className="authn-page-header">
                <h1 className="authn-page-title">Webhooks</h1>
            </div>
            <div className="authn-stack">
                {props.flash?.signing_secret && (
                    <Alert tone="warning">
                        Save this signing secret — it won't be shown again: <code>{props.flash.signing_secret}</code>
                    </Alert>
                )}
                <section className="authn-stack">
                    <h2 className="authn-page-subtitle" style={{ fontSize: '1rem', fontWeight: 600 }}>Endpoints</h2>
                    <div className="authn-table-wrap">
                        <table className="authn-table">
                            <thead>
                                <tr><th>URL</th><th>Status</th><th>Secret prefix</th></tr>
                            </thead>
                            <tbody>
                                {endpoints.length === 0 && (
                                    <tr>
                                        <td colSpan={3}>
                                            <div className="authn-empty-state">No endpoints configured.</div>
                                        </td>
                                    </tr>
                                )}
                                {endpoints.map((e) => (
                                    <tr key={e.id}>
                                        <td>{e.url}</td>
                                        <td>
                                            <Badge tone={e.enabled ? 'success' : 'neutral'}>
                                                {e.enabled ? 'enabled' : 'disabled'}
                                            </Badge>
                                        </td>
                                        <td><code>{e.signing_secret_prefix}</code></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
                <section className="authn-stack">
                    <h2 className="authn-page-subtitle" style={{ fontSize: '1rem', fontWeight: 600 }}>Recent deliveries</h2>
                    <div className="authn-table-wrap">
                        <table className="authn-table">
                            <thead>
                                <tr><th>Delivery</th><th>Status</th><th>HTTP</th></tr>
                            </thead>
                            <tbody>
                                {deliveries.length === 0 && (
                                    <tr>
                                        <td colSpan={3}>
                                            <div className="authn-empty-state">No deliveries yet.</div>
                                        </td>
                                    </tr>
                                )}
                                {deliveries.map((d) => (
                                    <tr key={d.id}>
                                        <td><code>{d.id}</code></td>
                                        <td><Badge tone={deliveryTone(d.status)}>{d.status}</Badge></td>
                                        <td>{d.response_status ?? '—'}</td>
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
