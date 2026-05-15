import { useForm, usePage } from '@inertiajs/react'
import * as React from 'react'
import { Button, Input } from '@authn-sh/ui'
import { BrandingConfigurePage } from '../../../components/BrandingConfigurePage'
import { useDashboard, useDashboardUrl } from '../../../shared'

type LocalizationShape = {
    default_locale: string
    fallback_locale: string
    supported_locales: string[]
    overrides: Record<string, Record<string, string>>
}

type LocalizationCanonical = {
    shipped_locales: string[]
    fallback_locale: string
    keys: string[]
    en_us_catalog: Record<string, string>
}

type Props = {
    localization: LocalizationShape
    localization_canonical: LocalizationCanonical
}

const PLANNED_LOCALES = ['it-IT', 'nl-NL', 'pl-PL', 'ru-RU', 'tr-TR', 'zh-TW']

function extractPlaceholders(value: string): string[] {
    const matches = value.match(/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/g)
    if (!matches) return []
    return Array.from(new Set(matches.map((m) => m.slice(1, -1)))).sort()
}

export default function Localization({ localization, localization_canonical: canonical }: Props) {
    const url = useDashboardUrl()
    const { active_project, active_environment } = useDashboard()
    const { props: pageProps } = usePage<{ flash?: { localization_saved?: boolean } }>()

    const form = useForm({
        default_locale: localization.default_locale,
        fallback_locale: localization.fallback_locale,
        supported_locales: [...localization.supported_locales],
        overrides: { ...localization.overrides } as Record<string, Record<string, string>>,
    })

    if (!active_project || !active_environment) {
        return <p>No active environment.</p>
    }
    const base = `/${active_project.slug}/${active_environment.slug}/configure`

    const toggleSupported = (locale: string) => {
        const has = form.data.supported_locales.includes(locale)
        const next = has
            ? form.data.supported_locales.filter((l) => l !== locale)
            : [...form.data.supported_locales, locale]
        form.setData('supported_locales', next)
    }

    const setOverride = (locale: string, key: string, value: string) => {
        const localeOverrides = { ...(form.data.overrides[locale] ?? {}) }
        if (value === '') {
            delete localeOverrides[key]
        } else {
            localeOverrides[key] = value
        }
        form.setData('overrides', { ...form.data.overrides, [locale]: localeOverrides })
    }

    const placeholderWarnings = (() => {
        const expectedByKey: Record<string, string[]> = {}
        for (const k of canonical.keys) {
            expectedByKey[k] = extractPlaceholders(canonical.en_us_catalog[k] ?? '')
        }
        const map: Record<string, Record<string, string[]>> = {}
        for (const [locale, perKey] of Object.entries(form.data.overrides)) {
            for (const [key, override] of Object.entries(perKey)) {
                const expected = expectedByKey[key] ?? []
                if (expected.length === 0) continue
                const got = extractPlaceholders(String(override))
                const missing = expected.filter((t) => !got.includes(t))
                if (missing.length > 0) {
                    map[locale] ??= {}
                    map[locale][key] = missing
                }
            }
        }
        return map
    })()

    const placeholderWarningCount = Object.values(placeholderWarnings)
        .reduce((acc, perKey) => acc + Object.keys(perKey).length, 0)

    const localizationDiff = (() => {
        const before = localization.overrides as Record<string, Record<string, string>>
        const after = form.data.overrides
        let added = 0, changed = 0, removed = 0
        const allLocales = new Set([...Object.keys(before), ...Object.keys(after)])
        for (const locale of allLocales) {
            const b = before[locale] ?? {}
            const a = after[locale] ?? {}
            for (const k of Object.keys(a)) {
                if (!(k in b)) added++
                else if (b[k] !== a[k]) changed++
            }
            for (const k of Object.keys(b)) {
                if (!(k in a)) removed++
            }
        }
        const localeAdds = form.data.supported_locales.filter((l) => !localization.supported_locales.includes(l))
        const localeRemoves = localization.supported_locales.filter((l) => !form.data.supported_locales.includes(l))
        return { added, changed, removed, localeAdds, localeRemoves }
    })()

    const onSubmit = (e: React.FormEvent) => {
        e.preventDefault()
        form.patch(url(`${base}/localization`), { preserveScroll: true })
    }

    return (
        <BrandingConfigurePage active="localization">
            <p style={{ color: '#475569', marginBottom: 16 }}>
                Per-environment locale set + operator string overrides. Defaults ship with the SDK;
                this surface stores overrides only.
            </p>
            {pageProps.flash?.localization_saved && (
                <p style={{ color: '#15803d', marginBottom: 12 }}>Localization saved.</p>
            )}
            <form onSubmit={onSubmit}>
                <div style={{ marginBottom: 16, display: 'flex', gap: 24 }}>
                    <label>
                        <strong>Default locale</strong>
                        <select
                            value={form.data.default_locale}
                            onChange={(e) => form.setData('default_locale', e.target.value)}
                            style={{ display: 'block', marginTop: 4, padding: 6, minWidth: 160 }}
                        >
                            {form.data.supported_locales.map((l) => (
                                <option key={l} value={l}>{l}</option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <strong>Fallback locale</strong>
                        <select
                            value={form.data.fallback_locale}
                            onChange={(e) => form.setData('fallback_locale', e.target.value)}
                            style={{ display: 'block', marginTop: 4, padding: 6, minWidth: 160 }}
                        >
                            {form.data.supported_locales.map((l) => (
                                <option key={l} value={l}>{l}</option>
                            ))}
                        </select>
                    </label>
                </div>

                <details open style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    <summary><strong>Supported locales</strong></summary>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12, marginTop: 8 }}>
                        {canonical.shipped_locales.map((l) => (
                            <label key={l} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                                <input
                                    type="checkbox"
                                    checked={form.data.supported_locales.includes(l)}
                                    onChange={() => toggleSupported(l)}
                                />
                                <span>{l}</span>
                            </label>
                        ))}
                        {PLANNED_LOCALES.filter((l) => !canonical.shipped_locales.includes(l)).map((l) => (
                            <span
                                key={l}
                                title="Translation catalog ships in a future release."
                                style={{ display: 'inline-flex', alignItems: 'center', gap: 4, color: '#94a3b8', cursor: 'not-allowed' }}
                            >
                                {l}
                                <span style={{
                                    fontSize: 10,
                                    padding: '1px 6px',
                                    borderRadius: 10,
                                    background: '#f1f5f9',
                                    color: '#64748b',
                                    textTransform: 'uppercase',
                                    letterSpacing: 0.4,
                                }}>
                                    Coming soon
                                </span>
                            </span>
                        ))}
                    </div>
                </details>

                <details style={{ marginBottom: 16, padding: 12, border: '1px solid #e5e7eb', borderRadius: 6 }}>
                    <summary><strong>Overrides</strong> ({canonical.keys.length} canonical keys; only filled cells are persisted)</summary>
                    {form.errors.overrides && <p style={{ color: '#b91c1c' }}>{form.errors.overrides}</p>}
                    <div style={{ marginTop: 8, maxHeight: 600, overflow: 'auto' }}>
                        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
                            <thead>
                                <tr>
                                    <th style={{ textAlign: 'left', padding: 6, borderBottom: '1px solid #e5e7eb', position: 'sticky', top: 0, background: '#fff' }}>Key</th>
                                    <th style={{ textAlign: 'left', padding: 6, borderBottom: '1px solid #e5e7eb', position: 'sticky', top: 0, background: '#fff' }}>en-US (default)</th>
                                    {form.data.supported_locales.filter((l) => l !== 'en-US').map((l) => (
                                        <th key={l} style={{ textAlign: 'left', padding: 6, borderBottom: '1px solid #e5e7eb', position: 'sticky', top: 0, background: '#fff' }}>{l}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {canonical.keys.map((key) => (
                                    <tr key={key}>
                                        <td style={{ padding: 6, fontFamily: 'monospace', color: '#475569', verticalAlign: 'top' }}>{key}</td>
                                        <td style={{ padding: 6, color: '#0f172a', verticalAlign: 'top' }}>
                                            {canonical.en_us_catalog[key]}
                                        </td>
                                        {form.data.supported_locales.filter((l) => l !== 'en-US').map((l) => {
                                            const missing = placeholderWarnings[l]?.[key]
                                            const hasMissing = (missing?.length ?? 0) > 0
                                            return (
                                                <td key={l} style={{ padding: 6, verticalAlign: 'top' }}>
                                                    <Input
                                                        type="text"
                                                        value={form.data.overrides[l]?.[key] ?? ''}
                                                        onChange={(e) => setOverride(l, key, e.target.value)}
                                                        style={{
                                                            width: '100%',
                                                            padding: 4,
                                                            border: hasMissing ? '1px solid #f59e0b' : '1px solid #d1d5db',
                                                            background: hasMissing ? '#fffbeb' : '#fff',
                                                        }}
                                                        title={hasMissing ? `Missing placeholder tokens: ${missing!.map((m) => '{' + m + '}').join(', ')}` : undefined}
                                                    />
                                                    {hasMissing && (
                                                        <p style={{ color: '#b45309', fontSize: 11, margin: '2px 0 0' }}>
                                                            Missing: {missing!.map((m) => `{${m}}`).join(', ')}
                                                        </p>
                                                    )}
                                                </td>
                                            )
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </details>

                {(localizationDiff.added + localizationDiff.changed + localizationDiff.removed > 0
                    || localizationDiff.localeAdds.length + localizationDiff.localeRemoves.length > 0) && (
                    <div style={{ marginBottom: 12, padding: 8, background: '#eff6ff', borderRadius: 4, fontSize: 12 }}>
                        <strong>Pending changes:</strong>{' '}
                        <span style={{ color: '#166534' }}>+{localizationDiff.added}</span>{' / '}
                        <span style={{ color: '#92400e' }}>~{localizationDiff.changed}</span>{' / '}
                        <span style={{ color: '#991b1b' }}>-{localizationDiff.removed}</span>
                        {(localizationDiff.localeAdds.length > 0 || localizationDiff.localeRemoves.length > 0) && (
                            <>
                                {' · '}
                                {localizationDiff.localeAdds.length > 0 && (
                                    <span style={{ color: '#166534' }}>+locales: {localizationDiff.localeAdds.join(', ')}</span>
                                )}
                                {localizationDiff.localeAdds.length > 0 && localizationDiff.localeRemoves.length > 0 && '; '}
                                {localizationDiff.localeRemoves.length > 0 && (
                                    <span style={{ color: '#991b1b' }}>-locales: {localizationDiff.localeRemoves.join(', ')}</span>
                                )}
                            </>
                        )}
                    </div>
                )}
                {placeholderWarningCount > 0 && (
                    <p style={{ color: '#b45309', fontSize: 12, marginBottom: 8 }}>
                        {placeholderWarningCount} placeholder warning{placeholderWarningCount === 1 ? '' : 's'}.{' '}
                        Overrides that drop a <code>{'{variable}'}</code> token from the canonical string will still save,
                        but the rendered string may show a literal <code>{'{variable}'}</code>.
                    </p>
                )}
                <Button variant="secondary" type="submit" disabled={form.processing}>
                    {form.processing ? 'Saving…' : 'Save localization'}
                </Button>
            </form>
        </BrandingConfigurePage>
    )
}
