<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Auto reply')</title>
    <style>
        :root { --bg:#f4f5f7; --panel:#fff; --line:#e1e4e8; --text:#1f2933; --muted:#6b7785; --accent:#128c7e; --bad:#b42318; --warn:#b45309; }
        @media (prefers-color-scheme: dark) { :root { --bg:#111418; --panel:#1b2026; --line:#2c333b; --text:#e6e9ed; --muted:#97a1ad; --accent:#25b3a1; --bad:#ff8a80; --warn:#f0b35b; } }
        * { box-sizing: border-box; }
        body { margin:0; font:15px/1.5 system-ui,Segoe UI,Roboto,sans-serif; background:var(--bg); color:var(--text); }
        main { max-width:720px; margin:0 auto; padding:20px 14px 40px; }
        h1 { font-size:20px; margin:0 0 14px; } h2 { font-size:15px; margin:0 0 8px; }
        .card { background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:16px; margin-bottom:16px; }
        .state { display:inline-block; padding:3px 12px; border-radius:14px; font-weight:700; color:#fff; background:var(--muted); }
        .state.on { background:var(--accent); } .state.off { background:var(--bad); }
        textarea { width:100%; min-height:130px; padding:9px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:var(--text); font:inherit; resize:vertical; }
        label.switch { display:flex; gap:10px; align-items:center; font-weight:600; margin:0 0 12px; cursor:pointer; }
        label.switch input { width:20px; height:20px; }
        button { padding:8px 18px; border:0; border-radius:8px; background:var(--accent); color:#fff; font:inherit; cursor:pointer; }
        button.secondary { background:var(--line); color:var(--text); }
        .flash { padding:10px 14px; border-radius:8px; background:#dcfce7; color:#14532d; margin-bottom:16px; }
        .errors { padding:10px 14px; border-radius:8px; background:#fee2e2; color:#7f1d1d; margin-bottom:16px; }
        .warn { padding:10px 14px; border-radius:8px; background:#fef3c7; color:#78350f; margin-bottom:16px; }
        .muted { color:var(--muted); font-size:13px; }
        .scroll { overflow-x:auto; } table { width:100%; border-collapse:collapse; font-size:13px; }
        td, th { text-align:left; padding:5px 8px; border-bottom:1px solid var(--line); white-space:nowrap; }
        td.wrap { white-space:normal; word-break:break-word; }
    </style>
</head>
<body>
<main>
@yield('body')
</main>
</body>
</html>
