import { useForm, usePage } from '@inertiajs/react'
import * as React from 'react'
import {
    Button,
    Card,
    CardBody,
    CardHeader,
    Field,
    Input,
    Switch,
} from '@authn-sh/ui'
import { ClockIcon, FingerprintIcon, HashIcon, PhoneIcon } from '../../../icons'
import { MethodGrid } from '../../../components/MethodToggle'
import { AuthenticationConfigurePage } from '../../../components/AuthenticationConfigurePage'
import { useDashboard, useDashboardUrl } from '../../../shared'

type MultiFactorSettings = {
    totp: { enabled: boolean }
    backup_codes: { enabled: boolean; default_count: number }
    phone_code?: { enabled: boolean }
}

type Props = {
    multi_factor: MultiFactorSettings
    passkey_enabled: boolean
}

export default function Mfa({ multi_factor, passkey_enabled }: Props) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const { props: pageProps } = usePage<{ flash?: { multi_factor_saved?: boolean } }>()
    const form = useForm({
        totp: { enabled: multi_factor.totp.enabled },
        backup_codes: {
            enabled: multi_factor.backup_codes.enabled,
            default_count: multi_factor.backup_codes.default_count,
        },
        phone_code: { enabled: multi_factor.phone_code?.enabled ?? false },
    })
    const passkeyForm = useForm({ enabled: passkey_enabled })
    React.useEffect(() => {
        if (passkeyForm.data.enabled !== passkey_enabled) {
            passkeyForm.setData('enabled', passkey_enabled)
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [passkey_enabled])

    if (!active_project || !active_environment) {
        return <p>No active environment.</p>
    }
    const action = url(`/${active_project.slug}/${active_environment.slug}/configure/multi-factor`)
    const passkeyAction = url(`/${active_project.slug}/${active_environment.slug}/configure/strategies/passkey`)

    return (
        <AuthenticationConfigurePage active="mfa">
            {pageProps.flash?.multi_factor_saved && (
                <p className="authn-page-subtitle" style={{ marginBottom: 12 }}>Saved.</p>
            )}
            <form
                onSubmit={(e) => {
                    e.preventDefault()
                    form.patch(action, { preserveScroll: true })
                }}
            >
                <MethodGrid>
                    <Card variant="row">
                        <CardHeader
                            icon={<ClockIcon />}
                            title="TOTP (authenticator app)"
                            description="Allow users to enroll an authenticator app as a second factor."
                            slot={
                                <Switch
                                    checked={form.data.totp.enabled}
                                    onCheckedChange={(next) => form.setData('totp', { enabled: next })}
                                    aria-label="Enable TOTP"
                                />
                            }
                        />
                        {form.errors['totp.enabled'] && (
                            <CardBody>
                                <span style={{ color: 'var(--authn-color-danger)', fontSize: 12 }}>{form.errors['totp.enabled']}</span>
                            </CardBody>
                        )}
                    </Card>
                    <Card variant="row">
                        <CardHeader
                            icon={<FingerprintIcon />}
                            title="Passkey"
                            description="Allow passkeys to be used as a second factor and for direct sign-in."
                            slot={
                                <Switch
                                    aria-label="Enable passkey"
                                    checked={passkeyForm.data.enabled}
                                    disabled={passkeyForm.processing}
                                    onCheckedChange={(next) => {
                                        passkeyForm.setData('enabled', next)
                                        passkeyForm.transform(() => ({ enabled: next })).patch(passkeyAction, { preserveScroll: true })
                                    }}
                                />
                            }
                        />
                    </Card>
                    <Card variant="row">
                        <CardHeader
                            icon={<PhoneIcon />}
                            title="SMS"
                            description="Allow users to receive a second-factor code by SMS."
                            slot={
                                <Switch
                                    checked={form.data.phone_code.enabled}
                                    onCheckedChange={(next) => form.setData('phone_code', { enabled: next })}
                                    aria-label="Enable SMS second factor"
                                />
                            }
                        />
                    </Card>
                    <Card variant="row">
                        <CardHeader
                            icon={<HashIcon />}
                            title="Backup codes"
                            description="Allow users to generate single-use recovery codes."
                            slot={
                                <Switch
                                    checked={form.data.backup_codes.enabled}
                                    onCheckedChange={(next) => form.setData('backup_codes', { ...form.data.backup_codes, enabled: next })}
                                    aria-label="Enable backup codes"
                                />
                            }
                        />
                        <CardBody>
                            <Field
                                label="Codes per regeneration (4–24)"
                                htmlFor="backup-code-count"
                                error={form.errors['backup_codes.default_count']}
                            >
                                <Input
                                    id="backup-code-count"
                                    type="number"
                                    min={4}
                                    max={24}
                                    value={form.data.backup_codes.default_count}
                                    onChange={(e) => form.setData('backup_codes', { ...form.data.backup_codes, default_count: Number(e.target.value) })}
                                    style={{ width: 120 }}
                                />
                            </Field>
                        </CardBody>
                    </Card>
                </MethodGrid>
                <div style={{ marginTop: 16 }}>
                    <Button type="submit" loading={form.processing}>
                        {form.processing ? 'Saving…' : 'Save'}
                    </Button>
                </div>
            </form>
        </AuthenticationConfigurePage>
    )
}
