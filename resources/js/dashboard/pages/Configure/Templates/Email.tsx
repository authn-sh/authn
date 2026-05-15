import { Badge } from '@authn-sh/ui'
import { TemplatesConfigurePage } from '../../../components/TemplatesConfigurePage'

type EmailTemplate = {
    id: string
    slug: string
    subject: string
    delivered_by_us: boolean
    updated_at: number | null
}

type Props = { templates: EmailTemplate[] }

export default function Email({ templates }: Props) {
    return (
        <TemplatesConfigurePage active="email">
            <div className="authn-table-wrap">
                <table className="authn-table">
                    <thead>
                        <tr><th>Slug</th><th>Subject</th><th>Delivery</th></tr>
                    </thead>
                    <tbody>
                        {templates.length === 0 && (
                            <tr><td colSpan={3}><div className="authn-empty-state">No email templates configured.</div></td></tr>
                        )}
                        {templates.map((t) => (
                            <tr key={t.id}>
                                <td><code>{t.slug}</code></td>
                                <td>{t.subject}</td>
                                <td>
                                    <Badge tone={t.delivered_by_us ? 'success' : 'neutral'}>
                                        {t.delivered_by_us ? 'authn.sh' : 'tenant'}
                                    </Badge>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </TemplatesConfigurePage>
    )
}
