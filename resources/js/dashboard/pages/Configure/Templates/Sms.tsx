import { Badge } from '@authn-sh/ui'
import { TemplatesConfigurePage } from '../../../components/TemplatesConfigurePage'

type SmsTemplate = {
    id: string
    slug: string
    body: string
    delivered_by_us: boolean
    from_number_override: string | null
}

type Props = { templates: SmsTemplate[] }

export default function Sms({ templates }: Props) {
    return (
        <TemplatesConfigurePage active="sms">
            <div className="authn-table-wrap">
                <table className="authn-table">
                    <thead>
                        <tr><th>Slug</th><th>Body</th><th>Delivery</th><th>From override</th></tr>
                    </thead>
                    <tbody>
                        {templates.length === 0 && (
                            <tr><td colSpan={4}><div className="authn-empty-state">No SMS templates configured.</div></td></tr>
                        )}
                        {templates.map((t) => (
                            <tr key={t.id}>
                                <td><code>{t.slug}</code></td>
                                <td style={{ whiteSpace: 'pre-wrap' }}>{t.body}</td>
                                <td>
                                    <Badge tone={t.delivered_by_us ? 'success' : 'neutral'}>
                                        {t.delivered_by_us ? 'authn.sh' : 'tenant'}
                                    </Badge>
                                </td>
                                <td>{t.from_number_override ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </TemplatesConfigurePage>
    )
}
