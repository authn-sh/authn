type Props = { membership: { organization_id: string; role: string } }

export default function WorkspaceSettings({ membership }: Props) {
    return (
        <div>
            <h1>Workspace settings</h1>
            <p>Workspace: <code>{membership.organization_id}</code> · Role: {membership.role}</p>
            <p>Member management lands when SR-3 ships <code>&lt;OrganizationProfile /&gt;</code>.</p>
        </div>
    )
}
