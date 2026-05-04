import { SignUp as SdkSignUp } from '@authn-sh/sdk-react'
import { useBootstrap } from '../bootstrap'

export default function SignUp() {
    const { ready, env, signUpProps } = useBootstrap()
    if (!ready) {
        return <p>Loading…</p>
    }

    return (
        <SdkSignUp
            path={env.paths.sign_up_url}
            signInUrl={signUpProps.signInUrl}
            fallbackRedirectUrl={signUpProps.afterSignUpUrl}
            appearance={signUpProps.appearance}
        />
    )
}
