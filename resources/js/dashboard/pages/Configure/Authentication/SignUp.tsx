import { useForm } from '@inertiajs/react'
import * as React from 'react'
import { AtIcon, KeyIcon, PhoneIcon, UserIcon } from '../../../icons'
import { MethodCard } from '../../../components/MethodCard'
import { MethodGrid, MethodToggle } from '../../../components/MethodToggle'
import { AuthenticationConfigurePage } from '../../../components/AuthenticationConfigurePage'
import { useDashboardUrl } from '../../../shared'

type SignUpMethods = {
    password: { enabled: boolean; signup_with_password: boolean; add_password: boolean }
    phone: { enabled: boolean; required: boolean }
}

type Props = {
    sign_up_methods: SignUpMethods
}

export default function SignUp({ sign_up_methods }: Props) {
    const url = useDashboardUrl()
    const form = useForm<SignUpMethods>({
        password: { ...sign_up_methods.password },
        phone: { ...sign_up_methods.phone },
    })
    const persist = (next: SignUpMethods) => {
        form.transform(() => next).patch(url('configure/sign-up-methods'), { preserveScroll: true })
    }
    const setPassword = (patch: Partial<SignUpMethods['password']>) => {
        const next = { ...form.data, password: { ...form.data.password, ...patch } }
        form.setData(next)
        persist(next)
    }
    const setPhone = (patch: Partial<SignUpMethods['phone']>) => {
        const next = { ...form.data, phone: { ...form.data.phone, ...patch } }
        form.setData(next)
        persist(next)
    }

    // Email / username cards persistence lands with the broader sign-up
    // identifier matrix (#280).
    const [email, setEmail] = React.useState({
        enabled: true,
        required: true,
        verify: true,
        restrict_changes: false,
    })
    const [username, setUsername] = React.useState({
        enabled: false,
        required: false,
        restrict_changes: false,
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
                    enabled={form.data.phone.enabled}
                    onToggle={(next) => setPhone({ enabled: next })}
                >
                    <MethodToggle
                        label="Require phone number"
                        description="Reject sign-ups that don't supply a phone number."
                        checked={form.data.phone.required}
                        onChange={(next) => setPhone({ required: next })}
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
                    enabled={form.data.password.enabled}
                    onToggle={(next) => setPassword({ enabled: next })}
                >
                    <MethodToggle
                        label="Signup with password"
                        description="Collect a password during sign-up. When off, users land without a password and can add one later."
                        checked={form.data.password.signup_with_password}
                        onChange={(next) => setPassword({ signup_with_password: next })}
                    />
                    <MethodToggle
                        label="Add password"
                        description="Let users without a password add one via the Account Portal."
                        checked={form.data.password.add_password}
                        onChange={(next) => setPassword({ add_password: next })}
                    />
                </MethodCard>
            </MethodGrid>
        </AuthenticationConfigurePage>
    )
}
