import * as React from 'react'
import { AtIcon, KeyIcon, PhoneIcon, UserIcon } from '../../../icons'
import { MethodCard } from '../../../components/MethodCard'
import { MethodGrid, MethodToggle } from '../../../components/MethodToggle'
import { AuthenticationConfigurePage } from '../../../components/AuthenticationConfigurePage'

export default function SignUp() {
    const [email, setEmail] = React.useState({
        enabled: true,
        required: true,
        verify: true,
        restrict_changes: false,
    })
    const [phone, setPhone] = React.useState({
        enabled: false,
        required: false,
        verify: true,
        restrict_changes: false,
    })
    const [username, setUsername] = React.useState({
        enabled: false,
        required: false,
        restrict_changes: false,
    })
    const [password, setPassword] = React.useState({
        enabled: true,
        signup_with_password: true,
        add_password: true,
    })

    return (
        <AuthenticationConfigurePage active="sign-up">
            <MethodGrid>
                <MethodCard
                    icon={<AtIcon />}
                    title="Email"
                    description="Collect an email address during sign-up."
                    enabled={email.enabled}
                    onToggle={(next) => setEmail({ ...email, enabled: next })}
                >
                    <MethodToggle
                        label="Require email address"
                        description="Reject sign-ups that don't supply an email."
                        checked={email.required}
                        onChange={(next) => setEmail({ ...email, required: next })}
                    />
                    <MethodToggle
                        label="Verify email address"
                        description="Send a one-time code to confirm the user controls the inbox."
                        checked={email.verify}
                        onChange={(next) => setEmail({ ...email, verify: next })}
                    />
                    <MethodToggle
                        label="Restrict changes"
                        description="Users can't change their email after sign-up."
                        checked={email.restrict_changes}
                        onChange={(next) => setEmail({ ...email, restrict_changes: next })}
                    />
                </MethodCard>
                <MethodCard
                    icon={<PhoneIcon />}
                    title="Phone"
                    description="Collect a phone number during sign-up."
                    enabled={phone.enabled}
                    onToggle={(next) => setPhone({ ...phone, enabled: next })}
                >
                    <MethodToggle
                        label="Require phone number"
                        description="Reject sign-ups that don't supply a phone number."
                        checked={phone.required}
                        onChange={(next) => setPhone({ ...phone, required: next })}
                    />
                    <MethodToggle
                        label="Verify phone number"
                        description="Send an SMS code to confirm the user owns the number."
                        checked={phone.verify}
                        onChange={(next) => setPhone({ ...phone, verify: next })}
                    />
                    <MethodToggle
                        label="Restrict changes"
                        description="Users can't change their phone number after sign-up."
                        checked={phone.restrict_changes}
                        onChange={(next) => setPhone({ ...phone, restrict_changes: next })}
                    />
                </MethodCard>
                <MethodCard
                    icon={<UserIcon />}
                    title="Username"
                    description="Let users pick a username during sign-up."
                    enabled={username.enabled}
                    onToggle={(next) => setUsername({ ...username, enabled: next })}
                >
                    <MethodToggle
                        label="Require username"
                        description="Reject sign-ups that don't pick a username."
                        checked={username.required}
                        onChange={(next) => setUsername({ ...username, required: next })}
                    />
                    <MethodToggle
                        label="Restrict changes"
                        description="Users can't change their username after sign-up."
                        checked={username.restrict_changes}
                        onChange={(next) => setUsername({ ...username, restrict_changes: next })}
                    />
                </MethodCard>
                <MethodCard
                    icon={<KeyIcon />}
                    title="Password"
                    description="Allow users to set or add a password during sign-up."
                    enabled={password.enabled}
                    onToggle={(next) => setPassword({ ...password, enabled: next })}
                >
                    <MethodToggle
                        label="Signup with password"
                        description="Require a password during sign-up."
                        checked={password.signup_with_password}
                        onChange={(next) => setPassword({ ...password, signup_with_password: next })}
                    />
                    <MethodToggle
                        label="Add password"
                        description="Let users add a password after signing up without one."
                        checked={password.add_password}
                        onChange={(next) => setPassword({ ...password, add_password: next })}
                    />
                </MethodCard>
            </MethodGrid>
        </AuthenticationConfigurePage>
    )
}
