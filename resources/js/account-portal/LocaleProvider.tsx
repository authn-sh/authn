import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useBootstrap, type Localization } from './bootstrap'

type LocaleContextValue = {
    locale: string
    localization: Localization
    /**
     * Render a canonical key with `{placeholder}` substitution. Falls
     * through to the operator override for the active locale, then the
     * fallback locale's override, then the dot-keyed key itself (so the
     * dev sees the literal key when something's miswired).
     */
    t: (key: string, vars?: Record<string, string | number>) => string
}

const LocaleContext = createContext<LocaleContextValue | null>(null)

/**
 * Reads the active locale from Inertia bootstrap + lets the SDK override
 * it client-side via `Authn.setLocale()` (PLAN §11.6.3 resolution order:
 * server-side resolution wins on first paint; browser-side setLocale()
 * wins after that). Translation lookups consult the catalog the server
 * pre-rendered into the bootstrap envelope (single round-trip) and fall
 * through per the strict-semantic spec.
 */
export function LocaleProvider({ children }: { children: ReactNode }) {
    const { ready, env } = useBootstrap()
    const localization: Localization = ready && env
        ? env.localization
        : { default_locale: 'en-US', fallback_locale: 'en-US', supported_locales: ['en-US'] }

    const [locale, setLocale] = useState<string>(localization.default_locale)

    useEffect(() => {
        // Customer apps mounting the SDK can override the locale globally
        // via `Authn.setLocale(tag)` — listen for the SDK's emitted event.
        const handler = (event: Event) => {
            const ce = event as CustomEvent<{ locale?: string }>
            const next = ce.detail?.locale
            if (typeof next === 'string' && localization.supported_locales.includes(next)) {
                setLocale(next)
            }
        }
        window.addEventListener('authn:locale-changed', handler as EventListener)
        return () => window.removeEventListener('authn:locale-changed', handler as EventListener)
    }, [localization.supported_locales])

    const catalog = useMemo<Record<string, string>>(() => localization.catalog ?? {}, [localization.catalog])

    const t = useCallback((key: string, vars?: Record<string, string | number>): string => {
        const template = typeof catalog[key] === 'string' && catalog[key] !== '' ? catalog[key] : key
        if (vars === undefined) {
            return template
        }
        return Object.entries(vars).reduce(
            (acc, [name, value]) => acc.split(`{${name}}`).join(String(value)),
            template,
        )
    }, [catalog])

    const value = useMemo<LocaleContextValue>(() => ({
        locale,
        localization,
        t,
    }), [locale, localization, t])

    return <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>
}

export function useLocale(): LocaleContextValue {
    const ctx = useContext(LocaleContext)
    if (ctx === null) {
        throw new Error('useLocale must be called inside <LocaleProvider>.')
    }
    return ctx
}
