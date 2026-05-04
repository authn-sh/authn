import { RedirectToSignIn, SignedIn, SignedOut, UserProfile as SdkUserProfile } from '@authn-sh/sdk-react'
import { useBootstrap } from '../bootstrap'

export default function UserProfile() {
    const { ready, userProfileProps } = useBootstrap()
    if (!ready) {
        return <p>Loading…</p>
    }

    return (
        <>
            <SignedIn>
                <SdkUserProfile appearance={userProfileProps.appearance} />
            </SignedIn>
            <SignedOut>
                <RedirectToSignIn />
            </SignedOut>
        </>
    )
}
