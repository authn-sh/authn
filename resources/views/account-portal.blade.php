<!doctype html>
<html lang="{{ $page['props']['localization']['default_locale'] ?? 'en' }}" dir="{{ $page['props']['direction'] ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title inertia>{{ $page['props']['appearance']['application_name'] ?? config('app.name') }}</title>
    {{-- React Fast Refresh preamble. No-op when Vite isn't in dev mode; required ahead of `@vite` so the plugin can detect it. --}}
    @viteReactRefresh
    @vite('resources/js/account-portal/main.tsx')
    @inertiaHead
</head>
<body class="antialiased">
    @inertia
</body>
</html>
