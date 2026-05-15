import { useForm } from '@inertiajs/react'
import { Button, Card, Field, Form, HelperText, Input, Label } from '@authn-sh/ui'
import { useDashboardUrl } from '../shared'

export default function CreateProject() {
    const form = useForm({ name: '', slug: '' })
    const url = useDashboardUrl()

    return (
        <div className="authn-rooted">
            <Card>
                <header>
                    <h1 className="authn-heading">Create your first project</h1>
                    <p className="authn-subheading">A project groups environments, users, and policies for one tenant app.</p>
                </header>
                <Form
                    onSubmit={(e) => {
                        e.preventDefault()
                        form.post(url('/create-project'))
                    }}
                    aria-busy={form.processing}
                >
                    <Field>
                        <Label htmlFor="create-project-name">Name</Label>
                        <Input
                            id="create-project-name"
                            name="name"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            invalid={!!form.errors.name}
                            autoFocus
                            required
                        />
                        {form.errors.name && <HelperText tone="danger">{form.errors.name}</HelperText>}
                    </Field>
                    <Field>
                        <Label htmlFor="create-project-slug">Slug</Label>
                        <Input
                            id="create-project-slug"
                            name="slug"
                            value={form.data.slug}
                            onChange={(e) => form.setData('slug', e.target.value)}
                            invalid={!!form.errors.slug}
                            required
                        />
                        {form.errors.slug && <HelperText tone="danger">{form.errors.slug}</HelperText>}
                    </Field>
                    <Button type="submit" loading={form.processing}>
                        Create project
                    </Button>
                </Form>
            </Card>
        </div>
    )
}
