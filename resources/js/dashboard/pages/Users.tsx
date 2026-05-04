type Props = {
    users: Array<{ id: string; first_name: string | null; last_name: string | null; username: string | null; banned: boolean; locked: boolean }>
    query: string
}

export default function Users({ users, query }: Props) {
    return (
        <div>
            <h1>Users</h1>
            <p>Filter: <code>{query || '—'}</code></p>
            <table>
                <thead>
                    <tr><th>ID</th><th>Name</th><th>Status</th></tr>
                </thead>
                <tbody>
                    {users.map(u => (
                        <tr key={u.id}>
                            <td>{u.id}</td>
                            <td>{[u.first_name, u.last_name].filter(Boolean).join(' ') || u.username || '—'}</td>
                            <td>{u.banned ? 'banned' : u.locked ? 'locked' : 'active'}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    )
}
