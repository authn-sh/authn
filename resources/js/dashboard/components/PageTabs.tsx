import { Link } from '@inertiajs/react'
import { Tabs, TabsList, TabsTrigger } from '@authn-sh/ui'
import { useDashboard, useDashboardUrl } from '../shared'

export type PageTab = { value: string; label: string; path: string }

export function PageTabs({ tabs, active }: { tabs: PageTab[]; active: string }) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    if (!active_project || !active_environment) return null
    const prefix = `/${active_project.slug}/${active_environment.slug}`
    return (
        <Tabs value={active}>
            <TabsList>
                {tabs.map((t) => (
                    <TabsTrigger key={t.value} value={t.value} asChild>
                        <Link href={url(`${prefix}${t.path}`)}>{t.label}</Link>
                    </TabsTrigger>
                ))}
            </TabsList>
        </Tabs>
    )
}

