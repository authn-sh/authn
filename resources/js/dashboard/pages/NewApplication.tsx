import { router } from '@inertiajs/react'
import { Card, CardBody, CardHeader } from '@authn-sh/ui'
import { OauthApplicationForm } from '../components/OauthApplicationForm'
import { Page } from '../components/Page'
import { useDashboard, useDashboardUrl } from '../shared'

export default function NewApplication() {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    if (!active_project || !active_environment) {
        return <p>No active environment.</p>
    }
    const base = `/${active_project.slug}/${active_environment.slug}/configure`
    const listHref = url(`/${active_project.slug}/${active_environment.slug}/configure/applications`)

    return (
        <Page
            title="New OAuth application"
            subtitle="A third-party app that authenticates against this environment as the IdP."
            backLink={{ href: listHref, label: 'All applications' }}
        >
            <Card variant="row">
                <CardHeader title="Application" />
                <CardBody>
                    <OauthApplicationForm
                        application={null}
                        saveUrl={url(`${base}/oauth-applications`)}
                        onDone={() => router.visit(listHref)}
                    />
                </CardBody>
            </Card>
        </Page>
    )
}
