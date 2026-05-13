import { Badge } from '@authn-sh/ui'

type Props = {
    templates: Array<{ id: string; slug: string; subject: string; delivered_by_us: boolean }>
}

export default function EmailTemplates({ templates }: Props) {
    return (
        <>
            <div className="authn-page-header">
                <h1 className="authn-page-title">Email templates</h1>
            </div>
            <div className="authn-table-wrap">
                <table className="authn-table">
                    <thead>
                        <tr><th>Slug</th><th>Subject</th><th>Delivery</th></tr>
                    </thead>
                    <tbody>
                        {templates.length === 0 && (
                            <tr>
                                <td colSpan={3}>
                                    <div className="authn-empty-state">No email templates configured.</div>
                                </td>
                            </tr>
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
        </>
    )
}
