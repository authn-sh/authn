import type { ReactNode } from 'react'

export function AccountPortalLayout({ children }: { children: ReactNode }) {
    return (
        <main
            style={{
                minHeight: '100dvh',
                backgroundColor: '#f8fafc',
                color: '#0f172a',
            }}
        >
            {children}
        </main>
    )
}
