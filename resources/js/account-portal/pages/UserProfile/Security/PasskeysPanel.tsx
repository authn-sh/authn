import { RedirectToSignIn, SignedIn, SignedOut, UserProfilePasskeysPanel } from '@authn-sh/sdk-react'
import { useBootstrap } from '../../../bootstrap'
import { useLocale } from '../../../LocaleProvider'

/**
 * Account Portal Security → Passkeys page. Wraps sdk-react's
 * `<UserProfilePasskeysPanel />` (JS-6) with the Inertia bootstrap +
 * locale context. The bundled SDK component handles the
 * list / add / rename / remove cycle, surfaces the unsupported-browser
 * alert, and re-reads `user.passkeys` from AuthnProvider's snapshot
 * store so it stays in sync as the ceremony completes.
 */
export default function PasskeysPanel() {
    const { ready } = useBootstrap()
    const { t } = useLocale()
    if (!ready) {
        return <p>Loading…</p>
    }

    return (
        <>
            <SignedIn>
                <h1>{t('userProfile.start.passkeysSection.title')}</h1>
                <UserProfilePasskeysPanel />
            </SignedIn>
            <SignedOut>
                <RedirectToSignIn />
            </SignedOut>
        </>
    )
}
