type Props = { note: string; entries: Array<{ id: string; type: string; at: number }> }

export default function AuditLog({ note, entries }: Props) {
    return (
        <div>
            <h1>Audit log</h1>
            <p>{note}</p>
            <ul>
                {entries.map(e => (
                    <li key={e.id}>{e.type} — {new Date(e.at).toISOString()}</li>
                ))}
            </ul>
        </div>
    )
}
