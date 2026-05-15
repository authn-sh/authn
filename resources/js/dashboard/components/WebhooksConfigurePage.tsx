import type { ReactNode } from 'react'
import { Page } from './Page'
import { PageTabs, type PageTab } from './PageTabs'

const TABS: PageTab[] = [
    { value: 'endpoints', label: 'Endpoints', path: '/configure/webhooks/endpoints' },
    { value: 'deliveries', label: 'Deliveries', path: '/configure/webhooks/deliveries' },
]

export type WebhooksTab = 'endpoints' | 'deliveries'

export function WebhooksConfigurePage({
    active,
    actions,
    children,
}: {
    active: WebhooksTab
    actions?: ReactNode
    children?: ReactNode
}) {
    return (
        <Page
            title="Webhooks"
            tabs={<PageTabs tabs={TABS} active={active} />}
            actions={actions}
        >
            {children}
        </Page>
    )
}
