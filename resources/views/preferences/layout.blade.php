@php
    $ink = $brand['heading_color'] ?? '#1B1D3E';
    $text = $brand['text_color'] ?? '#4A4D6E';
    $muted = $brand['muted_color'] ?? '#8B8EAC';
    $page = $brand['background_color'] ?? '#FFFAF4';
    $card = $brand['card_color'] ?? '#FFFFFF';
    $accent = $brand['accent_color'] ?? '#FED325';
    $brandName = $workspace->name;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ $brandName }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: {{ $page }}; color: {{ $text }}; font: 16px/1.6 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; }
        .wrap { max-width: 520px; margin: 0 auto; padding: 40px 16px 56px; }
        .logo { display: block; margin: 0 auto 28px; height: 36px; width: auto; max-width: 200px; }
        .brand-text { text-align: center; margin: 0 0 28px; font-weight: 800; font-size: 20px; color: {{ $ink }}; }
        .card { background: {{ $card }}; border-radius: 0 0 20px 20px; padding: 36px 32px 32px; box-shadow: 0 1px 2px rgba(27, 29, 62, .06), 0 12px 32px rgba(27, 29, 62, .06); }
        .bar { height: 6px; background: {{ $accent }}; border-radius: 20px 20px 0 0; }
        h1 { margin: 0 0 12px; color: {{ $ink }}; font-size: 24px; line-height: 1.3; }
        p { margin: 0 0 16px; }
        .muted { color: {{ $muted }}; font-size: 14px; }
        .button { display: inline-block; width: 100%; padding: 14px 20px; border: 0; border-radius: 12px; background: {{ $ink }}; color: #fff; font: inherit; font-weight: 700; cursor: pointer; text-align: center; text-decoration: none; }
        .button:focus-visible, .link:focus-visible, input:focus-visible { outline: 3px solid {{ $accent }}; outline-offset: 2px; }
        .link { background: none; border: 0; padding: 0; color: {{ $ink }}; font: inherit; font-weight: 600; text-align: left; text-decoration: underline; text-underline-offset: 3px; cursor: pointer; }
        .stack > * + * { margin-top: 14px; }
        .note { margin-top: 20px; padding: 14px 16px; border-radius: 12px; background: {{ $page }}; font-size: 14px; }
        .option { display: flex; gap: 14px; align-items: flex-start; padding: 16px 0; border-top: 1px solid rgba(27, 29, 62, .08); }
        .option:first-of-type { border-top: 0; }
        .option input { margin-top: 4px; width: 20px; height: 20px; accent-color: {{ $ink }}; flex: none; }
        .option strong { display: block; color: {{ $ink }}; }
        .option .muted { display: block; margin-top: 2px; line-height: 1.5; }
        .section-title { margin: 28px 0 4px; font-size: 13px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: {{ $muted }}; }
        .status { margin-bottom: 20px; padding: 12px 16px; border-radius: 12px; background: #ECFDF3; color: #05603A; font-size: 15px; }
        footer { margin-top: 24px; text-align: center; font-size: 13px; color: {{ $muted }}; }
        footer a { color: inherit; }
    </style>
</head>
<body>
<main class="wrap">
    @if (! empty($brand['logo_url']))
        <img class="logo" src="{{ $brand['logo_url'] }}" alt="{{ $brandName }}">
    @else
        <p class="brand-text">{{ $brandName }}</p>
    @endif
    <div class="bar" aria-hidden="true"></div>
    <div class="card">
        @yield('content')
    </div>
    <footer>
        @if (! empty($brand['support_email']))
            Questions? Write to <a href="mailto:{{ $brand['support_email'] }}">{{ $brand['support_email'] }}</a>.
        @endif
    </footer>
</main>
</body>
</html>
