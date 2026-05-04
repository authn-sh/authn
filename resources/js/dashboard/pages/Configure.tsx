type Props = {
    section: string
    user_settings: Record<string, unknown>
    allowed_origins: string[]
    appearance: Record<string, unknown>
    signup_mode: string
}

export default function Configure({ section, user_settings, signup_mode }: Props) {
    return (
        <div>
            <h1>Configure → {section}</h1>
            <p>Sign-up mode: <strong>{signup_mode}</strong></p>
            <pre style={{ background: '#f1f5f9', padding: 12, borderRadius: 6 }}>
                {JSON.stringify(user_settings, null, 2)}
            </pre>
        </div>
    )
}
