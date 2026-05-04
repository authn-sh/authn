type Props = {
    counts: {
        users: number
        sessions_active: number
        invitations_pending: number
    }
}

export default function Overview({ counts }: Props) {
    return (
        <div>
            <h1>Overview</h1>
            <ul>
                <li>Users: {counts.users}</li>
                <li>Active sessions: {counts.sessions_active}</li>
                <li>Pending invitations: {counts.invitations_pending}</li>
            </ul>
        </div>
    )
}
