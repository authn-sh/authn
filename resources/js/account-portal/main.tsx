import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'
import { AccountPortalLayout } from './layouts/AccountPortalLayout'

/**
 * Account Portal entry. Resolves Inertia pages from `pages/`, wraps each
 * one in <AccountPortalLayout>, and mounts.
 *
 * The pages themselves are intentionally tiny — the heavy lifting lives in
 * `@authn.sh/sdk-react` (SR-3). v0.1 ships stubs that render "Loading…"
 * until the SDK package is available.
 */

type PageModule = { default: React.ComponentType<Record<string, unknown>> & { layout?: (page: React.ReactNode) => React.ReactNode } }

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
        createRoot(el).render(<App {...props} />)
    },
})
