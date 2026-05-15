import { useForm } from '@inertiajs/react'
import * as React from 'react'
import { Button, Field, Input } from '@authn-sh/ui'

export type OauthApplicationRow = {
    id: string
    name: string
    client_id: string
    callback_urls: string[]
    scopes: string[]
    is_public: boolean
    grants_count: number
    created_at: number | null
}

const KNOWN_SCOPES = ['openid', 'profile', 'email'] as const

export function OauthApplicationForm({
    application,
    saveUrl,
    onDone,
}: {
    application: OauthApplicationRow | null
    saveUrl: string
    onDone?: () => void
}) {
    const isEdit = application !== null
    const form = useForm({
        name: application?.name ?? '',
        callback_urls_text: (application?.callback_urls ?? []).join('\n'),
        scopes: application?.scopes ?? ['openid', 'profile', 'email'],
        is_public: application?.is_public ?? false,
    })
    const [customScope, setCustomScope] = React.useState('')

    const toggleScope = (scope: string) => {
        const set = new Set(form.data.scopes)
        if (set.has(scope)) set.delete(scope)
        else set.add(scope)
        form.setData('scopes', Array.from(set))
    }

    const onSubmit = (e: React.FormEvent) => {
        e.preventDefault()
        const urls = form.data.callback_urls_text.split('\n').map((s) => s.trim()).filter(Boolean)
        const payload = form.transform(() => ({
            name: form.data.name,
            callback_urls: urls,
            scopes: form.data.scopes,
            ...(isEdit ? {} : { is_public: form.data.is_public }),
        }))
        if (isEdit) {
            payload.patch(saveUrl, { preserveScroll: true, onSuccess: onDone })
        } else {
            payload.post(saveUrl, { preserveScroll: true, onSuccess: onDone })
        }
    }

    return (
        <form onSubmit={onSubmit} className="authn-stack">
            <Field label="Name" htmlFor="oauth-app-name" error={form.errors.name}>
                <Input
                    id="oauth-app-name"
                    type="text"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
            </Field>

            <Field
                label="Callback URLs"
                htmlFor="oauth-app-callbacks"
                helper="One per line. Must match exactly on /oauth/authorize."
                error={form.errors.callback_urls as string | undefined}
            >
                <textarea
                    id="oauth-app-callbacks"
                    value={form.data.callback_urls_text}
                    onChange={(e) => form.setData('callback_urls_text', e.target.value)}
                    rows={4}
                    placeholder="https://app.acme.example/oauth/callback"
                    className="authn-input"
                    style={{ fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace' }}
                />
            </Field>

            <Field label="Scopes" helper="Pick from common OIDC scopes or add a custom one.">
                <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                    {KNOWN_SCOPES.map((s) => (
                        <label key={s} style={{ display: 'flex', alignItems: 'center', gap: 4 }}>
                            <input type="checkbox" checked={form.data.scopes.includes(s)} onChange={() => toggleScope(s)} />
                            <code>{s}</code>
                        </label>
                    ))}
                </div>
                {form.data.scopes.filter((s) => !KNOWN_SCOPES.includes(s as typeof KNOWN_SCOPES[number])).map((s) => (
                    <div key={s} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, marginRight: 8, marginTop: 6 }}>
                        <code>{s}</code>
                        <Button variant="ghost" type="button" onClick={() => toggleScope(s)}>×</Button>
                    </div>
                ))}
                <div style={{ display: 'flex', gap: 6, marginTop: 6 }}>
                    <Input
                        type="text"
                        value={customScope}
                        onChange={(e) => setCustomScope(e.target.value)}
                        placeholder="acme.read_billing"
                    />
                    <Button
                        variant="secondary"
                        type="button"
                        onClick={() => {
                            if (!customScope.trim()) return
                            if (!form.data.scopes.includes(customScope.trim())) {
                                form.setData('scopes', [...form.data.scopes, customScope.trim()])
                            }
                            setCustomScope('')
                        }}
                    >
                        Add scope
                    </Button>
                </div>
            </Field>

            {!isEdit && (
                <label style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <input type="checkbox" checked={form.data.is_public} onChange={(e) => form.setData('is_public', e.target.checked)} />
                    Public client (PKCE-only; no client_secret minted)
                </label>
            )}

            <div style={{ display: 'flex', gap: 8 }}>
                <Button type="submit" loading={form.processing}>
                    {isEdit ? 'Save changes' : 'Create application'}
                </Button>
                {onDone && (
                    <Button type="button" variant="secondary" onClick={onDone}>Cancel</Button>
                )}
            </div>
        </form>
    )
}
