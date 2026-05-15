import * as React from 'react'
import { Card, CardBody, CardHeader, Switch } from '@authn-sh/ui'

export function MethodCard({
    icon,
    title,
    description,
    enabled,
    onToggle,
    children,
}: {
    icon: React.ReactNode
    title: string
    description: string
    enabled: boolean
    onToggle: (next: boolean) => void
    children?: React.ReactNode
}) {
    return (
        <Card variant="row">
            <CardHeader
                icon={icon}
                title={title}
                description={description}
                slot={<Switch checked={enabled} onCheckedChange={onToggle} aria-label={title} />}
            />
            {enabled && children && <CardBody>{children}</CardBody>}
        </Card>
    )
}
