<!doctype html>
<html lang="sw">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $title }} — Mbunie VPN</title>
<style>
  :root { color-scheme: light dark; }
  body { font: 15px/1.65 -apple-system, "Segoe UI", Roboto, system-ui, sans-serif;
         margin: 0; color: #141b2d; background: #f6f8fc; }
  @media (prefers-color-scheme: dark) { body { color: #eef2fb; background: #0b111e; } }
  .wrap { max-width: 720px; margin: 0 auto; padding: 40px 22px 64px; }
  h1 { font-size: 24px; }
  h2 { font-size: 17px; margin-top: 28px; }
  .muted { opacity: .7; font-size: 13px; }
  a { color: #2563eb; }
</style>
</head>
<body>
<div class="wrap">
  <h1>{{ $title }}</h1>
  <p class="muted">Mbunie VPN · Mbunie Tech · imesasishwa {{ date('Y-m-d') }}</p>
  {{ $slot }}
  <p class="muted" style="margin-top:40px">
    Maswali: <a href="mailto:support@mbuniehub.com">support@mbuniehub.com</a>
  </p>
</div>
</body>
</html>
