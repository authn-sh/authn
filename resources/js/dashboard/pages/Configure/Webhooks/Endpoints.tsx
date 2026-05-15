import { usePage } from '@inertiajs/react'
import { Alert, Badge } from '@authn-sh/ui'
import { WebhooksConfigurePage } from '../../../components/WebhooksConfigurePage'

type Endpoint = { id: string; url: string; enabled: boolean; signing_secret_prefix: string }
type Props = { endpoints: Endpoint[] }

export default function Endpoints({ endpoints }: Props) {
    const { props } = usePage<{ flash?: { signing_secret?: string } }>()
    return (
        <WebhooksConfigurePage active="endpoints">
            {props.flash?.signing_secret && (
                <Alert tone="warning">
                    Save this signing secret — it won't be shown again: <code>{props.flash.signing_secret}</code>
                </Alert>
            )}
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
        </WebhooksConfigurePage>
    )
}
