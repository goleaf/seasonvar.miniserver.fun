<!DOCTYPE html>
<html lang="{{ $htmlLocale }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="robots" content="noindex,nofollow,noarchive">
        <meta name="theme-color" content="#ecfdf5">
        <meta name="referrer" content="no-referrer">
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="apple-touch-icon" href="/icons/pwa-192.png">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="Seasonvar">
        <title>{{ $title }}</title>
        @vite('resources/js/app.js')
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-800 antialiased" data-pwa-enabled="1" data-pwa-service-worker-url="/service-worker.js" data-pwa-session-url="/pwa/session" data-pwa-help-snapshot-url="{{ $pwaHelpSnapshotUrl }}">
        @yield('content')
    </body>
</html>
