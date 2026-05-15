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

type RedirectState = {
    after_sign_up: string
    after_sign_in: string
    home: string
    after_create_organization: string
    after_leave_organization: string
}

type FieldDef = {
    key: keyof RedirectState
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

export default function Redirects() {
    const [values, setValues] = React.useState<RedirectState>({
        after_sign_up: '',
        after_sign_in: '',
        home: '',
        after_create_organization: '',
        after_leave_organization: '',
    })

    const update = (key: keyof RedirectState, value: string) =>
        setValues((v) => ({ ...v, [key]: value }))

    return (
        <Page
            title="Redirects"
            subtitle="Fallback destinations the SDK uses when the tenant app doesn't override them at runtime."
        >
            <Alert tone="warning" style={{ marginBottom: 16 }}>
                These values don't persist yet — backend wiring tracked in issue #286.
            </Alert>

            <Card variant="row" style={{ marginBottom: 16 }}>
                <CardHeader title="User redirects" />
                <CardBody>
                    {USER_FIELDS.map((f) => (
                        <Field key={f.key} label={f.label} htmlFor={`redirect-${f.key}`} helper={f.helper}>
                            <Input
                                id={`redirect-${f.key}`}
                                type="url"
                                value={values[f.key]}
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
                        <Field key={f.key} label={f.label} htmlFor={`redirect-${f.key}`} helper={f.helper}>
                            <Input
                                id={`redirect-${f.key}`}
                                type="url"
                                value={values[f.key]}
                                onChange={(e) => update(f.key, e.target.value)}
                                placeholder={f.placeholder}
                            />
                        </Field>
                    ))}
                </CardBody>
            </Card>

            <div style={{ marginTop: 16 }}>
                <Button type="button" disabled>Save</Button>
            </div>
        </Page>
    )
}
