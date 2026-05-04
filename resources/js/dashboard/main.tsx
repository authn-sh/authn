import { AuthnProvider } from '@authn-sh/sdk-react'
import { createInertiaApp, router } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'
import { DashboardLayout } from './layouts/DashboardLayout'

type PageModule = { default: React.ComponentType<Record<string, unknown>> & { layout?: (page: React.ReactNode) => React.ReactNode } }
type SharedProps = {
    admin_environment: {
        publishable_key: string
        fapi_url: string
        sign_in_url?: string
        after_sign_out_url?: string
    } | null
}

const pages = import.meta.glob<PageModule>('./pages/*.tsx')

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
        const env = (props.initialPage.props as SharedProps).admin_environment
        const tree = (
            <AuthnProvider
                publishableKey={env?.publishable_key ?? ''}
                domain={env?.fapi_url ? new URL(env.fapi_url).host : undefined}
                signInUrl={env?.sign_in_url}
                afterSignOutUrl={env?.after_sign_out_url}
                routerPush={(url) => router.visit(url)}
                routerReplace={(url) => router.visit(url, { replace: true })}
            >
                <App {...props} />
            </AuthnProvider>
        )
        createRoot(el).render(tree)
    },
})
