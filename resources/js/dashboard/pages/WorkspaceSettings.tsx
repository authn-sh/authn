import { Badge } from '@authn-sh/ui'
import { Page } from '../components/Page'

type Props = { membership: { organization_id: string; role: string } }

export default function WorkspaceSettings({ membership }: Props) {
    return (
        <Page title="Workspace settings">
            <div className="authn-stack">
                <div className="authn-stats-grid">
                    <div className="authn-stat">
                        <p className="authn-stat-label">Workspace</p>
                        <p className="authn-stat-value" style={{ fontSize: '1rem' }}>
                            <code>{membership.organization_id}</code>
                        </p>
                    </div>
                    <div className="authn-stat">
                        <p className="authn-stat-label">Your role</p>
                        <p className="authn-stat-value" style={{ fontSize: '1rem' }}>
                            <Badge tone="success">{membership.role}</Badge>
                        </p>
                    </div>
                </div>
                <p className="authn-page-subtitle">
                    Member management UI lands with <code>&lt;OrganizationProfile /&gt;</code>.
                </p>
            </div>
        </Page>
    )
}
