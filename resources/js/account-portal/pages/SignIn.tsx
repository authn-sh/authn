import { SignIn as SdkSignIn } from '@authn-sh/sdk-react'
import { useBootstrap } from '../bootstrap'

export default function SignIn() {
    const { ready, env, signInProps } = useBootstrap()
    if (!ready) {
        return <p>Loading…</p>
    }

    // `routing="virtual"` keeps factor-one / factor-two / reset-password state
    // in component memory instead of mutating the URL — tenants embedding
    // <SignIn /> in their own app need exactly one route. The Laravel route
    // stays `/sign-in/{step?}` so deep-links from emails still resolve.
    return (
        <SdkSignIn
            routing="virtual"
            signUpUrl={signInProps.signUpUrl}
            fallbackRedirectUrl={signInProps.afterSignInUrl}
            appearance={signInProps.appearance}
        />
    )
}
