<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title') · Pesanin</title>
<style>
  :root { --bg:#f7f7f8; --card:#fff; --text:#1c1c21; --muted:#5f6170; --line:#e4e4ea; --accent:#e8590c; }
  @media (prefers-color-scheme: dark) { :root { --bg:#111114; --card:#1a1a1f; --text:#ececf1; --muted:#a0a2b0; --line:#2b2b33; --accent:#ff8a3d; } }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--text); font:16px/1.65 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
  header { max-width:760px; margin:0 auto; padding:28px 16px 0; display:flex; align-items:center; gap:10px; font-weight:700; font-size:18px; }
  header .dot { width:28px; height:28px; border-radius:8px; background:var(--accent); color:#fff; display:grid; place-items:center; font-size:15px; }
  nav { max-width:760px; margin:8px auto 0; padding:0 16px; display:flex; gap:16px; font-size:14px; }
  nav a { color:var(--muted); text-decoration:none; } nav a:hover { color:var(--accent); }
  main { max-width:760px; margin:20px auto 48px; padding:28px 24px; background:var(--card); border:1px solid var(--line); border-radius:14px; }
  h1 { font-size:26px; line-height:1.25; margin:0 0 4px; } h2 { font-size:18px; margin:28px 0 6px; }
  .muted { color:var(--muted); font-size:14px; } a { color:var(--accent); }
  li { margin:4px 0; } .box { border:1px solid var(--line); border-radius:10px; padding:14px 16px; margin:16px 0; }
  footer { text-align:center; color:var(--muted); font-size:13px; padding-bottom:32px; }
  @media (max-width:560px) { main { padding:20px 16px; border-radius:0; border-left:0; border-right:0; } }
</style>
</head>
<body>
<header><span class="dot">P</span> Pesanin</header>
<nav><a href="/privacy">Kebijakan Privasi</a><a href="/terms">Ketentuan Layanan</a><a href="/data-deletion">Penghapusan Data</a></nav>
<main>
@yield('content')
</main>
<footer>&copy; {{ date('Y') }} Pesanin · Sistem manajemen pesanan untuk penjual online</footer>
</body>
</html>
