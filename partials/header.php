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
$current_url = base_url(ltrim($_SERVER['REQUEST_URI'] ?? '', '/'));
$final_og_title = $_seo_og_title ?: (($pageTitle ?? '') ? $pageTitle . ' · ' . $_site_name : $_seo_title);
$final_og_desc = $_seo_og_desc ?: $_seo_desc;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#eef8ff">
<meta name="application-name" content="<?= htmlspecialchars($_site_name) ?>">
<meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($_site_name) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?= htmlspecialchars(($pageTitle ?? '') ? $pageTitle . ' · ' . $_site_name : $_seo_title) ?></title>
<?php if ($_seo_desc): ?><meta name="description" content="<?= htmlspecialchars($_seo_desc) ?>"><?php endif; ?>
<?php if ($_seo_kw):   ?><meta name="keywords"    content="<?= htmlspecialchars($_seo_kw) ?>"><?php endif; ?>
<?php if ($_seo_author):?><meta name="author"     content="<?= htmlspecialchars($_seo_author) ?>"><?php endif; ?>
<meta name="robots" content="<?= htmlspecialchars($_seo_robots) ?>">
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
<?php if ($fav_url): ?>
<link rel="icon" type="image/png" sizes="192x192" href="<?= htmlspecialchars($fav_url) ?>?v=<?= @filemtime(dirname(__DIR__) . '/' . ltrim($_favicon, '/')) ?: time() ?>">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/apple-touch-icon.png?v=<?= @filemtime(dirname(__DIR__) . '/assets/apple-touch-icon.png') ?: time() ?>">
<link rel="shortcut icon" href="/assets/favicon.ico">
<?php endif; ?>
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
        "url": "<?= htmlspecialchars(base_url('assets/logo.png')) ?>",
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
<style>
/* ══════════════════════════════════════════════════════════
   PANENCUAN — TOKEN GLOBAL, LATAR LEMBAH & TOP BAR
   Hijau sawah + emas panen, kartu putih lembut, ikon 3D.
   ══════════════════════════════════════════════════════════ */
:root {
  --pc-ink: #1d2b1f;
  --pc-muted: #5f6f62;
  --pc-line: rgba(29, 43, 31, .08);
  --pc-card: #ffffff;
  --pc-soft: #f1f5ec;
  --pc-green: #15803d;
  --pc-green-d: #14532d;
  --pc-green-l: #dcfce7;
  --pc-gold: #f5b301;
  --pc-gold-d: #c98a00;
  --pc-gold-l: #fff4cc;
  --pc-sky: #38bdf8;
  --pc-sky-l: #e0f2fe;
  --pc-red: #f43f5e;
  --pc-violet: #8b5cf6;
  --pc-r: 20px;
  --pc-sh: 0 6px 20px rgba(28, 60, 24, .08), 0 1px 3px rgba(28, 60, 24, .06);
  --pc-sh-lg: 0 14px 36px rgba(28, 60, 24, .16);
  --pc-font: 'Fredoka', 'Nunito', sans-serif;
  --pc-ease: cubic-bezier(.34, 1.56, .64, 1);
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
  background: #f5f8f0 !important;
  padding-bottom: calc(96px + env(safe-area-inset-bottom)) !important;
  -webkit-font-smoothing: antialiased;
  color: var(--pc-ink);
}
i[class^="ph-"] { display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; }
.app-shell { width: 100%; max-width: 440px; margin: 0 auto; position: relative; }

/* ══ TOP BAR: solid, bayangan tipis muncul saat discroll ══ */
.topbar-wrap { position: sticky; top: 0; z-index: 1000; width: 100%; background: #f5f8f0; padding-top: env(safe-area-inset-top); transition: box-shadow .2s ease; }
.topbar-floating-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; height: 56px; padding: 0 12px; }
.topbar-wrap.scrolled { box-shadow: 0 1px 0 var(--pc-line), 0 6px 14px rgba(28, 60, 24, .06); }

.island-brand-pill { display: inline-flex; align-items: center; gap: 9px; min-width: 0; min-height: 44px; flex: 1 1 auto; text-decoration: none; }
.brand-logo { position: relative; width: 36px; height: 36px; flex-shrink: 0; border-radius: 12px; padding: 3px; background: #fff; box-shadow: inset 0 0 0 1px var(--pc-line); }
.brand-logo img { display: block; width: 100%; height: 100%; object-fit: contain; border-radius: 9px; }
.brand-logo .brand-fin-icon { width: 100%; height: 100%; border-radius: 10px; }
.brand-copy { display: flex; flex-direction: column; min-width: 0; line-height: 1.05; }
.brand-name { font-family: var(--pc-font); font-size: 17px; font-weight: 700; letter-spacing: -.2px; color: var(--pc-green-d); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.brand-name b { font-weight: 700; color: var(--pc-gold-d); }
.brand-page { margin-top: 2px; display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 800; color: var(--pc-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.brand-fin-icon { width: 28px; height: 28px; background: linear-gradient(145deg, #4ade80, var(--pc-green-d)); border-radius: 9px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 16px; }
.island-brand-pill:active { transform: scale(.97); }

.island-cluster-pill { display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; }
.cluster-balance-btn {
  position: relative; display: flex; align-items: center; gap: 4px; height: 44px; padding: 0 12px 0 6px; border-radius: 12px; cursor: pointer; user-select: none;
  background: #fff; box-shadow: inset 0 0 0 1px var(--pc-line);
  transition: transform .15s var(--pc-ease);
}
.cluster-balance-btn:active { transform: scale(.94); }
.cluster-coin { width: 30px; height: 30px; display: grid; place-items: center; }
.cluster-coin img { width: 100%; height: 100%; object-fit: contain; filter: drop-shadow(0 2px 2px rgba(150, 100, 0, .25)); }
.cluster-bal-txt { font-family: var(--pc-font); font-size: 14px; font-weight: 600; color: var(--pc-ink); }
.cluster-bell-btn {
  width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; position: relative;
  color: var(--pc-ink); font-size: 20px; text-decoration: none; background: #fff; box-shadow: inset 0 0 0 1px var(--pc-line); transition: transform .15s var(--pc-ease);
}
.cluster-bell-btn:active { transform: scale(.9); }
.cluster-bell-btn.has-new i { animation: pcRing 1.2s ease-in-out 2; transform-origin: 50% 10%; }
@keyframes pcRing { 0%, 100% { transform: rotate(0); } 15% { transform: rotate(14deg); } 30% { transform: rotate(-12deg); } 45% { transform: rotate(8deg); } 60% { transform: rotate(-4deg); } }
.cluster-badge-count {
  position: absolute; top: -3px; right: -3px; min-width: 16px; height: 16px; padding: 0 4px; border-radius: 999px; display: none; align-items: center; justify-content: center;
  background: var(--pc-red); color: #fff; font-size: 9px; font-weight: 900; box-shadow: 0 0 0 2px #fff;
}
.island-cluster-pill.guest { gap: 4px; }
.island-guest-link { display: inline-flex; align-items: center; min-height: 44px; color: var(--pc-green); font-size: 13px; font-weight: 900; text-decoration: none; padding: 0 10px; }
.island-guest-btn {
  display: inline-flex; align-items: center; min-height: 44px; color: #fff; font-size: 13px; font-weight: 900; text-decoration: none; padding: 0 16px; border-radius: 12px; background: var(--pc-green);
}

/* ── DOMPET (popover) ── */
.tb-wallet-backdrop { display: none; position: fixed; inset: 0; z-index: 9998; background: rgba(15, 30, 18, .35); }
.tb-wallet-backdrop.open { display: block; animation: pcFade .2s ease; }
.tb-wallet-popover {
  display: none; position: fixed; top: calc(62px + env(safe-area-inset-top)); left: 50%; transform: translateX(-50%); z-index: 9999; overflow: hidden;
  width: calc(100% - 24px); max-width: 400px; border-radius: 22px; background: var(--pc-card); box-shadow: var(--pc-sh-lg);
}
.tb-wallet-popover.open { display: block; animation: pcPop .3s var(--pc-ease); }
@keyframes pcPop { from { transform: translateX(-50%) translateY(-8px) scale(.96); opacity: 0; } }
@keyframes pcFade { from { opacity: 0; } }
.twp-header { padding: 14px 16px 8px; display: flex; align-items: center; justify-content: space-between; }
.twp-title { font-family: var(--pc-font); font-size: 16px; font-weight: 700; color: var(--pc-ink); display: flex; align-items: center; gap: 6px; }
.twp-close { width: 44px; height: 44px; border: 0; border-radius: 12px; background: var(--pc-soft); color: var(--pc-muted); font-size: 16px; cursor: pointer; }
.twp-body { padding: 0 12px 12px; display: flex; flex-direction: column; gap: 8px; }
.twp-card { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 9px 10px; border-radius: 16px; background: var(--pc-soft); animation: pcRise .35s var(--pc-ease) both; }
.twp-card:nth-child(2) { animation-delay: .05s; } .twp-card:nth-child(3) { animation-delay: .1s; }
@keyframes pcRise { from { transform: translateY(8px); opacity: 0; } }
.twp-card-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
.twp-card-icon { width: 40px; height: 40px; border-radius: 12px; display: grid; place-items: center; background: #fff; flex-shrink: 0; }
.twp-card-icon img { width: 32px; height: 32px; object-fit: contain; }
.twp-card-lbl { font-size: 10px; font-weight: 800; color: var(--pc-muted); text-transform: uppercase; letter-spacing: .3px; }
.twp-card-val { font-family: var(--pc-font); font-size: 16px; font-weight: 700; color: var(--pc-ink); }
.twp-card-btn { display: inline-flex; align-items: center; min-height: 40px; padding: 0 14px; border-radius: 10px; font-size: 12px; font-weight: 900; text-decoration: none; color: #fff; white-space: nowrap; }
.twp-card-btn.wd { background: var(--pc-gold); color: #3d2a00; }
.twp-card-btn.dep { background: var(--pc-green-l); color: var(--pc-green-d); }
.twp-card-btn.farm { background: var(--pc-green-l); color: var(--pc-green-d); }

@media (prefers-reduced-motion: reduce) {
  .cluster-bell-btn.has-new i, .tb-wallet-popover.open, .twp-card { animation: none !important; }
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
      <a href="/home" class="island-brand-pill" aria-label="Beranda <?= htmlspecialchars($_site_name) ?>">
        <span class="brand-logo">
          <?php if ($_site_logo): ?>
          <img src="<?= htmlspecialchars($_site_logo) ?>" alt="" width="32" height="32" decoding="async"
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
        <div class="cluster-balance-btn" id="tb-saldo-trigger" onclick="toggleWalletPopover()" title="Rincian dompet" role="button" tabindex="0">
          <span class="cluster-coin"><img src="/assets/img/icons3d/coins.png" alt=""></span>
          <span class="cluster-bal-txt"><?= fmt_short_tb((float)$user['balance_wd']) ?></span>
        </div>
        <a href="/notifications" class="cluster-bell-btn" id="notif-bell-btn" title="Notifikasi">
          <i class="ph-fill ph-bell-simple"></i>
          <span id="notif-badge" class="cluster-badge-count"></span>
        </a>
      </div>
      <?php else: ?>
      <div class="island-cluster-pill guest">
        <a href="/login" class="island-guest-link">Masuk</a>
        <a href="/register" class="island-guest-btn">Daftar</a>
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
            <div><div class="twp-card-lbl">Saldo Siap Tarik</div><div class="twp-card-val"><?= format_rp((float)$user['balance_wd']) ?></div></div>
          </div>
          <a href="/withdraw" class="twp-card-btn wd">Tarik</a>
        </div>
        <div class="twp-card">
          <div class="twp-card-left">
            <div class="twp-card-icon"><img src="/assets/img/icons3d/seed.png" alt=""></div>
            <div><div class="twp-card-lbl">Saldo Beli</div><div class="twp-card-val"><?= format_rp((float)$user['balance_dep']) ?></div></div>
          </div>
          <a href="/deposit" class="twp-card-btn dep">+ Isi</a>
        </div>
        <div class="twp-card">
          <div class="twp-card-left">
            <div class="twp-card-icon"><img src="/assets/img/icons3d/padi.png" alt=""></div>
            <div><div class="twp-card-lbl">Sawah PanenCuan</div><div class="twp-card-val">Tanam &amp; Panen</div></div>
          </div>
          <a href="/farm" class="twp-card-btn farm">Bertani</a>
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
  // Top bar mengecil saat halaman discroll
  (function () {
    const bar = document.getElementById('pc-topbar');
    if (!bar) return;
    const on = () => bar.classList.toggle('scrolled', window.scrollY > 8);
    window.addEventListener('scroll', on, { passive: true });
    on();
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
