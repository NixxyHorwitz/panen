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
<meta name="theme-color" content="#4fb6f0">
<meta name="robots" content="noindex">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>Sawah Panen Cuan — <?= htmlspecialchars($_site_name) ?></title>
<link rel="icon" type="image/png" href="<?= htmlspecialchars($_fav_url) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/game-ui.css">
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1"></script>
<style>
/* ══════════════════════════════════════════════════════════
   SAWAH PANEN CUAN — UI GAYA GAME (panel kayu & perkamen,
   pita judul, slot item, tombol 3D "berbibir" yang bisa ditekan)
   ══════════════════════════════════════════════════════════ */
:root {
  --ink: #3b2414;
  --muted: #8a6a4a;
  --parch: #fff6e0;
  --parch-2: #fdebc4;
  --wood: #b8763a;
  --wood-d: #7c4a1f;
  --wood-l: #d9a066;
  --slot: #fffaf0;
  --slot-bd: #ecc98c;
  /* tombol: warna muka / warna bibir bawah */
  --g1: #6dd047; --g2: #3f9a24;
  --y1: #ffd43b; --y2: #d69a0b;
  --b1: #4cc0f7; --b2: #1f84c6;
  --r1: #ff6b6b; --r2: #c9373b;
  --font-game: 'Fredoka', 'Nunito', sans-serif;
  --safe-t: env(safe-area-inset-top, 0px);
  --safe-b: env(safe-area-inset-bottom, 0px);
}
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
html, body { height: 100%; overflow: hidden; overscroll-behavior: none; }
body {
  font-family: 'Nunito', sans-serif; font-weight: 700; color: var(--ink);
  background: linear-gradient(180deg, #4fb6f0 0%, #9ad7f5 38%, #d9efe8 72%, #e6f1df 100%);
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
.icon-btn {
  width: 42px; height: 42px; flex: 0 0 42px; display: grid; place-items: center; font-size: 20px; color: #fff;
  background: linear-gradient(180deg, var(--wood-l), var(--wood)); border-radius: 14px;
  box-shadow: 0 4px 0 var(--wood-d), 0 6px 10px rgba(60, 30, 5, .3), inset 0 2px 0 rgba(255, 255, 255, .35);
  text-shadow: 0 1px 0 rgba(0, 0, 0, .3); transition: transform .08s, box-shadow .08s;
}
.icon-btn:active { transform: translateY(3px); box-shadow: 0 1px 0 var(--wood-d), inset 0 2px 0 rgba(255, 255, 255, .3); }
.top-left { display: flex; flex-direction: column; gap: 8px; }

.lvl {
  flex: 1; min-width: 0; max-width: 230px; margin: 4px auto 0; display: flex; align-items: center;
  background: rgba(59, 36, 20, .62); border-radius: 999px; padding: 4px 12px 4px 4px; position: relative;
  box-shadow: inset 0 2px 4px rgba(0, 0, 0, .25), 0 3px 8px rgba(0, 0, 0, .2); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
}
.lvl-star { position: relative; width: 46px; height: 46px; flex: 0 0 46px; margin: -10px 4px -10px -6px; display: grid; place-items: center; }
.lvl-star img { position: absolute; inset: 0; }
.lvl-star > i { font-size: 30px; color: #fcd34d; position: absolute; }
.lvl-star b { position: relative; z-index: 1; font-family: var(--font-game); font-weight: 700; font-size: 16px; color: #7c2d12; text-shadow: 0 1px 0 rgba(255, 255, 255, .7); margin-top: 2px; }
.lvl-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.lvl-row { display: flex; align-items: baseline; justify-content: space-between; gap: 6px; }
.lvl-name { font-family: var(--font-game); font-weight: 600; font-size: 13px; color: #fff; text-shadow: 0 1px 0 rgba(0, 0, 0, .35); }
.lvl-row small { font-size: 10.5px; color: #fde68a; font-weight: 800; white-space: nowrap; }
.xp { height: 9px; border-radius: 999px; background: rgba(0, 0, 0, .35); overflow: hidden; box-shadow: inset 0 1px 2px rgba(0, 0, 0, .4); }
.xp-fill { height: 100%; width: 0; border-radius: 999px; background: linear-gradient(180deg, #fff3b0, #fbbf24 55%, #f59e0b); transition: width .5s cubic-bezier(.34,1.56,.64,1); }

.wallets { display: flex; flex-direction: column; gap: 8px; flex: 0 0 auto; margin-top: 4px; }
.wallet {
  position: relative; display: flex; align-items: center; min-width: 128px; height: 36px; padding: 0 8px 0 34px;
  background: rgba(59, 36, 20, .62); border-radius: 999px; color: #fff;
  box-shadow: inset 0 2px 4px rgba(0, 0, 0, .25), 0 3px 8px rgba(0, 0, 0, .2); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
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

/* ── DOCK BAWAH (papan kayu) ── */
.dock {
  position: fixed; z-index: 20; left: 50%; transform: translateX(-50%); bottom: calc(var(--safe-b) + 10px);
  width: min(460px, calc(100% - 16px)); display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px; padding: 8px 6px 7px;
  background: repeating-linear-gradient(90deg, rgba(0, 0, 0, .04) 0 2px, transparent 2px 46px), linear-gradient(180deg, var(--wood-l) 0%, var(--wood) 60%, #a5652d 100%);
  border-radius: 22px; box-shadow: 0 5px 0 var(--wood-d), 0 10px 22px rgba(60, 30, 5, .35), inset 0 2px 0 rgba(255, 255, 255, .35), inset 0 -2px 0 rgba(0, 0, 0, .12);
}
.dock-btn { position: relative; display: flex; flex-direction: column; align-items: center; gap: 2px; padding-top: 2px; }
.dock-ic {
  --f: #fff; --l: #ccc;
  width: 54px; height: 50px; margin-top: -22px; border-radius: 18px; display: grid; place-items: center; font-size: 26px; color: #fff;
  background: radial-gradient(circle at 50% 30%, color-mix(in srgb, var(--f) 55%, #fff), var(--f) 70%);
  box-shadow: 0 4px 0 var(--l), 0 6px 12px rgba(60, 30, 5, .3), inset 0 2px 0 rgba(255, 255, 255, .6);
  transition: transform .1s, box-shadow .1s; padding: 4px;
}
.dock-ic img { width: 46px; height: 46px; }
.dock-btn:active .dock-ic { transform: translateY(3px); box-shadow: 0 1px 0 var(--l), inset 0 2px 0 rgba(255, 255, 255, .5); }
.dock-btn.t-green .dock-ic { --f: var(--g1); --l: var(--g2); }
.dock-btn.t-sky .dock-ic { --f: var(--b1); --l: var(--b2); }
.dock-btn.t-gold .dock-ic { --f: var(--y1); --l: var(--y2); }
.dock-btn.t-red .dock-ic { --f: var(--r1); --l: var(--r2); }
.dock-lbl { font-family: var(--font-game); font-weight: 600; font-size: 12.5px; color: #fff; text-shadow: 0 1px 0 var(--wood-d), 0 0 3px rgba(60, 30, 5, .4); white-space: nowrap; }
.dock-chip {
  position: absolute; top: -26px; right: 0; background: #fff; border-radius: 999px; color: var(--ink); font-family: var(--font-game); font-weight: 600;
  font-size: 10.5px; padding: 1px 6px; font-variant-numeric: tabular-nums; box-shadow: 0 2px 0 #d6c3a0, 0 2px 6px rgba(0, 0, 0, .2);
}
.dock-chip.full { background: var(--r1); color: #fff; box-shadow: 0 2px 0 var(--r2); }
#dock-barn { will-change: transform; }

/* ── LABEL 3D (timer radial dsb.) ── */
.plot-tag { position: absolute; left: 0; top: 0; display: flex; flex-direction: column; align-items: center; gap: 3px; will-change: transform; }
.pt-ring {
  --c: #4ade80;
  width: 38px; height: 38px; border-radius: 50%; position: relative; padding: 4px;
  background: conic-gradient(var(--c) calc(var(--p, 0) * 1turn), rgba(59, 36, 20, .55) 0); display: grid; place-items: center;
  box-shadow: 0 3px 0 rgba(59, 36, 20, .45), 0 4px 10px rgba(0, 0, 0, .25);
}
.pt-ring::after { content: ''; position: absolute; inset: 4px; border-radius: 50%; background: var(--parch); }
.pt-ring i { position: relative; z-index: 1; font-size: 16px; color: #16a34a; }
.pt-text {
  font-family: var(--font-game); font-weight: 600; background: rgba(59, 36, 20, .78); color: #fff; border-radius: 999px; padding: 1px 9px 2px;
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

/* ── BACKDROP, SHEET & MODAL (panel kayu berisi perkamen) ── */
.backdrop {
  position: fixed; inset: 0; z-index: 40; background: rgba(30, 18, 8, .5); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
  opacity: 0; pointer-events: none; transition: opacity .2s;
}
.backdrop.show { opacity: 1; pointer-events: auto; }
.sheet, .modal {
  --rb1: var(--g1); --rb2: var(--g2);
  position: fixed; z-index: 50; display: flex; flex-direction: column;
  background: var(--wood); padding: 7px 7px 0; box-shadow: 0 -6px 30px rgba(30, 18, 8, .4), inset 0 2px 0 rgba(255, 255, 255, .3);
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
  box-shadow: 0 6px 0 var(--wood-d), 0 18px 40px rgba(30, 18, 8, .45), inset 0 2px 0 rgba(255, 255, 255, .3);
}
.modal.open { transform: translate(-50%, -50%) scale(1); opacity: 1; pointer-events: auto; }
.sheet-head {
  position: relative; display: flex; flex-direction: column; align-items: center; text-align: center;
  padding: 26px 16px 8px; margin-top: 18px; border-radius: 22px 22px 0 0;
  background: radial-gradient(circle at 20% 0%, rgba(255, 255, 255, .7), transparent 40%), var(--parch);
}
.modal .sheet-head { border-radius: 20px 20px 0 0; }
.hic {
  position: absolute; top: -40px; left: 50%; transform: translateX(-50%); width: 68px; height: 68px; display: grid; place-items: center;
  border-radius: 50%; background: radial-gradient(circle at 50% 35%, #fff, var(--parch-2) 70%); font-size: 32px; color: var(--wood-d);
  box-shadow: 0 0 0 5px var(--wood), 0 4px 0 6px var(--wood-d), inset 0 -3px 6px rgba(160, 100, 40, .25); padding: 6px;
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
  background: radial-gradient(circle at 50% 30%, #ff9a9a, var(--r1) 65%); box-shadow: 0 3px 0 var(--r2), 0 0 0 4px var(--wood), 0 5px 10px rgba(0, 0, 0, .25);
  text-shadow: 0 1px 0 rgba(0, 0, 0, .25); transition: transform .08s;
}
.close:active { transform: translateY(2px) scale(.95); }
.sheet-body {
  overflow-y: auto; padding: 8px 14px 18px; display: flex; flex-direction: column; gap: 10px; -webkit-overflow-scrolling: touch;
  background: var(--parch); border-radius: 0 0 0 0;
}
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
  background: rgba(59, 36, 20, .9); color: #fff; border-radius: 999px; padding: 9px 16px; font-size: 13.5px; display: flex; gap: 8px; align-items: center;
  box-shadow: 0 4px 14px rgba(0, 0, 0, .3); animation: tin .35s cubic-bezier(.34,1.56,.64,1); pointer-events: auto; text-shadow: 0 1px 0 rgba(0, 0, 0, .3);
}
.toast i { font-size: 18px; } .toast.ok i { color: #a6f08a; } .toast.err i { color: #ff9a9a; } .toast.gold i { color: #fde047; }
.toast.err { background: rgba(150, 30, 35, .92); } .toast.gold { background: rgba(140, 85, 5, .92); }
.toast a { color: #fde68a; text-decoration: underline; font-weight: 900; }
.toast.out { opacity: 0; transform: translateY(-8px); transition: .25s; }
@keyframes tin { from { opacity: 0; transform: translateY(-12px) scale(.9); } }

/* Loader */
#loader { position: fixed; inset: 0; z-index: 100; display: grid; place-items: center; background: linear-gradient(180deg, #8fd3ff, #dcefff); transition: opacity .4s; }
#loader.hide { opacity: 0; pointer-events: none; }
.load-card { text-align: center; background: var(--parch); border-radius: 26px; padding: 22px 30px 20px; box-shadow: 0 0 0 7px var(--wood), 0 11px 0 7px var(--wood-d), 0 20px 40px rgba(30, 18, 8, .35); }
.load-ic { font-size: 48px; color: var(--g2); display: inline-block; animation: wob 1s ease-in-out infinite; }
.load-card h1 { font-family: var(--font-game); font-size: 24px; font-weight: 700; margin-top: 4px; }
.load-card p { font-size: 13px; color: var(--muted); }
.load-bar { margin-top: 12px; height: 12px; border-radius: 999px; background: rgba(120, 80, 30, .2); overflow: hidden; }
.load-bar i { display: block; height: 100%; width: 40%; border-radius: 999px; background: linear-gradient(180deg, #a6f08a, var(--g1)); animation: loadbar 1.1s ease-in-out infinite; }
@keyframes loadbar { 0% { transform: translateX(-100%); } 100% { transform: translateX(260%); } }

@media (max-width: 380px) {
  .wallet { min-width: 112px; } .wal-tx b { font-size: 13.5px; }
  .lvl-row small { display: none; }
  .dock-ic { width: 50px; height: 46px; } .dock-ic img { width: 42px; height: 42px; }
  .shop-item .ic { width: 64px; height: 64px; flex-basis: 64px; }
}
@media (prefers-reduced-motion: reduce) { .plot-tag.is-pest .pt-ring, .load-ic, .load-bar i { animation: none; } }
</style>
</head>
<body>

<div id="loader"><div class="load-card"><span class="load-ic"><i class="ph-fill ph-plant"></i></span><h1>Sawah Panen Cuan</h1><p>Menyiapkan lahan&hellip;</p><div class="load-bar"><i></i></div></div></div>

<div id="farm-stage" aria-label="Sawah 3D"></div>
<div id="farm-labels"></div>

<!-- ── TOP BAR ── -->
<header class="topbar">
  <div class="top-left">
    <a class="icon-btn" href="/home" aria-label="Kembali ke Home"><i class="ph-bold ph-arrow-left"></i></a>
    <button class="icon-btn" id="btn-mute" type="button" aria-label="Suara"><i class="ph-bold ph-speaker-high"></i></button>
  </div>
  <div class="lvl">
    <span class="lvl-star" data-icon="star"><b id="lvl-num">1</b></span>
    <div class="lvl-info">
      <div class="lvl-row"><span class="lvl-name">Petani</span><small id="xp-txt">0/50 XP</small></div>
      <div class="xp"><div class="xp-fill" id="xp-fill"></div></div>
    </div>
  </div>
  <div class="wallets">
    <div class="wallet dep" id="wal-dep"><span class="wal-ic" data-icon="coins"></span><div class="wal-tx"><small>Saldo Beli</small><b id="bal-dep">Rp 0</b></div><a class="wal-add" href="/deposit" aria-label="Top up Saldo Beli"><i class="ph-bold ph-plus"></i></a></div>
    <div class="wallet wd" id="wal-wd"><span class="wal-ic" data-icon="basket"></span><div class="wal-tx"><small>Saldo Tarik</small><b id="bal-wd">Rp 0</b></div></div>
  </div>
</header>

<!-- ── DOCK ── -->
<nav class="dock">
  <button class="dock-btn t-green" type="button" data-open="seeds"><span class="dock-ic" data-icon="seed"><i class="ph-fill ph-plant"></i></span><span class="dock-lbl">Tanam</span></button>
  <button class="dock-btn t-sky" type="button" data-open="shop"><span class="dock-ic" data-icon="shop"><i class="ph-fill ph-storefront"></i></span><span class="dock-lbl">Toko Tani</span></button>
  <button class="dock-btn t-gold" type="button" data-open="barn" id="dock-barn"><span class="dock-ic" data-icon="barn"><i class="ph-fill ph-warehouse"></i></span><span class="dock-lbl">Lumbung</span><span class="dock-chip" id="barn-chip">0/30</span></button>
  <button class="dock-btn t-red" type="button" data-open="market"><span class="dock-ic" data-icon="coins"><i class="ph-fill ph-hand-coins"></i></span><span class="dock-lbl">Pasar</span></button>
</nav>

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
<section class="sheet t-sky" id="sheet-shop" aria-label="Toko Tani">
  <div class="sheet-head">
    <span class="hic" data-icon="shop"></span>
    <h3 class="ribbon">Toko Tani</h3>
    <p>Bayar pakai Saldo Beli</p>
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
<section class="sheet t-red" id="sheet-market" aria-label="Pasar Pengepul">
  <div class="sheet-head">
    <span class="hic" data-icon="coins"></span>
    <h3 class="ribbon">Pasar Pengepul</h3>
    <p>Hasil panen jadi Saldo Tarik</p>
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
  function renderSeeds() {
    const target = seedTarget || firstEmpty();
    $('seeds-target').textContent = target ? 'Tanam di Petak #' + target : 'Semua petak sedang terisi';
    $('seeds-body').innerHTML = S.crops.filter((c) => c.is_active !== 0).map((c) => {
      const lock = S.level.level < c.min_level, stock = qty('seeds', c.id);
      const pMin = c.yield_min * c.unit_price - c.seed_price, pMax = c.yield_max * c.unit_price - c.seed_price;
      return `<div class="card ${lock ? 'locked' : ''}" style="--c:${esc(c.color)}">
        <span class="ic">${cropImg(c)}${stock > 0 ? `<em class="qty">×${stock}</em>` : ''}</span>
        <div class="info"><b>${esc(c.name)}</b>
          <div class="chips">
            <span class="chip s"><i class="ph-bold ph-timer"></i>${U.fmtTime(c.growth_secs)}</span>
            <span class="chip g"><i class="ph-bold ph-trend-up"></i>${fmtRp(pMin)}–${U.fmtShort(pMax).replace('Rp ', '')}</span>
          </div>
          ${stock > 0 ? '<span class="stock">Pakai stok kantong</span>' : price(c.seed_price)}
        </div>
        <div class="act">${lock ? `<span class="lock-badge">${img('lock', 'ph-lock')}Lv ${c.min_level}</span>`
          : `<button class="nb-btn green" type="button" data-plant="${c.id}" ${target ? '' : 'disabled'}>Tanam</button>`}</div>
      </div>`;
    }).join('') + `<p class="note">Bibit di kantong dipakai lebih dulu. Bila kosong, 1 bibit otomatis dibeli dengan Saldo Beli. Untung = hasil panen × harga pasar − harga bibit.</p>`;
    $('seeds-body').querySelectorAll('[data-plant]').forEach((b) => b.addEventListener('click', () => plant(target, +b.dataset.plant)));
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
      <button class="nb-btn pink block" type="button" id="barn-to-market" ${harvest.length ? '' : 'disabled'}>${img('coins', 'ph-coins')} Jual di Pasar Pengepul</button>`;
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
       <button class="nb-btn yellow block" type="button" id="sell-all" ${rows.length ? '' : 'disabled'}>JUAL SEMUA HASIL PANEN</button>
       <p class="note">Uang hasil penjualan langsung masuk ke Saldo Tarik dan bisa ditarik.</p>`;
    $('sell-all').addEventListener('click', sellAll);
  }

  /* ── Render: Buka Petak ──────────────────────────────── */
  function renderUnlock() {
    const cost = S.next_plot_cost, enough = S.balance_dep >= cost;
    $('unlock-sub').textContent = 'Petak #' + plotCardIdx;
    $('unlock-body').innerHTML = `
      <div class="big-cost"><span class="ic">${img('lock', 'ph-lock-key')}</span><div><small>Biaya buka lahan</small><b>${fmtRp(cost)}</b></div></div>
      <div class="row"><span class="ic sm">${img('coins', 'ph-coins')}</span><div class="grow">Saldo Beli kamu</div><b>${fmtRp(S.balance_dep)}</b></div>
      <p class="note">Lahan baru dibersihkan dari rumput liar &amp; batu, siap ditanami. Harga petak berikutnya naik bertahap.</p>
      ${enough ? `<button class="nb-btn green block" type="button" id="do-unlock">Buka Petak</button>`
        : `<a class="nb-btn yellow block" href="/deposit" style="text-decoration:none">Deposit Saldo Beli</a>`}`;
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
    engine.flyIcons(from, $('wal-wd'), img('coins', 'ph-coins'), 8, (k, last) => {
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
