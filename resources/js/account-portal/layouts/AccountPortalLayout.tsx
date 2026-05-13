import type { ReactNode } from 'react'

export function AccountPortalLayout({ children }: { children: ReactNode }) {
    return (
        <main className="authn-root authn-page">
            {children}
        </main>
    )
}
