import type { ReactNode } from 'react'
import { Page } from './Page'
import { PageTabs, type PageTab } from './PageTabs'

const TABS: PageTab[] = [
    { value: 'allowlist', label: 'Allowlist', path: '/configure/restrictions/allowlist' },
    { value: 'blocklist', label: 'Blocklist', path: '/configure/restrictions/blocklist' },
]

export type RestrictionsTab = 'allowlist' | 'blocklist'

export function RestrictionsConfigurePage({
    active,
    actions,
    children,
}: {
    active: RestrictionsTab
    actions?: ReactNode
    children?: ReactNode
}) {
    return (
        <Page
            title="Restrictions"
            tabs={<PageTabs tabs={TABS} active={active} />}
            actions={actions}
        >
            {children}
        </Page>
    )
}
