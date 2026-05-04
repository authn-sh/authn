import type { ReactNode } from 'react'
import { useBootstrap } from '../bootstrap'

/**
 * Minimal layout wrapper. Honours brand_color from the env appearance
 * blob; theming heavy lifting lives in @authn.sh/sdk-react (SR-3).
 */
export function AccountPortalLayout({ children }: { children: ReactNode }) {
    const { env } = useBootstrap()
    const accent = env?.appearance.brand_color ?? '#5b21b6'

    return (
        <main
            style={{
                minHeight: '100dvh',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontFamily: 'system-ui, -apple-system, sans-serif',
                backgroundColor: '#f8fafc',
                color: '#0f172a',
            }}
        >
            <section
                style={{
                    width: 'min(420px, calc(100% - 32px))',
                    padding: 32,
                    borderRadius: 12,
                    backgroundColor: 'white',
                    borderTop: `4px solid ${accent}`,
                    boxShadow: '0 8px 24px rgba(15, 23, 42, 0.08)',
                }}
            >
                {children}
            </section>
        </main>
    )
}
