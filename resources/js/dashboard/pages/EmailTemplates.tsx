type Props = {
    templates: Array<{ id: string; slug: string; subject: string; delivered_by_us: boolean }>
}

export default function EmailTemplates({ templates }: Props) {
    return (
        <div>
            <h1>Email templates</h1>
            <ul>
                {templates.map(t => (
                    <li key={t.id}>{t.slug} — <em>{t.subject}</em></li>
                ))}
            </ul>
        </div>
    )
}
