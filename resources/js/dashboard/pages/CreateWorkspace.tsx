import { Card } from '@authn-sh/ui'

export default function CreateWorkspace() {
    return (
        <div className="authn-rooted">
            <Card>
                <header>
                    <h1 className="authn-heading">Create your first workspace</h1>
                    <p className="authn-subheading">
                        The bootstrap operator should already own a workspace; if you see this page, contact your platform administrator.
                    </p>
                </header>
            </Card>
        </div>
    )
}
