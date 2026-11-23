<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<link rel="icon" href="{{ asset('images/favicon-64.png') }}?v=5">
<style>
  :root { color-scheme: light dark; --blue: #2563eb; --blue-ink: #fff; --card: #fff; --line: #e3e8f2; --muted: #6b7385; --soft: #f1f4fa; --ink: #141b2d; --bg: #f6f8fc; --ok: #15803d; --ok-bg: #e8f7ee; --warn: #b45309; --warn-bg: #fff6e6; --bad: #b91c1c; --bad-bg: #fdecec; --gold: #b7892f; }
  @media (prefers-color-scheme: dark) { :root { --card: #121a2b; --line: #22304a; --muted: #9aa6bd; --soft: #1b2538; --ink: #eef2fb; --bg: #0b111e; --ok: #4ade80; --ok-bg: #12291c; --warn: #fbbf24; --warn-bg: #2a2111; --bad: #f87171; --bad-bg: #2c1414; --gold: #e3c27a; } }
  * { box-sizing: border-box; }
  body { font: 15px/1.55 -apple-system, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", system-ui, sans-serif; margin: 0; color: var(--ink); background: var(--bg); }
  .wrap { max-width: 520px; margin: 0 auto; padding: 20px 16px 48px; }
  .top { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  .brand { display: flex; align-items: center; gap: 10px; font-weight: 700; }
  .brand img { width: 36px; height: 36px; border-radius: 10px; }
  .vip { font-size: 11px; letter-spacing: .08em; font-weight: 800; color: var(--gold); border: 1px solid var(--gold); border-radius: 6px; padding: 1px 6px; }
  .langs { display: flex; gap: 4px; font-size: 13px; }
  .langs a { color: var(--muted); text-decoration: none; padding: 3px 7px; border-radius: 6px; }
  .langs a.on { background: var(--soft); color: var(--ink); font-weight: 600; }
  h1 { font-size: 24px; line-height: 1.25; margin: 22px 0 4px; }
  h2 { font-size: 16px; margin: 0 0 10px; }
  .muted { color: var(--muted); font-size: 14px; }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 18px; margin-top: 16px; }
  .price { display: flex; align-items: baseline; gap: 8px; margin-top: 4px; }
  .price b { font-size: 34px; letter-spacing: -.02em; }
  label.f { display: block; font-weight: 600; font-size: 14px; margin: 16px 0 6px; }
  .help { color: var(--muted); font-size: 12.5px; margin-top: 4px; }
  input[type=text], input[type=email], input[type=file], textarea { width: 100%; font: inherit; color: inherit; background: var(--bg); border: 1px solid var(--line); border-radius: 10px; padding: 11px 12px; }
  .stepper { display: flex; align-items: center; gap: 0; border: 1px solid var(--line); border-radius: 12px; width: max-content; overflow: hidden; }
  .stepper button { width: 46px; height: 44px; border: 0; background: var(--soft); color: var(--ink); font-size: 22px; cursor: pointer; }
  .stepper input { width: 56px; height: 44px; border: 0; text-align: center; font: 600 18px/1 inherit; background: transparent; color: inherit; }
  .opt { display: flex; align-items: center; gap: 12px; border: 1px solid var(--line); border-radius: 12px; padding: 12px 14px; margin-top: 8px; cursor: pointer; }
  .opt:has(input:checked) { border-color: var(--blue); box-shadow: 0 0 0 1px var(--blue) inset; }
  .opt input { accent-color: var(--blue); width: 18px; height: 18px; margin: 0; }
  .opt .l { flex: 1; font-weight: 500; }
  .opt .a { color: var(--muted); font-variant-numeric: tabular-nums; }
  .btn { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; border: 0; border-radius: 12px; padding: 14px 16px; margin-top: 12px; font: 600 16px/1.2 inherit; text-decoration: none; cursor: pointer; background: var(--blue); color: var(--blue-ink); }
  .btn.ghost { background: var(--soft); color: var(--ink); }
  .btn:disabled { opacity: .6; cursor: default; }
  .err { background: var(--bad-bg); color: var(--bad); border-radius: 10px; padding: 10px 12px; margin-top: 12px; font-size: 14px; }
  .note { border-radius: 12px; padding: 12px 14px; margin-top: 16px; font-size: 14px; }
  .note.ok { background: var(--ok-bg); color: var(--ok); }
  .note.warn { background: var(--warn-bg); color: var(--warn); }
  .note.bad { background: var(--bad-bg); color: var(--bad); }
  .note a { color: inherit; font-weight: 600; }
  ol.steps { margin: 0; padding: 0; list-style: none; counter-reset: s; }
  ol.steps li { counter-increment: s; display: flex; gap: 10px; padding: 6px 0; }
  ol.steps li::before { content: counter(s); flex: 0 0 24px; height: 24px; border-radius: 50%; background: var(--soft); color: var(--blue); font-weight: 700; font-size: 13px; display: grid; place-items: center; }
  .code { font: 700 34px/1.1 ui-monospace, "SF Mono", Menlo, Consolas, monospace; letter-spacing: .08em; text-align: center; padding: 14px 8px; background: var(--soft); border-radius: 12px; margin-top: 6px; user-select: all; }
  .row { display: flex; gap: 8px; }
  .row .btn { margin-top: 8px; }
  .qr { width: 180px; height: 180px; display: block; margin: 8px auto 0; background: #fff; padding: 6px; border-radius: 10px; }
  .spin { width: 36px; height: 36px; border: 4px solid var(--soft); border-top-color: var(--blue); border-radius: 50%; animation: s 1s linear infinite; margin: 6px auto 10px; }
  @keyframes s { to { transform: rotate(360deg); } }
  .center { text-align: center; }
  .foot { text-align: center; color: var(--muted); font-size: 12.5px; margin-top: 28px; }
  details summary { cursor: pointer; font-weight: 600; }
</style>
