<!DOCTYPE html>
<html lang="bg">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#e10600">

        <title>@yield('code') · @yield('title') — F1 България</title>

        {{-- Нарочно без Vite/външни асети: error страницата трябва да работи и когато app-ът е счупен. --}}
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            html { background: #0a0a0a; }
            body {
                font-family: 'Segoe UI', ui-sans-serif, system-ui, -apple-system, Roboto, 'Helvetica Neue', Arial, sans-serif;
                background: #0a0a0a;
                color: #f4f4f5;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                -webkit-font-smoothing: antialiased;
            }
            .wrap { text-align: center; padding: 2rem; }
            .brand { font-size: 1.125rem; font-weight: 900; letter-spacing: -0.025em; margin-bottom: 2.5rem; }
            .brand span { color: #e10600; }
            .code {
                font-size: 6rem;
                font-weight: 900;
                line-height: 1;
                color: #e10600;
                font-variant-numeric: tabular-nums;
            }
            .message { margin-top: 0.75rem; font-size: 1.125rem; color: #a1a1aa; }
            .home {
                display: inline-block;
                margin-top: 2.5rem;
                padding: 0.625rem 1.25rem;
                border-radius: 0.5rem;
                background: #e10600;
                color: #fff;
                font-weight: 600;
                font-size: 0.875rem;
                text-decoration: none;
                transition: background 0.2s;
            }
            .home:hover { background: #ff2d1f; }
            .home:focus-visible { outline: 2px solid #ff2d1f; outline-offset: 2px; }
            .stripe { position: fixed; left: 0; right: 0; bottom: 0; height: 6px; background: #e10600; }
        </style>
    </head>
    <body>
        <main class="wrap" role="main">
            <div class="brand"><span>F1</span> България</div>
            <div class="code">@yield('code')</div>
            <p class="message">@yield('message')</p>
            <a class="home" href="/">← Към началото</a>
        </main>
        <div class="stripe" aria-hidden="true"></div>
    </body>
</html>
