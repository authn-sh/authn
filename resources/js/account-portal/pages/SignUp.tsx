import { SignUp as SdkSignUp } from '@authn-sh/sdk-react'
import { useBootstrap } from '../bootstrap'

export default function SignUp() {
    const { ready, env, signUpProps } = useBootstrap()
    if (!ready) {
        return <p>Loading…</p>
    }

    // See SignIn.tsx — `routing="virtual"` keeps verify-email-address /
    // verify-phone-number / continue steps in component state so tenants
    // need only one route per surface.
    return (
        <SdkSignUp
            routing="virtual"
            signInUrl={signUpProps.signInUrl}
            fallbackRedirectUrl={signUpProps.afterSignUpUrl}
            appearance={signUpProps.appearance}
        />
    )
}
