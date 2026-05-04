import { createInertiaApp } from '@inertiajs/react'
import { createRoot } from 'react-dom/client'
import { DashboardLayout } from './layouts/DashboardLayout'

type PageModule = { default: React.ComponentType<Record<string, unknown>> & { layout?: (page: React.ReactNode) => React.ReactNode } }

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
        createRoot(el).render(<App {...props} />)
    },
})
