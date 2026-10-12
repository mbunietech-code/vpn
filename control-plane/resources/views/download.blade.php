<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Download Mbunie VPN</title>
<link rel="icon" href="{{ asset('images/favicon-64.png') }}?v=4">
<style>
  :root { color-scheme: light dark; --blue: #2563eb; --card: #fff; --line: #e3e8f2; --muted: #5b6478; }
  body { font: 15px/1.6 -apple-system, "Segoe UI", Roboto, system-ui, sans-serif; margin: 0; color: #141b2d; background: #f6f8fc; }
  @media (prefers-color-scheme: dark) { :root { --card: #121a2b; --line: #22304a; --muted: #9aa6bd; } body { color: #eef2fb; background: #0b111e; } }
  .wrap { max-width: 760px; margin: 0 auto; padding: 40px 16px 64px; }
  h1 { font-size: 26px; margin: 0 0 4px; }
  .muted { color: var(--muted); font-size: 14px; }
  .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-top: 24px; }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 20px; }
  .card h2 { font-size: 17px; margin: 0 0 6px; }
  .btn { display: inline-block; margin-top: 12px; background: var(--blue); color: #fff; text-decoration: none; padding: 10px 16px; border-radius: 10px; font-weight: 600; }
  ol { padding-left: 20px; margin: 8px 0 0; }
  .qr { background: #fff; padding: 8px; border-radius: 12px; width: 170px; height: 170px; }
  .brand { display: flex; align-items: center; gap: 14px; }
  .brand img { width: 56px; height: 56px; border-radius: 14px; box-shadow: 0 4px 14px rgba(0,0,0,.12); }
  .row { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <img src="{{ asset('images/mvpn-icon.png') }}?v=4" alt="Mbunie">
    <div>
      <h1>Mbunie VPN</h1>
      <p class="muted" style="margin:0">Version {{ $version }} · Fast, private access that works in China.</p>
    </div>
  </div>

  <div class="grid">
    <div class="card">
      <h2>Android</h2>
      <p class="muted">Android 7 or newer, 32-bit and 64-bit phones.</p>
      <a class="btn" href="{{ $android }}">Download APK</a>
    </div>
    <div class="card">
      <h2>Windows</h2>
      <p class="muted">Windows 10 / 11, 64-bit. Runs as Administrator.</p>
      <a class="btn" href="{{ $windows }}">Download for Windows</a>
    </div>
  </div>

  <div class="card" style="margin-top:16px">
    <h2>How to connect</h2>
    <ol>
      <li>Install the app. On Android allow “Install unknown apps”; on Windows choose “More info → Run anyway”.</li>
      <li>Open it and sign in with your email or phone number. We send you a one-time code.</li>
      <li>Tap <strong>Connect</strong>. Protocol <strong>Auto</strong> picks the best route for your network.</li>
    </ol>
    <p class="muted">No plan yet? Buy one at <a href="https://mbuniehub.com">mbuniehub.com</a> or inside the app.</p>
  </div>

  <div class="card row" style="margin-top:16px">
    <img class="qr" src="{{ route('download.qr') }}" alt="QR code for this download page">
    <div>
      <h2>Open on your phone</h2>
      <p class="muted">Scan to open this page on another device.</p>
    </div>
  </div>

  <p class="muted" style="margin-top:32px">Help: <a href="mailto:support@mbuniehub.com">support@mbuniehub.com</a> · <a href="{{ route('legal.terms') }}">Terms</a> · <a href="{{ route('legal.privacy') }}">Privacy</a></p>
</div>
</body>
</html>
