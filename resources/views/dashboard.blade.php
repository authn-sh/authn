<!doctype html>
<html lang="en" style="color-scheme: light dark;">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <title inertia>{{ $page['props']['operator']['name'] ?? 'authn.sh' }} — Dashboard</title>
    @viteReactRefresh
    @vite('resources/js/dashboard/main.tsx')
    @inertiaHead
    <style>
        html, body { margin: 0; background-color: var(--authn-color-background, #ffffff); }
    </style>
</head>
<body class="antialiased">
    @inertia
</body>
</html>
