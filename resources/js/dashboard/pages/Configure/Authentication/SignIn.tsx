import { useForm } from '@inertiajs/react'
import * as React from 'react'
import { AtIcon, FingerprintIcon, PhoneIcon, UserIcon } from '../../../icons'
import { AuthenticationConfigurePage } from '../../../components/AuthenticationConfigurePage'
import { MethodCard } from '../../../components/MethodCard'
import { MethodGrid, MethodToggle } from '../../../components/MethodToggle'
import { useDashboardUrl } from '../../../shared'

type SignInMethods = {
    email: { enabled: boolean; code: boolean }
}

type Props = {
    sign_in_methods: SignInMethods
}

export default function SignIn({ sign_in_methods }: Props) {
    const url = useDashboardUrl()
    const form = useForm({
        email: { enabled: sign_in_methods.email.enabled, code: sign_in_methods.email.code },
    })
    const persist = (next: SignInMethods) => {
        form.transform(() => next).patch(url('configure/sign-in-methods'), { preserveScroll: true })
    }
    const setEmailEnabled = (enabled: boolean) => {
        const next = { ...form.data, email: { ...form.data.email, enabled } }
        form.setData(next)
        persist(next)
    }
    const setEmailCode = (code: boolean) => {
        const next = { ...form.data, email: { ...form.data.email, code } }
        form.setData(next)
        persist(next)
    }

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
                    enabled={form.data.email.enabled}
                    onToggle={setEmailEnabled}
                >
                    <MethodToggle
                        label="Verification code"
                        description="Six-digit code delivered to the address on the sign-in form."
                        checked={form.data.email.code}
                        onChange={setEmailCode}
                    />
                </MethodCard>
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
