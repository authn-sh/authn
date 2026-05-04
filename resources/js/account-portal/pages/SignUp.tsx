import { useBootstrap } from '../bootstrap'

export default function SignUp() {
    const { ready, env } = useBootstrap()
    if (!ready) {
        return <p>Loading…</p>
    }

    return (
        <div>
            <h1 style={{ marginTop: 0 }}>Create your {env.appearance.application_name ?? 'Authn'} account</h1>
            <p>Loading the sign-up form…</p>
        </div>
    )
}
