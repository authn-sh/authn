import { useBootstrap } from '../bootstrap'

export default function UserProfile() {
    const { ready, env } = useBootstrap()
    if (!ready) {
        return <p>Loading…</p>
    }

    return (
        <div>
            <h1 style={{ marginTop: 0 }}>{env.appearance.application_name ?? 'Authn'} account</h1>
            <p>Loading your profile…</p>
        </div>
    )
}
