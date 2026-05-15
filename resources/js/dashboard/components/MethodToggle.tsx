import * as React from 'react'
import { Switch } from '@authn-sh/ui'

export function MethodGrid({ children }: { children: React.ReactNode }) {
    return <div className="authn-method-grid">{children}</div>
}

export function MethodToggle({
    label,
    description,
    checked,
    onChange,
    children,
}: {
    label: React.ReactNode
    description?: React.ReactNode
    checked: boolean
    onChange: (next: boolean) => void
    children?: React.ReactNode
}) {
    const switchId = React.useId()
    return (
        <div className="authn-method-toggle-group">
            <div className="authn-method-toggle">
                <label htmlFor={switchId} className="authn-method-toggle-text">
                    <span className="authn-method-toggle-label">{label}</span>
                    {description && <span className="authn-method-toggle-description">{description}</span>}
                </label>
                <Switch
                    id={switchId}
                    checked={checked}
                    onCheckedChange={onChange}
                    aria-label={typeof label === 'string' ? label : undefined}
                />
            </div>
            {checked && children && <div className="authn-method-toggle-children">{children}</div>}
        </div>
    )
}
