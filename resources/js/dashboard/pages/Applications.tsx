import { router, useForm, usePage } from '@inertiajs/react'
import * as React from 'react'
import { Alert, Button } from '@authn-sh/ui'
import { OauthApplicationForm, type OauthApplicationRow } from '../components/OauthApplicationForm'
import { Page } from '../components/Page'
import { useDashboard, useDashboardUrl } from '../shared'

type Props = {
    applications: OauthApplicationRow[]
}

export default function Applications({ applications }: Props) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const { props: pageProps } = usePage<{ flash?: {
        oauth_application_saved?: boolean
        oauth_application_deleted?: boolean
        oauth_application_secret?: string
        oauth_application_id?: string
    } }>()
    const [editing, setEditing] = React.useState<string | null>(null)

    if (!active_project || !active_environment) {
        return <p>No active environment.</p>
    }
    const base = `/${active_project.slug}/${active_environment.slug}/configure`
    const flashedSecret = pageProps.flash?.oauth_application_secret ?? null

    return (
        <Page
            title="OAuth Applications"
            subtitle="Third-party applications authenticating against this environment as the IdP. Confidential clients hold a server-side secret; public clients use PKCE."
            actions={
                <Button type="button" onClick={() => router.visit(url(`${base}/applications/new`))}>
                    + New application
                </Button>
            }
        >
            <div className="authn-stack">
                {pageProps.flash?.oauth_application_saved && (
                    <Alert tone="success">Application saved.</Alert>
                )}
                {pageProps.flash?.oauth_application_deleted && (
                    <Alert tone="success">Application deleted — every active AuthorizationGrant was revoked.</Alert>
                )}
                {flashedSecret && (
                    <Alert tone="warning">
                        <strong>Plaintext client secret — capture now, it never appears again:</strong>
                        <pre className="authn-json-block">{flashedSecret}</pre>
                    </Alert>
                )}
                <div className="authn-table-wrap">
                    <table className="authn-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Client ID</th>
                                <th>Type</th>
                                <th>Active grants</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {applications.length === 0 && (
                                <tr><td colSpan={5}><div className="authn-empty-state">No OAuth applications yet.</div></td></tr>
                            )}
                            {applications.map((a) => (
                                <tr key={a.id}>
                                    <td>{a.name}</td>
                                    <td><code>{a.client_id}</code></td>
                                    <td>{a.is_public ? 'Public (PKCE)' : 'Confidential'}</td>
                                    <td>{a.grants_count}</td>
                                    <td style={{ display: 'flex', gap: 6, justifyContent: 'flex-end' }}>
                                        <Button variant="secondary" type="button" onClick={() => setEditing(editing === a.id ? null : a.id)}>
                                            {editing === a.id ? 'Close' : 'Edit'}
                                        </Button>
                                        {!a.is_public && (
                                            <RotateSecretButton action={url(`${base}/oauth-applications/${a.id}/rotate-secret`)} />
                                        )}
                                        <DeleteOauthApplicationButton action={url(`${base}/oauth-applications/${a.id}`)} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {editing && (
                    <OauthApplicationForm
                        application={applications.find((a) => a.id === editing) ?? null}
                        saveUrl={url(`${base}/oauth-applications/${editing}`)}
                        onDone={() => setEditing(null)}
                    />
                )}
            </div>
        </Page>
    )
}

function RotateSecretButton({ action }: { action: string }) {
    const form = useForm({})
    const onClick = () => {
        if (!window.confirm('Mint a fresh client secret? The previous secret is invalidated immediately — coordinate with the consumer.')) return
        form.post(action, { preserveScroll: true })
    }

    return (
        <Button type="button" variant="secondary" onClick={onClick} loading={form.processing}>
            Rotate secret
        </Button>
    )
}

function DeleteOauthApplicationButton({ action }: { action: string }) {
    const form = useForm({})
    const onDelete = () => {
        if (!window.confirm('Delete this OAuth application? Every active AuthorizationGrant will be revoked.')) return
        form.delete(action, { preserveScroll: true })
    }

    return (
        <Button type="button" variant="danger" onClick={onDelete} loading={form.processing}>
            Delete
        </Button>
    )
}
