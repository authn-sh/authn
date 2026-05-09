import { OrganizationList as SdkOrganizationList, RedirectToSignIn, SignedIn, SignedOut } from '@authn-sh/sdk-react'
import { useBootstrap } from '../bootstrap'

export default function OrganizationList() {
    const { ready, userProfileProps } = useBootstrap()
    if (!ready) {
        return <p>Loading…</p>
    }

    return (
        <>
            <SignedIn>
                <SdkOrganizationList appearance={userProfileProps.appearance} />
            </SignedIn>
            <SignedOut>
                <RedirectToSignIn />
            </SignedOut>
        </>
    )
}
