import type { ReactNode } from 'react'
import { Page } from './Page'
import { PageTabs, type PageTab } from './PageTabs'

const TABS: PageTab[] = [
    { value: 'appearance', label: 'Appearance', path: '/configure/branding/appearance' },
    { value: 'localization', label: 'Localization', path: '/configure/branding/localization' },
]

export type BrandingTab = 'appearance' | 'localization'

export function BrandingConfigurePage({
    active,
    actions,
    children,
}: {
    active: BrandingTab
    actions?: ReactNode
    children?: ReactNode
}) {
    return (
        <Page
            title="Branding"
            tabs={<PageTabs tabs={TABS} active={active} />}
            actions={actions}
        >
            {children}
        </Page>
    )
}
