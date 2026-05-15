import { useForm } from '@inertiajs/react'
import { AtIcon, KeyIcon, PhoneIcon, UserIcon } from '../../../icons'
import { MethodCard } from '../../../components/MethodCard'
import { MethodGrid, MethodToggle } from '../../../components/MethodToggle'
import { AuthenticationConfigurePage } from '../../../components/AuthenticationConfigurePage'
import { useDashboardUrl } from '../../../shared'

type SignUpMethods = {
    email: {
        enabled: boolean
        required: boolean
        verify: boolean
        verify_code: boolean
        restrict_changes: boolean
    }
    phone: { enabled: boolean; required: boolean; verify: boolean; restrict_changes: boolean }
    username: { enabled: boolean; required: boolean; restrict_changes: boolean }
    password: { enabled: boolean; signup_with_password: boolean; add_password: boolean }
}

type Props = { sign_up_methods: SignUpMethods }

export default function SignUp({ sign_up_methods }: Props) {
    const url = useDashboardUrl()
    const form = useForm<SignUpMethods>({
        email: { ...sign_up_methods.email },
        phone: { ...sign_up_methods.phone },
        username: { ...sign_up_methods.username },
        password: { ...sign_up_methods.password },
    })
    const persist = (next: SignUpMethods) => {
        form.transform(() => next).patch(url('configure/sign-up-methods'), { preserveScroll: true })
    }
    const setEmail = (patch: Partial<SignUpMethods['email']>) => {
        const next = { ...form.data, email: { ...form.data.email, ...patch } }
        form.setData(next)
        persist(next)
    }
    const setPhone = (patch: Partial<SignUpMethods['phone']>) => {
        const next = { ...form.data, phone: { ...form.data.phone, ...patch } }
        form.setData(next)
        persist(next)
    }
    const setUsername = (patch: Partial<SignUpMethods['username']>) => {
        const next = { ...form.data, username: { ...form.data.username, ...patch } }
        form.setData(next)
        persist(next)
    }
    const setPassword = (patch: Partial<SignUpMethods['password']>) => {
        const next = { ...form.data, password: { ...form.data.password, ...patch } }
        form.setData(next)
        persist(next)
    }

    return (
        <AuthenticationConfigurePage active="sign-up">
            <MethodGrid>
                <MethodCard
                    icon={<AtIcon />}
                    title="Email"
                    description="Collect an email address during sign-up."
                    enabled={form.data.email.enabled}
                    onToggle={(next) => setEmail({ enabled: next })}
                >
                    <MethodToggle
                        label="Require email address"
                        description="Reject sign-ups that don't supply an email."
                        checked={form.data.email.required}
                        onChange={(next) => setEmail({ required: next })}
                    />
                    <MethodToggle
                        label="Verify email address"
                        description="Send a one-time code to confirm the user controls the inbox."
                        checked={form.data.email.verify}
                        onChange={(next) => setEmail({ verify: next })}
                    />
                    <MethodToggle
                        label="Restrict changes"
                        description="Users can't change their email after sign-up."
                        checked={form.data.email.restrict_changes}
                        onChange={(next) => setEmail({ restrict_changes: next })}
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
                    <MethodToggle
                        label="Verify phone number"
                        description="Send an SMS code to confirm the user owns the number."
                        checked={form.data.phone.verify}
                        onChange={(next) => setPhone({ verify: next })}
                    />
                    <MethodToggle
                        label="Restrict changes"
                        description="Users can't change their phone number after sign-up."
                        checked={form.data.phone.restrict_changes}
                        onChange={(next) => setPhone({ restrict_changes: next })}
                    />
                </MethodCard>
                <MethodCard
                    icon={<UserIcon />}
                    title="Username"
                    description="Let users pick a username during sign-up."
                    enabled={form.data.username.enabled}
                    onToggle={(next) => setUsername({ enabled: next })}
                >
                    <MethodToggle
                        label="Require username"
                        description="Reject sign-ups that don't pick a username."
                        checked={form.data.username.required}
                        onChange={(next) => setUsername({ required: next })}
                    />
                    <MethodToggle
                        label="Restrict changes"
                        description="Users can't change their username after sign-up."
                        checked={form.data.username.restrict_changes}
                        onChange={(next) => setUsername({ restrict_changes: next })}
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
