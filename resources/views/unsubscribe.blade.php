<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Unsubscribe</title></head>
<body style="font-family:system-ui;background:#020617;color:#e2e8f0;display:grid;place-items:center;min-height:100vh;margin:0">
<main style="max-width:480px;padding:32px;border:1px solid #1e293b;border-radius:16px;background:#0f172a">
    @if(session('unsubscribed'))
        <h1>You are unsubscribed</h1><p>You will no longer receive {{ $message->channel }} messages from this workspace.</p>
    @else
        <h1>Stop {{ $message->channel }} messages?</h1>
        <p>This applies to {{ $message->to_address }}.</p>
        <form method="post" action="{{ request()->fullUrl() }}">@csrf<button style="background:#34d399;border:0;border-radius:8px;padding:12px 18px;font-weight:700">Confirm unsubscribe</button></form>
    @endif
</main>
</body></html>
