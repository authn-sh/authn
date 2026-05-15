import { router } from '@inertiajs/react'
import * as React from 'react'
import {
    Badge,
    Button,
    Card,
    CardHeader,
    socialIcons,
    type ProviderKey,
} from '@authn-sh/ui'
import { MethodGrid } from '../../../components/MethodToggle'
import { AuthenticationConfigurePage } from '../../../components/AuthenticationConfigurePage'
import { useDashboard, useDashboardUrl } from '../../../shared'

type OauthProvider = {
    id: string
    provider_kind: 'preset' | 'custom_oidc' | 'custom_oauth2'
    provider_key: string
    name: string
    enabled: boolean
    client_id: string
}

type Props = {
    oauth_providers: OauthProvider[]
    oauth_preset_keys: string[]
}

function brandIcon(key: string) {
    const Icon = (socialIcons as Record<string, React.ComponentType<{ size?: number }>>)[key as ProviderKey]
    return Icon ? <Icon size={24} /> : null
}

type ProviderListItem = {
    key: string
    name: string
    kind: 'preset' | 'custom_oidc' | 'custom_oauth2'
    status: 'enabled' | 'disabled' | 'not_configured'
}

export default function Providers({ oauth_providers, oauth_preset_keys }: Props) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    if (!active_project || !active_environment) {
        return <p>No active environment.</p>
    }
    const providersBase = `/${active_project.slug}/${active_environment.slug}/configure/authentication/providers`

    const byKey = new Map(oauth_providers.map((p) => [p.provider_key, p]))
    const presetItems: ProviderListItem[] = oauth_preset_keys.map((k) => {
        const row = byKey.get(k)
        const configured = row !== undefined && row.client_id !== ''
        return {
            key: k,
            name: row?.name ?? k.charAt(0).toUpperCase() + k.slice(1),
            kind: 'preset',
            status: !configured ? 'not_configured' : row!.enabled ? 'enabled' : 'disabled',
        }
    })
    const customItems: ProviderListItem[] = oauth_providers
        .filter((p) => p.provider_kind !== 'preset')
        .map((p) => ({
            key: p.provider_key,
            name: p.name,
            kind: p.provider_kind,
            status: p.enabled ? 'enabled' : 'disabled',
        }))
    const items = [...presetItems, ...customItems]

    return (
        <AuthenticationConfigurePage
            active="providers"
            actions={
                <>
                    <Button type="button" variant="secondary" onClick={() => router.visit(url(`${providersBase}/new-oidc`))}>
                        + Add custom OIDC
                    </Button>
                    <Button type="button" variant="secondary" onClick={() => router.visit(url(`${providersBase}/new-oauth2`))}>
                        + Add custom OAuth2
                    </Button>
                </>
            }
        >
            <MethodGrid>
                {items.map((item) => (
                    <ProviderListCard key={`${item.kind}:${item.key}`} item={item} providersBase={providersBase} url={url} />
                ))}
            </MethodGrid>
        </AuthenticationConfigurePage>
    )
}

function ProviderListCard({
    item,
    providersBase,
    url,
}: {
    item: ProviderListItem
    providersBase: string
    url: (s: string) => string
}) {
    const badge =
        item.status === 'enabled' ? (
            <Badge tone="success">Enabled</Badge>
        ) : item.status === 'disabled' ? (
            <Badge>Disabled</Badge>
        ) : (
            <Badge tone="warning">Not configured</Badge>
        )
    return (
        <Card variant="row">
            <CardHeader
                icon={brandIcon(item.key)}
                title={item.name}
                slot={
                    <div style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                        {badge}
                        <Button type="button" variant="secondary" onClick={() => router.visit(url(`${providersBase}/${item.key}`))}>
                            Configure
                        </Button>
                    </div>
                }
            />
        </Card>
    )
}
