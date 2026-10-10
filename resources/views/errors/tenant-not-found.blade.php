<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Organisation not found') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: ui-sans-serif, system-ui, sans-serif; background: #fafafa; color: #171717; }
        main { max-width: 28rem; padding: 2rem; text-align: center; }
        h1 { font-size: 1.25rem; }
        a { color: inherit; font-weight: 600; }
        @media (prefers-color-scheme: dark) { body { background: #171717; color: #fafafa; } }
    </style>
</head>
<body>
    <main>
        <h1>{{ __('Organisation not found') }}</h1>
        <p>{{ __('There is no organisation at this address. Check the link or go to the main site.') }}</p>
        <p><a href="{{ $apexUrl }}">{{ __('Go to the main site') }}</a></p>
    </main>
</body>
</html>
