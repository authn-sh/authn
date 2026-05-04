import { SignIn as SdkSignIn } from '@authn-sh/sdk-react'
import { useBootstrap } from '../bootstrap'

export default function SignIn() {
    const { ready, env, signInProps } = useBootstrap()
    if (!ready) {
        return <p>Loading…</p>
    }

    return (
        <SdkSignIn
            path={env.paths.sign_in_url}
            signUpUrl={signInProps.signUpUrl}
            fallbackRedirectUrl={signInProps.afterSignInUrl}
            appearance={signInProps.appearance}
        />
    )
}
