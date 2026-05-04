import { AuthnProvider } from '@authn-sh/sdk-react'
import '@authn-sh/ui/styles.css'
import { createInertiaApp, router } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'
import { AccountPortalLayout } from './layouts/AccountPortalLayout'

// The SDK stores the injected `fetch` and calls it through that reference.
// Passing `window.fetch` directly loses the `this=window` binding and the
// browser throws "Illegal invocation" on the first network call.
const boundFetch: typeof globalThis.fetch = (...args) => window.fetch(...args)

/**
 * Account Portal entry. Resolves Inertia pages from `pages/`, wraps each
 * one in <AccountPortalLayout>, and mounts the tree inside an
 * AuthnProvider configured against the env-derived bootstrap props.
 *
 * Real <SignIn /> / <SignUp /> / <UserProfile /> components come from
 * @authn-sh/sdk-react. The pages are thin shims that just hand the
 * relevant props bag through.
 */

type PageModule = { default: React.ComponentType<Record<string, unknown>> & { layout?: (page: React.ReactNode) => React.ReactNode } }
type SharedProps = {
    environment: {
        publishable_key: string
        fapi_url: string
        appearance: Record<string, unknown>
        localization?: { default_locale?: string; supported_locales?: string[]; fallback_locale?: string }
        paths?: { sign_in_url?: string; sign_up_url?: string; after_sign_in_url?: string; after_sign_up_url?: string; after_sign_out_url?: string }
    } | null
}

const pages = import.meta.glob<PageModule>('./pages/*.tsx')

createInertiaApp({
    resolve: async (name) => {
        const path = `./pages/${name.replace(/^AccountPortal\//, '')}.tsx`
        const loader = pages[path]
        if (!loader) {
            throw new Error(`Unknown Inertia page: ${name}`)
        }
        const mod = await loader()
        const page = mod.default
        page.layout ??= (children) => <AccountPortalLayout>{children}</AccountPortalLayout>
        return page
    },
    setup({ el, App, props }) {
        const env = (props.initialPage.props as SharedProps).environment
        const tree = (
            <AuthnProvider
                publishableKey={env?.publishable_key ?? ''}
                domain={env?.fapi_url ? new URL(env.fapi_url).host : undefined}
                appearance={env?.appearance ?? {}}
                signInUrl={env?.paths?.sign_in_url}
                signUpUrl={env?.paths?.sign_up_url}
                signInFallbackRedirectUrl={env?.paths?.after_sign_in_url}
                signUpFallbackRedirectUrl={env?.paths?.after_sign_up_url}
                afterSignOutUrl={env?.paths?.after_sign_out_url}
                fetch={boundFetch}
                routerPush={(url) => router.visit(url)}
                routerReplace={(url) => router.visit(url, { replace: true })}
            >
                <App {...props} />
            </AuthnProvider>
        )
        createRoot(el).render(tree)
    },
})
