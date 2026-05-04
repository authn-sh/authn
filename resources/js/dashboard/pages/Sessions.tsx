type Props = {
    sessions: Array<{ id: string; user_id: string; status: string }>
}

export default function Sessions({ sessions }: Props) {
    return (
        <div>
            <h1>Sessions</h1>
            <p>{sessions.length} live session(s).</p>
        </div>
    )
}
