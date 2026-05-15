import { useForm, usePage } from '@inertiajs/react'
import * as React from 'react'
import { Button } from '@authn-sh/ui'
import { BrandingConfigurePage } from '../../../components/BrandingConfigurePage'
import { useDashboard, useDashboardUrl } from '../../../shared'

type AppearanceShape = {
    variables?: Record<string, string>
    elements?: Record<string, string>
    layout?: Record<string, unknown>
}

type Props = { appearance: AppearanceShape }

const VARIABLE_KEYS = [
    'colorPrimary',
    'colorBackground',
    'colorText',
    'colorTextOnPrimary',
    'colorInputBackground',
    'colorInputText',
    'colorDanger',
    'colorSuccess',
    'colorWarning',
    'colorNeutral',
    'fontFamily',
    'fontFamilyButtons',
    'fontSize',
    'borderRadius',
    'spacingUnit',
] as const

const LAYOUT_KEYS = [
    'logoImageUrl',
    'logoLinkUrl',
    'socialButtonsPlacement',
    'socialButtonsVariant',
    'showOptionalFields',
    'privacyPageUrl',
    'termsPageUrl',
    'helpPageUrl',
    'animations',
] as const

const CANONICAL_ELEMENT_KEYS = [
    'signIn.root',
    'signIn.card',
    'signIn.header',
    'signIn.title',
    'signIn.formButtonPrimary',
    'signIn.formFieldInput',
    'signIn.identifierField',
    'signIn.passwordField',
    'signIn.socialButtonsRoot',
    'signIn.socialButton',
    'signUp.root',
    'signUp.card',
    'signUp.header',
    'signUp.title',
    'signUp.formButtonPrimary',
    'signUp.formFieldInput',
    'userProfile.root',
    'userProfile.section',
    'userProfile.sectionTitle',
    'userProfile.row',
    'userButton.root',
    'userButton.avatar',
    'userButton.menu',
    'organizationProfile.root',
    'organizationProfile.section',
    'organizationProfile.sectionTitle',
    'organizationSwitcher.root',
    'organizationSwitcher.trigger',
    'organizationSwitcher.menu',
    'button.primary',
    'button.secondary',
    'button.ghost',
    'button.danger',
    'card.root',
    'dialog.overlay',
    'dialog.content',
    'dialog.title',
    'dialog.description',
    'input.root',
    'label.root',
    'field.root',
    'form.root',
    'helperText.root',
    'badge.root',
    'alert.root',
    'avatar.root',
    'tooltip.content',
] as const

type ElementDiff = {
    added: string[]
    changed: string[]
    removed: string[]
}

function diffElementMaps(before: Record<string, string>, after: Record<string, string>): ElementDiff {
    const added: string[] = []
    const changed: string[] = []
    const removed: string[] = []
    for (const k of Object.keys(after)) {
        if (!(k in before)) added.push(k)
        else if (before[k] !== after[k]) changed.push(k)
    }
    for (const k of Object.keys(before)) {
        if (!(k in after)) removed.push(k)
    }
    return { added: added.sort(), changed: changed.sort(), removed: removed.sort() }
}

function insertSnippetOnTab(
    event: React.KeyboardEvent<HTMLTextAreaElement>,
    text: string,
    apply: (next: string, cursor: number) => void,
): void {
    if (event.key !== 'Tab' || event.shiftKey) return
    const ta = event.currentTarget
    const caret = ta.selectionStart
    if (caret !== ta.selectionEnd) return
    const before = text.slice(0, caret)
    const after = text.slice(caret)
    const lineStart = before.lastIndexOf('\n') + 1
    const currentLine = before.slice(lineStart).trimStart()
    if (!currentLine.startsWith('"')) return
    const partial = currentLine.slice(1).replace(/"[^"]*$/, '')
    const match = CANONICAL_ELEMENT_KEYS.find((k) => k.startsWith(partial))
    if (!match || match === partial) return
    event.preventDefault()
    const completion = match.slice(partial.length) + '": "",'
    const cursor = caret + completion.length - 3
    apply(before + completion + after, cursor)
}

export default function Appearance({ appearance }: Props) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const { props: pageProps } = usePage<{ flash?: { appearance_saved?: boolean } }>()
    const [previewUrl, setPreviewUrl] = React.useState<string | null>(null)
    const [previewError, setPreviewError] = React.useState<string | null>(null)
    const [previewing, setPreviewing] = React.useState(false)

    const initialElementsObj = (appearance.elements ?? {}) as Record<string, string>
    const initial = {
        variables: { ...(appearance.variables ?? {}) } as Record<string, string>,
        elements: JSON.stringify(initialElementsObj, null, 2),
        layout: { ...((appearance.layout ?? {}) as Record<string, unknown>) },
    }
    const form = useForm(initial)

    if (!active_project || !active_environment) {
        return <p>No active environment.</p>
    }
    const base = `/${active_project.slug}/${active_environment.slug}/configure`

    const parsedElements = (() => {
        try {
            const v = JSON.parse(form.data.elements || '{}')
            return v && typeof v === 'object' && !Array.isArray(v)
                ? { ok: true as const, value: v as Record<string, string> }
                : { ok: false as const, error: 'Elements must be a JSON object.' }
        } catch (err) {
            return { ok: false as const, error: (err as Error).message }
        }
    })()

    const unknownKeys = parsedElements.ok
        ? Object.keys(parsedElements.value).filter((k) => !CANONICAL_ELEMENT_KEYS.includes(k as (typeof CANONICAL_ELEMENT_KEYS)[number]))
        : []

    const diff = parsedElements.ok ? diffElementMaps(initialElementsObj, parsedElements.value) : null

    const onSubmit = (e: React.FormEvent) => {
        e.preventDefault()
        if (!parsedElements.ok) {
            form.setError('elements', parsedElements.error)
            return
        }
        form.transform(() => ({
            variables: form.data.variables,
            elements: parsedElements.value,
            layout: form.data.layout,
        })).patch(url(`${base}/appearance`), { preserveScroll: true })
    }

    return (
        <BrandingConfigurePage active="appearance">
            <p style={{ color: '#475569', marginBottom: 16 }}>
                Operator-configured visual customisation. Values flow to <code>GET /v1/environment</code>
                and into the bundled component visuals.
            </p>
            {pageProps.flash?.appearance_saved && (
                <p style={{ color: '#15803d', marginBottom: 12 }}>Appearance saved.</p>
            )}
            <form onSubmit={onSubmit}>
                <details open style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    <summary><strong>Variables</strong> (CSS design tokens)</summary>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 8, marginTop: 8 }}>
                        {VARIABLE_KEYS.map((k) => (
                            <label key={k} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <span style={{ flexBasis: 180, color: '#475569' }}>{k}</span>
                                <input
                                    type="text"
                                    value={form.data.variables[k] ?? ''}
                                    onChange={(e) => form.setData('variables', { ...form.data.variables, [k]: e.target.value })}
                                    placeholder={k.startsWith('color') ? '#0a84ff or rgb(...)' : ''}
                                    style={{ flex: 1, padding: 6 }}
                                />
                            </label>
                        ))}
                    </div>
                </details>

                <details open style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    <summary>
                        <strong>Elements</strong> (className override map, JSON) — <code>{CANONICAL_ELEMENT_KEYS.length}</code> known slots
                    </summary>
                    <p style={{ color: '#475569', fontSize: 12, marginTop: 8, marginBottom: 8 }}>
                        Type <code>"signIn.root":</code> (or any key from the list below) and an autocomplete chip will appear.
                        Unknown keys still save, but raise a warning so typos don't ship silently.
                    </p>
                    <textarea
                        value={form.data.elements}
                        onChange={(e) => form.setData('elements', e.target.value)}
                        onKeyDown={(e) => insertSnippetOnTab(e, form.data.elements, (next, cursor) => {
                            form.setData('elements', next)
                            requestAnimationFrame(() => {
                                const ta = e.currentTarget
                                ta.setSelectionRange(cursor, cursor)
                            })
                        })}
                        spellCheck={false}
                        rows={12}
                        list="canonical-element-keys"
                        style={{ width: '100%', fontFamily: 'monospace', padding: 8, marginTop: 4 }}
                    />
                    <datalist id="canonical-element-keys">
                        {CANONICAL_ELEMENT_KEYS.map((k) => (<option key={k} value={k} />))}
                    </datalist>
                    {!parsedElements.ok && (
                        <p style={{ color: '#b91c1c', marginTop: 4 }}>JSON parse error: {parsedElements.error}</p>
                    )}
                    {form.errors.elements && <p style={{ color: '#b91c1c' }}>{form.errors.elements}</p>}
                    {parsedElements.ok && unknownKeys.length > 0 && (
                        <div style={{ marginTop: 8, padding: 8, background: '#fef3c7', borderRadius: 4, fontSize: 12 }}>
                            <strong>Unknown element keys</strong> (will save but may be ignored by SDKs):
                            <ul style={{ margin: '4px 0 0 16px' }}>
                                {unknownKeys.map((k) => (<li key={k}><code>{k}</code></li>))}
                            </ul>
                        </div>
                    )}
                    {diff && (diff.added.length + diff.changed.length + diff.removed.length > 0) && (
                        <div style={{ marginTop: 8, padding: 8, background: '#eff6ff', borderRadius: 4, fontSize: 12 }}>
                            <strong>Pending changes:</strong>{' '}
                            <span style={{ color: '#166534' }}>+{diff.added.length}</span>{' / '}
                            <span style={{ color: '#92400e' }}>~{diff.changed.length}</span>{' / '}
                            <span style={{ color: '#991b1b' }}>-{diff.removed.length}</span>
                            <details style={{ marginTop: 4 }}>
                                <summary>Show diff</summary>
                                <ul style={{ margin: '4px 0 0 16px', listStyle: 'none', paddingLeft: 0 }}>
                                    {diff.added.map((k) => (<li key={'a-' + k} style={{ color: '#166534' }}>+ {k}: <code>{parsedElements.value[k]}</code></li>))}
                                    {diff.changed.map((k) => (
                                        <li key={'c-' + k} style={{ color: '#92400e' }}>
                                            ~ {k}: <code>{initialElementsObj[k]}</code> → <code>{parsedElements.value[k]}</code>
                                        </li>
                                    ))}
                                    {diff.removed.map((k) => (<li key={'r-' + k} style={{ color: '#991b1b' }}>- {k}: <code>{initialElementsObj[k]}</code></li>))}
                                </ul>
                            </details>
                        </div>
                    )}
                </details>

                <details style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    <summary><strong>Layout</strong></summary>
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 8, marginTop: 8 }}>
                        {LAYOUT_KEYS.map((k) => {
                            const isBool = k === 'showOptionalFields' || k === 'animations'
                            const isSelect = k === 'socialButtonsPlacement' || k === 'socialButtonsVariant'
                            const value = form.data.layout[k]
                            if (isBool) {
                                return (
                                    <label key={k} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                        <input
                                            type="checkbox"
                                            checked={Boolean(value)}
                                            onChange={(e) => form.setData('layout', { ...form.data.layout, [k]: e.target.checked })}
                                        />
                                        <span>{k}</span>
                                    </label>
                                )
                            }
                            if (isSelect && k === 'socialButtonsPlacement') {
                                return (
                                    <label key={k} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                        <span style={{ flexBasis: 180, color: '#475569' }}>{k}</span>
                                        <select
                                            value={typeof value === 'string' ? value : ''}
                                            onChange={(e) => form.setData('layout', { ...form.data.layout, [k]: e.target.value })}
                                            style={{ flex: 1, padding: 6 }}
                                        >
                                            <option value="">(default)</option>
                                            <option value="top">top</option>
                                            <option value="bottom">bottom</option>
                                        </select>
                                    </label>
                                )
                            }
                            if (isSelect && k === 'socialButtonsVariant') {
                                return (
                                    <label key={k} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                        <span style={{ flexBasis: 180, color: '#475569' }}>{k}</span>
                                        <select
                                            value={typeof value === 'string' ? value : ''}
                                            onChange={(e) => form.setData('layout', { ...form.data.layout, [k]: e.target.value })}
                                            style={{ flex: 1, padding: 6 }}
                                        >
                                            <option value="">(default)</option>
                                            <option value="iconButton">iconButton</option>
                                            <option value="blockButton">blockButton</option>
                                        </select>
                                    </label>
                                )
                            }
                            return (
                                <label key={k} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                    <span style={{ flexBasis: 180, color: '#475569' }}>{k}</span>
                                    <input
                                        type={k.endsWith('Url') ? 'url' : 'text'}
                                        value={typeof value === 'string' ? value : ''}
                                        onChange={(e) => form.setData('layout', { ...form.data.layout, [k]: e.target.value })}
                                        style={{ flex: 1, padding: 6 }}
                                    />
                                </label>
                            )
                        })}
                    </div>
                </details>

                <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                    <Button type="submit" loading={form.processing}>
                        {form.processing ? 'Saving…' : 'Save appearance'}
                    </Button>
                    <Button
                        type="button"
                        loading={previewing || !parsedElements.ok}
                        onClick={async () => {
                            setPreviewError(null)
                            setPreviewing(true)
                            try {
                                const res = await fetch(url(`${base}/appearance/preview`), {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                                    credentials: 'same-origin',
                                    body: JSON.stringify({
                                        variables: form.data.variables,
                                        elements: parsedElements.ok ? parsedElements.value : {},
                                        layout: form.data.layout,
                                    }),
                                })
                                if (!res.ok) {
                                    throw new Error(`preview request failed (${res.status})`)
                                }
                                const body = await res.json()
                                setPreviewUrl(String(body.preview_url ?? ''))
                            } catch (err) {
                                setPreviewError((err as Error).message)
                            } finally {
                                setPreviewing(false)
                            }
                        }}
                    >
                        {previewing ? 'Preparing…' : 'Preview in <SignIn />'}
                    </Button>
                    {previewError && <span style={{ color: '#b91c1c', fontSize: 12 }}>{previewError}</span>}
                </div>

                {previewUrl && (
                    <div style={{ marginTop: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                        <p style={{ fontSize: 12, color: '#475569', marginBottom: 8 }}>
                            Preview against the unsaved draft. Token expires in 5 minutes.
                        </p>
                        <iframe
                            title="Appearance preview"
                            src={previewUrl}
                            style={{ width: '100%', minHeight: 480, border: '1px solid #cbd5e1', borderRadius: 4 }}
                            sandbox="allow-scripts allow-same-origin"
                        />
                    </div>
                )}
            </form>
        </BrandingConfigurePage>
    )
}
