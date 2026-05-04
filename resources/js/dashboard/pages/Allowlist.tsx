type Props = { rows: Array<{ id: string; identifier: string; notify: boolean }> }

export default function Allowlist({ rows }: Props) {
    return (
        <div>
            <h1>Allowlist</h1>
            <p>{rows.length} entries.</p>
        </div>
    )
}
