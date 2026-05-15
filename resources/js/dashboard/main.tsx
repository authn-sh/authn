import { AuthnProvider } from '@authn-sh/sdk-react'
import '@authn-sh/ui/styles.css'
import { createInertiaApp, router } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'
import { DashboardLayout } from './layouts/DashboardLayout'

// The Dashboard and Account Portal are separate Inertia apps with their
// own Blade root + page resolver. SDK navigations like sign-in / sign-out
// cross that boundary, so we hand them off via `window.location.assign`
// for a full page load. Same-app navigations stick with Inertia.
function makeNavigate(prefix: string) {
    return (url: string, replace = false) => {
        try {
            const target = new URL(url, window.location.origin)
            const sameOrigin = target.origin === window.location.origin
            // In subdomain mode `prefix` is empty, so any same-host URL counts as
            // in-app — that's correct because each surface has its own host.
            const inDashboard = prefix === ''
                ? sameOrigin
                : target.pathname === prefix || target.pathname.startsWith(`${prefix}/`)
            if (sameOrigin && inDashboard) {
                router.visit(url, replace ? { replace: true } : {})
                return
            }
        } catch {
            /* fall through to hard load */
        }
        if (replace) window.location.replace(url)
        else window.location.assign(url)
    }
}

// The SDK stores the injected `fetch` and calls it through that reference;
// passing `window.fetch` directly loses the `this=window` binding and the
// browser throws "Illegal invocation" on the first network call.
const boundFetch: typeof globalThis.fetch = (...args) => window.fetch(...args)

type PageModule = { default: React.ComponentType<Record<string, unknown>> & { layout?: (page: React.ReactNode) => React.ReactNode } }
type SharedProps = {
    dashboard_prefix?: string
    admin_environment: {
        publishable_key: string
        fapi_url: string
        sign_in_url?: string
        after_sign_out_url?: string
    } | null
}

const pages = import.meta.glob<PageModule>('./pages/**/*.tsx')

createInertiaApp({
    resolve: async (name) => {
        const path = `./pages/${name.replace(/^Dashboard\//, '')}.tsx`
        const loader = pages[path]
        if (!loader) {
            throw new Error(`Unknown Dashboard Inertia page: ${name}`)
        }
        const mod = await loader()
        const page = mod.default
        page.layout ??= (children) => <DashboardLayout>{children}</DashboardLayout>
        return page
    },
    setup({ el, App, props }) {
        const shared = props.initialPage.props as SharedProps
        const env = shared.admin_environment
        const navigate = makeNavigate(shared.dashboard_prefix ?? '')
        const tree = (
            <AuthnProvider
                publishableKey={env?.publishable_key ?? ''}
                domain={env?.fapi_url}
                signInUrl={env?.sign_in_url}
                afterSignOutUrl={env?.after_sign_out_url}
                fetch={boundFetch}
                routerPush={(url) => navigate(url)}
                routerReplace={(url) => navigate(url, true)}
            >
                <App {...props} />
            </AuthnProvider>
        )
        createRoot(el).render(tree)
    },
})
