import { CreateOrganization as SdkCreateOrganization, RedirectToSignIn, SignedIn, SignedOut } from '@authn-sh/sdk-react'
import { useBootstrap } from '../bootstrap'

export default function CreateOrganization() {
    const { ready, userProfileProps } = useBootstrap()
    if (!ready) {
        return <p>Loading…</p>
    }

    return (
        <>
            <SignedIn>
                <SdkCreateOrganization appearance={userProfileProps.appearance} />
            </SignedIn>
            <SignedOut>
                <RedirectToSignIn />
            </SignedOut>
        </>
    )
}
