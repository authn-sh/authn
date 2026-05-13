import type { SVGProps } from 'react'

const base: SVGProps<SVGSVGElement> = {
    width: 16,
    height: 16,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.75,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
    'aria-hidden': true,
    className: 'authn-nav-item-icon',
}

export function HomeIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p}>
            <path d="M3 11l9-8 9 8" />
            <path d="M5 10v10h14V10" />
        </svg>
    )
}
export function UsersIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p}>
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
            <circle cx="9" cy="7" r="4" />
            <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
            <path d="M16 3.13a4 4 0 0 1 0 7.75" />
        </svg>
    )
}
export function BuildingIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p}>
            <rect x="4" y="3" width="16" height="18" rx="2" />
            <path d="M9 7h.01M13 7h.01M9 11h.01M13 11h.01M9 15h.01M13 15h.01" />
        </svg>
    )
}
export function KeyIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p}>
            <circle cx="8" cy="15" r="4" />
            <path d="M10.85 12.15 19 4M18 5l2 2M15 8l2 2" />
        </svg>
    )
}
export function MailIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p}>
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="m3 7 9 6 9-6" />
        </svg>
    )
}
export function ShieldCheckIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p}>
            <path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6Z" />
            <path d="m9 12 2 2 4-4" />
        </svg>
    )
}
export function ShieldOffIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p}>
            <path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6Z" />
            <path d="m9 9 6 6M15 9l-6 6" />
        </svg>
    )
}
export function SlidersIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p}>
            <path d="M4 6h11M4 12h7M4 18h13" />
            <circle cx="17" cy="6" r="2" />
            <circle cx="13" cy="12" r="2" />
            <circle cx="19" cy="18" r="2" />
        </svg>
    )
}
export function FileTextIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p}>
            <path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z" />
            <path d="M14 3v6h6M9 13h6M9 17h4" />
        </svg>
    )
}
export function WebhookIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p}>
            <path d="M18 16.98h-5.99c-1.1 0-1.95.94-2.48 1.9A4 4 0 0 1 2 17a4 4 0 0 1 5.04-3.86" />
            <path d="m7.74 16.5 2.96-5.13A4 4 0 1 1 17 12" />
            <path d="m14 9 1.04 6.3" />
        </svg>
    )
}
export function ScrollIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p}>
            <path d="M19 17V5a2 2 0 0 0-2-2H4" />
            <path d="M8 21h12a2 2 0 0 0 2-2v-1H10v-1H4" />
            <path d="M22 17H6V3" />
        </svg>
    )
}
export function ShieldIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p}>
            <path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6Z" />
        </svg>
    )
}
export function ChevronsLeftIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p} className={p.className ?? base.className}>
            <path d="m11 17-5-5 5-5M18 17l-5-5 5-5" />
        </svg>
    )
}
export function ChevronsRightIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p} className={p.className ?? base.className}>
            <path d="m13 17 5-5-5-5M6 17l5-5-5-5" />
        </svg>
    )
}
export function ChevronDownIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p} className={p.className ?? base.className}>
            <path d="m6 9 6 6 6-6" />
        </svg>
    )
}
export function BoxIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p}>
            <path d="m21 16-9 5-9-5V8l9-5 9 5z" />
            <path d="m3.3 7 8.7 5 8.7-5M12 22V12" />
        </svg>
    )
}
export function PlusIcon(p: SVGProps<SVGSVGElement>) {
    return (
        <svg {...base} {...p} className={p.className ?? base.className}>
            <path d="M12 5v14M5 12h14" />
        </svg>
    )
}
