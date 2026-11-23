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
<meta name="theme-color" content="#5ec2ec">
<meta name="robots" content="noindex">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>Sawah Panen Cuan — <?= htmlspecialchars($_site_name) ?></title>
<?php require dirname(__DIR__) . '/partials/head_icons.php'; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/game-ui.css">
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1"></script>
<style>
/* ══════════════════════════════════════════════════════════
   SAWAH PANEN CUAN — TEMA PULAU BAHARI
   Kebun di pulau tropis: bingkai panel navy kapal + tali tambang,
   isi kertas peta, jendela kapal (porthole) kuningan, dock dermaga
   kayu dengan tombol pelampung, judul berbentuk bendera layar.
   ══════════════════════════════════════════════════════════ */
:root {
  --ink: #12324f;
  --muted: #5d7a94;
  --parch: #fdf5e1;
  --parch-2: #f1dfb6;
  --wood: #8b5a2b;
  --wood-d: #4e2c10;
  --wood-l: #b9803f;
  --slot: #fffaf0;
  --slot-bd: #e5cf9c;
  --navy: #13395c; --navy-d: #0a2238; --navy-l: #1f5486;
  --brass1: #f8dc84; --brass2: #c8922a; --brass3: #7f5310;
  --rope: #dcbc80; --rope-d: #a8823f;
  /* tombol: warna muka / warna bibir bawah */
  --g1: #2fc28a; --g2: #17855b;
  --y1: #ffd24a; --y2: #c98f0b;
  --b1: #2fb5e0; --b2: #1677a6;
  --r1: #ff7a6b; --r2: #cc4436;
  --font-game: 'Fredoka', 'Nunito', sans-serif;
  --safe-t: env(safe-area-inset-top, 0px);
  --safe-b: env(safe-area-inset-bottom, 0px);
}
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
html, body { height: 100%; overflow: hidden; overscroll-behavior: none; }
body {
  font-family: 'Nunito', sans-serif; font-weight: 700; color: var(--ink);
  background: linear-gradient(180deg, #5ec2ec 0%, #a5def3 34%, #cdeef5 56%, #cdeef5 100%);
  -webkit-user-select: none; user-select: none;
}
button { font: inherit; color: inherit; cursor: pointer; border: 0; background: none; }
button:disabled { cursor: not-allowed; }
img { -webkit-user-drag: none; }

#farm-stage { position: fixed; inset: 0; }
#farm-stage canvas { display: block; }
#farm-labels { position: fixed; inset: 0; pointer-events: none; overflow: hidden; z-index: 5; }

/* Ikon 3D di dalam elemen apa pun */
.ic img, .hic img, .dock-ic img, .wal-ic img, .lvl-star img, .price img, .lock-badge img, .nb-btn img, .fly-icon img {
  width: 100%; height: 100%; object-fit: contain; display: block; filter: drop-shadow(0 3px 2px rgba(60, 30, 5, .28));
}

/* ── Tombol game (muka + bibir bawah, turun saat ditekan) ── */
.nb-btn {
  --f: #fff; --l: #d6c3a0; --t: var(--ink);
  position: relative; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
  font-family: var(--font-game); font-weight: 600; font-size: 15px; letter-spacing: .2px; color: var(--t);
  padding: 9px 16px 10px; border-radius: 14px; background: linear-gradient(180deg, color-mix(in srgb, var(--f) 70%, #fff) 0%, var(--f) 55%);
  box-shadow: 0 4px 0 var(--l), 0 6px 10px rgba(60, 30, 5, .25), inset 0 2px 0 rgba(255, 255, 255, .55);
  text-shadow: 0 1px 0 rgba(0, 0, 0, .18); transition: transform .08s, box-shadow .08s, filter .15s;
}
.nb-btn img { width: 22px; height: 22px; }
.nb-btn:active:not(:disabled) { transform: translateY(3px); box-shadow: 0 1px 0 var(--l), 0 2px 4px rgba(60, 30, 5, .2), inset 0 2px 0 rgba(255, 255, 255, .5); }
.nb-btn:disabled { filter: grayscale(.8) brightness(1.05); opacity: .7; }
.nb-btn.green { --f: var(--g1); --l: var(--g2); --t: #fff; }
.nb-btn.yellow { --f: var(--y1); --l: var(--y2); --t: #5b3405; text-shadow: 0 1px 0 rgba(255, 255, 255, .5); }
.nb-btn.sky { --f: var(--b1); --l: var(--b2); --t: #fff; }
.nb-btn.pink { --f: var(--r1); --l: var(--r2); --t: #fff; }
.nb-btn.block { width: 100%; padding: 13px 14px 14px; font-size: 17px; border-radius: 16px; }

/* ── TOP BAR ── */
.topbar {
  position: fixed; z-index: 20; top: calc(var(--safe-t) + 10px); left: 10px; right: 10px;
  display: flex; align-items: flex-start; gap: 8px; pointer-events: none;
}
.topbar > * { pointer-events: auto; }
/* Tombol bulat = jendela kapal (porthole) berbingkai kuningan */
.icon-btn {
  width: 44px; height: 44px; flex: 0 0 44px; display: grid; place-items: center; font-size: 19px; color: #fff; border-radius: 50%;
  background:
    radial-gradient(circle at 50% 45%, #2a6aa0 0 50%, var(--navy) 51% 56%, transparent 57%),
    radial-gradient(circle at 35% 28%, #fff4c4 0%, var(--brass1) 30%, var(--brass2) 70%, var(--brass3) 100%);
  box-shadow: 0 3px 0 var(--brass3), 0 6px 10px rgba(5, 30, 50, .35), inset 0 0 0 1.5px rgba(255, 255, 255, .45);
  text-shadow: 0 1px 0 rgba(0, 0, 0, .35); transition: transform .08s, box-shadow .08s;
}
.icon-btn:active { transform: translateY(3px); box-shadow: 0 0 0 var(--brass3), inset 0 0 0 1.5px rgba(255, 255, 255, .4); }
.top-left { display: flex; flex-direction: column; gap: 8px; }

.lvl {
  flex: 1; min-width: 0; max-width: 236px; margin: 4px auto 0; display: flex; align-items: center;
  background: rgba(10, 34, 56, .66); border-radius: 999px; padding: 4px 12px 4px 4px; position: relative;
  box-shadow: inset 0 0 0 1.5px rgba(248, 220, 132, .55), 0 3px 8px rgba(0, 0, 0, .25); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
}
/* Lencana level = kemudi kapal (jari-jari kayu + pusat kuningan) */
.lvl-star { position: relative; width: 50px; height: 50px; flex: 0 0 50px; margin: -12px 4px -12px -8px; display: grid; place-items: center; }
.lvl-star::before {
  content: ''; position: absolute; inset: 0; border-radius: 50%;
  background: repeating-conic-gradient(from -4deg, var(--wood-l) 0 8deg, transparent 8deg 45deg);
  -webkit-mask: radial-gradient(circle, #000 0 70%, transparent 71%); mask: radial-gradient(circle, #000 0 70%, transparent 71%);
  animation: helm 14s linear infinite;
}
.lvl-star::after {
  content: ''; position: absolute; inset: 7px; border-radius: 50%; border: 4px solid var(--wood);
  box-shadow: 0 0 0 1.5px var(--wood-d), inset 0 0 0 1.5px var(--wood-d), 0 3px 5px rgba(0, 0, 0, .3);
}
.lvl-star b {
  position: relative; z-index: 1; width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center;
  background: radial-gradient(circle at 35% 30%, #fff4c4, var(--brass1) 35%, var(--brass2)); box-shadow: 0 0 0 1.5px var(--brass3);
  font-family: var(--font-game); font-weight: 700; font-size: 13.5px; color: var(--navy-d); text-shadow: 0 1px 0 rgba(255, 255, 255, .6);
}
@keyframes helm { to { transform: rotate(360deg); } }
.lvl-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.lvl-row { display: flex; align-items: baseline; justify-content: space-between; gap: 6px; }
.lvl-name { font-family: var(--font-game); font-weight: 600; font-size: 13px; color: #fff; text-shadow: 0 1px 0 rgba(0, 0, 0, .35); }
.lvl-row small { font-size: 10.5px; color: #fde68a; font-weight: 800; white-space: nowrap; }
.xp { height: 9px; border-radius: 999px; background: rgba(0, 0, 0, .35); overflow: hidden; box-shadow: inset 0 1px 2px rgba(0, 0, 0, .4); }
.xp-fill { height: 100%; width: 0; border-radius: 999px; background: linear-gradient(180deg, #fff3b0, #fbbf24 55%, #f59e0b); transition: width .5s cubic-bezier(.34,1.56,.64,1); }

.wallets { display: flex; flex-direction: column; gap: 8px; flex: 0 0 auto; margin-top: 4px; }
.wallet {
  position: relative; display: flex; align-items: center; min-width: 128px; height: 36px; padding: 0 8px 0 34px;
  background: rgba(10, 34, 56, .66); border-radius: 999px; color: #fff;
  box-shadow: inset 0 0 0 1.5px rgba(248, 220, 132, .55), 0 3px 8px rgba(0, 0, 0, .25); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
}
.wal-ic { position: absolute; left: -10px; top: 50%; width: 44px; height: 44px; transform: translateY(-50%); display: grid; place-items: center; }
.wal-ic > i { font-size: 26px; color: #fcd34d; }
.wal-tx { display: flex; flex-direction: column; line-height: 1.05; flex: 1; min-width: 0; }
.wal-tx small { font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .4px; color: #fde68a; }
.wallet.wd .wal-tx small { color: #bbf7d0; }
.wal-tx b { font-family: var(--font-game); font-weight: 600; font-size: 15px; font-variant-numeric: tabular-nums; white-space: nowrap; text-shadow: 0 1px 0 rgba(0, 0, 0, .35); }
.wal-add {
  width: 24px; height: 24px; margin-left: 6px; border-radius: 8px; display: grid; place-items: center; color: #fff; font-size: 14px; text-decoration: none;
  background: linear-gradient(180deg, #8ee86a, var(--g1)); box-shadow: 0 2px 0 var(--g2), inset 0 1px 0 rgba(255, 255, 255, .6);
}
.wallet.bump { animation: bump .45s cubic-bezier(.34,1.56,.64,1); }
@keyframes bump { 40% { transform: scale(1.1); } }

/* ── DOCK BAWAH (dermaga papan kayu bertali tambang, tombol = pelampung) ── */
.dock {
  position: fixed; z-index: 20; left: 50%; transform: translateX(-50%); bottom: calc(var(--safe-b) + 10px);
  width: min(460px, calc(100% - 16px)); display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px; padding: 10px 6px 7px;
  background:
    repeating-linear-gradient(90deg, rgba(40, 20, 5, .22) 0 2px, transparent 2px 33px),
    linear-gradient(180deg, var(--wood-l) 0%, var(--wood) 55%, #6f441d 100%);
  border-radius: 18px; box-shadow: 0 5px 0 var(--wood-d), 0 10px 22px rgba(5, 30, 50, .35), inset 0 2px 0 rgba(255, 255, 255, .28), inset 0 -2px 0 rgba(0, 0, 0, .15);
}
.dock::before { /* tali tambang di tepi atas */
  content: ''; position: absolute; left: 12px; right: 12px; top: -4px; height: 8px; border-radius: 999px;
  background: repeating-linear-gradient(-55deg, var(--rope) 0 4px, var(--rope-d) 4px 6px); box-shadow: 0 2px 2px rgba(0, 0, 0, .25);
}
.dock::after { /* tiang tambat di kedua ujung */
  content: ''; position: absolute; inset: -9px -4px auto -4px; height: 18px; pointer-events: none;
  background: radial-gradient(circle at 9px 9px, var(--wood-d) 0 6px, transparent 7px), radial-gradient(circle at calc(100% - 9px) 9px, var(--wood-d) 0 6px, transparent 7px);
}
.dock-btn { position: relative; display: flex; flex-direction: column; align-items: center; gap: 2px; padding-top: 2px; }
.dock-ic {
  --f: #fff; --l: #ccc;
  width: 58px; height: 58px; margin-top: -28px; border-radius: 50%; display: grid; place-items: center; font-size: 26px; color: #fff;
  background:
    radial-gradient(circle at 50% 38%, color-mix(in srgb, var(--f) 50%, #fff) 0%, var(--f) 46%, transparent 47.5%),
    repeating-conic-gradient(from 22.5deg, #fff 0 45deg, var(--r1) 45deg 90deg);
  box-shadow: 0 4px 0 var(--l), 0 6px 12px rgba(5, 30, 50, .3), inset 0 0 0 2px rgba(0, 0, 0, .12), inset 0 3px 0 rgba(255, 255, 255, .5);
  transition: transform .1s, box-shadow .1s; padding: 9px;
}
.dock-ic img { width: 40px; height: 40px; }
.dock-btn:active .dock-ic { transform: translateY(3px) rotate(-8deg); box-shadow: 0 1px 0 var(--l), inset 0 0 0 2px rgba(0, 0, 0, .12); }
.dock-btn.t-green .dock-ic { --f: var(--g1); --l: var(--g2); }
.dock-btn.t-sky .dock-ic { --f: var(--b1); --l: var(--b2); }
.dock-btn.t-gold .dock-ic { --f: var(--y1); --l: var(--y2); }
.dock-btn.t-red .dock-ic { --f: var(--navy-l); --l: var(--navy-d); }
.dock-lbl { font-family: var(--font-game); font-weight: 600; font-size: 12.5px; color: #fff; text-shadow: 0 1px 0 var(--wood-d), 0 0 3px rgba(60, 30, 5, .4); white-space: nowrap; }
.dock-chip {
  position: absolute; top: -26px; right: 0; background: #fff; border-radius: 999px; color: var(--ink); font-family: var(--font-game); font-weight: 600;
  font-size: 10.5px; padding: 1px 6px; font-variant-numeric: tabular-nums; box-shadow: 0 2px 0 #d6c3a0, 0 2px 6px rgba(0, 0, 0, .2);
}
.dock-chip.full { background: var(--r1); color: #fff; box-shadow: 0 2px 0 var(--r2); }
#dock-barn { will-change: transform; }

/* ── Aksi cepat di atas dock ── */
.quick { position: fixed; z-index: 19; left: 50%; transform: translateX(-50%); bottom: calc(var(--safe-b) + 128px); display: flex; gap: 8px; pointer-events: none; }
.quick .nb-btn { pointer-events: auto; font-size: 16px; padding: 10px 14px 11px 16px; animation: qin .4s cubic-bezier(.34, 1.56, .64, 1); }
.quick .nb-btn[hidden] { display: none; }
.quick .nb-btn i { font-size: 20px; }
.quick .nb-btn b { min-width: 24px; padding: 0 7px; border-radius: 999px; background: var(--navy); color: #fff; font-size: 13px; text-shadow: none; line-height: 22px; }
.quick .nb-btn.busy { filter: saturate(.6); pointer-events: none; }
@keyframes qin { from { opacity: 0; transform: translateY(14px) scale(.85); } }

/* ── Pilihan mode tanam (1 petak / semua petak kosong) ── */
.seg { display: flex; gap: 4px; padding: 4px; border-radius: 999px; background: rgba(19, 57, 92, .08); box-shadow: inset 0 0 0 2px var(--slot-bd); }
.seg button { flex: 1; padding: 7px 10px; border-radius: 999px; font-family: var(--font-game); font-weight: 600; font-size: 13.5px; color: var(--muted); transition: background .15s, color .15s; }
.seg button.on { background: var(--navy); color: #fff; box-shadow: 0 2px 0 var(--navy-d); }

/* ── LABEL 3D (timer radial dsb.) ── */
.plot-tag { position: absolute; left: 0; top: 0; display: flex; flex-direction: column; align-items: center; gap: 3px; will-change: transform; }
.pt-ring {
  --c: #4ade80;
  width: 38px; height: 38px; border-radius: 50%; position: relative; padding: 4px;
  background: conic-gradient(var(--c) calc(var(--p, 0) * 1turn), rgba(10, 34, 56, .55) 0); display: grid; place-items: center;
  box-shadow: 0 3px 0 rgba(10, 34, 56, .45), 0 4px 10px rgba(0, 0, 0, .25);
}
.pt-ring::after { content: ''; position: absolute; inset: 4px; border-radius: 50%; background: var(--parch); }
.pt-ring i { position: relative; z-index: 1; font-size: 16px; color: #16a34a; }
.pt-text {
  font-family: var(--font-game); font-weight: 600; background: rgba(10, 34, 56, .8); color: #fff; border-radius: 999px; padding: 1px 9px 2px;
  font-size: 12.5px; box-shadow: 0 2px 6px rgba(0, 0, 0, .25); font-variant-numeric: tabular-nums; white-space: nowrap;
}
.plot-tag.is-pest .pt-ring { --c: var(--r1); animation: wob .5s ease-in-out infinite; }
.plot-tag.is-pest .pt-ring i { color: var(--r2); }
.plot-tag.is-pest .pt-text { background: linear-gradient(180deg, var(--r1), var(--r2)); }
.plot-tag.is-locked .pt-ring { display: none; }
.plot-tag.is-locked .pt-text { background: linear-gradient(180deg, var(--y1), #f5b300); color: #5b3405; box-shadow: 0 2px 0 var(--y2), 0 3px 8px rgba(0, 0, 0, .2); }
.plot-tag.is-spin .pt-ring { animation: spin .25s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@keyframes wob { 50% { transform: rotate(-12deg) scale(1.08); } }
.float-text {
  position: absolute; left: 0; top: 0; white-space: nowrap; font-family: var(--font-game); font-size: 24px; font-weight: 700; color: #fff3b0;
  -webkit-text-stroke: 4px #8a4b0f; paint-order: stroke fill; text-shadow: 0 4px 0 #8a4b0f, 0 8px 14px rgba(0, 0, 0, .3);
}
.fly-icon { position: absolute; left: 0; top: 0; width: 40px; height: 40px; display: grid; place-items: center; font-size: 26px; color: #f59e0b; z-index: 30; }

/* ── HADIAH BIBIT HARIAN ── */
.gift-btn { position: relative; width: 48px; height: 48px; flex-basis: 48px; padding: 0; overflow: visible;
  background: radial-gradient(circle at 50% 30%, #fff6c9, #ffd43b 60%, #f2a50c); box-shadow: 0 4px 0 var(--y2), 0 6px 12px rgba(120, 70, 5, .35), inset 0 2px 0 rgba(255, 255, 255, .6); }
.gift-btn img { width: 40px; height: 40px; object-fit: contain; filter: drop-shadow(0 2px 2px rgba(0, 0, 0, .25)); }
.gift-btn.ready img { animation: giftShake 2.2s ease-in-out infinite; }
.gift-btn.ready::before { content: ''; position: absolute; inset: -6px; border-radius: 18px; background: radial-gradient(circle, rgba(255, 230, 120, .9), transparent 70%); animation: giftGlow 1.6s ease-in-out infinite; z-index: -1; }
.gift-btn .dot { position: absolute; top: -4px; right: -4px; width: 14px; height: 14px; border-radius: 50%; background: var(--r1); box-shadow: 0 0 0 2px #fff; display: none; }
.gift-btn.ready .dot { display: block; animation: giftDot 1.2s ease-in-out infinite; }
.gift-btn .cd { position: absolute; left: 50%; bottom: -15px; transform: translateX(-50%); white-space: nowrap; padding: 0 6px; border-radius: 999px; font-family: var(--font-game); font-size: 10.5px; font-weight: 600; color: #fff; background: rgba(10, 34, 56, .8); }
.gift-btn:not(.ready) img { filter: grayscale(.55) drop-shadow(0 2px 2px rgba(0, 0, 0, .25)); opacity: .85; }
@keyframes giftShake { 0%, 70%, 100% { transform: none; } 74% { transform: rotate(-12deg) scale(1.08); } 78% { transform: rotate(12deg) scale(1.08); } 82% { transform: rotate(-8deg); } 86% { transform: rotate(6deg); } }
@keyframes giftGlow { 50% { opacity: .45; transform: scale(1.1); } }
@keyframes giftDot { 50% { transform: scale(1.25); } }

.gift-stage { position: relative; height: 168px; display: grid; place-items: center; margin: -4px 0 6px; }
.gift-rays { position: absolute; width: 230px; height: 230px; border-radius: 50%; opacity: 0; transition: opacity .4s;
  background: repeating-conic-gradient(from 0deg, rgba(255, 214, 80, .55) 0 12deg, transparent 12deg 30deg); -webkit-mask: radial-gradient(circle, #000 30%, transparent 70%); mask: radial-gradient(circle, #000 30%, transparent 70%); animation: giftSpin 9s linear infinite; }
.gift-stage.open .gift-rays, .gift-stage.idle .gift-rays { opacity: 1; }
.gift-stage.idle .gift-rays { opacity: .45; }
@keyframes giftSpin { to { transform: rotate(360deg); } }
.gift-box { position: relative; width: 130px; height: 130px; object-fit: contain; filter: drop-shadow(0 10px 10px rgba(90, 50, 10, .35)); cursor: pointer; }
.gift-stage.idle .gift-box { animation: giftBob 2.4s ease-in-out infinite; }
.gift-stage.shake .gift-box { animation: giftOpen .9s ease-in-out both; }
@keyframes giftBob { 50% { transform: translateY(-8px) rotate(-3deg); } }
@keyframes giftOpen { 0% { transform: none; } 15% { transform: rotate(-10deg) scale(1.05); } 30% { transform: rotate(10deg) scale(1.08); } 45% { transform: rotate(-12deg) scale(1.1); } 60% { transform: rotate(12deg) scale(1.14); } 80% { transform: scale(1.25, .8); } 100% { transform: scale(1); } }
.gift-prize { position: absolute; display: grid; place-items: center; width: 96px; height: 96px; border-radius: 26px; opacity: 0; transform: translateY(30px) scale(.3);
  background: radial-gradient(circle at 50% 35%, #fff, var(--parch-2) 75%); box-shadow: 0 0 0 4px #fff, 0 0 0 7px var(--c, var(--y1)), 0 12px 26px rgba(90, 50, 10, .35); }
.gift-prize img { width: 74px; height: 74px; object-fit: contain; }
.gift-prize i { font-size: 52px; color: var(--c, var(--g2)); }
.gift-prize em { position: absolute; right: -12px; bottom: -10px; padding: 1px 9px; border-radius: 999px; font-style: normal; font-family: var(--font-game); font-size: 20px; font-weight: 700; color: #fff; background: linear-gradient(180deg, var(--g1), var(--g2)); box-shadow: 0 3px 0 #2c6e18; }
.gift-stage.open .gift-prize { animation: giftPrize .7s cubic-bezier(.34, 1.56, .64, 1) forwards; }
.gift-stage.open .gift-box { opacity: 0; transform: scale(.4); transition: opacity .25s, transform .25s; }
@keyframes giftPrize { to { opacity: 1; transform: translateY(-6px) scale(1); } }
.gift-name { text-align: center; font-family: var(--font-game); font-size: 22px; font-weight: 700; color: var(--ink); line-height: 1.15; }
.gift-rar { display: block; width: max-content; margin: 4px auto 0; padding: 1px 12px; border-radius: 999px; font-size: 12px; font-weight: 900; letter-spacing: .5px; text-transform: uppercase; color: #fff; }
.gift-rar.biasa { background: linear-gradient(180deg, var(--g1), var(--g2)); }
.gift-rar.langka { background: linear-gradient(180deg, var(--b1), var(--b2)); }
.gift-rar.super { background: linear-gradient(180deg, #c084fc, #7c3aed); animation: giftDot 1.2s ease-in-out infinite; }
.gift-cd { text-align: center; font-family: var(--font-game); font-size: 26px; font-weight: 700; color: var(--wood-d); }
.gift-cd small { display: block; font-family: 'Nunito', sans-serif; font-size: 12px; font-weight: 800; color: var(--muted); }
@media (prefers-reduced-motion: reduce) { .gift-btn img, .gift-btn.ready::before, .gift-rays, .gift-box, .gift-prize { animation: none !important; } }

/* ── BACKDROP, SHEET & MODAL (panel kayu berisi perkamen) ── */
.backdrop {
  position: fixed; inset: 0; z-index: 40; background: rgba(5, 25, 45, .5); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
  opacity: 0; pointer-events: none; transition: opacity .2s;
}
.backdrop.show { opacity: 1; pointer-events: auto; }
.sheet, .modal {
  --rb1: var(--g1); --rb2: var(--g2);
  position: fixed; z-index: 50; display: flex; flex-direction: column;
  background: linear-gradient(180deg, var(--navy-l), var(--navy) 40%, var(--navy-d)); padding: 7px 7px 0;
  box-shadow: 0 -6px 30px rgba(5, 25, 45, .45), inset 0 2px 0 rgba(255, 255, 255, .18), inset 0 0 0 2px rgba(248, 220, 132, .35);
}
.t-sky { --rb1: var(--b1); --rb2: var(--b2); }
.t-gold { --rb1: var(--y1); --rb2: var(--y2); }
.t-red { --rb1: var(--r1); --rb2: var(--r2); }
.sheet {
  left: 50%; bottom: 0; width: min(520px, 100%); max-height: 82dvh; border-radius: 28px 28px 0 0;
  transform: translate(-50%, calc(100% + 90px)); transition: transform .34s cubic-bezier(.2, 1.25, .4, 1); padding-bottom: var(--safe-b);
}
.sheet.open { transform: translate(-50%, 0); }
.modal {
  left: 50%; top: 50%; width: min(370px, calc(100% - 28px)); border-radius: 26px; padding-bottom: 7px;
  transform: translate(-50%, -46%) scale(.85); opacity: 0; pointer-events: none; transition: transform .28s cubic-bezier(.34,1.56,.64,1), opacity .2s;
  box-shadow: 0 6px 0 #061626, 0 18px 40px rgba(5, 25, 45, .5), inset 0 2px 0 rgba(255, 255, 255, .18), inset 0 0 0 2px rgba(248, 220, 132, .35);
}
.modal.open { transform: translate(-50%, -50%) scale(1); opacity: 1; pointer-events: auto; }
.sheet-head {
  position: relative; display: flex; flex-direction: column; align-items: center; text-align: center;
  padding: 26px 16px 8px; margin-top: 18px; border-radius: 22px 22px 0 0;
  background: radial-gradient(circle at 20% 0%, rgba(255, 255, 255, .7), transparent 40%), var(--parch);
  border: 2.5px dashed var(--rope-d); border-bottom: 0;
}
.modal .sheet-head { border-radius: 20px 20px 0 0; }
/* Ikon judul = jendela kapal kuningan berpaku keling */
.hic {
  position: absolute; top: -40px; left: 50%; transform: translateX(-50%); width: 68px; height: 68px; display: grid; place-items: center;
  border-radius: 50%; background: radial-gradient(circle at 40% 30%, #ffffff, #d7f1fb 45%, #9fd8ef 100%); font-size: 32px; color: var(--navy);
  box-shadow: 0 0 0 5px var(--brass2), 0 0 0 7px var(--brass3), 0 5px 0 7px var(--navy-d), inset 0 -4px 8px rgba(20, 90, 140, .25); padding: 6px;
}
.hic::after {
  content: ''; position: absolute; inset: -6px; border-radius: 50%; pointer-events: none;
  background: radial-gradient(circle at 50% 3px, var(--brass1) 0 2px, transparent 2.5px), radial-gradient(circle at 50% calc(100% - 3px), var(--brass1) 0 2px, transparent 2.5px),
              radial-gradient(circle at 3px 50%, var(--brass1) 0 2px, transparent 2.5px), radial-gradient(circle at calc(100% - 3px) 50%, var(--brass1) 0 2px, transparent 2.5px);
}
.ribbon {
  position: relative; z-index: 1; margin: 0 26px; padding: 5px 22px 7px; font-family: var(--font-game); font-weight: 700; font-size: 20px; line-height: 1.1; color: #fff;
  background: linear-gradient(180deg, color-mix(in srgb, var(--rb1) 75%, #fff), var(--rb1) 60%); border-radius: 8px;
  box-shadow: 0 3px 0 var(--rb2), inset 0 2px 0 rgba(255, 255, 255, .45); text-shadow: 0 2px 0 var(--rb2);
}
.ribbon::before, .ribbon::after {
  content: ''; position: absolute; z-index: -1; top: 7px; width: 22px; height: 100%; background: var(--rb2);
}
.ribbon::before { left: -14px; clip-path: polygon(0 0, 100% 0, 100% 100%, 0 100%, 8px 50%); }
.ribbon::after { right: -14px; clip-path: polygon(0 0, 100% 0, calc(100% - 8px) 50%, 100% 100%, 0 100%); }
.sheet-head p { margin-top: 8px; font-size: 12.5px; color: var(--muted); font-weight: 800; }
.close {
  position: absolute; top: -12px; right: 4px; width: 38px; height: 38px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-size: 18px;
  background: radial-gradient(circle at 50% 30%, #ffa99c, var(--r1) 65%); box-shadow: 0 3px 0 var(--r2), 0 0 0 4px var(--brass2), 0 0 0 5.5px var(--brass3), 0 5px 10px rgba(0, 0, 0, .3);
  text-shadow: 0 1px 0 rgba(0, 0, 0, .25); transition: transform .08s;
}
.close:active { transform: translateY(2px) scale(.95); }
.sheet-body {
  overflow-y: auto; padding: 8px 14px 18px; display: flex; flex-direction: column; gap: 10px; -webkit-overflow-scrolling: touch;
  border: 2.5px dashed var(--rope-d); border-top: 0; border-bottom: 0;
  /* kertas peta bergaris kisi tipis */
  background:
    repeating-linear-gradient(0deg, rgba(120, 90, 40, .07) 0 1px, transparent 1px 28px),
    repeating-linear-gradient(90deg, rgba(120, 90, 40, .07) 0 1px, transparent 1px 28px),
    var(--parch);
}
.modal .sheet-body { border-bottom: 2.5px dashed var(--rope-d); }
.modal .sheet-body { border-radius: 0 0 20px 20px; padding: 10px 16px 16px; }
.sec-title { display: flex; align-items: center; gap: 8px; margin: 6px 0 0; font-family: var(--font-game); font-weight: 600; font-size: 14px; color: var(--wood-d); }
.sec-title::before, .sec-title::after { content: ''; flex: 1; height: 2px; border-radius: 2px; background: linear-gradient(90deg, transparent, var(--slot-bd)); }
.sec-title::after { background: linear-gradient(90deg, var(--slot-bd), transparent); }

/* Slot ikon item */
.ic {
  position: relative; width: 60px; height: 60px; flex: 0 0 60px; display: grid; place-items: center; font-size: 30px; color: var(--wood-d);
  border-radius: 18px; padding: 5px; background: radial-gradient(circle at 50% 40%, #fff 0%, color-mix(in srgb, var(--c, #fde68a) 30%, #fff8e6) 75%);
  box-shadow: inset 0 0 0 2px var(--slot-bd), inset 0 -4px 0 rgba(200, 150, 80, .25), 0 2px 0 rgba(160, 110, 50, .25);
}
.ic.sm { width: 42px; height: 42px; flex-basis: 42px; border-radius: 13px; padding: 3px; font-size: 22px; }
.qty {
  position: absolute; right: -4px; bottom: -4px; font-style: normal; font-family: var(--font-game); font-weight: 700; font-size: 12px; color: #fff;
  background: var(--wood-d); border-radius: 999px; padding: 0 6px 1px; box-shadow: 0 0 0 2px var(--parch);
}

/* Kartu (bibit, barang, aksi) */
.card {
  display: flex; align-items: center; gap: 12px; padding: 9px 10px; border-radius: 20px; background: var(--slot);
  box-shadow: 0 0 0 2px var(--slot-bd), 0 4px 0 #e2bd7c, 0 6px 10px rgba(120, 80, 30, .12);
}
.card.locked { filter: grayscale(.55); opacity: .75; }
.card .info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; align-items: flex-start; }
.card .info b { font-family: var(--font-game); font-weight: 600; font-size: 16px; line-height: 1.1; }
.chips { display: flex; flex-wrap: wrap; gap: 4px; }
.chip { font-size: 10.5px; font-weight: 800; border-radius: 999px; padding: 1px 7px; background: #f3e3c3; color: #6b4423; display: inline-flex; align-items: center; gap: 3px; white-space: nowrap; }
.chip.s { background: #dbeefc; color: #1e5f8f; } .chip.g { background: #dcf7cf; color: #2f6d16; } .chip.y { background: #ffefb8; color: #7c4a03; } .chip.p { background: #ffe0e0; color: #9f1d22; }
.price {
  display: inline-flex; align-items: center; gap: 3px; font-family: var(--font-game); font-weight: 600; font-size: 14px; color: #7c4a03;
  font-variant-numeric: tabular-nums; white-space: nowrap;
}
.price img { width: 20px; height: 20px; }
.stock { font-size: 11.5px; font-weight: 800; color: var(--g2); }
.card .act { display: flex; flex-direction: column; gap: 5px; flex: 0 0 auto; }
.card .act .nb-btn { padding: 8px 14px 9px; font-size: 14px; }
.lock-badge {
  display: inline-flex; align-items: center; gap: 2px; font-family: var(--font-game); font-weight: 600; font-size: 13px; color: #6b4423;
  background: #f3e3c3; border-radius: 999px; padding: 2px 9px 2px 3px;
}
.lock-badge img { width: 22px; height: 22px; }
.empty-note { text-align: center; padding: 18px 10px; color: var(--muted); font-size: 14px; border-radius: 18px; background: var(--slot); box-shadow: inset 0 0 0 2px dashed var(--slot-bd); border: 2px dashed var(--slot-bd); }

/* Toko: kisi barang */
.shop-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
.shop-item {
  position: relative; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 4px; padding: 12px 8px 10px;
  border-radius: 20px; background: var(--slot); box-shadow: 0 0 0 2px var(--slot-bd), 0 4px 0 #e2bd7c, 0 6px 10px rgba(120, 80, 30, .12);
}
.shop-item.locked { filter: grayscale(.55); opacity: .75; }
.shop-item .ic { width: 74px; height: 74px; flex-basis: 74px; border-radius: 50%; }
.shop-item b { font-family: var(--font-game); font-weight: 600; font-size: 15px; line-height: 1.1; }
.shop-item small { font-size: 11px; color: var(--muted); font-weight: 800; display: flex; align-items: center; gap: 3px; }
.shop-item .nb-btn { width: 100%; margin-top: 4px; padding: 7px 8px 8px; font-size: 13.5px; }
.own { position: absolute; top: 8px; right: 8px; font-style: normal; font-size: 10px; font-weight: 800; color: #fff; background: var(--g2); border-radius: 999px; padding: 1px 7px; }

/* Lumbung */
.cap { border-radius: 18px; background: var(--slot); padding: 10px 12px 12px; box-shadow: 0 0 0 2px var(--slot-bd); }
.cap-row { display: flex; justify-content: space-between; align-items: baseline; font-size: 13px; margin-bottom: 7px; }
.cap-row b { font-family: var(--font-game); font-weight: 600; font-size: 16px; }
.cap-bar { height: 16px; border-radius: 999px; background: rgba(120, 80, 30, .18); overflow: hidden; box-shadow: inset 0 2px 3px rgba(80, 50, 20, .25); }
.cap-bar div { height: 100%; border-radius: 999px; background: linear-gradient(180deg, #a6f08a, var(--g1) 55%, var(--g2)); }
.cap-bar.full div { background: linear-gradient(180deg, #ffb0b0, var(--r1) 55%, var(--r2)); }
.grid3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.tile { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 6px; }
.tile .ic { width: 72px; height: 72px; flex-basis: 72px; }
.tile small { font-size: 11px; color: var(--ink); font-weight: 800; line-height: 1.2; }
.mini-price { color: var(--g2); }

/* Pasar */
.row { display: flex; align-items: center; gap: 10px; border-radius: 18px; padding: 8px 12px 8px 8px; background: var(--slot); box-shadow: 0 0 0 2px var(--slot-bd); font-size: 14px; }
.row .grow { flex: 1; min-width: 0; }
.row .grow b { font-family: var(--font-game); font-weight: 600; }
.row small { display: block; font-size: 11px; color: var(--muted); }
.row > b { font-family: var(--font-game); font-weight: 600; }
.total {
  display: flex; align-items: center; gap: 10px; border-radius: 20px; padding: 8px 16px 8px 8px; color: #fff; font-size: 15px;
  background: linear-gradient(180deg, #8ee86a, var(--g1) 50%, #52b52e); box-shadow: 0 4px 0 var(--g2), inset 0 2px 0 rgba(255, 255, 255, .45);
  text-shadow: 0 1px 0 var(--g2);
}
.total > span:nth-child(2) { flex: 1; font-weight: 800; }
.total b { font-family: var(--font-game); font-size: 24px; font-weight: 700; }

/* Kartu aksi petak */
.plot-hero { display: flex; align-items: center; gap: 12px; }
.plot-hero .ic { width: 70px; height: 70px; flex-basis: 70px; }
.plot-hero b { font-family: var(--font-game); font-weight: 600; font-size: 18px; }
.prog { margin-top: 6px; height: 22px; border-radius: 999px; background: rgba(120, 80, 30, .2); overflow: hidden; position: relative; box-shadow: inset 0 2px 3px rgba(80, 50, 20, .3); }
.prog div { height: 100%; border-radius: 999px; background: linear-gradient(180deg, #a6f08a, var(--g1) 55%, var(--g2)); }
.prog span { position: absolute; inset: 0; display: grid; place-items: center; font-family: var(--font-game); font-size: 12.5px; font-weight: 600; color: #fff; text-shadow: 0 1px 2px rgba(0, 0, 0, .5); font-variant-numeric: tabular-nums; }
.note { font-size: 12px; color: var(--muted); font-weight: 700; line-height: 1.4; }
.big-cost {
  display: flex; align-items: center; justify-content: center; gap: 12px; padding: 12px; border-radius: 20px; color: #5b3405;
  background: linear-gradient(180deg, #fff3b0, var(--y1)); box-shadow: 0 4px 0 var(--y2), inset 0 2px 0 rgba(255, 255, 255, .6);
}
.big-cost .ic { width: 56px; height: 56px; flex-basis: 56px; background: rgba(255, 255, 255, .55); box-shadow: none; }
.big-cost small { display: block; font-size: 12px; font-weight: 800; }
.big-cost b { font-family: var(--font-game); font-size: 28px; font-weight: 700; }

/* Toast */
#toasts { position: fixed; z-index: 80; top: calc(var(--safe-t) + 110px); left: 50%; transform: translateX(-50%); display: flex; flex-direction: column; gap: 6px; align-items: center; pointer-events: none; width: min(420px, calc(100% - 24px)); }
.toast {
  background: rgba(10, 34, 56, .92); color: #fff; border-radius: 999px; padding: 9px 16px; box-shadow: inset 0 0 0 1.5px rgba(248, 220, 132, .45); font-size: 13.5px; display: flex; gap: 8px; align-items: center;
  box-shadow: 0 4px 14px rgba(0, 0, 0, .3); animation: tin .35s cubic-bezier(.34,1.56,.64,1); pointer-events: auto; text-shadow: 0 1px 0 rgba(0, 0, 0, .3);
}
.toast i { font-size: 18px; } .toast.ok i { color: #a6f08a; } .toast.err i { color: #ff9a9a; } .toast.gold i { color: #fde047; }
.toast.err { background: rgba(150, 30, 35, .92); } .toast.gold { background: rgba(140, 85, 5, .92); }
.toast a { color: #fde68a; text-decoration: underline; font-weight: 900; }
.toast.out { opacity: 0; transform: translateY(-8px); transition: .25s; }
@keyframes tin { from { opacity: 0; transform: translateY(-12px) scale(.9); } }

/* Loader */
#loader { position: fixed; inset: 0; z-index: 100; display: grid; place-items: center; background: radial-gradient(circle at 50% 120%, #2b9fd0, transparent 60%), linear-gradient(180deg, #5ec2ec, #cdeef5); transition: opacity .4s; }
#loader.hide { opacity: 0; pointer-events: none; }
.load-card { text-align: center; background: var(--parch); border-radius: 26px; padding: 22px 30px 20px; box-shadow: 0 0 0 7px var(--navy), 0 0 0 9px var(--brass2), 0 11px 0 9px var(--navy-d), 0 20px 40px rgba(5, 25, 45, .35); }
.load-ic { font-size: 52px; color: var(--brass2); display: inline-block; animation: compass 2.4s cubic-bezier(.5,-.4,.5,1.4) infinite; }
@keyframes compass { 0% { transform: rotate(-30deg); } 50% { transform: rotate(40deg); } 100% { transform: rotate(-30deg); } }
.load-card h1 { font-family: var(--font-game); font-size: 24px; font-weight: 700; margin-top: 4px; }
.load-card p { font-size: 13px; color: var(--muted); }
.load-bar { margin-top: 12px; height: 12px; border-radius: 999px; background: rgba(120, 80, 30, .2); overflow: hidden; }
.load-bar i { display: block; height: 100%; width: 40%; border-radius: 999px; background: linear-gradient(180deg, #a6f08a, var(--g1)); animation: loadbar 1.1s ease-in-out infinite; }
@keyframes loadbar { 0% { transform: translateX(-100%); } 100% { transform: translateX(260%); } }

@media (max-width: 430px) { .lvl-row small { display: none; } }
@media (max-width: 380px) {
  .wallet { min-width: 112px; } .wal-tx b { font-size: 13.5px; }
  .lvl-row small { display: none; }
  .dock-ic { width: 50px; height: 46px; } .dock-ic img { width: 42px; height: 42px; }
  .shop-item .ic { width: 64px; height: 64px; flex-basis: 64px; }
}
@media (prefers-reduced-motion: reduce) { .plot-tag.is-pest .pt-ring, .load-ic, .load-bar i, .lvl-star::before { animation: none; } }
</style>
</head>
<body>

<div id="loader"><div class="load-card"><span class="load-ic"><i class="ph-fill ph-compass"></i></span><h1>Sawah Panen Cuan</h1><p>Berlayar menuju pulau&hellip;</p><div class="load-bar"><i></i></div></div></div>

<div id="farm-stage" aria-label="Sawah 3D"></div>
<div id="farm-labels"></div>

<!-- ── TOP BAR ── -->
<header class="topbar">
  <div class="top-left">
    <a class="icon-btn" href="/home" aria-label="Kembali ke Home"><i class="ph-bold ph-arrow-left"></i></a>
    <button class="icon-btn" id="btn-mute" type="button" aria-label="Suara"><i class="ph-bold ph-speaker-high"></i></button>
    <button class="icon-btn gift-btn" id="btn-gift" type="button" aria-label="Hadiah bibit harian"><img src="/assets/img/icons3d/chest.png" alt="" draggable="false"><span class="dot"></span><span class="cd" id="gift-cd" hidden></span></button>
  </div>
  <div class="lvl">
    <span class="lvl-star" aria-hidden="true"><b id="lvl-num">1</b></span>
    <div class="lvl-info">
      <div class="lvl-row"><span class="lvl-name">Juragan</span><small id="xp-txt">0/50 XP</small></div>
      <div class="xp"><div class="xp-fill" id="xp-fill"></div></div>
    </div>
  </div>
  <div class="wallets">
    <div class="wallet dep" id="wal-dep"><span class="wal-ic" data-icon="coins"></span><div class="wal-tx"><small>Saldo Modal</small><b id="bal-dep">Rp 0</b></div><a class="wal-add" href="/deposit" aria-label="Top up Saldo Modal"><i class="ph-bold ph-plus"></i></a></div>
    <div class="wallet wd" id="wal-wd"><span class="wal-ic" data-icon="basket"></span><div class="wal-tx"><small>Keuntungan</small><b id="bal-wd">Rp 0</b></div></div>
  </div>
</header>

<!-- ── DOCK ── -->
<nav class="dock">
  <button class="dock-btn t-green" type="button" data-open="seeds"><span class="dock-ic" data-icon="seed"><i class="ph-fill ph-plant"></i></span><span class="dock-lbl">Tanam</span></button>
  <button class="dock-btn t-sky" type="button" data-open="shop"><span class="dock-ic" data-icon="shop"><i class="ph-fill ph-storefront"></i></span><span class="dock-lbl">Toko</span></button>
  <button class="dock-btn t-gold" type="button" data-open="barn" id="dock-barn"><span class="dock-ic" data-icon="barn"><i class="ph-fill ph-warehouse"></i></span><span class="dock-lbl">Lumbung</span><span class="dock-chip" id="barn-chip">0/30</span></button>
  <button class="dock-btn t-red" type="button" data-open="market"><span class="dock-ic"><i class="ph-fill ph-sailboat"></i></span><span class="dock-lbl">Kapal Dagang</span></button>
</nav>

<!-- Aksi cepat: muncul bila ada tanaman siap panen -->
<div class="quick">
  <button class="nb-btn yellow" id="btn-harvest-all" type="button" hidden><i class="ph-fill ph-basket"></i>Panen Semua<b id="ha-n">0</b></button>
</div>

<div class="backdrop" id="backdrop"></div>

<!-- Sheet: Pilih Bibit -->
<section class="sheet t-green" id="sheet-seeds" aria-label="Pilih Bibit">
  <div class="sheet-head">
    <span class="hic" data-icon="seed"></span>
    <h3 class="ribbon">Pilih Bibit</h3>
    <p id="seeds-target">Petak #1</p>
    <button class="close" type="button" data-close aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="sheet-body" id="seeds-body"></div>
</section>

<!-- Sheet: Toko Tani -->
<section class="sheet t-gold" id="sheet-shop" aria-label="Toko Pelabuhan">
  <div class="sheet-head">
    <span class="hic" data-icon="shop"></span>
    <h3 class="ribbon">Toko Pelabuhan</h3>
    <p>Bayar pakai Saldo Modal</p>
    <button class="close" type="button" data-close aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="sheet-body" id="shop-body"></div>
</section>

<!-- Sheet: Lumbung -->
<section class="sheet t-gold" id="sheet-barn" aria-label="Lumbung">
  <div class="sheet-head">
    <span class="hic" data-icon="barn"></span>
    <h3 class="ribbon">Lumbung</h3>
    <p>Hasil panen, bibit &amp; perlengkapan</p>
    <button class="close" type="button" data-close aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="sheet-body" id="barn-body"></div>
</section>

<!-- Sheet: Pasar Pengepul -->
<section class="sheet t-sky" id="sheet-market" aria-label="Kapal Dagang">
  <div class="sheet-head">
    <span class="hic" data-icon="coins"></span>
    <h3 class="ribbon">Kapal Dagang</h3>
    <p>Muat hasil panen ke kapal, terima Keuntungan</p>
    <button class="close" type="button" data-close aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="sheet-body" id="market-body"></div>
</section>

<!-- Modal: Buka Petak -->
<section class="modal t-gold" id="modal-unlock" aria-label="Buka Petak Lahan">
  <div class="sheet-head">
    <span class="hic" data-icon="lock"></span>
    <h3 class="ribbon">Buka Petak</h3>
    <p id="unlock-sub">Petak #5</p>
    <button class="close" type="button" data-close aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="sheet-body" id="unlock-body"></div>
</section>

<!-- Modal: Aksi petak (Pupuk Kilat / Pestisida) -->
<section class="modal t-sky" id="modal-plot" aria-label="Aksi Petak">
  <div class="sheet-head">
    <span class="hic" data-icon="speed"></span>
    <h3 class="ribbon" id="plot-title">Pupuk Kilat</h3>
    <p id="plot-sub">Petak #1</p>
    <button class="close" type="button" data-close aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="sheet-body" id="plot-body"></div>
</section>

<!-- Modal: Hadiah bibit harian -->
<section class="modal t-gold" id="modal-gift" aria-label="Hadiah Bibit Harian">
  <div class="sheet-head">
    <span class="hic" data-icon="seed"></span>
    <h3 class="ribbon">Hadiah Harian</h3>
    <p id="gift-sub">Bibit gratis setiap hari</p>
    <button class="close" type="button" data-close aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="sheet-body" id="gift-body"></div>
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
  let plantAll = false;         // mode tanam: true = semua petak kosong sekaligus
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
    const w = el.closest('.wallet'); w.classList.remove('bump'); void w.offsetWidth; w.classList.add('bump');
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
    if (typeof renderGiftBtn === 'function') renderGiftBtn();
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
    if (reason === 'no_balance') return toast('', 'err', 'ph-wallet', esc(res.msg) + ' <a href="/deposit" style="pointer-events:auto">Deposit</a>');
    if (reason === 'barn_full') { toast(res.msg, 'err', 'ph-warehouse'); return openLayer('market'); }
    toast((res && res.msg) || 'Terjadi kesalahan.', 'err');
  }

  /* ── Sheet & modal ───────────────────────────────────── */
  const layers = { seeds: 'sheet-seeds', shop: 'sheet-shop', barn: 'sheet-barn', market: 'sheet-market', unlock: 'modal-unlock', plot: 'modal-plot', gift: 'modal-gift' };
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
    clearInterval(plotTimer); plotTimer = null; plotCardIdx = null; giftResult = null;
    if (!silent) { $('backdrop').classList.remove('show'); engine.setFrozen(false); }
  }
  function rerenderOpen() {
    const n = openEl && openEl.dataset.name;
    ({ seeds: renderSeeds, shop: renderShop, barn: renderBarn, market: renderMarket, unlock: renderUnlock, plot: renderPlotCard, gift: renderGift })[n]?.();
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
  $('btn-gift').addEventListener('click', () => { audio.unlock(); audio.soundTick(); openLayer('gift'); });

  /* ── Tap petak 3D ────────────────────────────────────── */
  function onPlotTap(plot) {
    const idx = plot.plotIndex;
    if (pending.has(idx)) return;
    audio.soundTick();
    switch (plot.state) {
      case 'LOCKED': plotCardIdx = idx; return openLayer('unlock');
      case 'EMPTY': seedTarget = idx; plantAll = false; return openLayer('seeds');
      case 'READY': if (!plot.pest) return harvest(idx); // eslint-disable-line no-fallthrough
      default: plotCardIdx = idx; openLayer('plot');
    }
  }

  /* ── Ikon 3D (dirender engine) dengan cadangan ikon Phosphor ── */
  const ITEM_FALLBACK = { speed: 'ph-lightning', cure: 'ph-flask', barn: 'ph-warehouse' };
  const img = (key, fb) => U.iconHtml(engine.icon(key), fb || 'ph-cube');
  const cropImg = (c) => img('crop:' + c.id, iconOf(c));
  const itemImg = (it) => img(it.effect, ITEM_FALLBACK[it.effect]);
  const price = (n) => `<span class="price">${img('coins', 'ph-coins')}${fmtRp(n).replace('Rp ', '')}</span>`;
  /** Pasang ikon 3D ke elemen statis ber-atribut data-icon (dock, dompet, header sheet). */
  function fillIcons(root = document) {
    root.querySelectorAll('[data-icon]').forEach((el) => {
      const url = engine.icon(el.dataset.icon);
      if (!url || el.querySelector(':scope > img')) return;
      el.querySelectorAll(':scope > i').forEach((i) => i.remove());
      el.insertAdjacentHTML('afterbegin', '<img src="' + url + '" alt="" draggable="false">');
    });
  }
  fillIcons();

  /* ── Render: Pilih Bibit ─────────────────────────────── */
  function firstEmpty() { const p = engine.plots.find((p) => p.state === 'EMPTY' && !pending.has(p.plotIndex)); return p ? p.plotIndex : null; }
  function emptyPlots() { return engine.plots.filter((p) => p.state === 'EMPTY' && !pending.has(p.plotIndex)).map((p) => p.plotIndex); }
  function renderSeeds() {
    const target = seedTarget || firstEmpty(), empties = emptyPlots();
    const many = plantAll && empties.length > 1, n = many ? empties.length : 1;
    $('seeds-target').textContent = many ? 'Tanam di ' + n + ' petak kosong sekaligus' : target ? 'Tanam di Petak #' + target : 'Semua petak sedang terisi';
    const seg = empties.length > 1 ? `<div class="seg" role="group" aria-label="Mode tanam">
        <button type="button" data-mode="one" class="${many ? '' : 'on'}">1 Petak</button>
        <button type="button" data-mode="all" class="${many ? 'on' : ''}">Semua Kosong (${empties.length})</button></div>` : '';
    $('seeds-body').innerHTML = seg + S.crops.filter((c) => c.is_active !== 0).map((c) => {
      const lock = S.level.level < c.min_level, stock = qty('seeds', c.id), buyN = Math.max(0, n - stock);
      const pMin = c.yield_min * c.unit_price - c.seed_price, pMax = c.yield_max * c.unit_price - c.seed_price;
      const cost = buyN === 0 ? '<span class="stock">Pakai stok kantong</span>'
        : many ? `${price(buyN * c.seed_price)} <span class="note">${buyN} bibit dibeli${stock > 0 ? ', ' + Math.min(stock, n) + ' dari kantong' : ''}</span>` : price(c.seed_price);
      return `<div class="card ${lock ? 'locked' : ''}" style="--c:${esc(c.color)}">
        <span class="ic">${cropImg(c)}${stock > 0 ? `<em class="qty">×${stock}</em>` : ''}</span>
        <div class="info"><b>${esc(c.name)}</b>
          <div class="chips">
            <span class="chip s"><i class="ph-bold ph-timer"></i>${U.fmtTime(c.growth_secs)}</span>
            <span class="chip g"><i class="ph-bold ph-trend-up"></i>${fmtRp(pMin)}–${U.fmtShort(pMax).replace('Rp ', '')}</span>
          </div>
          ${cost}
        </div>
        <div class="act">${lock ? `<span class="lock-badge">${img('lock', 'ph-lock')}Lv ${c.min_level}</span>`
          : `<button class="nb-btn green" type="button" data-plant="${c.id}" ${target ? '' : 'disabled'}>Tanam${many ? ' ×' + n : ''}</button>`}</div>
      </div>`;
    }).join('') + `<p class="note">Bibit di kantong dipakai lebih dulu. Bila kosong, bibit otomatis dibeli dengan Saldo Modal (1 per petak). Untung = hasil panen × harga pasar − harga bibit.</p>`;
    $('seeds-body').querySelectorAll('[data-mode]').forEach((b) => b.addEventListener('click', () => { plantAll = b.dataset.mode === 'all'; audio.soundTick(); renderSeeds(); }));
    $('seeds-body').querySelectorAll('[data-plant]').forEach((b) => b.addEventListener('click', () => (many ? plantMany(empties, +b.dataset.plant) : plant(target, +b.dataset.plant))));
  }

  /* ── Render: Toko Tani ───────────────────────────────── */
  function renderShop() {
    $('shop-body').innerHTML = `<div class="sec-title"><span>Bibit Unggul</span></div><div class="shop-grid">` +
      S.crops.filter((c) => c.is_active !== 0).map((c) => {
        const lock = S.level.level < c.min_level, own = qty('seeds', c.id);
        return `<div class="shop-item ${lock ? 'locked' : ''}" style="--c:${esc(c.color)}">
          ${own > 0 ? `<em class="own">Punya ${own}</em>` : ''}
          <span class="ic">${cropImg(c)}</span>
          <b>${esc(c.name)}</b>
          <small>${price(c.seed_price)} / bibit</small>
          ${lock ? `<span class="lock-badge">${img('lock', 'ph-lock')}Lv ${c.min_level}</span>`
            : `<button class="nb-btn yellow" type="button" data-buy="seed:${c.id}:5">Beli ×5 · ${U.fmtShort(c.seed_price * 5).replace('Rp ', '')}</button>`}
        </div>`;
      }).join('') + `</div>` +
      `<div class="sec-title"><span>Perlengkapan</span></div>` +
      S.items.map((it) => `<div class="card">
          <span class="ic">${itemImg(it)}${it.effect !== 'barn' && qty('items', it.id) > 0 ? `<em class="qty">×${qty('items', it.id)}</em>` : ''}</span>
          <div class="info"><b>${esc(it.name)}</b><span class="note">${esc(it.description)}</span>
            ${price(it.price)}${it.effect === 'barn' ? ` <span class="chip">Kapasitas ${S.barn_capacity}</span>` : ''}</div>
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
    const tile = (icon, n, name, sub = '') => `<div class="tile"><span class="ic">${icon}<em class="qty">×${n}</em></span><small>${esc(name)}${sub}</small></div>`;
    $('barn-body').innerHTML = `
      <div class="cap"><div class="cap-row"><span>Kapasitas Lumbung</span><b>${S.barn_used}/${S.barn_capacity}</b></div>
        <div class="cap-bar ${full ? 'full' : ''}"><div style="width:${pct}%"></div></div></div>
      <div class="sec-title"><span>Hasil Panen</span></div>
      ${harvest.length ? `<div class="grid3">${harvest.map((c) => tile(cropImg(c), qty('harvest', c.id), c.name, `<br><span class="mini-price">≈ ${fmtRp(qty('harvest', c.id) * c.unit_price)}</span>`)).join('')}</div>`
        : '<div class="empty-note">Lumbung masih kosong. Tanam &amp; panen dulu ya!</div>'}
      ${seeds.length ? `<div class="sec-title"><span>Kantong Bibit</span></div><div class="grid3">${seeds.map((c) => tile(img('seed', 'ph-plant'), qty('seeds', c.id), c.name)).join('')}</div>` : ''}
      ${items.length ? `<div class="sec-title"><span>Perlengkapan</span></div><div class="grid3">${items.map((i) => tile(itemImg(i), qty('items', i.id), i.name)).join('')}</div>` : ''}
      <button class="nb-btn pink block" type="button" id="barn-to-market" ${harvest.length ? '' : 'disabled'}>${img('coins', 'ph-coins')} Muat ke Kapal Dagang</button>`;
    $('barn-to-market').addEventListener('click', () => openLayer('market'));
  }

  /* ── Render: Pasar Pengepul ──────────────────────────── */
  function renderMarket() {
    const rows = S.crops.filter((c) => qty('harvest', c.id) > 0);
    const total = rows.reduce((s, c) => s + qty('harvest', c.id) * c.unit_price, 0);
    $('market-body').innerHTML = (rows.length ? rows.map((c) => `<div class="row"><span class="ic sm">${cropImg(c)}</span>
        <div class="grow"><b>${esc(c.name)}</b> ×${qty('harvest', c.id)}<small>${fmtRp(c.unit_price)} / hasil</small></div>${price(qty('harvest', c.id) * c.unit_price)}</div>`).join('')
      : '<div class="empty-note">Belum ada hasil panen untuk dijual.</div>') +
      `<div class="total"><span class="ic sm">${img('coins', 'ph-coins')}</span><span>Total diterima</span><b>${fmtRp(total)}</b></div>
       <button class="nb-btn yellow block" type="button" id="sell-all" ${rows.length ? '' : 'disabled'}>MUAT &amp; JUAL SEMUA</button>
       <p class="note">Uang hasil penjualan langsung masuk ke Keuntungan dan bisa ditarik.</p>`;
    $('sell-all').addEventListener('click', sellAll);
  }

  /* ── Render: Buka Petak ──────────────────────────────── */
  function renderUnlock() {
    const cost = S.next_plot_cost, enough = S.balance_dep >= cost;
    $('unlock-sub').textContent = 'Petak #' + plotCardIdx;
    $('unlock-body').innerHTML = `
      <div class="big-cost"><span class="ic">${img('lock', 'ph-lock-key')}</span><div><small>Biaya buka lahan</small><b>${fmtRp(cost)}</b></div></div>
      <div class="row"><span class="ic sm">${img('coins', 'ph-coins')}</span><div class="grow">Saldo Modal kamu</div><b>${fmtRp(S.balance_dep)}</b></div>
      <p class="note">Lahan baru dibersihkan dari rumput liar &amp; batu, siap ditanami. Harga petak berikutnya naik bertahap.</p>
      ${enough ? `<button class="nb-btn green block" type="button" id="do-unlock">Buka Petak</button>`
        : `<a class="nb-btn yellow block" href="/deposit" style="text-decoration:none">Deposit Saldo Modal</a>`}`;
    if (enough) $('do-unlock').addEventListener('click', () => unlock(plotCardIdx));
  }

  /* ── Hadiah bibit harian ─────────────────────────────── */
  var giftResult = null, giftBusy = false; // var: dipakai closeLayer yang dideklarasikan lebih awal
  const RARITY = { biasa: 'Biasa', langka: 'Langka', super: 'Super Langka' };
  function giftLeft() { return Math.max(0, ((S.daily_gift && S.daily_gift.next_at) || 0) - engine.serverNow()); }
  function hms(sec) { sec = Math.floor(sec); return [Math.floor(sec / 3600), Math.floor(sec / 60) % 60, sec % 60].map((n) => String(n).padStart(2, '0')).join(':'); }
  function renderGiftBtn() {
    const g = S.daily_gift || {}, btn = $('btn-gift'), cd = $('gift-cd');
    btn.hidden = !g.enabled;
    btn.classList.toggle('ready', !!g.available);
    cd.hidden = !!g.available || !g.enabled;
    if (!g.available) {
      const left = giftLeft();
      cd.textContent = left >= 3600 ? Math.floor(left / 3600) + 'j ' + (Math.floor(left / 60) % 60) + 'm' : hms(left).slice(3);
      if (left <= 0 && !giftBusy && g.enabled) { giftBusy = true; resync().finally(() => { giftBusy = false; }); }
    }
  }
  function renderGift() {
    const g = S.daily_gift || {}, body = $('gift-body');
    if (giftResult) {
      const c = cropById(giftResult.crop_id) || {};
      $('gift-sub').textContent = 'Selamat! Bibit masuk kantong';
      body.innerHTML = `
        <div class="gift-stage open" style="--c:${esc(c.color || '#6dd047')}"><span class="gift-rays"></span>
          <img class="gift-box" src="/assets/img/icons3d/chest_open.png" alt="">
          <span class="gift-prize">${cropImg(c)}<em>×${giftResult.qty}</em></span></div>
        <div class="gift-name">${giftResult.qty} Bibit ${esc(c.name || '')}</div>
        <span class="gift-rar ${giftResult.rarity}">${RARITY[giftResult.rarity] || ''}</span>
        <p class="note">Bibit gratis dipakai lebih dulu saat menanam. Kembali besok untuk hadiah berikutnya!</p>
        <button class="nb-btn green block" type="button" id="gift-plant">Tanam Sekarang</button>`;
      $('gift-plant').addEventListener('click', () => { giftResult = null; seedTarget = null; openLayer('seeds'); });
      return;
    }
    if (g.available) {
      $('gift-sub').textContent = 'Bibit gratis setiap hari';
      body.innerHTML = `
        <div class="gift-stage idle" id="gift-stage"><span class="gift-rays"></span>
          <img class="gift-box" id="gift-box" src="/assets/img/icons3d/chest.png" alt="Peti hadiah"><span class="gift-prize"></span></div>
        <p class="note" style="text-align:center">Buka peti untuk dapat bibit acak. Bisa dapat bibit langka!</p>
        <button class="nb-btn yellow block" type="button" id="gift-open">Buka Hadiah</button>`;
      $('gift-open').addEventListener('click', claimGift);
      $('gift-box').addEventListener('click', claimGift);
      return;
    }
    $('gift-sub').textContent = 'Sudah diambil hari ini';
    body.innerHTML = `
      <div class="gift-stage idle"><span class="gift-rays"></span><img class="gift-box" src="/assets/img/icons3d/chest_open.png" alt="" style="filter:grayscale(.5) drop-shadow(0 10px 10px rgba(90,50,10,.3))"></div>
      <div class="gift-cd"><small>Hadiah berikutnya dalam</small><span id="gift-left">${hms(giftLeft())}</span></div>
      <p class="note" style="text-align:center">Hadiah baru tersedia setiap hari mulai pukul 00:00.</p>
      <button class="nb-btn green block" type="button" data-close-gift>Oke</button>`;
    body.querySelector('[data-close-gift]').addEventListener('click', () => closeLayer());
    clearInterval(plotTimer);
    plotTimer = setInterval(() => { const el = $('gift-left'); if (el) el.textContent = hms(giftLeft()); }, 1000);
  }
  async function claimGift() {
    if (giftBusy) return;
    giftBusy = true;
    const stage = $('gift-stage'), btn = $('gift-open');
    if (btn) { btn.disabled = true; btn.textContent = 'Membuka...'; }
    if (stage) { stage.classList.remove('idle'); stage.classList.add('shake'); }
    audio.unlock(); audio.soundSwoosh();
    const [res] = await Promise.all([api.call('claim_gift'), new Promise((r) => setTimeout(r, 900))]);
    giftBusy = false;
    if (!res.ok) { if (res.state) setState(res.state); renderGift(); return fail(res); }
    giftResult = res.gift;
    S = res.state; engine.applyState(res.state); renderHUD();
    renderGift();
    audio.soundUnlock(); setTimeout(() => audio.soundHarvest(), 350);
    audio.vibrate([30, 40, 60]);
    engine.celebrate();
  }

  /* ── Render: Kartu aksi petak (Pupuk Kilat / Pestisida) ─ */
  function renderPlotCard() {
    const p = engine.plots[plotCardIdx - 1];
    if (!p || !['GROWING', 'WITHERED', 'READY'].includes(p.state)) { closeLayer(); return; }
    const crop = p.cropData || {}, speed = itemByEffect('speed'), cure = itemByEffect('cure');
    const stockS = speed ? qty('items', speed.id) : 0, stockC = cure ? qty('items', cure.id) : 0;
    $('plot-title').textContent = p.pest ? 'Serangan Hama!' : 'Pupuk Kilat';
    $('plot-sub').textContent = 'Petak #' + p.plotIndex + ' · ' + (crop.name || '');
    openEl.classList.toggle('t-red', !!p.pest);
    openEl.classList.toggle('t-sky', !p.pest);
    openEl.querySelector('.hic').innerHTML = img(p.pest ? 'cure' : 'speed', p.pest ? 'ph-bug' : 'ph-lightning');
    const ready = p.state === 'READY';
    $('plot-body').innerHTML = `
      <div class="plot-hero"><span class="ic">${crop.id ? cropImg(crop) : img('seed', 'ph-plant')}</span>
        <div style="flex:1"><b>${esc(crop.name || 'Tanaman')}</b>
          <div class="prog"><div id="pc-bar"></div><span id="pc-time"></span></div></div></div>
      ${p.pest ? `<div class="card">${cure ? `<span class="ic">${itemImg(cure)}${stockC > 0 ? `<em class="qty">×${stockC}</em>` : ''}</span>` : ''}
          <div class="info"><b>${esc(cure ? cure.name : 'Pestisida')}</b><span class="note">Ulat memakan daun! Tanpa dibasmi hasil panen tinggal separuh.</span></div>
          <div class="act"><button class="nb-btn pink" type="button" id="do-cure" ${cure ? '' : 'disabled'}>${stockC > 0 ? 'Pakai' : (cure ? U.fmtShort(cure.price) : '-')}</button></div></div>` : ''}
      ${!ready ? `<div class="card">${speed ? `<span class="ic">${itemImg(speed)}${stockS > 0 ? `<em class="qty">×${stockS}</em>` : ''}</span>` : ''}
          <div class="info"><b>${esc(speed ? speed.name : 'Pupuk Kilat')}</b><span class="note">Siram pupuk ajaib, tanaman langsung siap panen.</span></div>
          <div class="act"><button class="nb-btn sky" type="button" id="do-speed" ${speed ? '' : 'disabled'}>${stockS > 0 ? 'Pakai' : (speed ? U.fmtShort(speed.price) : '-')}</button></div></div>` : ''}
      ${ready && p.pest ? `<button class="nb-btn yellow block" type="button" id="do-harvest">Panen Saja (hasil 50%)</button>` : ''}
      <p class="note">${stockS > 0 || stockC > 0 ? 'Memakai stok dari Lumbung lebih dulu.' : 'Stok kosong: item dibeli otomatis dengan Saldo Modal.'}</p>`;
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

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  /** Tanam di satu petak; resolve dengan respon server (null bila petak sedang sibuk). */
  function plantOne(idx, cropId) {
    let out = null;
    return guarded(idx, async () => {
      const res = out = await api.call('plant', { plot_index: idx, crop_id: cropId });
      if (!res.ok) return fail(res);
      setState(res.state);
      engine.playPlant(idx);
    }).then(() => out);
  }

  function plant(idx, cropId) {
    if (!idx) return;
    closeLayer();
    plantOne(idx, cropId).then((res) => { if (res && res.ok && res.paid > 0) toast('1 bibit dibeli ' + fmtRp(res.paid), 'ok', 'ph-plant'); });
  }

  /** Tanam bibit yang sama di beberapa petak kosong, berurutan (aturan & biaya per petak tetap sama). Berhenti di kegagalan pertama. */
  async function plantMany(list, cropId) {
    closeLayer();
    let paid = 0, done = 0;
    for (const idx of list) {
      const res = await plantOne(idx, cropId);
      if (!res || !res.ok) break;
      paid += +res.paid || 0; done++;
      await sleep(140);
    }
    if (done) toast(done + ' petak ditanami' + (paid > 0 ? ' · bibit dibeli ' + fmtRp(paid) : ''), 'ok', 'ph-plant');
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

  /** Panen satu petak; resolve dengan respon server (null bila petak sedang sibuk). */
  function harvest(idx) {
    let out = null;
    return guarded(idx, () => new Promise((resolve) => {
      const req = api.call('harvest', { plot_index: idx });
      engine.playHarvest(idx, req, (res) => {
        out = res;
        resolve();
        if (!res || !res.ok) return fail(res);
        setState(res.state);
        if (res.level_up) {
          engine.celebrate();
          setTimeout(() => { audio.soundHarvest(); toast('Naik ke Level ' + res.level_up + '! Bibit baru mungkin sudah terbuka.', 'gold', 'ph-crown'); }, 600);
        }
      });
    })).then(() => out);
  }

  /* ── Panen Semua: panen berurutan tiap petak siap (tanpa hama); berhenti bila lumbung penuh / gagal ── */
  let harvestingAll = false;
  const readyPlots = () => engine.plots.filter((p) => p.state === 'READY' && !p.pest && !pending.has(p.plotIndex));
  function updateQuick() {
    const btn = $('btn-harvest-all'), n = readyPlots().length;
    btn.hidden = !harvestingAll && n < 2;            // 1 petak cukup diketuk langsung
    btn.classList.toggle('busy', harvestingAll);
    if (!harvestingAll) $('ha-n').textContent = n;
  }
  async function harvestAll() {
    if (harvestingAll) return;
    harvestingAll = true; updateQuick();
    audio.unlock();
    for (const p of readyPlots()) {
      if (S.barn_used >= S.barn_capacity) { toast('Lumbung penuh! Muat dulu ke Kapal Dagang.', 'err', 'ph-warehouse'); openLayer('market'); break; }
      const res = await harvest(p.plotIndex);
      if (!res || !res.ok) break;
      $('ha-n').textContent = readyPlots().length;
      await sleep(160);
    }
    harvestingAll = false; updateQuick();
  }
  $('btn-harvest-all').addEventListener('click', harvestAll);
  setInterval(updateQuick, 500);

  async function sellAll() {
    const btn = $('sell-all'); if (btn) btn.disabled = true;
    const res = await api.call('sell_all');
    if (!res.ok) { if (btn) btn.disabled = false; return fail(res); }
    closeLayer();
    audio.soundCoin(0.3);
    const lr = $('farm-labels').getBoundingClientRect(), br = $('dock-barn').getBoundingClientRect();
    const from = { x: br.left + br.width / 2 - lr.left, y: br.top - lr.top };
    engine.flyIcons(from, $('wal-wd'), img('coins', 'ph-coins'), 8, (k, last) => {
      audio.tone(1000 + k * 60, 0.06, 'triangle', 0.12);
      if (last) { setState(res.state); audio.soundHarvest(); engine.celebrate(); }
    });
    document.querySelectorAll('.fly-icon').forEach((el) => el.classList.add('coin'));
    toast(res.sold_count + ' hasil panen terjual · +' + fmtRp(res.credited) + ' Keuntungan', 'gold', 'ph-coins');
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

  renderGiftBtn();
  setInterval(renderGiftBtn, 1000);
  if (S.daily_gift && S.daily_gift.available) setTimeout(() => { if (!openEl) openLayer('gift'); }, 1400);

  window.__farm = { engine, api, audio, get state() { return S; } };
})();
</script>
</body>
</html>
