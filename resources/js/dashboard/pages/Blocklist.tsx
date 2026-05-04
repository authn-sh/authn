type Props = { rows: Array<{ id: string; identifier: string }> }

export default function Blocklist({ rows }: Props) {
    return (
        <div>
            <h1>Blocklist</h1>
            <p>{rows.length} entries.</p>
        </div>
    )
}
