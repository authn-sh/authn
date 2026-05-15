import { Badge } from '@authn-sh/ui'
import { WebhooksConfigurePage } from '../../../components/WebhooksConfigurePage'

type Delivery = {
    id: string
    webhook_endpoint_id: string
    webhook_event_id: string
    attempt: number
    status: string
    response_status: number | null
    created_at: number | null
}

type Props = { deliveries: Delivery[] }

function deliveryTone(status: string): 'success' | 'danger' | 'warning' | 'neutral' {
    if (status === 'delivered' || status === 'success') return 'success'
    if (status === 'failed' || status === 'error') return 'danger'
    if (status === 'pending' || status === 'retrying') return 'warning'
    return 'neutral'
}

export default function Deliveries({ deliveries }: Props) {
    return (
        <WebhooksConfigurePage active="deliveries">
            <div className="authn-table-wrap">
                <table className="authn-table">
                    <thead>
                        <tr>
                            <th>At</th>
                            <th>Delivery</th>
                            <th>Endpoint</th>
                            <th>Attempt</th>
                            <th>Status</th>
                            <th>HTTP</th>
                        </tr>
                    </thead>
                    <tbody>
                        {deliveries.length === 0 && (
                            <tr>
                                <td colSpan={6}>
                                    <div className="authn-empty-state">No deliveries yet.</div>
                                </td>
                            </tr>
                        )}
                        {deliveries.map((d) => (
                            <tr key={d.id}>
                                <td><code>{d.created_at ? new Date(d.created_at).toISOString() : '—'}</code></td>
                                <td><code>{d.id}</code></td>
                                <td><code>{d.webhook_endpoint_id}</code></td>
                                <td>{d.attempt}</td>
                                <td><Badge tone={deliveryTone(d.status)}>{d.status}</Badge></td>
                                <td>{d.response_status ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </WebhooksConfigurePage>
    )
}
