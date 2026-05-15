import type { ReactNode } from 'react'
import { Page } from './Page'
import { PageTabs, type PageTab } from './PageTabs'

const TABS: PageTab[] = [
    { value: 'roles', label: 'Roles', path: '/configure/authorization/roles' },
    { value: 'permissions', label: 'Permissions', path: '/configure/authorization/permissions' },
]

export type AuthorizationTab = 'roles' | 'permissions'

export function AuthorizationConfigurePage({
    active,
    actions,
    children,
}: {
    active: AuthorizationTab
    actions?: ReactNode
    children?: ReactNode
}) {
    return (
        <Page
            title="Authorization"
            tabs={<PageTabs tabs={TABS} active={active} />}
            actions={actions}
        >
            {children}
        </Page>
    )
}
