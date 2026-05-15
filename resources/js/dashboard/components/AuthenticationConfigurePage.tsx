import type { ReactNode } from 'react'
import { Page } from './Page'
import { PageTabs, type PageTab } from './PageTabs'

const TABS: PageTab[] = [
    { value: 'sign-in', label: 'Sign in', path: '/configure/authentication/sign-in' },
    { value: 'sign-up', label: 'Sign up', path: '/configure/authentication/sign-up' },
    { value: 'mfa', label: 'MFA', path: '/configure/authentication/mfa' },
    { value: 'providers', label: 'Providers', path: '/configure/authentication/providers' },
    { value: 'enterprise-sso', label: 'Enterprise SSO', path: '/configure/authentication/enterprise-sso' },
    { value: 'jwt-templates', label: 'JWT Templates', path: '/configure/authentication/jwt-templates' },
]

export type AuthenticationTab = 'sign-in' | 'sign-up' | 'mfa' | 'providers' | 'enterprise-sso' | 'jwt-templates'

export function AuthenticationConfigurePage({
    active,
    actions,
    children,
}: {
    active: AuthenticationTab
    actions?: ReactNode
    children?: ReactNode
}) {
    return (
        <Page
            title="Authentication"
            tabs={<PageTabs tabs={TABS} active={active} />}
            actions={actions}
        >
            {children}
        </Page>
    )
}
