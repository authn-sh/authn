<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title inertia>{{ $page['props']['operator']['name'] ?? 'authn.sh' }} — Dashboard</title>
    {{-- React Fast Refresh preamble. No-op when Vite isn't in dev mode; required ahead of `@vite` so the plugin can detect it. --}}
    @viteReactRefresh
    @vite('resources/js/dashboard/main.tsx')
    @inertiaHead
</head>
<body class="antialiased">
    @inertia
</body>
</html>
