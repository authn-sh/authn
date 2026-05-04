import { useBootstrap } from '../bootstrap'

export default function Verify() {
    const { ready } = useBootstrap()
    if (!ready) {
        return <p>Loading…</p>
    }

    return (
        <div>
            <h1 style={{ marginTop: 0 }}>Verifying…</h1>
            <p>Hang tight while we confirm your link.</p>
        </div>
    )
}
