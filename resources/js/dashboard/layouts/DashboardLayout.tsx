import { RedirectToSignIn, SignedIn, SignedOut, UserButton } from '@authn-sh/sdk-react'
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@authn-sh/ui'
import { Link, usePage } from '@inertiajs/react'
import { useEffect, useState, type ReactNode } from 'react'
import {
    BookIcon,
    BoxIcon,
    BuildingIcon,
    ChevronDownIcon,
    ChevronsLeftIcon,
    ChevronsRightIcon,
    FileTextIcon,
    HomeIcon,
    KeyIcon,
    MonitorIcon,
    MoonIcon,
    PlusIcon,
    ScrollIcon,
    ShieldCheckIcon,
    ShieldIcon,
    ShieldOffIcon,
    SlidersIcon,
    SunIcon,
    UsersIcon,
    WebhookIcon,
} from '../icons'
import { useDashboard, useDashboardUrl } from '../shared'

type IconCmp = (props: { className?: string }) => React.ReactElement
type NavItem = { label: string; slug: string; Icon: IconCmp; matches?: string[] }
type NavSection = { label?: string; items: NavItem[] }

const NAV: NavSection[] = [
    {
        items: [
            { label: 'Overview', slug: 'overview', Icon: HomeIcon },
            { label: 'Users', slug: 'users', Icon: UsersIcon },
            { label: 'Organizations', slug: 'organizations', Icon: BuildingIcon },
            { label: 'Audit log', slug: 'audit-log', Icon: ScrollIcon },
        ],
    },
    {
        label: 'Configure',
        items: [
            { label: 'Authentication', slug: 'configure/authentication', Icon: ShieldCheckIcon },
            { label: 'Authorization', slug: 'configure/authorization', Icon: ShieldIcon },
            { label: 'Applications', slug: 'configure/applications', Icon: BoxIcon },
            { label: 'Restrictions', slug: 'configure/restrictions', Icon: ShieldOffIcon },
            { label: 'Domains', slug: 'configure/domains', Icon: BuildingIcon },
            { label: 'Redirects', slug: 'configure/redirects', Icon: WebhookIcon },
            { label: 'API Keys', slug: 'configure/api-keys', Icon: KeyIcon },
            { label: 'IdP Attributes', slug: 'configure/idp-attributes', Icon: SlidersIcon },
            { label: 'Branding', slug: 'configure/branding', Icon: SlidersIcon },
            { label: 'Templates', slug: 'configure/templates', Icon: FileTextIcon },
            { label: 'Webhooks', slug: 'configure/webhooks', Icon: WebhookIcon },
        ],
    },
]

const COLLAPSE_KEY = 'authn.dashboard.sidebar.collapsed'

function useCollapsed(): [boolean, (next: boolean) => void] {
    const [collapsed, setCollapsed] = useState(false)
    useEffect(() => {
        try {
            setCollapsed(localStorage.getItem(COLLAPSE_KEY) === '1')
        } catch {
            /* ignore */
        }
    }, [])
    const set = (next: boolean) => {
        setCollapsed(next)
        try {
            localStorage.setItem(COLLAPSE_KEY, next ? '1' : '0')
        } catch {
            /* ignore */
        }
    }
    return [collapsed, set]
}

type ThemeMode = 'auto' | 'light' | 'dark'
const THEME_KEY = 'authn.dashboard.theme'

function useTheme(): [ThemeMode, () => void] {
    const [mode, setMode] = useState<ThemeMode>('auto')
    useEffect(() => {
        try {
            const stored = localStorage.getItem(THEME_KEY) as ThemeMode | null
            if (stored === 'light' || stored === 'dark') {
                setMode(stored)
                document.documentElement.setAttribute('data-theme', stored)
            } else {
                setMode('auto')
                document.documentElement.removeAttribute('data-theme')
            }
        } catch {
            /* ignore */
        }
    }, [])
    const cycle = () => {
        const next: ThemeMode = mode === 'auto' ? 'light' : mode === 'light' ? 'dark' : 'auto'
        setMode(next)
        try {
            if (next === 'auto') {
                localStorage.removeItem(THEME_KEY)
                document.documentElement.removeAttribute('data-theme')
            } else {
                localStorage.setItem(THEME_KEY, next)
                document.documentElement.setAttribute('data-theme', next)
            }
        } catch {
            /* ignore */
        }
    }
    return [mode, cycle]
}

export function DashboardLayout({ children }: { children: ReactNode }) {
    const { active_project, active_environment, projects, environments, dashboard_prefix } = useDashboard()
    const url = useDashboardUrl()
    const projectSlug = active_project?.slug
    const envSlug = active_environment?.slug
    const { url: currentUrl } = usePage()
    const [collapsed, setCollapsed] = useCollapsed()
    const [theme, cycleTheme] = useTheme()

    return (
        <>
            <SignedIn>
                <div className="authn-root authn-shell">
                    <aside className="authn-shell-sidebar" data-collapsed={collapsed || undefined}>
                        <div className="authn-shell-brand-row">
                            <h2 className="authn-shell-brand">authn.sh</h2>
                            <button
                                type="button"
                                className="authn-collapse-toggle"
                                onClick={() => setCollapsed(!collapsed)}
                                aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                                title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
                            >
                                {collapsed ? <ChevronsRightIcon /> : <ChevronsLeftIcon />}
                            </button>
                        </div>
                        {projectSlug && envSlug ? (
                            <>
                                {NAV.map((section, idx) => (
                                    <div key={section.label ?? `section-${idx}`}>
                                        {section.label && (
                                            <p className="authn-shell-section-title">{section.label}</p>
                                        )}
                                        <nav className="authn-shell-nav">
                                            {section.items.map(({ label, slug, Icon, matches }) => {
                                                const href = url(`/${projectSlug}/${envSlug}/${slug}`)
                                                const matchPrefixes = [slug, ...(matches ?? [])]
                                                const active = matchPrefixes.some((m) =>
                                                    currentUrl.startsWith(url(`/${projectSlug}/${envSlug}/${m}`)),
                                                )
                                                return (
                                                    <Link
                                                        key={slug}
                                                        href={href}
                                                        className="authn-nav-item"
                                                        title={collapsed ? label : undefined}
                                                        {...(active ? { 'aria-current': 'page' as const } : {})}
                                                    >
                                                        <Icon />
                                                        <span className="authn-nav-item-label">{label}</span>
                                                    </Link>
                                                )
                                            })}
                                        </nav>
                                    </div>
                                ))}
                            </>
                        ) : (
                            !collapsed && (
                                <div className="authn-empty-state" style={{ padding: '1rem 0.5rem', textAlign: 'left', alignItems: 'flex-start' }}>
                                    Pick a project to load the sidebar.
                                </div>
                            )
                        )}
                    </aside>
                    <div className="authn-shell-main">
                        <header className="authn-shell-topbar">
                            <div className="authn-shell-topbar-left">
                                <ContextPickers
                                    activeProject={active_project}
                                    activeEnv={active_environment}
                                    projects={projects}
                                    environments={environments}
                                    dashboardPrefix={dashboard_prefix}
                                />
                            </div>
                            <div className="authn-shell-topbar-right">
                                <a
                                    href="https://authn.sh/docs"
                                    target="_blank"
                                    rel="noreferrer"
                                    className="authn-icon-button"
                                    data-shape="pill"
                                    title="Docs"
                                >
                                    <BookIcon />
                                    <span>Docs</span>
                                </a>
                                <button
                                    type="button"
                                    className="authn-icon-button"
                                    onClick={cycleTheme}
                                    title={`Theme: ${theme} (click to change)`}
                                    aria-label={`Theme: ${theme} (click to change)`}
                                >
                                    {theme === 'light' ? <SunIcon /> : theme === 'dark' ? <MoonIcon /> : <MonitorIcon />}
                                </button>
                                <UserButton />
                            </div>
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

type ContextPickerProps = {
    activeProject: { id: string; slug: string; name: string } | null
    activeEnv: { id: string; slug: string; kind: string } | null
    projects: Array<{ id: string; slug: string; name: string }>
    environments: Array<{ id: string; slug: string; kind: string }>
    dashboardPrefix: string
}

function ContextPickers({ activeProject, activeEnv, projects, environments, dashboardPrefix }: ContextPickerProps) {
    const prefix = dashboardPrefix.replace(/\/+$/, '')
    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger className="authn-context-picker">
                    <span>{activeProject?.name ?? 'Select project'}</span>
                    <ChevronDownIcon className="authn-context-picker-chevron" />
                </DropdownMenuTrigger>
                <DropdownMenuContent className="authn-context-menu" sideOffset={6} align="start">
                        <p className="authn-context-menu-section">Projects</p>
                        {projects.length === 0 && (
                            <div className="authn-context-menu-item" style={{ color: 'var(--authn-color-text-secondary)' }}>
                                No projects yet
                            </div>
                        )}
                        {projects.map((p) => (
                            <DropdownMenuItem key={p.id} asChild>
                                <Link
                                    href={`${prefix}/${p.slug}/${activeEnv?.slug ?? 'production'}/overview`}
                                    className="authn-context-menu-item"
                                    {...(p.id === activeProject?.id ? { 'aria-current': 'true' as const } : {})}
                                >
                                    <span>{p.name}</span>
                                    {p.id === activeProject?.id && <span style={{ color: 'var(--authn-color-text-secondary)' }}>active</span>}
                                </Link>
                            </DropdownMenuItem>
                        ))}
                        <div className="authn-context-menu-separator" />
                        <DropdownMenuItem asChild>
                            <Link href={`${prefix}/create-project`} className="authn-context-menu-item">
                                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                                    <PlusIcon /> Create project
                                </span>
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
            </DropdownMenu>
            {activeProject && (
                <>
                    <span className="authn-context-divider" aria-hidden />
                    <DropdownMenu>
                        <DropdownMenuTrigger className="authn-context-picker" data-kind={activeEnv?.kind}>
                            <BoxIcon className="authn-context-picker-chevron" />
                            <span className="authn-context-picker-kind">{activeEnv?.kind ?? 'environment'}</span>
                            <ChevronDownIcon className="authn-context-picker-chevron" />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent className="authn-context-menu" sideOffset={6} align="start">
                            <p className="authn-context-menu-section">Environments</p>
                            {environments.length === 0 && (
                                <div className="authn-context-menu-item" style={{ color: 'var(--authn-color-text-secondary)' }}>
                                    No environments
                                </div>
                            )}
                            {environments.map((e) => (
                                <DropdownMenuItem key={e.id} asChild>
                                    <Link
                                        href={`${prefix}/${activeProject.slug}/${e.slug}/overview`}
                                        className="authn-context-menu-item"
                                        {...(e.id === activeEnv?.id ? { 'aria-current': 'true' as const } : {})}
                                    >
                                        <span>{e.kind}</span>
                                        <code style={{ color: 'var(--authn-color-text-secondary)' }}>{e.slug}</code>
                                    </Link>
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </>
            )}
        </>
    )
}
