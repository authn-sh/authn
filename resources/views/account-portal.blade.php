<!doctype html>
<html lang="{{ $page['props']['localization']['default_locale'] ?? 'en' }}" dir="{{ $page['props']['direction'] ?? 'ltr' }}" style="color-scheme: light dark;">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <title inertia>{{ $page['props']['appearance']['application_name'] ?? config('app.name') }}</title>
    @viteReactRefresh
    @vite('resources/js/account-portal/main.tsx')
    @inertiaHead
    <style>
        html, body { margin: 0; background-color: var(--authn-color-background-muted, #f8fafc); }
    </style>
</head>
<body class="antialiased">
    @inertia
</body>
</html>
