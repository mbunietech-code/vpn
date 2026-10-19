<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Download Mbunie VPN</title>
<link rel="icon" href="{{ asset('images/favicon-64.png') }}?v=4">
<style>
  :root { color-scheme: light dark; --blue: #2563eb; --card: #fff; --line: #e3e8f2; --muted: #6b7385; --disc: #f1f3f7; --ink: #141b2d; }
  @media (prefers-color-scheme: dark) { :root { --card: #121a2b; --line: #22304a; --muted: #9aa6bd; --disc: #1b2538; --ink: #eef2fb; } body { background: #0b111e !important; } }
  body { font: 15px/1.6 -apple-system, "Segoe UI", Roboto, system-ui, sans-serif; margin: 0; color: var(--ink); background: #f6f8fc; }
  .wrap { max-width: 980px; margin: 0 auto; padding: 40px 16px 64px; }
  h1 { font-size: 26px; margin: 0 0 4px; }
  h2 { font-size: 17px; margin: 0 0 6px; }
  .muted { color: var(--muted); font-size: 14px; }
  .brand { display: flex; align-items: center; gap: 14px; }
  .brand img { width: 56px; height: 56px; border-radius: 14px; box-shadow: 0 4px 14px rgba(0,0,0,.12); }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 20px; }
  ol { padding-left: 20px; margin: 8px 0 0; }

  /* Platform tiles */
  .platforms { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 28px; padding: 32px 0; }
  @media (max-width: 720px) { .platforms { grid-template-columns: repeat(2, 1fr); row-gap: 28px; } }
  .tile { position: relative; display: flex; flex-direction: column; align-items: center; text-align: center; color: inherit; text-decoration: none; padding: 8px 4px 12px; }
  .disc { position: relative; width: 132px; height: 132px; border-radius: 50%; background: var(--disc); display: grid; place-items: center; transition: transform .15s; }
  a.tile:hover .disc { transform: translateY(-2px); }
  .disc svg.os { width: 74px; height: 74px; }
  .badge { position: absolute; right: 2px; bottom: 6px; width: 40px; height: 40px; border-radius: 50%; border: 3px solid var(--card); background: var(--blue); color: #fff; display: grid; place-items: center; padding: 0; cursor: pointer; }
  .badge svg { width: 20px; height: 20px; }
  .name { font-size: 20px; font-weight: 500; margin-top: 16px; }
  .meta { color: var(--muted); font-size: 13.5px; line-height: 1.55; margin-top: 4px; }
  .soon .disc { opacity: .55; }
  .soon .badge { background: #9aa3b5; cursor: default; }
  .pill { display: inline-block; margin-top: 4px; font-size: 12px; padding: 1px 9px; border-radius: 999px; background: var(--disc); color: var(--muted); }
  @media (max-width: 380px) { .disc { width: 112px; height: 112px; } .disc svg.os { width: 62px; height: 62px; } .name { font-size: 18px; } }

  /* Android: the QR badge sits on the disc, outside the download link */
  .android { position: relative; }
  .android .badge { position: absolute; top: 94px; left: calc(50% + 24px); }
  @media (max-width: 380px) { .android .badge { top: 74px; left: calc(50% + 14px); } }
  .qrpop { display: none; position: absolute; top: 150px; left: 50%; transform: translateX(-50%); z-index: 5; background: #fff; color: #141b2d; border-radius: 14px; box-shadow: 0 18px 40px rgba(10,29,46,.22); padding: 12px; width: 180px; }
  .qrpop.open { display: block; }
  .qrpop img { width: 156px; height: 156px; display: block; }
  .qrpop p { margin: 6px 0 0; font-size: 12.5px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <img src="{{ asset('images/mvpn-icon.png') }}?v=4" alt="Mbunie">
    <div>
      <h1>Mbunie VPN</h1>
      <p class="muted" style="margin:0">Fast, private access that works in China.</p>
    </div>
  </div>

  <div class="platforms">
    {{-- macOS --}}
    <a class="tile" href="{{ $macos }}" aria-label="Download for macOS">
      <div class="disc">
        <svg class="os" viewBox="0 0 64 64" fill="none" aria-hidden="true">
          <rect x="11" y="13" width="42" height="29" rx="3" stroke="#5f6675" stroke-width="3.5"/>
          <path d="M5 47h54l-3 4H8z" fill="#5f6675"/>
          <path d="M33.6 22.4c.9-1.1 1.4-2.3 1.3-3.6-1.2.1-2.5.8-3.3 1.8-.8.9-1.4 2.2-1.2 3.5 1.3.1 2.5-.6 3.2-1.7zm2.6 6.7c0-2.3 1.9-3.4 2-3.5-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.1-2.8.9-3.5.9-.7 0-1.8-.8-3-.8-1.6 0-3 .9-3.8 2.3-1.6 2.8-.4 7 1.2 9.3.8 1.1 1.7 2.4 2.9 2.3 1.2 0 1.6-.7 3-.7s1.8.7 3 .7c1.3 0 2.1-1.1 2.8-2.3.9-1.3 1.3-2.5 1.3-2.6 0 0-2.5-1-2.5-3.8z" fill="#5f6675"/>
        </svg>
        <span class="badge" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>
        </span>
      </div>
      <div class="name">macOS</div>
      <div class="meta">Version {{ $version }}@if ($macosDate)<br>{{ $macosDate }}@endif</div>
    </a>

    {{-- Windows --}}
    <a class="tile" href="{{ $windows }}" aria-label="Download for Windows">
      <div class="disc">
        <svg class="os" viewBox="0 0 64 64" aria-hidden="true">
          <path fill="#1ba1e2" d="M6 12.2 27 9.3v20.3H6zM29.4 9 58 5v24.6H29.4zM6 32h21v20.3L6 49.4zM29.4 32H58v26.6l-28.6-4z"/>
        </svg>
        <span class="badge" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"/></svg>
        </span>
      </div>
      <div class="name">Windows</div>
      <div class="meta">Version {{ $windowsVersion }}@if ($windowsDate)<br>{{ $windowsDate }}@endif</div>
    </a>

    {{-- iOS --}}
    <div class="tile soon" aria-label="iOS, coming soon">
      <div class="disc">
        <svg class="os" viewBox="0 0 64 64" fill="none" stroke="#5f6675" stroke-width="3.2" stroke-linecap="round" aria-hidden="true">
          <circle cx="32" cy="32" r="25"/>
          <path d="M26.5 17.5 39 41M37.5 17.5 25 41M20 41h24M43 34l3.5 7"/>
        </svg>
        <span class="badge" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><path d="M14 14h2.5v2.5H14zM17.5 17.5H20V20h-2.5z"/></svg>
        </span>
      </div>
      <div class="name">iOS</div>
      <div class="meta">iPhone / iPad<br><span class="pill">Coming soon</span></div>
    </div>

    {{-- Android: the tile downloads; its badge shows a QR to scan from a computer --}}
    <div class="android">
      <a class="tile" href="{{ $android }}" aria-label="Download APK">
        <div class="disc">
          <svg class="os" viewBox="0 0 64 64" aria-hidden="true">
            <g fill="#3ddc84">
              <path d="M17 26a15 13 0 0 1 30 0z"/>
              <rect x="17" y="28" width="30" height="21" rx="3"/>
              <rect x="10" y="28" width="5" height="16" rx="2.5"/>
              <rect x="49" y="28" width="5" height="16" rx="2.5"/>
              <rect x="23" y="46" width="5" height="11" rx="2.5"/>
              <rect x="36" y="46" width="5" height="11" rx="2.5"/>
            </g>
            <path d="M22 10.5l3.4 5.4M42 10.5l-3.4 5.4" stroke="#3ddc84" stroke-width="2.2" stroke-linecap="round"/>
            <circle cx="26" cy="20" r="1.7" fill="#fff"/><circle cx="38" cy="20" r="1.7" fill="#fff"/>
          </svg>
        </div>
        <div class="name">Android</div>
        <div class="meta">Version {{ $androidVersion }}@if ($androidDate)<br>{{ $androidDate }}@endif</div>
      </a>
      <button class="badge" type="button" aria-label="Show QR code for the Android app" aria-expanded="false" aria-controls="android-qr" onclick="toggleQr(this)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><path d="M14 14h2.5v2.5H14zM17.5 17.5H20V20h-2.5z"/></svg>
      </button>
      <div class="qrpop" id="android-qr" role="dialog" aria-label="Android download QR code">
        <img src="{{ route('download.qr.android') }}" alt="QR code linking to the Android app" loading="lazy">
        <p>Scan with your phone's camera to download the Android app.</p>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>How to connect</h2>
    <ol>
      <li>Install the app. On Android allow “Install unknown apps”; on Windows choose “More info → Run anyway”;
        on Mac drag it into Applications, open it once, then System Settings → Privacy &amp; Security → <strong>Open Anyway</strong>.</li>
      <li>Open it and sign in with your email or phone number. We send you a one-time code.</li>
      <li>Tap <strong>Connect</strong>. Protocol <strong>Auto</strong> picks the best route for your network.</li>
    </ol>
    <p class="muted">No plan yet? Buy one at <a href="https://mbuniehub.com">mbuniehub.com</a> or inside the app.</p>
  </div>

  <div class="card" style="margin-top:16px;display:flex;gap:20px;align-items:center;flex-wrap:wrap">
    <img src="{{ route('download.qr') }}" alt="QR code for this download page" style="background:#fff;padding:8px;border-radius:12px;width:150px;height:150px">
    <div>
      <h2>Open on another device</h2>
      <p class="muted">Scan to open this page on your phone or another computer.</p>
    </div>
  </div>

  <p class="muted" style="margin-top:32px">Help: <a href="mailto:support@mbuniehub.com">support@mbuniehub.com</a> · <a href="{{ route('legal.terms') }}">Terms</a> · <a href="{{ route('legal.privacy') }}">Privacy</a></p>
</div>
<script>
  function toggleQr(btn) {
    const open = document.getElementById('android-qr').classList.toggle('open');
    btn.setAttribute('aria-expanded', open);
  }
  document.addEventListener('click', e => {
    const pop = document.getElementById('android-qr');
    if (pop.classList.contains('open') && !e.target.closest('#android-qr, [aria-controls="android-qr"]')) {
      pop.classList.remove('open');
      document.querySelector('[aria-controls="android-qr"]').setAttribute('aria-expanded', 'false');
    }
  });
</script>
</body>
</html>
