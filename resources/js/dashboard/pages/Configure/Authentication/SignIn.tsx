import * as React from 'react'
import { AtIcon, FingerprintIcon, PhoneIcon, UserIcon } from '../../../icons'
import { AuthenticationConfigurePage } from '../../../components/AuthenticationConfigurePage'
import { MethodCard } from '../../../components/MethodCard'
import { MethodGrid } from '../../../components/MethodToggle'

export default function SignIn() {
    const [email, setEmail] = React.useState(true)
    const [phone, setPhone] = React.useState(false)
    const [username, setUsername] = React.useState(false)
    const [passkey, setPasskey] = React.useState(true)

    return (
        <AuthenticationConfigurePage active="sign-in">
            <MethodGrid>
                <MethodCard
                    icon={<AtIcon />}
                    title="Email"
                    description="Sign in by sending a one-time verification code to the user's email."
                    enabled={email}
                    onToggle={setEmail}
                />
                <MethodCard
                    icon={<PhoneIcon />}
                    title="Phone"
                    description="Sign in by sending a one-time code by SMS."
                    enabled={phone}
                    onToggle={setPhone}
                />
                <MethodCard
                    icon={<UserIcon />}
                    title="Username"
                    description="Sign in with a username paired with a password."
                    enabled={username}
                    onToggle={setUsername}
                />
                <MethodCard
                    icon={<FingerprintIcon />}
                    title="Passkey"
                    description="Sign in with a passkey or hardware security key."
                    enabled={passkey}
                    onToggle={setPasskey}
                />
            </MethodGrid>
        </AuthenticationConfigurePage>
    )
}
