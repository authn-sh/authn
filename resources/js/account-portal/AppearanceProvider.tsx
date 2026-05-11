import { createContext, useContext, useMemo, type ReactNode } from 'react'
import { type Appearance } from './bootstrap'

type AppearanceContextValue = {
    appearance: Appearance
    /**
     * Resolves a className for one of the canonical element keys, threading
     * the operator's `elements` overrides on top of the SDK default class
     * name. Pure string concatenation — no Tailwind merge logic.
     */
    elementClass: (key: string, baseClass?: string) => string
}

const AppearanceContext = createContext<AppearanceContextValue | null>(null)

/**
 * Mounts the operator-configured `appearance` block as CSS custom
 * properties on the component root + exposes an `elementClass(key)`
 * helper for non-SDK pages (the bundled components consume the
 * `appearance` prop directly).
 *
 * Caller passes `appearance` explicitly (read from
 * `props.initialPage.props` in `main.tsx`) instead of going through
 * `useBootstrap()` — the provider mounts above `<App>` so the Inertia
 * page context isn't available yet.
 */
export function AppearanceProvider({ appearance, children }: { appearance: Appearance; children: ReactNode }) {
    const variables = (appearance.variables ?? {}) as Record<string, string>
    const elements = (appearance.elements ?? {}) as Record<string, string>

    const style = useMemo(() => {
        const decls = Object.entries(variables)
            .filter(([, v]) => typeof v === 'string' && v !== '')
            .map(([k, v]) => `--authn-${cssVarName(k)}: ${v};`)
            .join('\n')
        return decls === '' ? null : `:root[data-authn-appearance] {\n${decls}\n}`
    }, [variables])

    const value = useMemo<AppearanceContextValue>(() => ({
        appearance,
        elementClass: (key: string, baseClass = '') => {
            const override = elements[key]
            if (typeof override === 'string' && override !== '') {
                return baseClass === '' ? override : `${baseClass} ${override}`
            }
            return baseClass
        },
    }), [appearance, elements])

    return (
        <AppearanceContext.Provider value={value}>
            {style !== null && <style data-authn-appearance dangerouslySetInnerHTML={{ __html: style }} />}
            {children}
        </AppearanceContext.Provider>
    )
}

export function useAppearance(): AppearanceContextValue {
    const ctx = useContext(AppearanceContext)
    if (ctx === null) {
        throw new Error('useAppearance must be called inside <AppearanceProvider>.')
    }
    return ctx
}

/**
 * `colorPrimary` -> `color-primary`. Kept conservative — only camelCase
 * boundaries are split; anything that already contains a `-` is left as-is.
 */
function cssVarName(key: string): string {
    return key.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase()
}
