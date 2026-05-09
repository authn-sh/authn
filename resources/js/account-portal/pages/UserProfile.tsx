import { OrganizationSwitcher, RedirectToSignIn, SignedIn, SignedOut, UserProfile as SdkUserProfile } from '@authn-sh/sdk-react'
import { useBootstrap } from '../bootstrap'

export default function UserProfile() {
    const { ready, userProfileProps } = useBootstrap()
    if (!ready) {
        return <p>Loading…</p>
    }

    return (
        <>
            <SignedIn>
                <header className="account-portal__header">
                    <OrganizationSwitcher
                        appearance={userProfileProps.appearance}
                        createOrganizationUrl="/create-organization"
                        organizationProfileUrl="/organization"
                    />
                </header>
                <SdkUserProfile appearance={userProfileProps.appearance} />
            </SignedIn>
            <SignedOut>
                <RedirectToSignIn />
            </SignedOut>
        </>
    )
}
