import { useForm } from '@inertiajs/react'
import * as React from 'react'
import { AtIcon, FingerprintIcon, PhoneIcon, UserIcon } from '../../../icons'
import { AuthenticationConfigurePage } from '../../../components/AuthenticationConfigurePage'
import { MethodCard } from '../../../components/MethodCard'
import { MethodGrid, MethodToggle } from '../../../components/MethodToggle'
import { useDashboardUrl } from '../../../shared'

type SignInMethods = {
    email: { enabled: boolean; code: boolean }
    phone: { enabled: boolean }
    username: { enabled: boolean }
}

type Props = { sign_in_methods: SignInMethods }

export default function SignIn({ sign_in_methods }: Props) {
    const url = useDashboardUrl()
    const form = useForm<SignInMethods>({
        email: { ...sign_in_methods.email },
        phone: { ...sign_in_methods.phone },
        username: { ...sign_in_methods.username },
    })
    const persist = (next: SignInMethods) => {
        form.transform(() => next).patch(url('configure/sign-in-methods'), { preserveScroll: true })
    }
    const setEmail = (patch: Partial<SignInMethods['email']>) => {
        const next = { ...form.data, email: { ...form.data.email, ...patch } }
        form.setData(next)
        persist(next)
    }
    const setPhone = (patch: Partial<SignInMethods['phone']>) => {
        const next = { ...form.data, phone: { ...form.data.phone, ...patch } }
        form.setData(next)
        persist(next)
    }
    const setUsername = (patch: Partial<SignInMethods['username']>) => {
        const next = { ...form.data, username: { ...form.data.username, ...patch } }
        form.setData(next)
        persist(next)
    }
    const [passkey, setPasskey] = React.useState(true)

    return (
        <AuthenticationConfigurePage active="sign-in">
            <MethodGrid>
                <MethodCard
                    icon={<AtIcon />}
                    title="Email"
                    description="Sign in by sending a one-time verification code to the user's email."
                    enabled={form.data.email.enabled}
                    onToggle={(enabled) => setEmail({ enabled })}
                >
                    <MethodToggle
                        label="Verification code"
                        description="Six-digit code delivered to the address on the sign-in form."
                        checked={form.data.email.code}
                        onChange={(code) => setEmail({ code })}
                    />
                </MethodCard>
                <MethodCard
                    icon={<PhoneIcon />}
                    title="Phone"
                    description="Sign in by sending a one-time code by SMS."
                    enabled={form.data.phone.enabled}
                    onToggle={(enabled) => setPhone({ enabled })}
                />
                <MethodCard
                    icon={<UserIcon />}
                    title="Username"
                    description="Sign in with a username paired with a password."
                    enabled={form.data.username.enabled}
                    onToggle={(enabled) => setUsername({ enabled })}
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
