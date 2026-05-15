import { useForm, usePage } from '@inertiajs/react'
import * as React from 'react'
import {
    Alert,
    Button,
    Card,
    CardBody,
    CardHeader,
    Field,
    Input,
} from '@authn-sh/ui'
import { Page } from '../components/Page'
import { useDashboardUrl } from '../shared'

type RedirectsState = {
    after_sign_up: string | null
    after_sign_in: string | null
    home: string | null
    after_create_organization: string | null
    after_leave_organization: string | null
}

type Props = {
    redirects: RedirectsState
    allowed_origins: string[]
}

type FieldDef = {
    key: keyof RedirectsState
    label: string
    helper: string
    placeholder: string
}

const USER_FIELDS: FieldDef[] = [
    {
        key: 'after_sign_up',
        label: 'After sign-up fallback',
        helper: 'Where the SDK navigates after a successful sign-up when the tenant app does not supply afterSignUpUrl.',
        placeholder: 'https://app.example.com/welcome',
    },
    {
        key: 'after_sign_in',
        label: 'After sign-in fallback',
        helper: 'Where the SDK navigates after a successful sign-in when the tenant app does not supply afterSignInUrl.',
        placeholder: 'https://app.example.com/dashboard',
    },
    {
        key: 'home',
        label: 'After logo click',
        helper: 'URL behind the brand logo on hosted Sign in / Sign up / Account Portal pages.',
        placeholder: 'https://app.example.com',
    },
]

const ORGANIZATION_FIELDS: FieldDef[] = [
    {
        key: 'after_create_organization',
        label: 'After create organization',
        helper: 'Where the user lands after creating a new organization.',
        placeholder: 'https://app.example.com/orgs/:slug',
    },
    {
        key: 'after_leave_organization',
        label: 'After leave organization',
        helper: 'Where the user lands after leaving an organization.',
        placeholder: 'https://app.example.com',
    },
]

type FlashProps = { redirects_saved?: boolean }

export default function Redirects({ redirects, allowed_origins }: Props) {
    const url = useDashboardUrl()
    const pageProps = usePage<{ flash?: FlashProps }>().props
    const form = useForm<RedirectsState>({
        after_sign_up: redirects.after_sign_up ?? '',
        after_sign_in: redirects.after_sign_in ?? '',
        home: redirects.home ?? '',
        after_create_organization: redirects.after_create_organization ?? '',
        after_leave_organization: redirects.after_leave_organization ?? '',
    })

    const update = (key: keyof RedirectsState, value: string) => form.setData(key, value)

    const onSubmit = (e: React.FormEvent) => {
        e.preventDefault()
        form.patch(url('configure/redirects'), { preserveScroll: true })
    }

    return (
        <Page
            title="Redirects"
            subtitle="Fallback destinations the SDK uses when the tenant app doesn't override them at runtime."
        >
            {pageProps.flash?.redirects_saved && <Alert tone="success" style={{ marginBottom: 16 }}>Saved.</Alert>}
            {allowed_origins.length > 0 && (
                <Alert tone="neutral" style={{ marginBottom: 16 }}>
                    URLs must use an origin already listed in this environment's <code>allowed_origins</code>.
                </Alert>
            )}

            <form onSubmit={onSubmit}>
                <Card variant="row" style={{ marginBottom: 16 }}>
                    <CardHeader title="User redirects" />
                    <CardBody>
                        {USER_FIELDS.map((f) => (
                            <Field
                                key={f.key}
                                label={f.label}
                                htmlFor={`redirect-${f.key}`}
                                helper={f.helper}
                                error={form.errors[f.key] as string | undefined}
                            >
                                <Input
                                    id={`redirect-${f.key}`}
                                    type="url"
                                    value={form.data[f.key] ?? ''}
                                    onChange={(e) => update(f.key, e.target.value)}
                                    placeholder={f.placeholder}
                                />
                            </Field>
                        ))}
                    </CardBody>
                </Card>

                <Card variant="row">
                    <CardHeader title="Organization redirects" />
                    <CardBody>
                        {ORGANIZATION_FIELDS.map((f) => (
                            <Field
                                key={f.key}
                                label={f.label}
                                htmlFor={`redirect-${f.key}`}
                                helper={f.helper}
                                error={form.errors[f.key] as string | undefined}
                            >
                                <Input
                                    id={`redirect-${f.key}`}
                                    type="url"
                                    value={form.data[f.key] ?? ''}
                                    onChange={(e) => update(f.key, e.target.value)}
                                    placeholder={f.placeholder}
                                />
                            </Field>
                        ))}
                    </CardBody>
                </Card>

                <div style={{ marginTop: 16 }}>
                    <Button type="submit" disabled={form.processing}>{form.processing ? 'Saving…' : 'Save'}</Button>
                </div>
            </form>
        </Page>
    )
}
