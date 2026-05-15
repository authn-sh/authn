import { router, useForm, usePage } from '@inertiajs/react'
import * as React from 'react'
import {
    Alert,
    Badge,
    Button,
    Card,
    CardBody,
    CardHeader,
    Field,
    Input,
    socialIcons,
    Switch,
    type ProviderKey,
} from '@authn-sh/ui'
import { MethodToggle } from '../components/MethodToggle'
import { Page } from '../components/Page'
import { useDashboard, useDashboardUrl } from '../shared'

type ProviderRow = {
    id: string
    provider_kind: 'preset' | 'custom_oidc' | 'custom_oauth2'
    provider_key: string
    name: string
    enabled: boolean
    allow_sign_in: boolean
    allow_sign_up: boolean
    block_email_subaddresses: boolean
    client_id: string
    client_secret_set: boolean
    scopes: string[]
    issuer: string | null
    authorization_endpoint: string | null
    token_endpoint: string | null
    userinfo_endpoint: string | null
    redirect_uri: string
}

type PresetMeta = {
    key: string
    name: string
    default_scopes: string[]
    authorization_endpoint: string
    token_endpoint: string
    userinfo_endpoint: string
    issuer: string | null
}

type Props = {
    provider: ProviderRow | null
    preset: PresetMeta | null
    provider_key: string | null
    new_kind?: 'custom_oidc' | 'custom_oauth2'
    docs_url: string
}

function brandIcon(key: string | null) {
    if (!key) return null
    const Icon = (socialIcons as Record<string, React.ComponentType<{ size?: number }>>)[key as ProviderKey]
    return Icon ? <Icon size={28} /> : null
}

function titleFor(props: Props): string {
    if (props.provider) return props.provider.name
    if (props.preset) return `Set up ${props.preset.name}`
    if (props.new_kind === 'custom_oidc') return 'New custom OIDC provider'
    return 'New custom OAuth2 provider'
}

export default function Provider(props: Props) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const { props: pageProps } = usePage<{ flash?: { oauth_provider_saved?: boolean; oauth_provider_test?: { provider_id: string; userinfo_status: number | null; errors: string[] } } }>()
    if (!active_project || !active_environment) {
        return <p>No active environment.</p>
    }
    const base = `/${active_project.slug}/${active_environment.slug}/configure`
    const listHref = url(`/${active_project.slug}/${active_environment.slug}/configure/authentication/providers`)

    const kind: 'preset' | 'custom_oidc' | 'custom_oauth2' =
        props.provider?.provider_kind ?? (props.preset ? 'preset' : (props.new_kind ?? 'custom_oidc'))
    const isPreset = kind === 'preset'
    const isOidc = kind === 'custom_oidc'
    const isEdit = props.provider !== null

    const initialName = props.provider?.name ?? props.preset?.name ?? ''
    const initialScopes = (props.provider?.scopes ?? props.preset?.default_scopes ?? []).join(' ')

    const form = useForm({
        provider_kind: kind,
        provider_key: props.provider?.provider_key ?? props.preset?.key ?? '',
        name: initialName,
        enabled: props.provider?.enabled ?? false,
        allow_sign_in: props.provider?.allow_sign_in ?? true,
        allow_sign_up: props.provider?.allow_sign_up ?? true,
        block_email_subaddresses: props.provider?.block_email_subaddresses ?? false,
        client_id: props.provider?.client_id ?? '',
        client_secret: '',
        scopes: initialScopes,
        issuer: props.provider?.issuer ?? '',
        authorization_endpoint: props.provider?.authorization_endpoint ?? '',
        token_endpoint: props.provider?.token_endpoint ?? '',
        userinfo_endpoint: props.provider?.userinfo_endpoint ?? '',
        userinfo_method: 'GET',
        userinfo_auth: 'bearer',
    })

    const onSubmit = (e: React.FormEvent) => {
        e.preventDefault()
        const scopeList = form.data.scopes.trim() === '' ? [] : form.data.scopes.trim().split(/\s+/)
        const payload: Record<string, unknown> = {
            name: form.data.name,
            enabled: form.data.enabled,
            allow_sign_in: form.data.allow_sign_in,
            allow_sign_up: form.data.allow_sign_up,
            block_email_subaddresses: form.data.block_email_subaddresses,
            client_id: form.data.client_id,
            scopes: scopeList,
        }
        if (form.data.client_secret !== '') payload.client_secret = form.data.client_secret

        if (isEdit) {
            form.transform(() => payload).patch(url(`${base}/oauth-providers/${props.provider!.id}`), { preserveScroll: true })
            return
        }
        const createPayload = {
            ...payload,
            provider_kind: kind,
            provider_key: form.data.provider_key,
            ...(isOidc ? { issuer: form.data.issuer } : {}),
            ...(kind === 'custom_oauth2'
                ? {
                      authorization_endpoint: form.data.authorization_endpoint,
                      token_endpoint: form.data.token_endpoint,
                      userinfo_endpoint: form.data.userinfo_endpoint,
                      userinfo_method: form.data.userinfo_method,
                      userinfo_auth: form.data.userinfo_auth,
                  }
                : {}),
        }
        form.transform(() => createPayload).post(url(`${base}/oauth-providers`), { preserveScroll: true })
    }

    const onTest = () => {
        if (!props.provider) return
        router.post(url(`${base}/oauth-providers/${props.provider.id}/test`), {}, { preserveScroll: true })
    }

    return (
        <Page
            title={titleFor(props)}
            subtitle={!isEdit ? 'Fill in client credentials to activate.' : undefined}
            icon={brandIcon(props.preset?.key ?? props.provider?.provider_key ?? null)}
            backLink={{ href: listHref, label: 'All providers' }}
            actions={
                <>
                    {isEdit && (
                        <Badge tone={form.data.enabled ? 'success' : 'neutral'}>{form.data.enabled ? 'Enabled' : 'Disabled'}</Badge>
                    )}
                    <a className="authn-link" href={props.docs_url} target="_blank" rel="noreferrer">Documentation →</a>
                </>
            }
        >
            {pageProps.flash?.oauth_provider_saved && <Alert tone="success">Saved.</Alert>}
            {pageProps.flash?.oauth_provider_test && (
                <Alert tone={pageProps.flash.oauth_provider_test.errors.length === 0 ? 'success' : 'danger'}>
                    Userinfo status {pageProps.flash.oauth_provider_test.userinfo_status ?? 'n/a'}
                    {pageProps.flash.oauth_provider_test.errors.length > 0 && (
                        <ul>{pageProps.flash.oauth_provider_test.errors.map((e, i) => <li key={i}>{e}</li>)}</ul>
                    )}
                </Alert>
            )}

            <form onSubmit={onSubmit}>
                {isEdit && (
                    <Card variant="row" style={{ marginBottom: 16 }}>
                        <CardHeader
                            title="Status"
                            description="Enabling makes this provider available on Sign in and/or Sign up."
                            slot={
                                <Switch
                                    aria-label="Enable provider"
                                    checked={form.data.enabled}
                                    onCheckedChange={(next) => form.setData('enabled', next)}
                                />
                            }
                        />
                    </Card>
                )}

                {props.provider?.redirect_uri && (
                    <Card variant="row" style={{ marginBottom: 16 }}>
                        <CardHeader
                            title="Redirect URL"
                            description="Paste this exact URL into the IdP's allow-list. It never changes."
                        />
                        <CardBody>
                            <code className="authn-code-block">{props.provider.redirect_uri}</code>
                        </CardBody>
                    </Card>
                )}

                <Card variant="row" style={{ marginBottom: 16 }}>
                    <CardHeader title="Credentials" />
                    <CardBody>
                        {!isPreset && !isEdit && (
                            <Field label="Provider key" htmlFor="provider-key" error={form.errors.provider_key}>
                                <Input
                                    id="provider-key"
                                    type="text"
                                    value={form.data.provider_key}
                                    onChange={(e) => form.setData('provider_key', e.target.value)}
                                    placeholder="acme_oidc"
                                />
                            </Field>
                        )}
                        <Field label="Display name" htmlFor="provider-name" error={form.errors.name}>
                            <Input
                                id="provider-name"
                                type="text"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                        </Field>
                        <Field label="Client ID" htmlFor="provider-client-id" error={form.errors.client_id}>
                            <Input
                                id="provider-client-id"
                                type="text"
                                value={form.data.client_id}
                                onChange={(e) => form.setData('client_id', e.target.value)}
                            />
                        </Field>
                        <Field
                            label="Client secret"
                            htmlFor="provider-client-secret"
                            helper={props.provider?.client_secret_set ? '•••• stored. Leave blank to keep the existing secret.' : undefined}
                            error={form.errors.client_secret}
                        >
                            <Input
                                id="provider-client-secret"
                                type="password"
                                value={form.data.client_secret}
                                onChange={(e) => form.setData('client_secret', e.target.value)}
                                placeholder={props.provider?.client_secret_set ? '•••• rotate' : ''}
                            />
                        </Field>
                        <Field
                            label="Scopes"
                            htmlFor="provider-scopes"
                            helper="Space-separated."
                            error={form.errors.scopes as string | undefined}
                        >
                            <Input
                                id="provider-scopes"
                                type="text"
                                value={form.data.scopes}
                                onChange={(e) => form.setData('scopes', e.target.value)}
                            />
                        </Field>
                    </CardBody>
                </Card>

                {isOidc && !isEdit && (
                    <Card variant="row" style={{ marginBottom: 16 }}>
                        <CardHeader title="Discovery" description="We'll fetch the rest from the issuer's /.well-known/openid-configuration." />
                        <CardBody>
                            <Field label="Issuer URL" htmlFor="provider-issuer" error={form.errors.issuer}>
                                <Input
                                    id="provider-issuer"
                                    type="url"
                                    value={form.data.issuer}
                                    onChange={(e) => form.setData('issuer', e.target.value)}
                                    placeholder="https://idp.example.com"
                                />
                            </Field>
                        </CardBody>
                    </Card>
                )}

                {kind === 'custom_oauth2' && !isEdit && (
                    <Card variant="row" style={{ marginBottom: 16 }}>
                        <CardHeader title="Endpoints" description="Custom OAuth2 doesn't support discovery — set endpoints explicitly." />
                        <CardBody>
                            <Field label="Authorization endpoint" htmlFor="auth-endpoint" error={form.errors.authorization_endpoint}>
                                <Input id="auth-endpoint" type="url" value={form.data.authorization_endpoint} onChange={(e) => form.setData('authorization_endpoint', e.target.value)} />
                            </Field>
                            <Field label="Token endpoint" htmlFor="token-endpoint" error={form.errors.token_endpoint}>
                                <Input id="token-endpoint" type="url" value={form.data.token_endpoint} onChange={(e) => form.setData('token_endpoint', e.target.value)} />
                            </Field>
                            <Field label="Userinfo endpoint" htmlFor="userinfo-endpoint" error={form.errors.userinfo_endpoint}>
                                <Input id="userinfo-endpoint" type="url" value={form.data.userinfo_endpoint} onChange={(e) => form.setData('userinfo_endpoint', e.target.value)} />
                            </Field>
                        </CardBody>
                    </Card>
                )}

                <Card variant="row" style={{ marginBottom: 16 }}>
                    <CardHeader title="Sign-in / Sign-up" description="Control which flows this provider can complete." />
                    <CardBody>
                        <MethodToggle
                            label="Allow sign-in"
                            description="Existing users may complete sign-in with this provider."
                            checked={form.data.allow_sign_in}
                            onChange={(next) => form.setData('allow_sign_in', next)}
                        />
                        <MethodToggle
                            label="Allow sign-up"
                            description="New users may register via this provider."
                            checked={form.data.allow_sign_up}
                            onChange={(next) => form.setData('allow_sign_up', next)}
                        />
                        <MethodToggle
                            label="Block email sub-addresses"
                            description="Refuse identifiers like user+tag@example.com."
                            checked={form.data.block_email_subaddresses}
                            onChange={(next) => form.setData('block_email_subaddresses', next)}
                        />
                    </CardBody>
                </Card>

                <div style={{ display: 'flex', gap: 8 }}>
                    <Button type="submit" loading={form.processing}>
                        {form.processing ? 'Saving…' : isEdit ? 'Save changes' : 'Create provider'}
                    </Button>
                    {isEdit && (
                        <Button type="button" variant="secondary" onClick={onTest}>Test</Button>
                    )}
                </div>
            </form>
        </Page>
    )
}
