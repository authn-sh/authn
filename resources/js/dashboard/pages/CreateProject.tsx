import { useForm } from '@inertiajs/react'
import { useDashboardUrl } from '../shared'

export default function CreateProject() {
    const form = useForm({ name: '', slug: '' })
    const url = useDashboardUrl()

    return (
        <div>
            <h1>Create your first project</h1>
            <form
                onSubmit={(e) => {
                    e.preventDefault()
                    form.post(url('/create-project'))
                }}
            >
                <label style={{ display: 'block', marginBottom: 12 }}>
                    Name
                    <input
                        name="name"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        style={{ display: 'block', width: '100%', padding: 8, marginTop: 4 }}
                    />
                    {form.errors.name && <span style={{ color: '#c00', fontSize: 12 }}>{form.errors.name}</span>}
                </label>
                <label style={{ display: 'block', marginBottom: 12 }}>
                    Slug
                    <input
                        name="slug"
                        value={form.data.slug}
                        onChange={(e) => form.setData('slug', e.target.value)}
                        style={{ display: 'block', width: '100%', padding: 8, marginTop: 4 }}
                    />
                    {form.errors.slug && <span style={{ color: '#c00', fontSize: 12 }}>{form.errors.slug}</span>}
                </label>
                <button type="submit" disabled={form.processing}>
                    Create project
                </button>
            </form>
        </div>
    )
}
