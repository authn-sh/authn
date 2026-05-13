import { RedirectToSignIn, SignedIn, SignedOut, UserButton } from '@authn-sh/sdk-react'
import { Link, usePage } from '@inertiajs/react'
import type { ReactNode } from 'react'
import { useDashboard, useDashboardUrl } from '../shared'

const SECTIONS = [
    ['Overview', 'overview'],
    ['Users', 'users'],
    ['Sessions', 'sessions'],
    ['Invitations', 'invitations'],
    ['Allowlist', 'allowlist'],
    ['Blocklist', 'blocklist'],
    ['Configure', 'configure'],
    ['Email templates', 'email-templates'],
    ['API keys', 'api-keys'],
    ['Webhooks', 'webhooks'],
    ['Audit log', 'audit-log'],
] as const

export function DashboardLayout({ children }: { children: ReactNode }) {
    const { active_project, active_environment } = useDashboard()
    const url = useDashboardUrl()
    const projectSlug = active_project?.slug
    const envSlug = active_environment?.slug
    const { url: currentUrl } = usePage()

    return (
        <>
            <SignedIn>
                <div className="authn-root authn-shell">
                    <aside className="authn-shell-sidebar">
                        <h2 className="authn-shell-brand">authn.sh</h2>
                        {projectSlug && envSlug ? (
                            <nav className="authn-shell-nav">
                                {SECTIONS.map(([label, slug]) => {
                                    const href = url(`/${projectSlug}/${envSlug}/${slug}`)
                                    const active = currentUrl.startsWith(href)
                                    return (
                                        <Link
                                            key={slug}
                                            href={href}
                                            className="authn-nav-item"
                                            {...(active ? { 'aria-current': 'page' as const } : {})}
                                        >
                                            {label}
                                        </Link>
                                    )
                                })}
                            </nav>
                        ) : (
                            <p className="authn-empty-state" style={{ padding: '1rem 0.5rem', textAlign: 'left', alignItems: 'flex-start' }}>
                                Pick a project to load the sidebar.
                            </p>
                        )}
                    </aside>
                    <div className="authn-shell-main">
                        <header className="authn-shell-topbar">
                            <strong>{active_project?.name ?? 'Dashboard'}</strong>
                            <UserButton />
                        </header>
                        <main className="authn-shell-content">{children}</main>
                    </div>
                </div>
            </SignedIn>
            <SignedOut>
                <RedirectToSignIn />
            </SignedOut>
        </>
    )
}
