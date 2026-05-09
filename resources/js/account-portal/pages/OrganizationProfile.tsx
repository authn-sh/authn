import { OrganizationProfile as SdkOrganizationProfile, RedirectToSignIn, SignedIn, SignedOut } from '@authn-sh/sdk-react'
import { usePage } from '@inertiajs/react'
import { useBootstrap, type SharedProps } from '../bootstrap'

type Props = {
    organizationId?: string | null
    tab?: string | null
}

export default function OrganizationProfile() {
    const { ready, userProfileProps } = useBootstrap()
    const { props } = usePage<SharedProps & Props>()
    if (!ready) {
        return <p>Loading…</p>
    }

    return (
        <>
            <SignedIn>
                <SdkOrganizationProfile
                    appearance={userProfileProps.appearance}
                    organizationId={props.organizationId ?? undefined}
                    initialTab={props.tab ?? undefined}
                />
            </SignedIn>
            <SignedOut>
                <RedirectToSignIn />
            </SignedOut>
        </>
    )
}
