import { usePage } from '@inertiajs/react'

export type Operator = {
    id: string
    name: string
    first_name: string | null
    last_name: string | null
    image_url: string | null
}

export type Workspace = { id: string; role: string } | null

export type ActiveProject = { id: string; slug: string; name: string } | null

export type ActiveEnvironment = {
    id: string
    slug: string
    kind: string
    frontend_api_host: string
} | null

export type DashboardSharedProps = {
    dashboard_prefix: string
    operator: Operator | null
    workspace: Workspace
    sign_in_url: string | null
    active_project: ActiveProject
    active_environment: ActiveEnvironment
}

export function useDashboard() {
    return usePage<DashboardSharedProps>().props
}

export function useDashboardUrl() {
    const prefix = (usePage<DashboardSharedProps>().props.dashboard_prefix ?? '').replace(/\/+$/, '')
    return (path: string) => prefix + (path.startsWith('/') ? path : `/${path}`)
}
