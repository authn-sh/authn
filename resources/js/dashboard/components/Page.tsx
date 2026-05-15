import { Link } from '@inertiajs/react'
import type { ReactNode } from 'react'

type BackLink = { href: string; label?: string }

export function Page({
    title,
    subtitle,
    icon,
    backLink,
    actions,
    tabs,
    children,
}: {
    title: ReactNode
    subtitle?: ReactNode
    icon?: ReactNode
    backLink?: BackLink
    actions?: ReactNode
    tabs?: ReactNode
    children?: ReactNode
}) {
    return (
        <>
            <div className="authn-page-header">
                <div className="authn-page-header-leading">
                    {backLink && (
                        <Link href={backLink.href} className="authn-link">
                            ← {backLink.label ?? 'Back'}
                        </Link>
                    )}
                    <div className="authn-page-heading">
                        {icon && <span className="authn-page-heading-icon">{icon}</span>}
                        <div>
                            <h1 className="authn-page-title">{title}</h1>
                            {subtitle && <p className="authn-page-subtitle">{subtitle}</p>}
                        </div>
                    </div>
                </div>
                {actions && <div className="authn-page-header-trailing">{actions}</div>}
            </div>
            {tabs}
            {children}
        </>
    )
}
