<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') - {{ \App\Support\Branding::platformName() }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #fafafa; --fg: #171717; --muted: #525252; --card: #ffffff; --border: #e5e5e5; }
        @media (prefers-color-scheme: dark) { :root { --bg: #171717; --fg: #fafafa; --muted: #a3a3a3; --card: #262626; --border: #404040; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; font-family: ui-sans-serif, system-ui, sans-serif; background: var(--bg); color: var(--fg); }
        main { width: 100%; max-width: 28rem; padding: 2rem; border: 1px solid var(--border); border-radius: 0.75rem; background: var(--card); text-align: center; }
        .code { font-size: 0.875rem; color: var(--muted); letter-spacing: 0.05em; }
        h1 { margin: 0.5rem 0 0; font-size: 1.25rem; }
        p { color: var(--muted); line-height: 1.5; }
        .actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.75rem; margin-top: 1.5rem; }
        .actions a, .actions button { font: inherit; font-weight: 600; color: inherit; background: none; border: 1px solid var(--border); border-radius: 0.5rem; padding: 0.5rem 1rem; text-decoration: none; cursor: pointer; }
    </style>
</head>
<body>
    <main>
        <div class="code">@yield('code')</div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="actions">@yield('actions')</div>
    </main>
</body>
</html>
