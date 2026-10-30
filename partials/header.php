<?php
/** partials/header.php — requires: $pageTitle, $activePage, $user */
$_site_name   = setting($pdo, 'site_name', 'PanenCuan');
$_seo_title   = setting($pdo, 'seo_title', 'PanenCuan');
$_seo_desc    = setting($pdo, 'seo_description', 'PanenCuan adalah platform penghasil saldo nyata terpercaya di Indonesia. Tonton video harian, selesaikan misi, dan cairkan saldo cuan instan.');
$_seo_kw      = setting($pdo, 'seo_keywords', 'panencuan, panen cuan, nonton video dapat uang, aplikasi penghasil saldo dana');
$_seo_robots  = setting($pdo, 'seo_robots', 'index,follow');
$_seo_og      = setting($pdo, 'seo_og_image', '/assets/seobanner.jpg');
$_seo_twcard  = setting($pdo, 'seo_twitter_card', 'summary_large_image');
$_seo_author  = setting($pdo, 'seo_author', 'PanenCuan Official');
$_seo_og_title = setting($pdo, 'seo_og_title', '');
$_seo_og_desc  = setting($pdo, 'seo_og_description', '');
$_seo_og_type  = setting($pdo, 'seo_og_type', 'website');
$_favicon     = setting($pdo, 'favicon_path', '/assets/favicon.png');
$_site_logo   = site_logo($pdo);

$og_disk = $_seo_og ? dirname(__DIR__) . '/' . ltrim(preg_replace('~\?.*$~', '', $_seo_og), '/') : '';
if (!$_seo_og || !file_exists($og_disk)) {
    $_seo_og = '/assets/seobanner.jpg';
}
$absolute_og = preg_match('~^https?://~', $_seo_og) ? $_seo_og : base_url(ltrim($_seo_og, '/'));
$og_mime = str_ends_with(strtolower($absolute_og), '.png') ? 'image/png' : 'image/jpeg';
$fav_url = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : '/' . ltrim($_favicon, '/')) : '/assets/favicon.png';
$current_url = base_url(ltrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/'));
$final_og_title = $_seo_og_title ?: (($pageTitle ?? '') ? $pageTitle . ' · ' . $_site_name : $_seo_title);
$final_og_desc = $_seo_og_desc ?: $_seo_desc;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#ffd9a8">
<meta name="mobile-web-app-capable" content="yes">
<meta name="application-name" content="<?= htmlspecialchars($_site_name) ?>">
<meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($_site_name) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?= htmlspecialchars(($pageTitle ?? '') ? $pageTitle . ' · ' . $_site_name : $_seo_title) ?></title>
<?php if ($_seo_desc): ?><meta name="description" content="<?= htmlspecialchars($_seo_desc) ?>"><?php endif; ?>
<?php if ($_seo_kw):   ?><meta name="keywords"    content="<?= htmlspecialchars($_seo_kw) ?>"><?php endif; ?>
<?php if ($_seo_author):?><meta name="author"     content="<?= htmlspecialchars($_seo_author) ?>"><?php endif; ?>
<meta name="robots" content="<?= htmlspecialchars(!empty($user['id']) ? 'noindex,follow' : $_seo_robots) ?>">
<meta name="googlebot" content="<?= htmlspecialchars(!empty($user['id']) ? 'noindex,follow' : $_seo_robots . ',max-image-preview:large,max-snippet:-1') ?>">
<link rel="canonical" href="<?= htmlspecialchars($current_url) ?>">
<meta property="og:locale" content="id_ID">
<meta property="og:site_name" content="<?= htmlspecialchars($_site_name) ?>">
<meta property="og:url" content="<?= htmlspecialchars($current_url) ?>">
<meta property="og:type" content="<?= htmlspecialchars($_seo_og_type) ?>">
<meta property="og:title" content="<?= htmlspecialchars($final_og_title) ?>">
<?php if ($final_og_desc): ?>
<meta property="og:description" content="<?= htmlspecialchars($final_og_desc) ?>">
<?php endif; ?>
<?php if ($absolute_og): ?>
<meta property="og:image" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:secure_url" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:type" content="<?= $og_mime ?>">
<meta property="og:image:alt" content="<?= htmlspecialchars($_site_name) ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?= htmlspecialchars($_seo_twcard) ?>">
<meta name="twitter:site" content="@PanenCuan">
<meta name="twitter:creator" content="@PanenCuan">
<meta name="twitter:title" content="<?= htmlspecialchars($final_og_title) ?>">
<?php if ($final_og_desc): ?><meta name="twitter:description" content="<?= htmlspecialchars($final_og_desc) ?>"><?php endif; ?>
<?php if ($absolute_og): ?>
<meta name="twitter:image" content="<?= htmlspecialchars($absolute_og) ?>">
<meta name="twitter:image:alt" content="<?= htmlspecialchars($_site_name) ?>">
<?php endif; ?>
<?php require dirname(__DIR__) . '/partials/head_icons.php'; ?>
<!-- Schema.org JSON-LD Structured Data for Google Rich Snippets -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "WebSite",
      "@id": "<?= htmlspecialchars(base_url('#website')) ?>",
      "url": "<?= htmlspecialchars(base_url()) ?>",
      "name": "<?= htmlspecialchars($_site_name) ?>",
      "description": "<?= htmlspecialchars($_seo_desc) ?>",
      "publisher": {
        "@id": "<?= htmlspecialchars(base_url('#organization')) ?>"
      },
      "inLanguage": "id-ID"
    },
    {
      "@type": "Organization",
      "@id": "<?= htmlspecialchars(base_url('#organization')) ?>",
      "name": "<?= htmlspecialchars($_site_name) ?>",
      "url": "<?= htmlspecialchars(base_url()) ?>",
      "logo": {
        "@type": "ImageObject",
        "url": "<?= htmlspecialchars(preg_match('~^https?://~', $_site_logo) ? $_site_logo : base_url(ltrim(preg_replace('~\?.*$~', '', $_site_logo), '/'))) ?>",
        "width": 512,
        "height": 512
      }
    }
  ]
}
</script>
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/app.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/app.css') ?>">
<link rel="stylesheet" href="/assets/css/pc-game.css?v=<?= filemtime(dirname(__DIR__) . '/assets/css/pc-game.css') ?>">
<style>
/* ══════════════════════════════════════════════════════════
   PANENCUAN · "GOLDEN HOUR HARVEST"
   Token global, langit sore di latar, header transparan → kaca.
   ══════════════════════════════════════════════════════════ */
:root {
  --pc-ink: #1b2a1e;
  --pc-muted: #5c6b5f;
  --pc-line: rgba(27, 42, 30, .08);
  --pc-card: #ffffff;
  --pc-soft: #f2f6ec;
  --pc-green: #16a34a;
  --pc-green-d: #14532d;
  --pc-green-m: #15803d;
  --pc-green-l: #dcfce7;
  --pc-gold: #f6b70a;
  --pc-gold-d: #c98a00;
  --pc-gold-l: #fff3c4;
  --pc-orange: #fb923c;
  --pc-sky: #38bdf8;
  --pc-sky-l: #e0f2fe;
  --pc-red: #f43f5e;
  --pc-violet: #8b5cf6;
  --pc-r: 22px;
  --pc-sh: 0 8px 24px rgba(40, 60, 20, .10), 0 1px 3px rgba(40, 60, 20, .06);
  --pc-sh-lg: 0 18px 44px rgba(40, 60, 20, .20);
  --pc-glass: rgba(255, 255, 255, .62);
  --pc-font: 'Fredoka', 'Nunito', sans-serif;
  --pc-ease: cubic-bezier(.34, 1.56, .64, 1);
  --pc-out: cubic-bezier(.22, 1, .36, 1);
  /* alias lama agar halaman lain tetap rapi */
  --reef-ink: var(--pc-ink); --reef-muted: var(--pc-muted);
  --reef-coral-500: #f43f5e; --reef-coral-400: #fb7185; --reef-coral-200: #fda4af;
  --reef-sun-500: #f59e0b; --reef-sun-400: #fbbf24; --reef-sun-200: #fef08a;
  --reef-kelp-500: #10b981; --reef-kelp-600: #059669; --reef-kelp-400: #34d399;
  --reef-glow-500: #8b5cf6; --reef-glow-450: #a855f7; --reef-glow-300: #c084fc;
  --reef-sand-50: #fff7ed; --reef-sand-100: #ffedd5;
  --reef-lagoon-400: #22d3ee; --reef-lagoon-600: #0891b2;
  --reef-border: linear-gradient(#ffffff, #ffffff);
  --reef-card: linear-gradient(160deg, #ffffff 0%, #f7fbf2 100%);
  --reef-clay-shadow: var(--pc-sh);
}

*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; width: 100%; overflow-x: hidden; }
body {
  font-family: 'Nunito', -apple-system, sans-serif !important;
  background-color: #f4f8ec !important;
  background-image:
    linear-gradient(180deg, #ffd9a8 0px, #ffe9c7 120px, #e9f7ff 300px, #f4f8ec 560px) !important;
  background-repeat: no-repeat !important;
  padding-bottom: calc(100px + env(safe-area-inset-bottom)) !important;
  -webkit-font-smoothing: antialiased;
  color: var(--pc-ink);
}
/* Kunang-kunang emas melayang pelan di latar (GPU transform) */
body::before {
  content: ''; position: fixed; inset: 0 0 -240px 0; z-index: 0; pointer-events: none; opacity: .7;
  background-image:
    radial-gradient(rgba(246, 183, 10, .55) 1.4px, transparent 2px),
    radial-gradient(rgba(255, 255, 255, .9) 1.1px, transparent 1.6px),
    radial-gradient(rgba(22, 163, 74, .3) 1.2px, transparent 1.8px);
  background-size: 120px 120px, 80px 80px, 160px 160px;
  background-position: 0 0, 37px 51px, 73px 19px;
  animation: pcFireflies 36s linear infinite;
}
@keyframes pcFireflies { to { transform: translateY(-240px); } }
i[class^="ph-"] { display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; }
.app-shell { width: 100%; max-width: 440px; margin: 0 auto; position: relative; }

/* Efek riak saat diketuk (dipakai elemen .rp di semua halaman) */
.rp { position: relative; overflow: hidden; }
.rp-wave { position: absolute; border-radius: 50%; pointer-events: none; background: currentColor; opacity: .22; transform: scale(0); animation: pcRipple .6s var(--pc-out) forwards; }
@keyframes pcRipple { to { transform: scale(2.6); opacity: 0; } }

/* ══ HEADER: transparan di atas langit, jadi kaca saat discroll ══ */
.topbar-wrap { position: sticky; top: 0; z-index: 1000; width: 100%; padding: calc(6px + env(safe-area-inset-top)) 10px 6px; transition: padding .3s var(--pc-out); }
.topbar-floating-row {
  position: relative; display: flex; align-items: center; justify-content: space-between; gap: 8px; height: 54px; padding: 0 6px 0 8px;
  border-radius: 20px; background: transparent; transition: background .35s, box-shadow .35s, backdrop-filter .35s, height .3s var(--pc-out);
}
.topbar-wrap.scrolled { padding-top: calc(4px + env(safe-area-inset-top)); }
.topbar-wrap.scrolled .topbar-floating-row {
  height: 50px; background: var(--pc-glass); backdrop-filter: blur(18px) saturate(180%); -webkit-backdrop-filter: blur(18px) saturate(180%);
  box-shadow: 0 1px 0 rgba(255, 255, 255, .8) inset, 0 10px 28px rgba(60, 40, 10, .14);
}

.island-brand-pill { display: inline-flex; align-items: center; gap: 9px; min-width: 0; min-height: 44px; flex: 1 1 auto; text-decoration: none; animation: pcSlideR .6s var(--pc-out) both; }
@keyframes pcSlideR { from { opacity: 0; transform: translateX(-16px); } }
.brand-logo {
  position: relative; width: 40px; height: 40px; flex-shrink: 0; border-radius: 14px; padding: 3px;
  background: linear-gradient(145deg, #fff, #fff3c4); box-shadow: 0 6px 16px rgba(201, 138, 0, .28), inset 0 0 0 1px rgba(255, 255, 255, .8);
}
.brand-logo::after {
  content: ''; position: absolute; inset: 0; border-radius: inherit; pointer-events: none;
  background: linear-gradient(115deg, transparent 30%, rgba(255, 255, 255, .85) 50%, transparent 70%); background-size: 250% 100%;
  animation: pcGleam 4.5s ease-in-out infinite;
}
@keyframes pcGleam { 0%, 60% { background-position: 150% 0; } 100% { background-position: -100% 0; } }
.brand-logo img { display: block; width: 100%; height: 100%; object-fit: contain; border-radius: 11px; }
.brand-fin-icon { width: 100%; height: 100%; border-radius: 11px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; background: linear-gradient(145deg, #4ade80, var(--pc-green-m)); }
.brand-copy { display: flex; flex-direction: column; min-width: 0; line-height: 1.05; }
.brand-name { font-family: var(--pc-font); font-size: 18px; font-weight: 700; letter-spacing: -.2px; color: var(--pc-green-d); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.brand-name b {
  font-weight: 700; background: linear-gradient(90deg, #e08a00, #f6b70a, #ffd54f, #e08a00); background-size: 200% 100%;
  -webkit-background-clip: text; background-clip: text; color: transparent; animation: pcGoldShift 4s linear infinite;
}
@keyframes pcGoldShift { to { background-position: -200% 0; } }
.brand-page { margin-top: 2px; font-size: 10.5px; font-weight: 800; color: #8a6a3a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.island-cluster-pill { display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; animation: pcSlideL .6s var(--pc-out) .1s both; }
@keyframes pcSlideL { from { opacity: 0; transform: translateX(16px); } }
.cluster-balance-btn {
  position: relative; display: flex; align-items: center; gap: 4px; height: 42px; padding: 0 12px 0 4px; border-radius: 999px; cursor: pointer; user-select: none; overflow: hidden;
  background: rgba(255, 255, 255, .78); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
  box-shadow: 0 6px 16px rgba(201, 138, 0, .2), inset 0 0 0 1.5px rgba(246, 183, 10, .45);
  transition: transform .2s var(--pc-ease);
}
.cluster-balance-btn:active { transform: scale(.93); }
.cluster-coin { width: 34px; height: 34px; display: grid; place-items: center; animation: pcCoinFlip 4s ease-in-out infinite; }
.cluster-coin img { width: 100%; height: 100%; object-fit: contain; filter: drop-shadow(0 2px 3px rgba(150, 100, 0, .3)); }
@keyframes pcCoinFlip { 0%, 75%, 100% { transform: rotateY(0); } 87% { transform: rotateY(180deg) scale(1.08); } }
.cluster-bal-txt { font-family: var(--pc-font); font-size: 14.5px; font-weight: 700; color: #7a4f00; }
.cluster-bell-btn {
  width: 42px; height: 42px; border-radius: 14px; display: flex; align-items: center; justify-content: center; position: relative;
  color: var(--pc-green-d); font-size: 20px; text-decoration: none;
  background: rgba(255, 255, 255, .78); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); box-shadow: 0 6px 16px rgba(40, 60, 20, .12);
  transition: transform .2s var(--pc-ease);
}
.cluster-bell-btn:active { transform: scale(.88); }
.cluster-bell-btn.has-new i { animation: pcRing 2.8s ease-in-out infinite; transform-origin: 50% 8%; }
@keyframes pcRing { 0%, 70%, 100% { transform: rotate(0); } 74% { transform: rotate(16deg); } 78% { transform: rotate(-14deg); } 82% { transform: rotate(9deg); } 86% { transform: rotate(-5deg); } }
.cluster-badge-count {
  position: absolute; top: -4px; right: -4px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; display: none; align-items: center; justify-content: center;
  background: linear-gradient(180deg, #fb7185, var(--pc-red)); color: #fff; font-size: 10px; font-weight: 900; box-shadow: 0 0 0 2px #fff, 0 4px 10px rgba(244, 63, 94, .45);
  animation: pcBadgePop .5s var(--pc-ease);
}
@keyframes pcBadgePop { from { transform: scale(0); } }
.island-cluster-pill.guest { gap: 6px; }
.island-guest-link { display: inline-flex; align-items: center; min-height: 42px; padding: 0 12px; border-radius: 14px; color: var(--pc-green-d); font-size: 13px; font-weight: 900; text-decoration: none; background: rgba(255, 255, 255, .7); }
.island-guest-btn {
  display: inline-flex; align-items: center; min-height: 42px; padding: 0 16px; border-radius: 14px; color: #fff; font-size: 13px; font-weight: 900; text-decoration: none;
  background: linear-gradient(135deg, #22c55e, var(--pc-green-m)); box-shadow: 0 8px 18px rgba(22, 163, 74, .4);
}

/* ── DOMPET (popover) ── */
.tb-wallet-backdrop { display: none; position: fixed; inset: 0; z-index: 9998; background: rgba(40, 25, 5, .35); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); }
.tb-wallet-backdrop.open { display: block; animation: pcFade .25s ease; }
.tb-wallet-popover {
  display: none; position: fixed; top: calc(66px + env(safe-area-inset-top)); left: 50%; transform: translateX(-50%); z-index: 9999; overflow: hidden;
  width: calc(100% - 24px); max-width: 400px; border-radius: 26px; background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: var(--pc-sh-lg);
}
.tb-wallet-popover.open { display: block; animation: pcPop .4s var(--pc-ease); }
@keyframes pcPop { from { transform: translateX(-50%) translateY(-14px) scale(.9); opacity: 0; } }
@keyframes pcFade { from { opacity: 0; } }
.twp-header { padding: 16px 16px 8px; display: flex; align-items: center; justify-content: space-between; }
.twp-title { font-family: var(--pc-font); font-size: 18px; font-weight: 700; color: var(--pc-ink); }
.twp-close { width: 40px; height: 40px; border: 0; border-radius: 12px; background: var(--pc-soft); color: var(--pc-muted); font-size: 18px; cursor: pointer; }
.twp-body { padding: 0 12px 14px; display: flex; flex-direction: column; gap: 8px; }
.twp-card { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px; border-radius: 18px; background: #fff; box-shadow: var(--pc-sh); animation: pcRise .45s var(--pc-ease) both; }
.twp-card:nth-child(2) { animation-delay: .06s; } .twp-card:nth-child(3) { animation-delay: .12s; }
@keyframes pcRise { from { transform: translateY(12px) scale(.96); opacity: 0; } }
.twp-card-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
.twp-card-icon { width: 44px; height: 44px; border-radius: 14px; display: grid; place-items: center; background: linear-gradient(145deg, #fff8e1, #fff3c4); flex-shrink: 0; }
.twp-card-icon img { width: 36px; height: 36px; object-fit: contain; }
.twp-card-lbl { font-size: 10.5px; font-weight: 800; color: var(--pc-muted); }
.twp-card-val { font-family: var(--pc-font); font-size: 17px; font-weight: 700; color: var(--pc-ink); }
.twp-card-btn { display: inline-flex; align-items: center; min-height: 40px; padding: 0 15px; border-radius: 12px; font-size: 13px; font-weight: 900; text-decoration: none; color: #fff; white-space: nowrap; }
.twp-card-btn.wd { background: linear-gradient(135deg, #ffcf3a, var(--pc-gold)); color: #4a3000; box-shadow: 0 6px 14px rgba(246, 183, 10, .4); }
.twp-card-btn.dep { background: linear-gradient(135deg, #22c55e, var(--pc-green-m)); box-shadow: 0 6px 14px rgba(22, 163, 74, .35); }
.twp-card-btn.farm { background: linear-gradient(135deg, #22c55e, var(--pc-green-m)); box-shadow: 0 6px 14px rgba(22, 163, 74, .35); }

@media (prefers-reduced-motion: reduce) {
  body::before, .brand-logo::after, .brand-name b, .cluster-coin, .cluster-bell-btn.has-new i, .island-brand-pill, .island-cluster-pill { animation: none !important; }
}
</style>
</head>
<body>
<div class="app-shell">
  <div class="topbar-wrap" id="pc-topbar">
    <div class="topbar-floating-row">
      <?php
        // Nama situs dua warna: "PanenCuan" -> "Panen" + "Cuan"
        $_brand_a = $_site_name; $_brand_b = '';
        if (preg_match('/^(.+?)([A-Z][a-z0-9]+)$/u', trim($_site_name), $_bm)) { $_brand_a = $_bm[1]; $_brand_b = $_bm[2]; }
        $_brand_page = trim((string)($pageTitle ?? ''));
      ?>
      <a href="/home" class="island-brand-pill" aria-label="Home <?= htmlspecialchars($_site_name) ?>">
        <span class="brand-logo">
          <?php if ($_site_logo): ?>
          <img src="<?= htmlspecialchars($_site_logo) ?>" alt="" width="34" height="34" decoding="async"
               onerror="this.outerHTML='<span class=&quot;brand-fin-icon&quot;><i class=&quot;ph-fill ph-plant&quot;></i></span>'">
          <?php else: ?>
          <span class="brand-fin-icon"><i class="ph-fill ph-plant"></i></span>
          <?php endif; ?>
        </span>
        <span class="brand-copy">
          <span class="brand-name"><?= htmlspecialchars($_brand_a) ?><?php if ($_brand_b !== ''): ?><b><?= htmlspecialchars($_brand_b) ?></b><?php endif; ?></span>
          <?php if ($_brand_page !== ''): ?><span class="brand-page"><?= htmlspecialchars($_brand_page) ?></span><?php endif; ?>
        </span>
      </a>

      <?php if (!empty($user['id'])): ?>
      <?php
      if (!function_exists('fmt_short_tb')) {
        function fmt_short_tb(float $n): string {
          if ($n >= 1_000_000_000) return 'Rp ' . number_format($n/1_000_000_000, 1, '.', '') . 'M';
          if ($n >= 1_000_000)     return 'Rp ' . number_format($n/1_000_000, 1, '.', '') . 'jt';
          if ($n >= 100_000)       return 'Rp ' . number_format($n/1_000, 0, '.', '') . 'rb';
          return 'Rp ' . number_format($n, 0, ',', '.');
        }
      }
      ?>
      <div class="island-cluster-pill">
        <div class="cluster-balance-btn rp" id="tb-saldo-trigger" onclick="toggleWalletPopover()" title="Rincian dompet" role="button" tabindex="0">
          <span class="cluster-coin"><img src="/assets/img/icons3d/coins.png" alt=""></span>
          <span class="cluster-bal-txt"><?= fmt_short_tb((float)$user['balance_wd']) ?></span>
        </div>
        <a href="/notifications" class="cluster-bell-btn rp" id="notif-bell-btn" title="Notifikasi">
          <i class="ph-fill ph-bell-simple"></i>
          <span id="notif-badge" class="cluster-badge-count"></span>
        </a>
      </div>
      <?php else: ?>
      <div class="island-cluster-pill guest">
        <a href="/login" class="island-guest-link">Masuk</a>
        <a href="/register" class="island-guest-btn rp">Daftar</a>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($user['id'])): ?>
    <div class="tb-wallet-backdrop" id="tb-wallet-backdrop" onclick="toggleWalletPopover(false)"></div>
    <div class="tb-wallet-popover" id="tb-wallet-popover">
      <div class="twp-header">
        <div class="twp-title">Dompet kamu</div>
        <button type="button" class="twp-close" onclick="toggleWalletPopover(false)" aria-label="Tutup">&times;</button>
      </div>
      <div class="twp-body">
        <div class="twp-card">
          <div class="twp-card-left">
            <div class="twp-card-icon"><img src="/assets/img/icons3d/coins.png" alt=""></div>
            <div><div class="twp-card-lbl">Saldo siap tarik</div><div class="twp-card-val"><?= format_rp((float)$user['balance_wd']) ?></div></div>
          </div>
          <a href="/withdraw" class="twp-card-btn wd rp">Tarik</a>
        </div>
        <div class="twp-card">
          <div class="twp-card-left">
            <div class="twp-card-icon"><img src="/assets/img/icons3d/seed.png" alt=""></div>
            <div><div class="twp-card-lbl">Saldo beli</div><div class="twp-card-val"><?= format_rp((float)$user['balance_dep']) ?></div></div>
          </div>
          <a href="/deposit" class="twp-card-btn dep rp">+ Isi</a>
        </div>
        <div class="twp-card">
          <div class="twp-card-left">
            <div class="twp-card-icon"><img src="/assets/img/icons3d/padi.png" alt=""></div>
            <div><div class="twp-card-lbl">Sawah PanenCuan</div><div class="twp-card-val">Tanam &amp; panen</div></div>
          </div>
          <a href="/farm" class="twp-card-btn farm rp">Bertani</a>
        </div>
      </div>
    </div>

    <script>
    function toggleWalletPopover(force) {
      const popover = document.getElementById('tb-wallet-popover');
      const backdrop = document.getElementById('tb-wallet-backdrop');
      if (!popover || !backdrop) return;
      const shouldOpen = typeof force === 'boolean' ? force : !popover.classList.contains('open');
      popover.classList.toggle('open', shouldOpen);
      backdrop.classList.toggle('open', shouldOpen);
    }
    </script>
    <?php endif; ?>
  </div>
  <script>
  (function () {
    // Header jadi kaca saat discroll
    const bar = document.getElementById('pc-topbar');
    if (bar) {
      const on = () => bar.classList.toggle('scrolled', window.scrollY > 10);
      window.addEventListener('scroll', on, { passive: true });
      on();
    }
    // Riak saat diketuk untuk semua elemen .rp
    document.addEventListener('pointerdown', (e) => {
      const el = e.target.closest && e.target.closest('.rp');
      if (!el || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
      const r = el.getBoundingClientRect(), d = Math.max(r.width, r.height);
      const w = document.createElement('span');
      w.className = 'rp-wave';
      w.style.cssText = 'width:' + d + 'px;height:' + d + 'px;left:' + (e.clientX - r.left - d / 2) + 'px;top:' + (e.clientY - r.top - d / 2) + 'px';
      el.appendChild(w);
      setTimeout(() => w.remove(), 650);
    }, { passive: true });
  })();
  </script>

  <main class="page-content">

<?php if (!empty($user['id'])): ?>
<script>
(function() {
  function fetchNotifCount() {
    fetch('/notif_action?action=count')
      .then(r => r.json())
      .then(data => {
        const badge = document.getElementById('notif-badge');
        const bell = document.getElementById('notif-bell-btn');
        if (!badge) return;
        if (data.count > 0) {
          badge.textContent = data.count > 9 ? '9+' : data.count;
          badge.style.display = 'inline-flex';
          if (bell) bell.classList.add('has-new');
        } else {
          badge.style.display = 'none';
          if (bell) bell.classList.remove('has-new');
        }
      })
      .catch(() => {});
  }
  fetchNotifCount();
  setInterval(fetchNotifCount, 60000);
})();
</script>
<?php endif; ?>
