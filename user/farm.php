<?php
declare(strict_types=1);
/**
 * user/farm.php — Sawah Panen Cuan (3D farming world, pengganti Pemancingan).
 *
 * Halaman ini hanya merender kerangka HUD + state awal. Semua aksi lewat
 * POST /api/farm_action.php (lihat lib/farm.php), mesin 3D di
 * assets/js/farm_engine.js.
 */
require_once dirname(__DIR__) . '/auth/guard.php';
require_once dirname(__DIR__) . '/lib/farm.php';

farm_ensure_schema($pdo);
if (!farm_config($pdo)['enabled']) {
    $_SESSION['flash_home_err'] = 'Fitur Sawah Panen Cuan sedang dinonaktifkan sementara oleh Administrator.';
    redirect('/home');
}

$FARM_BOOT = [
    'api'   => '/api/farm_action.php',
    'csrf'  => csrf_token(),
    'state' => farm_state($pdo, (int)$user['id']),
];
$_site_name = setting($pdo, 'site_name', 'PanenCuan');
$_favicon   = setting($pdo, 'favicon_path', '/assets/favicon.png');
$_fav_url   = preg_match('~^https?://~', (string)$_favicon) ? $_favicon : '/' . ltrim((string)$_favicon, '/');
$asset_v    = (string)(@filemtime(dirname(__DIR__) . '/assets/js/farm_engine.js') ?: time());
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#10b981">
<meta name="robots" content="noindex">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>Sawah Panen Cuan — <?= htmlspecialchars($_site_name) ?></title>
<link rel="icon" type="image/png" href="<?= htmlspecialchars($_fav_url) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1"></script>
<style>
/* ══════════════════════════════════════════════════════════
   SAWAH PANEN CUAN — HUD SOFT MODERN (kaca lembut, membulat)
   ══════════════════════════════════════════════════════════ */
:root {
  --ink: #1f2a24;
  --muted: #64748b;
  --yellow: #fcd34d;
  --emerald: #10b981;
  --emerald-d: #059669;
  --sky: #38bdf8;
  --pink: #f43f5e;
  --card: rgba(255, 255, 255, .86);
  --card-solid: #ffffff;
  --cream: #f8fafc;
  --line: rgba(15, 23, 42, .08);
  --bd: 1px solid var(--line);
  --sh: 0 6px 18px rgba(15, 23, 42, .10);
  --sh-lg: 0 14px 40px rgba(15, 23, 42, .16);
  --blur: saturate(160%) blur(14px);
  --g-emerald: linear-gradient(135deg, #34d399, #059669);
  --g-sky: linear-gradient(135deg, #7dd3fc, #0284c7);
  --g-yellow: linear-gradient(135deg, #fde68a, #f59e0b);
  --g-pink: linear-gradient(135deg, #fb7185, #e11d48);
  --safe-t: env(safe-area-inset-top, 0px);
  --safe-b: env(safe-area-inset-bottom, 0px);
}
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
html, body { height: 100%; overflow: hidden; overscroll-behavior: none; }
body {
  font-family: 'Nunito', sans-serif; font-weight: 700; color: var(--ink);
  background: linear-gradient(180deg, #bae6fd 0%, #e0f2fe 38%, #f0fdf4 100%);
  -webkit-user-select: none; user-select: none;
}
button { font: inherit; color: inherit; cursor: pointer; border: 0; background: none; }
button:disabled { cursor: not-allowed; }

#farm-stage { position: fixed; inset: 0; }
#farm-stage canvas { display: block; }
#farm-labels { position: fixed; inset: 0; pointer-events: none; overflow: hidden; z-index: 5; }

/* ── Tombol dasar ── */
.nb-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  border: 0; border-radius: 999px; background: var(--card-solid); box-shadow: 0 2px 8px rgba(15, 23, 42, .12);
  font-weight: 800; padding: 9px 16px; transition: transform .15s, box-shadow .15s, filter .15s;
}
.nb-btn:hover:not(:disabled) { filter: brightness(1.05); box-shadow: 0 6px 16px rgba(15, 23, 42, .16); }
.nb-btn:active:not(:disabled) { transform: scale(.96); }
.nb-btn:disabled { opacity: .45; }
.nb-btn.yellow { background: var(--g-yellow); color: #78350f; box-shadow: 0 6px 16px rgba(245, 158, 11, .35); }
.nb-btn.green { background: var(--g-emerald); color: #fff; box-shadow: 0 6px 16px rgba(5, 150, 105, .35); }
.nb-btn.sky { background: var(--g-sky); color: #fff; box-shadow: 0 6px 16px rgba(2, 132, 199, .35); }
.nb-btn.pink { background: var(--g-pink); color: #fff; box-shadow: 0 6px 16px rgba(225, 29, 72, .35); }
.nb-btn.block { width: 100%; padding: 14px; font-size: 16px; }

/* ── TOP BAR ── */
.topbar {
  position: fixed; z-index: 20; top: calc(var(--safe-t) + 10px); left: 10px; right: 10px;
  display: flex; align-items: flex-start; gap: 8px; pointer-events: none;
}
.topbar > * { pointer-events: auto; }
.icon-btn {
  width: 42px; height: 42px; flex: 0 0 42px; display: grid; place-items: center; font-size: 20px; color: var(--ink);
  background: var(--card); border: var(--bd); border-radius: 14px; box-shadow: var(--sh);
  backdrop-filter: var(--blur); -webkit-backdrop-filter: var(--blur); transition: transform .15s;
}
.icon-btn:active { transform: scale(.94); }
.top-left { display: flex; flex-direction: column; gap: 6px; }
.lvl {
  flex: 1; min-width: 0; max-width: 220px; margin: 0 auto; background: var(--card); border: var(--bd); border-radius: 16px;
  box-shadow: var(--sh); padding: 7px 11px 8px; display: flex; flex-direction: column; gap: 5px;
  backdrop-filter: var(--blur); -webkit-backdrop-filter: var(--blur);
}
.lvl-row { display: flex; align-items: center; justify-content: space-between; gap: 6px; font-size: 12px; }
.lvl-badge { display: inline-flex; align-items: center; gap: 4px; background: var(--g-emerald); color: #fff; border-radius: 999px; padding: 2px 9px; font-weight: 800; white-space: nowrap; }
.lvl-row small { font-size: 10.5px; color: var(--muted); font-weight: 700; white-space: nowrap; }
.xp { height: 7px; border-radius: 999px; background: rgba(15, 23, 42, .08); overflow: hidden; }
.xp-fill { height: 100%; width: 0; border-radius: 999px; background: linear-gradient(90deg, #fde68a, #f59e0b); transition: width .5s cubic-bezier(.34,1.56,.64,1); }
.wallets { display: flex; flex-direction: column; gap: 5px; flex: 0 0 auto; }
.wallet {
  border-radius: 14px; box-shadow: var(--sh); padding: 5px 11px 6px; min-width: 112px;
  display: flex; flex-direction: column; line-height: 1.15;
}
.wallet small { font-size: 9.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .4px; display: flex; align-items: center; gap: 3px; opacity: .85; }
.wallet b { font-size: 14px; font-weight: 900; font-variant-numeric: tabular-nums; white-space: nowrap; }
.wallet.dep { background: var(--g-yellow); color: #78350f; }
.wallet.wd { background: var(--g-emerald); color: #fff; }
.wallet.bump { animation: bump .45s cubic-bezier(.34,1.56,.64,1); }
@keyframes bump { 40% { transform: scale(1.1); } }

/* ── DOCK BAWAH ── */
.dock {
  position: fixed; z-index: 20; left: 50%; transform: translateX(-50%); bottom: calc(var(--safe-b) + 12px);
  width: min(460px, calc(100% - 20px)); display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px;
  background: var(--card); border: var(--bd); border-radius: 24px; box-shadow: var(--sh-lg); padding: 8px;
  backdrop-filter: var(--blur); -webkit-backdrop-filter: var(--blur);
}
.dock-btn { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 2px 0; font-size: 11px; font-weight: 800; color: #334155; position: relative; }
.dock-ic {
  width: 46px; height: 42px; border-radius: 14px; display: grid; place-items: center; font-size: 22px; color: #fff;
  transition: transform .15s;
}
.dock-btn:active .dock-ic { transform: scale(.92); }
.dock-btn:nth-child(1) .dock-ic { background: var(--g-emerald); box-shadow: 0 6px 14px rgba(5, 150, 105, .35); }
.dock-btn:nth-child(2) .dock-ic { background: var(--g-sky); box-shadow: 0 6px 14px rgba(2, 132, 199, .35); }
.dock-btn:nth-child(3) .dock-ic { background: var(--g-yellow); box-shadow: 0 6px 14px rgba(245, 158, 11, .35); color: #78350f; }
.dock-btn:nth-child(4) .dock-ic { background: var(--g-pink); box-shadow: 0 6px 14px rgba(225, 29, 72, .35); }
.dock-chip {
  position: absolute; top: -8px; right: 2px; background: #fff; border-radius: 999px; color: var(--ink);
  font-size: 10px; padding: 1px 6px; font-variant-numeric: tabular-nums; box-shadow: 0 2px 8px rgba(15, 23, 42, .18);
}
.dock-chip.full { background: var(--pink); color: #fff; }
#dock-barn { will-change: transform; }

/* ── LABEL 3D (timer radial dsb.) ── */
.plot-tag { position: absolute; left: 0; top: 0; display: flex; flex-direction: column; align-items: center; gap: 4px; will-change: transform; }
.pt-ring {
  --c: var(--emerald);
  width: 36px; height: 36px; border-radius: 50%; position: relative;
  background: conic-gradient(var(--c) calc(var(--p, 0) * 1turn), rgba(255, 255, 255, .7) 0); display: grid; place-items: center;
  box-shadow: 0 4px 12px rgba(15, 23, 42, .22);
}
.pt-ring::after { content: ''; position: absolute; inset: 4px; border-radius: 50%; background: #fff; }
.pt-ring i { position: relative; z-index: 1; font-size: 15px; color: var(--emerald-d); }
.pt-text {
  background: rgba(255, 255, 255, .92); color: var(--ink); border-radius: 999px; padding: 1px 8px; font-size: 12px; font-weight: 800;
  box-shadow: 0 3px 10px rgba(15, 23, 42, .18); font-variant-numeric: tabular-nums; white-space: nowrap;
}
.plot-tag.is-pest .pt-ring { --c: var(--pink); animation: wob .5s ease-in-out infinite; }
.plot-tag.is-pest .pt-ring i { color: var(--pink); }
.plot-tag.is-pest .pt-text { background: var(--g-pink); color: #fff; }
.plot-tag.is-locked .pt-ring { display: none; }
.plot-tag.is-locked .pt-text { background: var(--g-yellow); color: #78350f; }
.plot-tag.is-spin .pt-ring { animation: spin .25s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@keyframes wob { 50% { transform: rotate(-12deg) scale(1.08); } }
.float-text {
  position: absolute; left: 0; top: 0; white-space: nowrap; font-size: 22px; font-weight: 900; color: #fde047;
  text-shadow: 0 2px 0 #b45309, 0 6px 16px rgba(0, 0, 0, .35);
}
.fly-icon {
  position: absolute; left: 0; top: 0; width: 34px; height: 34px; border-radius: 12px; background: var(--g-yellow); color: #78350f;
  display: grid; place-items: center; box-shadow: 0 6px 16px rgba(245, 158, 11, .45); font-size: 18px; z-index: 30;
}
.fly-icon.coin { border-radius: 50%; }

/* ── BACKDROP, SHEET & MODAL ── */
.backdrop {
  position: fixed; inset: 0; z-index: 40; background: rgba(15, 23, 42, .45); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
  opacity: 0; pointer-events: none; transition: opacity .2s;
}
.backdrop.show { opacity: 1; pointer-events: auto; }
.sheet {
  position: fixed; z-index: 50; left: 50%; bottom: 0; width: min(520px, 100%); max-height: 80dvh;
  transform: translate(-50%, 105%); transition: transform .32s cubic-bezier(.2, 1.2, .4, 1);
  background: var(--cream); border-radius: 26px 26px 0 0; box-shadow: 0 -10px 40px rgba(15, 23, 42, .2);
  display: flex; flex-direction: column; padding-bottom: var(--safe-b); overflow: hidden;
}
.sheet::before { content: ''; position: absolute; top: 7px; left: 50%; width: 40px; height: 4px; margin-left: -20px; border-radius: 999px; background: rgba(15, 23, 42, .15); z-index: 1; }
.sheet.open { transform: translate(-50%, 0); }
.sheet-head { display: flex; align-items: center; gap: 12px; padding: 18px 16px 12px; background: var(--card-solid); border-bottom: 1px solid var(--line); }
.sheet-head .hic { width: 42px; height: 42px; border-radius: 14px; display: grid; place-items: center; font-size: 21px; box-shadow: 0 6px 14px rgba(15, 23, 42, .15); flex: 0 0 auto; }
.sheet-head h3 { font-size: 18px; font-weight: 900; line-height: 1.1; }
.sheet-head p { font-size: 12px; color: var(--muted); font-weight: 700; }
.sheet-head .close { margin-left: auto; box-shadow: none; background: rgba(15, 23, 42, .05); backdrop-filter: none; -webkit-backdrop-filter: none; width: 36px; height: 36px; flex-basis: 36px; font-size: 17px; }
.sheet-body { overflow-y: auto; padding: 14px 16px 18px; display: flex; flex-direction: column; gap: 10px; -webkit-overflow-scrolling: touch; }
.sec-title { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; color: var(--muted); display: flex; align-items: center; gap: 6px; margin-top: 4px; }

.modal {
  position: fixed; z-index: 50; left: 50%; top: 50%; width: min(360px, calc(100% - 32px));
  transform: translate(-50%, -46%) scale(.94); opacity: 0; pointer-events: none; transition: transform .25s cubic-bezier(.34,1.56,.64,1), opacity .2s;
  background: var(--cream); border-radius: 24px; box-shadow: var(--sh-lg); overflow: hidden;
}
.modal.open { transform: translate(-50%, -50%) scale(1); opacity: 1; pointer-events: auto; }
.modal .sheet-head { padding-top: 14px; }
.modal .sheet-body { padding: 16px; }

/* Kartu bibit / barang */
.card {
  background: var(--card-solid); border-radius: 18px; box-shadow: 0 2px 10px rgba(15, 23, 42, .07); padding: 10px;
  display: flex; align-items: center; gap: 12px; border: 1px solid var(--line);
}
.card.locked { opacity: .55; filter: grayscale(.4); }
.card .ic {
  width: 48px; height: 48px; flex: 0 0 48px; border-radius: 14px; display: grid; place-items: center; color: #fff;
  font-size: 26px; background: var(--c, var(--yellow)); box-shadow: inset 0 -4px 0 rgba(0, 0, 0, .12), 0 4px 10px rgba(15, 23, 42, .12);
}
.card .info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.card .info b { font-size: 15px; font-weight: 900; line-height: 1.1; }
.chips { display: flex; flex-wrap: wrap; gap: 4px; }
.chip { font-size: 10.5px; font-weight: 800; border-radius: 999px; padding: 1px 7px; background: #f1f5f9; color: #334155; display: inline-flex; align-items: center; gap: 3px; white-space: nowrap; }
.chip.y { background: #fef3c7; color: #92400e; } .chip.g { background: #d1fae5; color: #065f46; } .chip.s { background: #e0f2fe; color: #075985; } .chip.p { background: #ffe4e6; color: #9f1239; }
.card .act { display: flex; flex-direction: column; gap: 5px; flex: 0 0 auto; }
.card .act .nb-btn { padding: 8px 13px; font-size: 13px; }
.empty-note { text-align: center; padding: 20px 10px; color: var(--muted); font-size: 14px; border-radius: 18px; background: #fff; border: 1px dashed rgba(15, 23, 42, .15); }

/* Lumbung */
.cap { background: var(--card-solid); border-radius: 18px; box-shadow: 0 2px 10px rgba(15, 23, 42, .07); padding: 12px 14px; border: 1px solid var(--line); }
.cap-row { display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 7px; }
.cap-bar { height: 10px; border-radius: 999px; background: rgba(15, 23, 42, .08); overflow: hidden; }
.cap-bar div { height: 100%; border-radius: 999px; background: var(--g-emerald); }
.cap-bar.full div { background: var(--g-pink); }
.grid3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.tile { background: var(--card-solid); border-radius: 16px; box-shadow: 0 2px 10px rgba(15, 23, 42, .07); border: 1px solid var(--line); padding: 10px 4px; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 3px; }
.tile i { font-size: 24px; }
.tile b { font-size: 18px; font-weight: 900; line-height: 1; }
.tile small { font-size: 10.5px; color: var(--muted); font-weight: 700; line-height: 1.15; }

/* Pasar */
.row { display: flex; align-items: center; gap: 10px; background: var(--card-solid); border-radius: 16px; padding: 10px 12px; box-shadow: 0 2px 10px rgba(15, 23, 42, .07); border: 1px solid var(--line); font-size: 14px; }
.row i { font-size: 22px; }
.row .grow { flex: 1; min-width: 0; }
.row small { display: block; font-size: 11px; color: var(--muted); }
.total { display: flex; justify-content: space-between; align-items: center; background: var(--g-emerald); color: #fff; border-radius: 18px; box-shadow: 0 8px 20px rgba(5, 150, 105, .3); padding: 14px 16px; font-size: 15px; }
.total b { font-size: 22px; font-weight: 900; }

/* Kartu aksi petak */
.plot-hero { display: flex; align-items: center; gap: 12px; }
.plot-hero .ic { width: 56px; height: 56px; border-radius: 18px; display: grid; place-items: center; font-size: 30px; color: #fff; box-shadow: inset 0 -4px 0 rgba(0, 0, 0, .12), 0 6px 14px rgba(15, 23, 42, .15); background: var(--c, var(--yellow)); }
.prog { height: 20px; border-radius: 999px; background: rgba(15, 23, 42, .08); overflow: hidden; position: relative; }
.prog div { height: 100%; border-radius: 999px; background: var(--g-emerald); }
.prog span { position: absolute; inset: 0; display: grid; place-items: center; font-size: 12px; font-weight: 800; font-variant-numeric: tabular-nums; }
.note { font-size: 12.5px; color: var(--muted); font-weight: 700; line-height: 1.4; }
.big-cost { text-align: center; padding: 14px; background: var(--g-yellow); color: #78350f; border-radius: 18px; box-shadow: 0 8px 20px rgba(245, 158, 11, .3); }
.big-cost small { display: block; font-size: 12px; } .big-cost b { font-size: 26px; font-weight: 900; }

/* Toast */
#toasts { position: fixed; z-index: 80; top: calc(var(--safe-t) + 120px); left: 50%; transform: translateX(-50%); display: flex; flex-direction: column; gap: 6px; align-items: center; pointer-events: none; width: min(420px, calc(100% - 24px)); }
.toast { background: var(--card-solid); border-radius: 16px; box-shadow: var(--sh-lg); padding: 10px 14px; font-size: 13.5px; display: flex; gap: 8px; align-items: center; animation: tin .3s cubic-bezier(.34,1.56,.64,1); pointer-events: auto; }
.toast i { font-size: 18px; } .toast.ok i { color: var(--emerald); } .toast.err i { color: var(--pink); } .toast.gold i { color: #f59e0b; }
.toast.err { background: #fff1f2; } .toast.gold { background: #fffbeb; }
.toast a { color: inherit; text-decoration: underline; font-weight: 900; }
.toast.out { opacity: 0; transform: translateY(-8px); transition: .25s; }
@keyframes tin { from { opacity: 0; transform: translateY(-10px) scale(.95); } }

/* Loader */
#loader { position: fixed; inset: 0; z-index: 100; display: grid; place-items: center; background: linear-gradient(180deg, #e0f2fe, #f0fdf4); transition: opacity .4s; }
#loader.hide { opacity: 0; pointer-events: none; }
.load-card { text-align: center; background: #fff; border-radius: 24px; box-shadow: var(--sh-lg); padding: 24px 30px; }
.load-card i { font-size: 46px; color: var(--emerald); animation: wob 1s ease-in-out infinite; display: inline-block; }
.load-card h1 { font-size: 22px; font-weight: 900; margin-top: 6px; }
.load-card p { font-size: 13px; color: var(--muted); }

@media (max-width: 380px) {
  .wallet { min-width: 98px; padding: 4px 8px 5px; } .wallet b { font-size: 12.5px; }
  .lvl-row small { display: none; }
  .dock-ic { width: 42px; height: 38px; font-size: 20px; }
}
@media (prefers-reduced-motion: reduce) { .plot-tag.is-pest .pt-ring, .load-card i { animation: none; } }
</style>
</head>
<body>

<div id="loader"><div class="load-card"><i class="ph-fill ph-plant"></i><h1>Sawah Panen Cuan</h1><p>Menyiapkan lahan&hellip;</p></div></div>

<div id="farm-stage" aria-label="Sawah 3D"></div>
<div id="farm-labels"></div>

<!-- ── TOP BAR ── -->
<header class="topbar">
  <div class="top-left">
    <a class="icon-btn" href="/home" aria-label="Kembali ke Beranda"><i class="ph-bold ph-arrow-left"></i></a>
    <button class="icon-btn" id="btn-mute" type="button" aria-label="Suara"><i class="ph-bold ph-speaker-high"></i></button>
  </div>
  <div class="lvl">
    <div class="lvl-row">
      <span class="lvl-badge"><i class="ph-fill ph-plant"></i> Petani Lv <span id="lvl-num">1</span></span>
      <small id="xp-txt">0/50 XP</small>
    </div>
    <div class="xp"><div class="xp-fill" id="xp-fill"></div></div>
  </div>
  <div class="wallets">
    <div class="wallet dep" id="wal-dep"><small><i class="ph-fill ph-wallet"></i> Saldo Beli</small><b id="bal-dep">Rp 0</b></div>
    <div class="wallet wd" id="wal-wd"><small><i class="ph-fill ph-bank"></i> Saldo Tarik</small><b id="bal-wd">Rp 0</b></div>
  </div>
</header>

<!-- ── DOCK ── -->
<nav class="dock">
  <button class="dock-btn" type="button" data-open="seeds"><span class="dock-ic"><i class="ph-fill ph-plant"></i></span>Tanam</button>
  <button class="dock-btn" type="button" data-open="shop"><span class="dock-ic"><i class="ph-fill ph-storefront"></i></span>Toko Tani</button>
  <button class="dock-btn" type="button" data-open="barn" id="dock-barn"><span class="dock-ic"><i class="ph-fill ph-warehouse"></i></span>Lumbung<span class="dock-chip" id="barn-chip">0/30</span></button>
  <button class="dock-btn" type="button" data-open="market"><span class="dock-ic"><i class="ph-fill ph-hand-coins"></i></span>Pasar</button>
</nav>

<div class="backdrop" id="backdrop"></div>

<!-- Sheet: Pilih Bibit -->
<section class="sheet" id="sheet-seeds" aria-label="Pilih Bibit">
  <div class="sheet-head">
    <span class="hic" style="background:var(--g-emerald);color:#fff"><i class="ph-fill ph-plant"></i></span>
    <div><h3>Pilih Bibit</h3><p id="seeds-target">Petak #1</p></div>
    <button class="icon-btn close" type="button" data-close aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="sheet-body" id="seeds-body"></div>
</section>

<!-- Sheet: Toko Tani -->
<section class="sheet" id="sheet-shop" aria-label="Toko Tani">
  <div class="sheet-head">
    <span class="hic" style="background:var(--g-sky);color:#fff"><i class="ph-fill ph-storefront"></i></span>
    <div><h3>Toko Tani</h3><p>Bayar pakai Saldo Beli</p></div>
    <button class="icon-btn close" type="button" data-close aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="sheet-body" id="shop-body"></div>
</section>

<!-- Sheet: Lumbung -->
<section class="sheet" id="sheet-barn" aria-label="Lumbung">
  <div class="sheet-head">
    <span class="hic" style="background:var(--g-yellow);color:#78350f"><i class="ph-fill ph-warehouse"></i></span>
    <div><h3>Lumbung</h3><p>Hasil panen, bibit &amp; perlengkapan</p></div>
    <button class="icon-btn close" type="button" data-close aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="sheet-body" id="barn-body"></div>
</section>

<!-- Sheet: Pasar Pengepul -->
<section class="sheet" id="sheet-market" aria-label="Pasar Pengepul">
  <div class="sheet-head">
    <span class="hic" style="background:var(--g-pink);color:#fff"><i class="ph-fill ph-hand-coins"></i></span>
    <div><h3>Pasar Pengepul</h3><p>Hasil panen jadi Saldo Tarik</p></div>
    <button class="icon-btn close" type="button" data-close aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="sheet-body" id="market-body"></div>
</section>

<!-- Modal: Buka Petak -->
<section class="modal" id="modal-unlock" aria-label="Buka Petak Lahan">
  <div class="sheet-head">
    <span class="hic" style="background:var(--g-yellow);color:#78350f"><i class="ph-fill ph-lock-key-open"></i></span>
    <div><h3>Buka Petak Lahan</h3><p id="unlock-sub">Petak #5</p></div>
    <button class="icon-btn close" type="button" data-close aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="sheet-body" id="unlock-body"></div>
</section>

<!-- Modal: Aksi petak (Pupuk Kilat / Pestisida) -->
<section class="modal" id="modal-plot" aria-label="Aksi Petak">
  <div class="sheet-head">
    <span class="hic" style="background:var(--g-sky);color:#fff"><i class="ph-fill ph-lightning"></i></span>
    <div><h3 id="plot-title">Pupuk Kilat</h3><p id="plot-sub">Petak #1</p></div>
    <button class="icon-btn close" type="button" data-close aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="sheet-body" id="plot-body"></div>
</section>

<div id="toasts" aria-live="polite"></div>

<script>window.FARM_BOOT = <?= json_encode($FARM_BOOT, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script src="/assets/js/farm_engine.js?v=<?= htmlspecialchars($asset_v) ?>"></script>
<script>
/* ══════════════════════════════════════════════════════════
   UI CONTROLLER — jembatan HUD ⇄ FarmAPI ⇄ FarmEngine
   ══════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  const BOOT = window.FARM_BOOT;
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  if (!window.THREE || !THREE.OrbitControls || !window.gsap || !window.FarmEngine) {
    document.querySelector('#loader p').textContent = 'Gagal memuat mesin 3D. Periksa koneksi lalu muat ulang.';
    return;
  }
  const U = window.FarmUtil, fmtRp = U.fmtRp;
  const api = new FarmAPI(BOOT.api, BOOT.csrf);
  const audio = new FarmAudio();
  let S = BOOT.state;           // state server terbaru
  let seedTarget = null;        // petak tujuan sheet bibit
  let openEl = null;            // sheet/modal yang sedang terbuka
  let plotCardIdx = null, plotTimer = null;
  const pending = new Set();    // petak yang sedang menunggu server
  const shown = { dep: 0, wd: 0 }; // angka saldo yang sedang ditampilkan (untuk animasi hitung)

  const engine = new FarmEngine($('farm-stage'), {
    labelLayer: $('farm-labels'),
    barnEl: $('dock-barn'),
    audio,
    onPlotTap,
  });
  engine.applyState(S);
  renderHUD(true);
  setTimeout(() => $('loader').classList.add('hide'), 250);

  const cropById = (id) => S.crops.find((c) => c.id === +id);
  const itemByEffect = (e) => S.items.find((i) => i.effect === e);
  const qty = (bag, id) => (S.inventory[bag] && S.inventory[bag][id]) || 0;
  const iconOf = (crop) => U.cropIcon(crop && crop.model);

  /* ── HUD ─────────────────────────────────────────────── */
  function countTo(key, el, val, instant) {
    if (instant) { shown[key] = val; el.textContent = fmtRp(val); return; }
    if (Math.round(shown[key]) === Math.round(val)) return;
    gsap.to(shown, { [key]: val, duration: 0.8, ease: 'power2.out', onUpdate: () => { el.textContent = fmtRp(shown[key]); } });
    const w = el.parentElement; w.classList.remove('bump'); void w.offsetWidth; w.classList.add('bump');
  }
  function renderHUD(instant) {
    countTo('dep', $('bal-dep'), S.balance_dep, instant);
    countTo('wd', $('bal-wd'), S.balance_wd, instant);
    const L = S.level;
    $('lvl-num').textContent = L.level;
    $('xp-txt').textContent = (L.xp - L.xp_cur) + '/' + (L.xp_next - L.xp_cur) + ' XP';
    $('xp-fill').style.width = Math.min(100, ((L.xp - L.xp_cur) / Math.max(1, L.xp_next - L.xp_cur)) * 100) + '%';
    const chip = $('barn-chip');
    chip.textContent = S.barn_used + '/' + S.barn_capacity;
    chip.classList.toggle('full', S.barn_used >= S.barn_capacity);
    $('btn-mute').innerHTML = '<i class="ph-bold ' + (audio.muted ? 'ph-speaker-slash' : 'ph-speaker-high') + '"></i>';
  }
  function setState(st) {
    if (!st) return;
    S = st;
    engine.applyState(st);
    renderHUD();
    if (openEl) rerenderOpen();
  }

  /* ── Toast ───────────────────────────────────────────── */
  function toast(msg, type = 'ok', icon, html) {
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = '<i class="ph-fill ' + (icon || { ok: 'ph-check-circle', err: 'ph-warning-circle', gold: 'ph-sparkle' }[type] || 'ph-info') + '"></i><span>' + (html || esc(msg)) + '</span>';
    $('toasts').appendChild(el);
    setTimeout(() => { el.classList.add('out'); setTimeout(() => el.remove(), 300); }, 3200);
  }
  function fail(res) {
    audio.soundError();
    if (res && res.state) setState(res.state);
    const reason = res && res.reason;
    if (reason === 'auth') { location.href = '/login'; return; }
    if (reason === 'no_balance') return toast('', 'err', 'ph-wallet', esc(res.msg) + ' <a href="/deposit" style="pointer-events:auto">Top Up</a>');
    if (reason === 'barn_full') { toast(res.msg, 'err', 'ph-warehouse'); return openLayer('market'); }
    toast((res && res.msg) || 'Terjadi kesalahan.', 'err');
  }

  /* ── Sheet & modal ───────────────────────────────────── */
  const layers = { seeds: 'sheet-seeds', shop: 'sheet-shop', barn: 'sheet-barn', market: 'sheet-market', unlock: 'modal-unlock', plot: 'modal-plot' };
  function openLayer(name) {
    closeLayer(true);
    const el = $(layers[name]);
    openEl = el; openEl.dataset.name = name;
    rerenderOpen();
    $('backdrop').classList.add('show');
    el.classList.add('open');
    engine.setFrozen(true);   // bekukan OrbitControls & tap 3D selama modal terbuka
  }
  function closeLayer(silent) {
    if (!openEl) return;
    openEl.classList.remove('open');
    openEl = null;
    clearInterval(plotTimer); plotTimer = null; plotCardIdx = null;
    if (!silent) { $('backdrop').classList.remove('show'); engine.setFrozen(false); }
  }
  function rerenderOpen() {
    const n = openEl && openEl.dataset.name;
    ({ seeds: renderSeeds, shop: renderShop, barn: renderBarn, market: renderMarket, unlock: renderUnlock, plot: renderPlotCard })[n]?.();
  }
  $('backdrop').addEventListener('click', () => closeLayer());
  document.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => closeLayer()));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeLayer(); });
  document.querySelectorAll('[data-open]').forEach((b) => b.addEventListener('click', () => {
    audio.unlock(); audio.soundTick();
    if (b.dataset.open === 'seeds') seedTarget = null;
    openLayer(b.dataset.open);
  }));
  $('btn-mute').addEventListener('click', () => { audio.unlock(); audio.setMuted(!audio.muted); renderHUD(); });

  /* ── Tap petak 3D ────────────────────────────────────── */
  function onPlotTap(plot) {
    const idx = plot.plotIndex;
    if (pending.has(idx)) return;
    audio.soundTick();
    switch (plot.state) {
      case 'LOCKED': plotCardIdx = idx; return openLayer('unlock');
      case 'EMPTY': seedTarget = idx; return openLayer('seeds');
      case 'READY': if (!plot.pest) return harvest(idx); // eslint-disable-line no-fallthrough
      default: plotCardIdx = idx; openLayer('plot');
    }
  }

  /* ── Render: Pilih Bibit ─────────────────────────────── */
  function firstEmpty() { const p = engine.plots.find((p) => p.state === 'EMPTY' && !pending.has(p.plotIndex)); return p ? p.plotIndex : null; }
  function renderSeeds() {
    const target = seedTarget || firstEmpty();
    $('seeds-target').textContent = target ? 'Tanam di Petak #' + target : 'Semua petak sedang terisi';
    $('seeds-body').innerHTML = S.crops.filter((c) => c.is_active !== 0).map((c) => {
      const lock = S.level.level < c.min_level, stock = qty('seeds', c.id);
      const pMin = c.yield_min * c.unit_price - c.seed_price, pMax = c.yield_max * c.unit_price - c.seed_price;
      return `<div class="card ${lock ? 'locked' : ''}" style="--c:${esc(c.color)}">
        <span class="ic"><i class="ph-fill ${iconOf(c)}"></i></span>
        <div class="info"><b>${esc(c.name)}</b>
          <div class="chips">
            <span class="chip s"><i class="ph-bold ph-timer"></i>${U.fmtTime(c.growth_secs)}</span>
            <span class="chip y">${stock > 0 ? '<i class="ph-bold ph-package"></i>Stok ' + stock : fmtRp(c.seed_price)}</span>
            <span class="chip g"><i class="ph-bold ph-trend-up"></i>${fmtRp(pMin)}–${U.fmtShort(pMax).replace('Rp ', '')}</span>
          </div>
        </div>
        <div class="act">${lock ? `<span class="chip p"><i class="ph-bold ph-lock"></i>Lv ${c.min_level}</span>`
          : `<button class="nb-btn green" type="button" data-plant="${c.id}" ${target ? '' : 'disabled'}>Tanam</button>`}</div>
      </div>`;
    }).join('') + `<p class="note">Bibit di kantong dipakai lebih dulu. Bila kosong, 1 bibit otomatis dibeli dengan Saldo Beli. Untung dihitung dari hasil panen × harga pasar dikurangi harga bibit.</p>`;
    $('seeds-body').querySelectorAll('[data-plant]').forEach((b) => b.addEventListener('click', () => plant(target, +b.dataset.plant)));
  }

  /* ── Render: Toko Tani ───────────────────────────────── */
  function renderShop() {
    const itemIcon = { speed: 'ph-lightning', cure: 'ph-flask', barn: 'ph-warehouse' }, itemColor = { speed: 'var(--sky)', cure: '#86efac', barn: 'var(--yellow)' };
    $('shop-body').innerHTML = `<div class="sec-title"><i class="ph-fill ph-plant"></i> Bibit Unggul</div>` +
      S.crops.filter((c) => c.is_active !== 0).map((c) => {
        const lock = S.level.level < c.min_level;
        return `<div class="card ${lock ? 'locked' : ''}" style="--c:${esc(c.color)}">
          <span class="ic"><i class="ph-fill ${iconOf(c)}"></i></span>
          <div class="info"><b>${esc(c.name)}</b><div class="chips"><span class="chip y">${fmtRp(c.seed_price)}/bibit</span><span class="chip">Punya ${qty('seeds', c.id)}</span></div></div>
          <div class="act">${lock ? `<span class="chip p"><i class="ph-bold ph-lock"></i>Lv ${c.min_level}</span>`
            : `<button class="nb-btn yellow" type="button" data-buy="seed:${c.id}:5">×5 · ${U.fmtShort(c.seed_price * 5)}</button>`}</div>
        </div>`;
      }).join('') +
      `<div class="sec-title"><i class="ph-fill ph-toolbox"></i> Perlengkapan</div>` +
      S.items.map((it) => `<div class="card" style="--c:${itemColor[it.effect] || '#fff'}">
          <span class="ic"><i class="ph-fill ${itemIcon[it.effect] || 'ph-cube'}"></i></span>
          <div class="info"><b>${esc(it.name)}</b><span class="note">${esc(it.description)}</span>
            <div class="chips"><span class="chip y">${fmtRp(it.price)}</span>${it.effect === 'barn' ? `<span class="chip">Kapasitas ${S.barn_capacity}</span>` : `<span class="chip">Punya ${qty('items', it.id)}</span>`}</div></div>
          <div class="act"><button class="nb-btn sky" type="button" data-buy="item:${it.id}:1">Beli</button></div>
        </div>`).join('');
    $('shop-body').querySelectorAll('[data-buy]').forEach((b) => b.addEventListener('click', () => {
      const [kind, id, n] = b.dataset.buy.split(':');
      buy(kind, +id, +n, b);
    }));
  }

  /* ── Render: Lumbung ─────────────────────────────────── */
  function renderBarn() {
    const pct = Math.min(100, (S.barn_used / Math.max(1, S.barn_capacity)) * 100), full = S.barn_used >= S.barn_capacity;
    const harvest = S.crops.filter((c) => qty('harvest', c.id) > 0);
    const seeds = S.crops.filter((c) => qty('seeds', c.id) > 0);
    const items = S.items.filter((i) => qty('items', i.id) > 0);
    $('barn-body').innerHTML = `
      <div class="cap"><div class="cap-row"><span>Kapasitas</span><span>${S.barn_used}/${S.barn_capacity} Hasil Panen</span></div>
        <div class="cap-bar ${full ? 'full' : ''}"><div style="width:${pct}%"></div></div></div>
      <div class="sec-title"><i class="ph-fill ph-basket"></i> Hasil Panen</div>
      ${harvest.length ? `<div class="grid3">${harvest.map((c) => `<div class="tile"><i class="ph-fill ${iconOf(c)}" style="color:${esc(c.color)}"></i><b>${qty('harvest', c.id)}</b><small>${esc(c.name)}<br>≈ ${fmtRp(qty('harvest', c.id) * c.unit_price)}</small></div>`).join('')}</div>`
        : '<div class="empty-note">Lumbung masih kosong. Tanam &amp; panen dulu ya!</div>'}
      ${seeds.length ? `<div class="sec-title"><i class="ph-fill ph-plant"></i> Kantong Bibit</div><div class="grid3">${seeds.map((c) => `<div class="tile"><i class="ph-fill ${iconOf(c)}"></i><b>${qty('seeds', c.id)}</b><small>${esc(c.name)}</small></div>`).join('')}</div>` : ''}
      ${items.length ? `<div class="sec-title"><i class="ph-fill ph-toolbox"></i> Perlengkapan</div><div class="grid3">${items.map((i) => `<div class="tile"><i class="ph-fill ${i.effect === 'cure' ? 'ph-flask' : 'ph-lightning'}"></i><b>${qty('items', i.id)}</b><small>${esc(i.name)}</small></div>`).join('')}</div>` : ''}
      <button class="nb-btn pink block" type="button" id="barn-to-market" ${harvest.length ? '' : 'disabled'}><i class="ph-fill ph-hand-coins"></i> Jual di Pasar Pengepul</button>`;
    $('barn-to-market').addEventListener('click', () => openLayer('market'));
  }

  /* ── Render: Pasar Pengepul ──────────────────────────── */
  function renderMarket() {
    const rows = S.crops.filter((c) => qty('harvest', c.id) > 0);
    const total = rows.reduce((s, c) => s + qty('harvest', c.id) * c.unit_price, 0);
    $('market-body').innerHTML = (rows.length ? rows.map((c) => `<div class="row"><i class="ph-fill ${iconOf(c)}" style="color:${esc(c.color)}"></i>
        <div class="grow">${esc(c.name)} ×${qty('harvest', c.id)}<small>${fmtRp(c.unit_price)} / hasil</small></div><b>${fmtRp(qty('harvest', c.id) * c.unit_price)}</b></div>`).join('')
      : '<div class="empty-note">Belum ada hasil panen untuk dijual.</div>') +
      `<div class="total"><span>Total diterima</span><b>${fmtRp(total)}</b></div>
       <button class="nb-btn yellow block" type="button" id="sell-all" ${rows.length ? '' : 'disabled'}><i class="ph-fill ph-coins"></i> JUAL SEMUA HASIL PANEN</button>
       <p class="note">Uang hasil penjualan langsung masuk ke Saldo Tarik dan bisa ditarik.</p>`;
    $('sell-all').addEventListener('click', sellAll);
  }

  /* ── Render: Buka Petak ──────────────────────────────── */
  function renderUnlock() {
    const cost = S.next_plot_cost, enough = S.balance_dep >= cost;
    $('unlock-sub').textContent = 'Petak #' + plotCardIdx;
    $('unlock-body').innerHTML = `
      <div class="big-cost"><small>Biaya buka lahan</small><b>${fmtRp(cost)}</b></div>
      <div class="row"><i class="ph-fill ph-wallet"></i><div class="grow">Saldo Beli kamu</div><b>${fmtRp(S.balance_dep)}</b></div>
      <p class="note">Lahan baru dibersihkan dari rumput liar &amp; batu, siap ditanami. Harga petak berikutnya naik bertahap.</p>
      ${enough ? `<button class="nb-btn green block" type="button" id="do-unlock"><i class="ph-fill ph-lock-key-open"></i> Buka Petak</button>`
        : `<a class="nb-btn yellow block" href="/deposit" style="text-decoration:none"><i class="ph-fill ph-wallet"></i> Top Up Saldo Beli</a>`}`;
    if (enough) $('do-unlock').addEventListener('click', () => unlock(plotCardIdx));
  }

  /* ── Render: Kartu aksi petak (Pupuk Kilat / Pestisida) ─ */
  function renderPlotCard() {
    const p = engine.plots[plotCardIdx - 1];
    if (!p || !['GROWING', 'WITHERED', 'READY'].includes(p.state)) { closeLayer(); return; }
    const crop = p.cropData || {}, speed = itemByEffect('speed'), cure = itemByEffect('cure');
    const stockS = speed ? qty('items', speed.id) : 0, stockC = cure ? qty('items', cure.id) : 0;
    $('plot-title').textContent = p.pest ? 'Serangan Hama!' : 'Pupuk Kilat';
    $('plot-sub').textContent = 'Petak #' + p.plotIndex + ' · ' + (crop.name || '');
    const head = openEl.querySelector('.hic');
    head.style.background = p.pest ? 'var(--g-pink)' : 'var(--g-sky)';
    head.innerHTML = '<i class="ph-fill ' + (p.pest ? 'ph-bug' : 'ph-lightning') + '"></i>';
    const ready = p.state === 'READY';
    $('plot-body').innerHTML = `
      <div class="plot-hero" style="--c:${esc(crop.color || '#fde047')}"><span class="ic"><i class="ph-fill ${iconOf(crop)}"></i></span>
        <div style="flex:1"><b style="font-size:17px">${esc(crop.name || 'Tanaman')}</b>
          <div class="prog" style="margin-top:6px"><div id="pc-bar"></div><span id="pc-time"></span></div></div></div>
      ${p.pest ? `<div class="card" style="--c:#86efac"><span class="ic"><i class="ph-fill ph-flask"></i></span>
          <div class="info"><b>${esc(cure ? cure.name : 'Pestisida')}</b><span class="note">Ulat memakan daun! Tanpa dibasmi hasil panen tinggal separuh.</span></div>
          <div class="act"><button class="nb-btn pink" type="button" id="do-cure" ${cure ? '' : 'disabled'}>${stockC > 0 ? 'Pakai (' + stockC + ')' : (cure ? U.fmtShort(cure.price) : '-')}</button></div></div>` : ''}
      ${!ready ? `<div class="card" style="--c:var(--sky)"><span class="ic"><i class="ph-fill ph-lightning"></i></span>
          <div class="info"><b>${esc(speed ? speed.name : 'Pupuk Kilat')}</b><span class="note">Siram pupuk ajaib, tanaman langsung siap panen.</span></div>
          <div class="act"><button class="nb-btn sky" type="button" id="do-speed" ${speed ? '' : 'disabled'}>${stockS > 0 ? 'Pakai (' + stockS + ')' : (speed ? U.fmtShort(speed.price) : '-')}</button></div></div>` : ''}
      ${ready && p.pest ? `<button class="nb-btn yellow block" type="button" id="do-harvest"><i class="ph-fill ph-scissors"></i> Panen Saja (hasil 50%)</button>` : ''}
      <p class="note">${stockS > 0 || stockC > 0 ? 'Memakai stok dari Lumbung lebih dulu.' : 'Stok kosong: item dibeli otomatis dengan Saldo Beli.'}</p>`;
    $('do-speed')?.addEventListener('click', () => speedUp(p.plotIndex));
    $('do-cure')?.addEventListener('click', () => cureIt(p.plotIndex));
    $('do-harvest')?.addEventListener('click', () => { closeLayer(); harvest(p.plotIndex); });
    const tick = () => {
      const now = engine.serverNow(), tot = Math.max(1, p.maturesAt - p.plantedAt), pct = Math.min(1, (now - p.plantedAt) / tot);
      const bar = $('pc-bar'); if (!bar) return;
      bar.style.width = (pct * 100).toFixed(1) + '%';
      $('pc-time').textContent = now >= p.maturesAt ? 'SIAP PANEN!' : U.fmtTime(p.maturesAt - now) + ' lagi';
      if (now >= p.maturesAt && !p.pest && !ready) { closeLayer(); }
    };
    tick();
    clearInterval(plotTimer); plotTimer = setInterval(tick, 250);
  }

  /* ══════════════════════════════════════════════════════════
     AKSI → API → ANIMASI
     ══════════════════════════════════════════════════════════ */
  async function guarded(idx, fn) {
    if (idx && pending.has(idx)) return;
    if (idx) pending.add(idx);
    try { await fn(); } finally { if (idx) pending.delete(idx); }
  }

  function plant(idx, cropId) {
    if (!idx) return;
    closeLayer();
    guarded(idx, async () => {
      const res = await api.call('plant', { plot_index: idx, crop_id: cropId });
      if (!res.ok) return fail(res);
      setState(res.state);
      engine.playPlant(idx);
      if (res.paid > 0) toast('1 bibit dibeli ' + fmtRp(res.paid), 'ok', 'ph-plant');
    });
  }

  function speedUp(idx) {
    const p = engine.plots[idx - 1], prev = { maturesAt: p.maturesAt };
    closeLayer();
    guarded(idx, async () => {
      const res = await api.call('speed_up', { plot_index: idx });
      if (!res.ok) return fail(res);
      setState(res.state);
      engine.playSpeedUp(idx, prev);
    });
  }

  function cureIt(idx) {
    closeLayer();
    guarded(idx, async () => {
      const res = await api.call('cure', { plot_index: idx });
      if (!res.ok) return fail(res);
      setState(res.state);
      engine.playCure(idx);
      toast('Hama berhasil dibasmi! Hasil panen kembali penuh.', 'ok', 'ph-flask');
    });
  }

  function unlock(idx) {
    closeLayer();
    guarded(idx, async () => {
      const res = await api.call('unlock_plot', { plot_index: idx });
      if (!res.ok) return fail(res);
      setState(res.state);
      engine.playUnlock(idx);
      toast('Petak #' + idx + ' terbuka! Siap ditanami.', 'gold', 'ph-lock-key-open');
    });
  }

  function harvest(idx) {
    guarded(idx, () => new Promise((resolve) => {
      const req = api.call('harvest', { plot_index: idx });
      engine.playHarvest(idx, req, (res) => {
        resolve();
        if (!res || !res.ok) return fail(res);
        setState(res.state);
        if (res.level_up) {
          engine.celebrate();
          setTimeout(() => { audio.soundHarvest(); toast('Naik ke Level ' + res.level_up + '! Bibit baru mungkin sudah terbuka.', 'gold', 'ph-crown'); }, 600);
        }
      });
    }));
  }

  async function sellAll() {
    const btn = $('sell-all'); if (btn) btn.disabled = true;
    const res = await api.call('sell_all');
    if (!res.ok) { if (btn) btn.disabled = false; return fail(res); }
    closeLayer();
    audio.soundCoin(0.3);
    const lr = $('farm-labels').getBoundingClientRect(), br = $('dock-barn').getBoundingClientRect();
    const from = { x: br.left + br.width / 2 - lr.left, y: br.top - lr.top };
    engine.flyIcons(from, $('wal-wd'), '<i class="ph-fill ph-coins"></i>', 8, (k, last) => {
      audio.tone(1000 + k * 60, 0.06, 'triangle', 0.12);
      if (last) { setState(res.state); audio.soundHarvest(); engine.celebrate(); }
    });
    document.querySelectorAll('.fly-icon').forEach((el) => el.classList.add('coin'));
    toast(res.sold_count + ' hasil panen terjual · +' + fmtRp(res.credited) + ' Saldo Tarik', 'gold', 'ph-coins');
  }

  async function buy(kind, id, n, btn) {
    btn.disabled = true;
    const res = await api.call('buy', { kind, ref_id: id, qty: n });
    btn.disabled = false;
    if (!res.ok) return fail(res);
    audio.soundCoin(0.22);
    setState(res.state);
    toast(res.msg || 'Pembelian berhasil!', 'ok', 'ph-shopping-bag');
  }

  /* ── Sinkron latar: tab kembali aktif → kalibrasi timer & tarik state ── */
  async function resync() {
    const res = await api.call('get_state');
    if (res.ok) setState(res.state);
  }
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    engine.recalibrate();   // tanaman yang matang selama tab ditinggal langsung jadi READY
    resync();
  });
  setInterval(() => { if (document.visibilityState === 'visible' && !pending.size) resync(); }, 60000);

  window.__farm = { engine, api, audio, get state() { return S; } };
})();
</script>
</body>
</html>
