type Props = {
    invitations: Array<{ id: string; email_address: string; status: string }>
}

export default function Invitations({ invitations }: Props) {
    return (
        <div>
            <h1>Invitations</h1>
            <p>{invitations.length} invitation(s).</p>
        </div>
    )
}
