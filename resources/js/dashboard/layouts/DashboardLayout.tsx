import type { ReactNode } from 'react'
import { Link } from '@inertiajs/react'
import { useDashboard } from '../shared'

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

/**
 * Sidebar + topbar shell for the Dashboard. Heavy widgets land in a
 * follow-up — v0.1 keeps it intentionally plain so the wire-up is testable
 * without React component dependencies.
 */
export function DashboardLayout({ children }: { children: ReactNode }) {
    const { operator, active_project, active_environment } = useDashboard()
    const projectSlug = active_project?.slug
    const envSlug = active_environment?.slug

    return (
        <div style={{ display: 'flex', minHeight: '100dvh', fontFamily: 'system-ui, -apple-system, sans-serif' }}>
            <aside style={{ width: 240, padding: 20, borderRight: '1px solid #e2e8f0', background: '#f8fafc' }}>
                <h2 style={{ fontSize: 16, marginTop: 0 }}>authn.sh</h2>
                {projectSlug && envSlug ? (
                    <nav style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
                        {SECTIONS.map(([label, slug]) => (
                            <Link
                                key={slug}
                                href={`/${projectSlug}/${envSlug}/${slug}`}
                                style={{ padding: '6px 8px', borderRadius: 6, color: '#0f172a', textDecoration: 'none' }}
                            >
                                {label}
                            </Link>
                        ))}
                    </nav>
                ) : (
                    <p style={{ color: '#64748b', fontSize: 13 }}>Pick a project to load the sidebar.</p>
                )}
            </aside>
            <main style={{ flex: 1, padding: 32 }}>
                <header style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 24 }}>
                    <strong>{active_project?.name ?? 'Dashboard'}</strong>
                    <span style={{ color: '#475569' }}>{operator?.name ?? 'Operator'}</span>
                </header>
                {children}
            </main>
        </div>
    )
}
