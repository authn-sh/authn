import { useBootstrap } from '../bootstrap'

/**
 * v0.1 stub. SR-3 swaps in `<SignIn {...signInProps} />` from
 * `@authn.sh/sdk-react` once that package is published.
 */
export default function SignIn() {
    const { ready, env } = useBootstrap()
    if (!ready) {
        return <p>Loading…</p>
    }

    return (
        <div>
            <h1 style={{ marginTop: 0 }}>Sign in to {env.appearance.application_name ?? 'Authn'}</h1>
            <p>Loading the sign-in form…</p>
        </div>
    )
}
