import type { ReactNode } from 'react'
import { Page } from './Page'
import { PageTabs, type PageTab } from './PageTabs'

const TABS: PageTab[] = [
    { value: 'email', label: 'Email', path: '/configure/templates/email' },
    { value: 'sms', label: 'SMS', path: '/configure/templates/sms' },
]

export type TemplatesTab = 'email' | 'sms'

export function TemplatesConfigurePage({
    active,
    actions,
    children,
}: {
    active: TemplatesTab
    actions?: ReactNode
    children?: ReactNode
}) {
    return (
        <Page
            title="Templates"
            tabs={<PageTabs tabs={TABS} active={active} />}
            actions={actions}
        >
            {children}
        </Page>
    )
}
