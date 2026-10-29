<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/farm.php';

$user = auth_user($pdo);
$is_guest = false;
if (!$user) {
    $is_guest = true;
    $user = [
        'id' => 0, 'username' => 'Sobat Cuan', 'balance_wd' => 0, 'balance_dep' => 0,
        'membership_id' => null, 'membership_expires_at' => null, 'referral_code' => '-', 'is_promotor' => 0,
        'total_earned' => 0, 'watch_count_today' => 0, 'last_checkin' => null,
    ];
}

if (is_maintenance($pdo) && !auth_admin()) {
    $maintenance_msg = setting($pdo, 'maintenance_message', 'Sistem sedang dalam perbaikan.');
    require dirname(__DIR__) . '/user/maintenance.php';
    exit;
}

track_pageview($pdo, parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

// Ambil data terbaru user
if (!$is_guest) {
    $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $uStmt->execute([$user['id']]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);
}

// ── KONFIGURASI HOME CMS (console/home_cms.php) ──
$cms     = home_cms_config($pdo);
$H       = $cms['hero'];
$cms_txt = static fn(string $t): string => str_replace('{username}', (string)($GLOBALS['user']['username'] ?? 'Petani Cuan'), $t);

// ── TUGAS UTAMA: DATA NONTON VIDEO ──
$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);
$watch_pct   = $watch_limit > 0 ? min(100, (int)round(($watch_today / $watch_limit) * 100)) : 0;
$watch_remaining = max(0, $watch_limit - $watch_today);

// Cuan dari Nonton Video Hari Ini
$today_watch_earned = 0.0;
if (!$is_guest) {
    try {
        $twStmt = $pdo->prepare("SELECT COALESCE(SUM(reward_given), 0) FROM watch_history WHERE user_id = ? AND DATE(watched_at) = CURDATE()");
        $twStmt->execute([$user['id']]);
        $today_watch_earned = (float)$twStmt->fetchColumn();
    } catch (\Throwable) {}
}

// Data Referral Pengguna untuk Flex / Promosi
$ref_code = $user['referral_code'] ?? '-';
$ref_url = function_exists('base_url') ? base_url('register/' . ($ref_code !== '-' ? $ref_code : '')) : '/register?ref=' . urlencode($ref_code);

// 🌾 RINGKASAN SAWAH USER (untuk Portal Sawah Panen Cuan)
$farm_summary = null;
if (!$is_guest) {
    try {
        farm_ensure_schema($pdo);
        $now = time();
        // Hanya petak yang sedang ditanami (status 'growing'); siap panen = sudah lewat matures_at
        $plotStmt = $pdo->prepare("
            SELECT
                COUNT(*) as total_planted,
                COALESCE(SUM(matures_at <= ?), 0) as ready_count,
                COALESCE(SUM(matures_at > ?), 0) as growing_count
            FROM farm_plots
            WHERE user_id = ? AND status <> 'empty'
        ");
        $plotStmt->execute([$now, $now, $user['id']]);
        $plotsData = $plotStmt->fetch(PDO::FETCH_ASSOC);

        $invStmt = $pdo->prepare("
            SELECT COALESCE(SUM(qty), 0) FROM farm_inventory WHERE user_id = ? AND kind = 'harvest'
        ");
        $invStmt->execute([$user['id']]);
        $harvestCount = (int)$invStmt->fetchColumn();

        $farm_summary = [
            'total_planted' => (int)($plotsData['total_planted'] ?? 0),
            'ready_count'   => (int)($plotsData['ready_count'] ?? 0),
            'growing_count' => (int)($plotsData['growing_count'] ?? 0),
            'harvest_count' => $harvestCount,
        ];
    } catch (\Throwable) {}
}

// Video Populer & Cuan Hari Ini
$featured_videos = [];
$feat_limit = max(1, min(20, (int)($cms['featured_videos']['limit'] ?? 4)));
if (!empty($cms['featured_videos']['enabled'])) {
    try {
        $vStmt = $pdo->prepare("
            SELECT v.*,
                   (SELECT COUNT(*) FROM watch_history wh WHERE wh.user_id = ? AND wh.video_id = v.id AND DATE(wh.watched_at) = CURDATE()) AS watched_today
            FROM videos v
            WHERE v.is_active = 1
            ORDER BY v.sort_order ASC, v.id DESC
            LIMIT {$feat_limit}
        ");
        $vStmt->execute([$user['id']]);
        $featured_videos = $vStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable) {}
}

// Riwayat Tontonan Belum Selesai
$continue_watching_home = [];
$cw_limit = max(1, min(10, (int)($cms['continue_watching']['limit'] ?? 3)));
if (!$is_guest && !empty($cms['continue_watching']['enabled'])) {
    try {
        $pdo->prepare(
            "DELETE uwp FROM user_watch_progress uwp
             JOIN watch_history wh ON wh.user_id = uwp.user_id AND wh.video_id = uwp.video_id AND DATE(wh.watched_at) = CURDATE()
             WHERE uwp.user_id = ?"
        )->execute([$user['id']]);

        $cwHStmt = $pdo->prepare("
            SELECT uwp.seconds_watched, uwp.duration, uwp.last_position,
                   v.id, v.title, v.youtube_id, v.watch_duration, v.reward_amount
            FROM user_watch_progress uwp
            JOIN videos v ON v.id = uwp.video_id
            WHERE uwp.user_id = ? AND v.is_active = 1
            ORDER BY uwp.updated_at DESC
            LIMIT {$cw_limit}
        ");
        $cwHStmt->execute([$user['id']]);
        $continue_watching_home = $cwHStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable) {}
}

// Notifikasi Preview
$notif_preview = [];
if (!$is_guest && !empty($cms['notif_inbox']['enabled'])) {
    try {
        $uid = $user['id'];
        $np = $pdo->prepare("
            SELECT n.* FROM notifications n LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
            WHERE nr.id IS NULL AND (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, JSON_QUOTE(?))))
              AND (n.expires_at IS NULL OR n.expires_at > NOW()) ORDER BY n.created_at DESC LIMIT 2
        ");
        $np->execute([$uid, (string)$uid]);
        $notif_preview = $np->fetchAll();
    } catch (\Throwable) {}
}

// Level Membership
$membership_name = '';
if ($user['membership_id'] && $user['membership_expires_at'] && strtotime((string)$user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT name FROM memberships WHERE id=?");
    $ms->execute([$user['membership_id']]);
    $membership_name = $ms->fetchColumn();
}
if (!$membership_name) {
    $membership_name = $pdo->query("SELECT name FROM memberships WHERE price=0 AND is_active=1 ORDER BY sort_order ASC LIMIT 1")->fetchColumn();
    if (!$membership_name) $membership_name = 'Petani Pemula';
}

// ── STATUS CHECK-IN HARIAN ──
$has_checked_in_today = false;
if (!$is_guest) {
    $has_checked_in_today = (($user['last_checkin'] ?? '') === date('Y-m-d'));
}

// ── KELAYAKAN FITUR UPGRADE ──
$user_has_good_income = !$is_guest && (
    (float)($user['total_earned'] ?? 0) >= 1500 || 
    (float)($user['balance_wd'] ?? 0) >= 1500 ||
    $today_watch_earned >= 1000 || 
    $watch_today >= 2 || 
    (int)($user['watch_count_today'] ?? 0) >= 2 ||
    (!empty($user['membership_id']) && (int)$user['membership_id'] > 1)
);

$show_upgrade_feature = $user_has_good_income || (!$is_guest && (float)($user['total_earned'] ?? 0) > 0);

// ── PENGATURAN POPUP ──
$popup_enabled     = setting($pdo, 'popup_enabled', '1') === '1';
$popup_title       = setting($pdo, 'popup_title', 'Informasi Spesial PanenCuan');
$popup_body        = setting($pdo, 'popup_body', 'Biar makin lancar dapat reward, yuk tonton video pilihan dan panen hasil sawahmu hari ini!');
$popup_cta_text    = setting($pdo, 'popup_cta_text', 'Buka Sekarang');
$popup_cta_url     = setting($pdo, 'popup_cta_url', '/panduan');
$popup_delay       = max(200, (int) setting($pdo, 'popup_delay', '500'));
$popup_reset_hours = max(0, (int) setting($pdo, 'popup_reset_hours', '0'));

$popup_title    = trim(preg_replace('/^\?+\s*/', '', (string)$popup_title));
$popup_cta_text = trim(preg_replace('/^\?+\s*/', '', (string)$popup_cta_text));
$popup_body     = trim(str_replace('??', '', (string)$popup_body));

if ($popup_enabled && str_contains($popup_cta_url, 'upgrade') && !$show_upgrade_feature) {
    $popup_enabled = false;
}

// ── KAMPANYE PROMOSI THREADS ──
$threads_campaign_enabled = setting($pdo, 'threads_campaign_enabled', '1') === '1';
$threads_step1_reward     = (float)setting($pdo, 'threads_campaign_reward', '25000');
$threads_step2_reward     = (float)setting($pdo, 'threads_campaign_reward_step2', '50000');

// ── CEK STATUS SURVEI BERHADIAH ──
$user_has_surveyed = false;
if (!$is_guest && !empty($user['id'])) {
    try {
        $survCheck = $pdo->prepare("SELECT 1 FROM user_surveys WHERE user_id = ? LIMIT 1");
        $survCheck->execute([$user['id']]);
        $user_has_surveyed = (bool)$survCheck->fetchColumn();
    } catch (\Throwable) {}
}

$pageTitle = 'Beranda';
$activePage = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>
<style>
/* ══════════════════════════════════════════════════════════
   BERANDA PANENCUAN — lihat DESIGN.md (ENERGY 2 · RHYTHM 2 · MOTION 2)
   Fokus: kartu saldo. Aksen emas hanya untuk momen uang.
   ══════════════════════════════════════════════════════════ */
.h-wrap { position: relative; z-index: 1; display: flex; flex-direction: column; gap: 10px; padding: 10px 12px 6px; }
.h-wrap img { -webkit-user-drag: none; }

/* Muncul bertahap saat masuk layar (dipicu scroll) */
[data-rv] { opacity: 0; transform: translateY(10px); transition: opacity .45s ease, transform .5s var(--pc-ease); transition-delay: var(--d, 0s); }
[data-rv].in { opacity: 1; transform: none; }

/* Tombol: tinggi 44 px (target sentuh) */
.h-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 44px; padding: 0 16px;
  border: 0; border-radius: 12px; cursor: pointer; text-decoration: none; white-space: nowrap;
  font-family: var(--pc-font); font-size: 15px; font-weight: 600; color: #fff; background: var(--pc-green);
  transition: transform .15s var(--pc-ease), background .15s;
}
.h-btn:active { transform: scale(.95); }
.h-btn:focus-visible, .h-tile:focus-visible, .h-row:focus-visible, .h-act:focus-visible { outline: 3px solid rgba(21, 128, 61, .35); outline-offset: 2px; }
.h-btn img { width: 24px; height: 24px; object-fit: contain; }
.h-btn.gold { background: var(--pc-gold); color: #3d2a00; }
.h-btn.soft { background: var(--pc-soft); color: var(--pc-ink); }
.h-btn.full { width: 100%; }

/* Judul seksi */
.h-sec { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; margin: 6px 2px -2px; }
.h-sec b { font-family: var(--pc-font); font-size: 16px; font-weight: 600; }
.h-sec a { display: inline-flex; align-items: center; min-height: 44px; padding: 0 2px; font-size: 12.5px; font-weight: 800; color: var(--pc-green); text-decoration: none; }

/* Kartu dasar: putih + garis tipis, tanpa bayangan */
.h-card { border-radius: 18px; background: var(--pc-card); box-shadow: inset 0 0 0 1px var(--pc-line); }

/* ── 1. KARTU SALDO (fokus layar) ── */
.h-hero {
  position: relative; overflow: hidden; border-radius: 22px; padding: 14px 14px 12px; color: #fff;
  background: linear-gradient(170deg, #166534 0%, #14532d 100%);
  box-shadow: 0 12px 28px rgba(20, 83, 45, .28);
}
.h-hero-field { position: absolute; left: 0; right: 0; bottom: 0; width: 100%; height: 46px; pointer-events: none; }
.h-hero-top { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.h-who { min-width: 0; font-size: 13px; font-weight: 800; color: #dcfce7; }
.h-who b { color: #fff; font-family: var(--pc-font); font-weight: 600; font-size: 15px; }
.h-rank { display: inline-flex; align-items: center; gap: 4px; margin-top: 1px; font-size: 11.5px; font-weight: 800; color: #bbf7d0; }
.h-rank i { color: var(--pc-gold); }
.h-today { flex-shrink: 0; font-size: 11.5px; font-weight: 800; color: #bbf7d0; text-align: right; line-height: 1.2; }
.h-today b { display: block; font-family: var(--pc-font); font-size: 14px; font-weight: 600; color: #fff; }

.h-bal { position: relative; display: flex; align-items: flex-end; justify-content: space-between; gap: 8px; margin-top: 12px; }
.h-bal small { display: block; font-size: 12px; font-weight: 800; color: #bbf7d0; }
.h-bal-num { font-family: var(--pc-font); font-size: clamp(26px, 8.4vw, 32px); font-weight: 700; line-height: 1.05; letter-spacing: -.4px; font-variant-numeric: tabular-nums; }
.h-bal-num span { font-size: .55em; margin-right: 3px; color: #bbf7d0; }
.h-bal-coin { width: 58px; height: 58px; object-fit: contain; margin-bottom: -2px; filter: drop-shadow(0 6px 6px rgba(0, 0, 0, .25)); }
.h-dep { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(255, 255, 255, .14); font-size: 12px; font-weight: 800; color: #bbf7d0; }
.h-dep b { font-family: var(--pc-font); font-size: 14px; font-weight: 600; color: #fff; margin-left: 4px; }
.h-dep a { display: inline-flex; align-items: center; gap: 3px; min-height: 32px; padding: 0 10px; border-radius: 10px; color: #fff; font-weight: 900; text-decoration: none; background: rgba(255, 255, 255, .12); }
.h-acts { position: relative; display: grid; grid-template-columns: 1.3fr 1fr 1fr; gap: 8px; margin-top: 10px; }
.h-act {
  display: flex; align-items: center; justify-content: center; gap: 5px; min-height: 44px; border-radius: 12px; text-decoration: none;
  font-family: var(--pc-font); font-size: 14px; font-weight: 600; color: #fff; background: rgba(255, 255, 255, .12);
  transition: transform .15s var(--pc-ease), background .15s;
}
.h-act:active { transform: scale(.94); background: rgba(255, 255, 255, .2); }
.h-act.gold { background: var(--pc-gold); color: #3d2a00; }
.h-act i { font-size: 18px; }

/* ── 2. KARTU SAWAH (motif identitas) ── */
.h-farm { overflow: hidden; }
.h-farm-scene { position: relative; height: 78px; background: linear-gradient(180deg, #d9f1ff 0%, #eef9e8 100%); }
.h-farm-scene svg { position: absolute; inset: 0; width: 100%; height: 100%; }
.h-rice { transform-origin: 50% 100%; transform-box: fill-box; }
.h-farm.in .h-rice { animation: hRice 2.4s ease-in-out 3; }
.h-farm.in .h-rice.d2 { animation-delay: .4s; } .h-farm.in .h-rice.d3 { animation-delay: .8s; }
@keyframes hRice { 50% { transform: rotate(-8deg); } }
.h-farm-props { position: absolute; right: 12px; bottom: 4px; display: flex; align-items: flex-end; }
.h-farm-props img { object-fit: contain; filter: drop-shadow(0 4px 4px rgba(0, 0, 0, .18)); }
.h-farm-body { display: flex; flex-direction: column; gap: 8px; padding: 10px 12px 12px; }
.h-farm-head { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
.h-farm-head h2 { margin: 0; font-family: var(--pc-font); font-size: 18px; font-weight: 700; }
.h-farm-head small { font-size: 11.5px; font-weight: 800; color: var(--pc-muted); }
.h-farm-body p { margin: -4px 0 0; font-size: 12.5px; font-weight: 700; color: var(--pc-muted); line-height: 1.4; }
.h-stats { display: flex; flex-wrap: wrap; gap: 6px; }
.h-stat { display: inline-flex; align-items: center; gap: 4px; min-height: 28px; padding: 0 9px 0 4px; border-radius: 10px; font-size: 12px; font-weight: 800; background: var(--pc-soft); }
.h-stat img { width: 22px; height: 22px; object-fit: contain; }
.h-stat.ready { background: var(--pc-gold-l); color: #6b4a00; }

/* ── 3. MISI NONTON ── */
.h-watch { display: flex; align-items: center; gap: 12px; padding: 10px 12px; }
.h-ring { position: relative; width: 52px; height: 52px; flex-shrink: 0; }
.h-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.h-ring circle { fill: none; stroke-width: 6; }
.h-ring .bg { stroke: var(--pc-green-l); }
.h-ring .fg { stroke: var(--pc-green); stroke-linecap: round; stroke-dasharray: 151; stroke-dashoffset: 151; transition: stroke-dashoffset 1.1s var(--pc-ease) .25s; }
.h-ring span { position: absolute; inset: 0; display: grid; place-items: center; font-family: var(--pc-font); font-size: 12.5px; font-weight: 700; color: var(--pc-green); }
.h-watch-tx { flex: 1; min-width: 0; line-height: 1.25; }
.h-watch-tx b { display: block; font-family: var(--pc-font); font-size: 15.5px; font-weight: 600; }
.h-watch-tx small { font-size: 12px; font-weight: 700; color: var(--pc-muted); }

/* ── 4. PINTASAN (satu baris, bisa digeser) ── */
.h-tiles { display: flex; gap: 4px; overflow-x: auto; margin: 0 -12px; padding: 2px 12px; scrollbar-width: none; scroll-snap-type: x proximity; }
.h-tiles::-webkit-scrollbar { display: none; }
.h-tile {
  flex: 0 0 68px; scroll-snap-align: start; display: flex; flex-direction: column; align-items: center; gap: 5px; padding: 4px 0;
  border: 0; background: none; text-decoration: none; color: var(--pc-ink); font: inherit; cursor: pointer;
}
.h-tile-ic { position: relative; width: 52px; height: 52px; border-radius: 16px; display: grid; place-items: center; font-size: 24px; color: var(--pc-green); background: var(--pc-card); box-shadow: inset 0 0 0 1px var(--pc-line); transition: transform .2s var(--pc-ease); }
.h-tile-ic img { width: 38px; height: 38px; object-fit: contain; }
.h-tile:active .h-tile-ic { transform: scale(.88); }
.h-tile span { font-size: 11.5px; font-weight: 800; text-align: center; line-height: 1.15; }
.h-dot { position: absolute; top: 4px; right: 4px; width: 9px; height: 9px; border-radius: 50%; background: var(--pc-red); box-shadow: 0 0 0 2px #fff; }
.h-done { position: absolute; bottom: -3px; right: -3px; width: 18px; height: 18px; border-radius: 50%; display: grid; place-items: center; font-size: 11px; color: #fff; background: var(--pc-green); box-shadow: 0 0 0 2px #fff; }

/* ── 5. DAFTAR (info & promo dalam satu kartu) ── */
.h-list { padding: 2px 0; }
.h-row {
  display: flex; align-items: center; gap: 10px; width: 100%; min-height: 56px; padding: 8px 12px; border: 0; background: none;
  text-align: left; text-decoration: none; color: var(--pc-ink); font: inherit; cursor: pointer; transition: background .15s;
}
.h-row + .h-row { box-shadow: 0 -1px 0 var(--pc-line); }
.h-row:active { background: var(--pc-soft); }
.h-row-ic { width: 38px; height: 38px; flex-shrink: 0; border-radius: 12px; display: grid; place-items: center; font-size: 20px; color: var(--pc-green); background: var(--pc-green-l); }
.h-row-ic img { width: 30px; height: 30px; object-fit: contain; }
.h-row-ic.dark { background: #18181b; color: #fff; }
.h-row-tx { flex: 1; min-width: 0; line-height: 1.25; }
.h-row-tx b { display: block; font-size: 13.5px; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.h-row-tx small { display: block; font-size: 11.5px; font-weight: 700; color: var(--pc-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.h-row-end { flex-shrink: 0; font-size: 12px; font-weight: 900; color: var(--pc-green); }
.h-row-end.money { padding: 3px 8px; border-radius: 8px; background: var(--pc-gold-l); color: #6b4a00; }
.h-code { font-family: var(--pc-font); font-size: 15px; font-weight: 700; letter-spacing: .5px; color: var(--pc-green); }

/* ── 6. VIDEO (geser) ── */
.h-hscroll { display: flex; gap: 8px; overflow-x: auto; margin: 0 -12px; padding: 2px 12px 4px; scrollbar-width: none; scroll-snap-type: x proximity; }
.h-hscroll::-webkit-scrollbar { display: none; }
.h-vid { flex: 0 0 148px; scroll-snap-align: start; overflow: hidden; display: flex; flex-direction: column; text-decoration: none; color: var(--pc-ink); }
.h-vid.wide { flex-basis: 210px; }
.h-thumb { position: relative; aspect-ratio: 16 / 10; overflow: hidden; background: #e3ead9; }
.h-thumb img { width: 100%; height: 100%; object-fit: cover; }
.h-rew { position: absolute; left: 6px; bottom: 6px; padding: 2px 7px; border-radius: 8px; font-size: 11px; font-weight: 900; background: var(--pc-gold); color: #3d2a00; }
.h-dur { position: absolute; right: 6px; bottom: 6px; padding: 2px 6px; border-radius: 6px; font-size: 10.5px; font-weight: 900; background: rgba(0, 0, 0, .62); color: #fff; }
.h-vid .h-done { top: 6px; right: 6px; bottom: auto; }
.h-vid-b { padding: 7px 9px 9px; }
.h-vid-b b { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; font-size: 12px; font-weight: 800; line-height: 1.3; min-height: 31px; }
.h-bar { height: 4px; margin-top: 6px; border-radius: 999px; background: var(--pc-soft); overflow: hidden; }
.h-bar i { display: block; height: 100%; border-radius: 999px; background: var(--pc-green); }

/* ── 7. TIPS ── */
.h-tip { display: flex; gap: 8px; align-items: flex-start; padding: 2px 4px; font-size: 12px; font-weight: 700; color: var(--pc-muted); line-height: 1.45; }
.h-tip i { font-size: 18px; color: var(--pc-gold); margin-top: 1px; }
.h-tip b { color: var(--pc-ink); }

/* Toast */
.cuan-toast {
  position: fixed; z-index: 99998; left: 50%; top: 68px; transform: translate(-50%, -16px); opacity: 0; pointer-events: none;
  display: flex; align-items: center; gap: 6px; max-width: calc(100% - 32px); padding: 10px 16px; border-radius: 12px; font-size: 13px; font-weight: 800;
  background: #1d2b1f; color: #fff; box-shadow: 0 10px 24px rgba(0, 0, 0, .2); transition: transform .3s var(--pc-ease), opacity .2s;
}
.cuan-toast.show { transform: translate(-50%, 0); opacity: 1; }

/* ── MODAL ── */
.h-backdrop {
  position: fixed; inset: 0; z-index: 99990; display: flex; align-items: flex-end; justify-content: center; padding: 12px;
  background: rgba(15, 30, 18, .45); opacity: 0; visibility: hidden; transition: opacity .2s, visibility .2s;
}
.h-backdrop.open { opacity: 1; visibility: visible; }
.h-modal {
  position: relative; width: 100%; max-width: 420px; padding: 18px 16px 14px; border-radius: 22px; background: var(--pc-card);
  box-shadow: 0 20px 50px rgba(0, 0, 0, .25); transform: translateY(40px); transition: transform .4s var(--pc-ease);
  margin-bottom: env(safe-area-inset-bottom);
}
.h-backdrop.open .h-modal { transform: none; }
.h-modal-head { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; padding-right: 40px; }
.h-modal-ic { width: 52px; height: 52px; flex-shrink: 0; border-radius: 16px; display: grid; place-items: center; font-size: 26px; color: var(--pc-green); background: var(--pc-green-l); }
.h-modal-ic img { width: 42px; height: 42px; object-fit: contain; }
.h-modal-ic.dark { background: #18181b; color: #fff; }
.h-modal h3 { margin: 0; font-family: var(--pc-font); font-size: 18px; font-weight: 700; line-height: 1.25; }
.h-modal-x { position: absolute; top: 12px; right: 12px; width: 44px; height: 44px; border: 0; border-radius: 12px; background: none; color: var(--pc-muted); font-size: 18px; cursor: pointer; display: grid; place-items: center; }
.h-modal p { margin: 0 0 12px; font-size: 13px; font-weight: 700; color: var(--pc-muted); line-height: 1.5; }
.h-modal-acts { display: flex; flex-direction: column; gap: 6px; }
.h-step { display: flex; gap: 10px; padding: 9px 0; font-size: 12.5px; font-weight: 700; line-height: 1.45; }
.h-step + .h-step { box-shadow: 0 -1px 0 var(--pc-line); }
.h-step-n { width: 26px; height: 26px; flex-shrink: 0; border-radius: 8px; display: grid; place-items: center; font-family: var(--pc-font); font-weight: 700; color: var(--pc-green); background: var(--pc-green-l); }
.h-step em { font-style: normal; font-weight: 900; color: #6b4a00; background: var(--pc-gold-l); padding: 0 5px; border-radius: 5px; }
.h-row3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin: 4px 0 10px; }
.h-row3 .h-btn { padding: 0 8px; font-size: 13.5px; }

@media (prefers-reduced-motion: reduce) {
  [data-rv] { opacity: 1; transform: none; transition: none; }
  .h-farm.in .h-rice { animation: none; }
  .h-ring .fg, .h-modal { transition: none; }
}
</style>

<?php
  $ic3d = static fn(string $n): string => '/assets/img/icons3d/' . $n . '.png';
  $rp0  = static fn(float $n): string => number_format($n, 0, ',', '.');
  $FS = $cms['farm_spotlight'] ?? [];
  $SB = $cms['survey_banner']; $PB = $cms['promo_banner']; $TC = $cms['threads_card']; $FP = $cms['floating_promo'] ?? [];
  if (setting($pdo, 'survey_enabled', '1') !== '1' || $user_has_surveyed) $SB['enabled'] = false;
?>
<div class="h-wrap">

  <!-- 1. KARTU SALDO (#tour-balance-box) -->
  <?php if ($H['show_user_bar'] || $H['show_balance_card'] || $H['show_quick_actions']): ?>
  <section class="h-hero" id="tour-balance-box" data-rv>
    <svg class="h-hero-field" viewBox="0 0 400 46" preserveAspectRatio="none" aria-hidden="true">
      <path d="M0 22 C90 8 180 30 270 16 C330 8 370 18 400 14 V46 H0 Z" fill="rgba(255,255,255,.05)"/>
      <path d="M0 34 C100 22 200 42 300 30 C350 25 380 32 400 30 V46 H0 Z" fill="rgba(0,0,0,.10)"/>
    </svg>

    <?php if ($H['show_user_bar']): ?>
    <div class="h-hero-top">
      <div class="h-who">
        Halo, <b><?= htmlspecialchars((string)($user['username'] ?? 'Petani')) ?></b>
        <div class="h-rank"><i class="ph-fill ph-crown-simple" aria-hidden="true"></i><?= htmlspecialchars((string)$membership_name) ?></div>
      </div>
      <?php if ($H['show_today_stats'] && !$is_guest): ?>
      <div class="h-today">Hari ini<b>+Rp <?= $rp0($today_watch_earned) ?></b></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($H['show_balance_card']): ?>
    <div class="h-bal">
      <div>
        <small>Saldo siap tarik</small>
        <div class="h-bal-num"><span>Rp</span><b data-count="<?= (int)round((float)($user['balance_wd'] ?? 0)) ?>"><?= $rp0((float)($user['balance_wd'] ?? 0)) ?></b></div>
      </div>
      <img class="h-bal-coin" src="<?= $ic3d('coins') ?>" alt="" width="58" height="58">
    </div>
    <?php if (!$is_guest): ?>
    <div class="h-dep">
      <span>Saldo Beli <b>Rp <?= $rp0((float)($user['balance_dep'] ?? 0)) ?></b></span>
      <a href="/deposit"><i class="ph-bold ph-plus" aria-hidden="true"></i>Isi</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($H['show_quick_actions']): ?>
    <div class="h-acts">
      <?php if ($is_guest): ?>
      <a href="/register" class="h-act gold">Daftar gratis</a>
      <a href="/login" class="h-act">Masuk</a>
      <a href="/panduan" class="h-act">Panduan</a>
      <?php else: ?>
      <a href="/withdraw" class="h-act gold" id="tour-btn-withdraw"><i class="ph-bold ph-hand-coins" aria-hidden="true"></i>Tarik saldo</a>
      <a href="/missions" class="h-act"><i class="ph-bold ph-target" aria-hidden="true"></i>Misi</a>
      <a href="/history" class="h-act"><i class="ph-bold ph-receipt" aria-hidden="true"></i>Riwayat</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- 2. SAWAH PANENCUAN (#tour-sidejob-card) -->
  <?php if (!empty($FS['enabled'])): ?>
  <section class="h-card h-farm" id="tour-sidejob-card" data-rv style="--d:.05s">
    <div class="h-farm-scene" aria-hidden="true">
      <svg viewBox="0 0 400 78" preserveAspectRatio="xMidYMax slice" fill="none">
        <circle cx="64" cy="20" r="11" fill="#fde047" opacity=".9"/>
        <polygon points="0,44 50,20 96,40 150,14 210,42 262,22 320,42 400,24 400,78 0,78" fill="#bbf7d0"/>
        <path d="M0 50 C80 38 170 56 260 46 C330 38 370 48 400 45 V78 H0 Z" fill="#86efac"/>
        <path d="M0 62 C90 54 200 70 300 60 C350 55 380 61 400 60 V78 H0 Z" fill="#4ade80"/>
        <path d="M0 70 C100 64 220 76 320 68 C360 65 390 70 400 69 V78 H0 Z" fill="#22c55e"/>
        <g stroke="#a16207" stroke-width="2" stroke-linecap="round">
          <g class="h-rice"><path d="M26 74 V60"/><path d="M33 73 V57"/><path d="M40 74 V61"/></g>
          <g class="h-rice d2"><path d="M104 72 V58"/><path d="M111 71 V55"/><path d="M118 72 V59"/></g>
          <g class="h-rice d3"><path d="M182 73 V59"/><path d="M189 72 V56"/><path d="M196 73 V60"/></g>
        </g>
      </svg>
      <div class="h-farm-props"><img src="<?= $ic3d('padi') ?>" alt="" width="46" height="46" style="width:46px;height:46px"><img src="<?= $ic3d('basket') ?>" alt="" width="58" height="58" style="width:58px;height:58px;margin-left:-10px"></div>
    </div>
    <div class="h-farm-body">
      <div class="h-farm-head">
        <h2><?= htmlspecialchars((string)($FS['title'] ?: 'Sawah PanenCuan')) ?></h2>
        <?php if ($farm_summary && $farm_summary['total_planted'] > 0): ?><small><?= $farm_summary['total_planted'] ?> petak ditanami</small><?php endif; ?>
      </div>
      <p><?= htmlspecialchars((string)($FS['desc'] ?: 'Tanam bibit, panen, lalu jual ke pasar jadi saldo tarik.')) ?></p>
      <?php if ($farm_summary && ($farm_summary['ready_count'] || $farm_summary['growing_count'] || $farm_summary['harvest_count'])): ?>
      <div class="h-stats">
        <?php if ($farm_summary['ready_count'] > 0): ?><span class="h-stat ready"><img src="<?= $ic3d('padi') ?>" alt="" width="22" height="22"><?= $farm_summary['ready_count'] ?> siap panen</span><?php endif; ?>
        <?php if ($farm_summary['growing_count'] > 0): ?><span class="h-stat"><img src="<?= $ic3d('seed') ?>" alt="" width="22" height="22"><?= $farm_summary['growing_count'] ?> tumbuh</span><?php endif; ?>
        <?php if ($farm_summary['harvest_count'] > 0): ?><span class="h-stat"><img src="<?= $ic3d('barn') ?>" alt="" width="22" height="22"><?= $farm_summary['harvest_count'] ?> di lumbung</span><?php endif; ?>
      </div>
      <?php endif; ?>
      <a href="<?= htmlspecialchars((string)($FS['url'] ?: '/farm')) ?>" class="h-btn full">
        <?= $farm_summary && $farm_summary['ready_count'] > 0 ? 'Panen sekarang' : htmlspecialchars((string)($FS['cta'] ?: 'Buka sawah')) ?>
      </a>
    </div>
  </section>
  <?php endif; ?>

  <!-- 3. MISI NONTON (#tour-video-mission) -->
  <?php if (!empty($H['show_watch_cta'])): ?>
  <section class="h-card h-watch" id="tour-video-mission" data-rv style="--d:.08s">
    <?php if ($H['show_watch_mission']): ?>
    <div class="h-ring" data-ring="<?= $watch_pct ?>" role="img" aria-label="<?= $watch_today ?> dari <?= $watch_limit ?> video hari ini">
      <svg viewBox="0 0 60 60" aria-hidden="true"><circle class="bg" cx="30" cy="30" r="24"/><circle class="fg" cx="30" cy="30" r="24"/></svg>
      <span aria-hidden="true"><?= $watch_today ?>/<?= $watch_limit ?></span>
    </div>
    <?php endif; ?>
    <div class="h-watch-tx">
      <b>Nonton video harian</b>
      <small><?= $watch_remaining > 0 ? $watch_remaining . ' video lagi bisa dibayar hari ini' : 'Kuota hari ini penuh, buka lagi besok' ?></small>
    </div>
    <a href="/videos" class="h-btn" id="tour-btn-watch"><i class="ph-fill ph-play" aria-hidden="true"></i>Nonton</a>
  </section>
  <?php endif; ?>

  <!-- 4. PINTASAN (#tour-bento-grid) -->
  <?php
    $tiles = array_values(array_filter((array)($cms['bento']['tiles'] ?? []), fn($t) => !empty($t['enabled']) && !in_array($t['id'] ?? '', ['fishing', 'farm', 'watch'], true)));
    $tile3d = ['redeem' => 'basket', 'upgrade' => 'coins', 'referral' => 'seed'];
  ?>
  <?php if (!empty($cms['bento']['show_section']) && $tiles): ?>
  <nav class="h-tiles" id="tour-bento-grid" aria-label="<?= htmlspecialchars((string)($cms['bento']['title'] ?: 'Pintasan')) ?>">
    <?php foreach ($tiles as $ti => $t):
      $tid = (string)($t['id'] ?? '');
      $mark = '';
      if ($tid === 'checkin' && !$is_guest) $mark = $has_checked_in_today ? '<span class="h-done" aria-label="sudah absen"><i class="ph-bold ph-check"></i></span>' : '<span class="h-dot" aria-label="belum absen"></span>';
      $ic = isset($tile3d[$tid])
        ? '<span class="h-tile-ic"><img src="' . $ic3d($tile3d[$tid]) . '" alt="" width="38" height="38">' . $mark . '</span>'
        : '<span class="h-tile-ic"><i class="ph-fill ' . htmlspecialchars((string)$t['icon']) . '" aria-hidden="true"></i>' . $mark . '</span>';
      $rv = 'data-rv style="--d:' . number_format(0.03 * $ti, 2) . 's"';
    ?>
      <?php if ($tid === 'checkin'): ?>
        <button type="button" class="h-tile" <?= $rv ?> onclick="openCheckinModal()"><?= $ic ?><span><?= htmlspecialchars((string)$t['label']) ?></span></button>
      <?php elseif ($tid === 'threads'): ?>
        <button type="button" class="h-tile" id="tour-threads-promo-card" <?= $rv ?> onclick="openThreadsPromoModal()"><?= $ic ?><span><?= htmlspecialchars((string)$t['label']) ?></span></button>
      <?php else: ?>
        <a href="<?= htmlspecialchars((string)$t['url']) ?>" class="h-tile" <?= $rv ?>><?= $ic ?><span><?= htmlspecialchars((string)$t['label']) ?></span></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <!-- 5. INFO & PELUANG CUAN (satu daftar) -->
  <?php
    $show_notif = !empty($cms['notif_inbox']['enabled']) && $notif_preview;
    $show_ref   = $H['show_ref_strip'] && !$is_guest && $ref_code !== '-';
    $show_tour  = !empty($cms['tour']['show_banner']);
    $has_list   = $show_notif || $show_ref || $show_tour || !empty($SB['enabled']) || !empty($TC['enabled']) || !empty($PB['enabled']) || !empty($FP['enabled']);
  ?>
  <?php if ($has_list): ?>
  <div class="h-card h-list" data-rv>
    <?php if ($show_notif): foreach ($notif_preview as $nf): ?>
    <a href="/notifications" class="h-row">
      <span class="h-row-ic" style="background:#ffe4e6;color:var(--pc-red)"><i class="ph-fill ph-bell-ringing" aria-hidden="true"></i></span>
      <span class="h-row-tx"><b><?= htmlspecialchars((string)($nf['title'] ?? 'Pengumuman')) ?></b><small><?= htmlspecialchars(mb_strimwidth(strip_tags((string)($nf['message'] ?? $nf['body'] ?? '')), 0, 70, '…')) ?></small></span>
    </a>
    <?php endforeach; endif; ?>

    <?php if (!empty($SB['enabled'])): ?>
    <a href="/survey" class="h-row">
      <span class="h-row-ic"><i class="ph-fill ph-clipboard-text" aria-hidden="true"></i></span>
      <span class="h-row-tx"><b><?= htmlspecialchars((string)$SB['title']) ?></b><small><?= htmlspecialchars((string)$SB['desc']) ?></small></span>
      <?php if ($SB['badge']): ?><span class="h-row-end money"><?= htmlspecialchars((string)$SB['badge']) ?></span><?php endif; ?>
    </a>
    <?php endif; ?>

    <?php if (!empty($TC['enabled'])): ?>
    <button type="button" class="h-row" onclick="openThreadsPromoModal()">
      <span class="h-row-ic dark"><i class="ph-bold ph-threads-logo" aria-hidden="true"></i></span>
      <span class="h-row-tx"><b><?= htmlspecialchars((string)$TC['title']) ?></b><small><?= htmlspecialchars((string)$TC['desc']) ?></small></span>
      <?php if ($TC['badge_cuan']): ?><span class="h-row-end money"><?= htmlspecialchars((string)$TC['badge_cuan']) ?></span><?php endif; ?>
    </button>
    <?php endif; ?>

    <?php if (!empty($PB['enabled']) || !empty($FP['enabled'])): ?>
    <button type="button" class="h-row" onclick="openPromoHubModal()">
      <span class="h-row-ic"><img src="<?= $ic3d('coins') ?>" alt="" width="30" height="30"></span>
      <span class="h-row-tx"><b><?= htmlspecialchars((string)($PB['title'] ?: $FP['title'] ?? 'Ajak teman')) ?></b><small><?= htmlspecialchars((string)($PB['desc'] ?: $FP['sub'] ?? '')) ?></small></span>
      <?php if (!empty($PB['badge'])): ?><span class="h-row-end money"><?= htmlspecialchars((string)$PB['badge']) ?></span><?php endif; ?>
    </button>
    <?php endif; ?>

    <?php if ($show_ref): ?>
    <button type="button" class="h-row" onclick="copyRefCode('<?= htmlspecialchars($ref_code) ?>')">
      <span class="h-row-ic"><img src="<?= $ic3d('seed') ?>" alt="" width="30" height="30"></span>
      <span class="h-row-tx"><small>Kode undanganmu</small><span class="h-code"><?= htmlspecialchars($ref_code) ?></span></span>
      <span class="h-row-end"><i class="ph-bold ph-copy" aria-hidden="true"></i> Salin</span>
    </button>
    <?php endif; ?>

    <?php if ($show_tour): ?>
    <div id="tour-invite-card">
      <button type="button" class="h-row" onclick="openTourSelectModal()">
        <span class="h-row-ic" style="background:var(--pc-soft);color:var(--pc-ink)"><i class="ph-fill ph-compass" aria-hidden="true"></i></span>
        <span class="h-row-tx"><b><?= htmlspecialchars((string)$cms['tour']['title']) ?></b><small><?= htmlspecialchars((string)$cms['tour']['desc']) ?></small></span>
      </button>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- 6. LANJUTKAN MENONTON -->
  <?php if (!empty($cms['continue_watching']['enabled']) && $continue_watching_home): ?>
  <div class="h-sec" data-rv><b><?= htmlspecialchars((string)$cms['continue_watching']['title']) ?></b></div>
  <div class="h-hscroll" data-rv>
    <?php foreach ($continue_watching_home as $cw):
      $cwDur = max(1, (int)($cw['watch_duration'] ?: $cw['duration'] ?: 1));
      $cwPct = min(100, (int)round(((float)$cw['seconds_watched'] / $cwDur) * 100));
    ?>
    <a href="/watch/<?= (int)$cw['id'] ?>" class="h-card h-vid wide">
      <span class="h-thumb"><img src="<?= htmlspecialchars(yt_thumb($cw['youtube_id'])) ?>" alt="" loading="lazy" width="210" height="131"><span class="h-rew">+<?= format_rp((float)$cw['reward_amount']) ?></span></span>
      <span class="h-vid-b"><b><?= htmlspecialchars((string)$cw['title']) ?></b><span class="h-bar"><i style="width:<?= $cwPct ?>%"></i></span></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- 7. VIDEO PILIHAN -->
  <?php if (!empty($cms['featured_videos']['enabled']) && !empty($featured_videos)): ?>
  <div class="h-sec" data-rv><b><?= htmlspecialchars((string)($cms['featured_videos']['title'] ?: 'Video pilihan')) ?></b><a href="/videos">Lihat semua</a></div>
  <div class="h-hscroll" data-rv>
    <?php foreach ($featured_videos as $fv): ?>
    <a href="/watch/<?= (int)$fv['id'] ?>" class="h-card h-vid">
      <span class="h-thumb">
        <img src="<?= htmlspecialchars(yt_thumb($fv['youtube_id'])) ?>" alt="" loading="lazy" width="148" height="93" onerror="this.onerror=null;this.src='https://img.youtube.com/vi/<?= htmlspecialchars($fv['youtube_id']) ?>/hqdefault.jpg'">
        <span class="h-rew">+<?= format_rp((float)$fv['reward_amount']) ?></span>
        <span class="h-dur"><?= (int)($fv['watch_duration'] ?? 0) ?> dtk</span>
        <?php if (!empty($fv['watched_today'])): ?><span class="h-done" aria-label="sudah ditonton"><i class="ph-bold ph-check"></i></span><?php endif; ?>
      </span>
      <span class="h-vid-b"><b><?= htmlspecialchars((string)$fv['title']) ?></b></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- 8. TIPS -->
  <?php if (!empty($cms['tips']['enabled']) && trim((string)$cms['tips']['text']) !== ''): ?>
  <p class="h-tip" data-rv><i class="ph-fill ph-lightbulb" aria-hidden="true"></i><span><b><?= htmlspecialchars((string)$cms['tips']['title']) ?>.</b> <?= htmlspecialchars($cms_txt((string)$cms['tips']['text'])) ?></span></p>
  <?php endif; ?>

</div>

<div id="cuan-toast" class="cuan-toast" role="status" aria-live="polite"></div>

<!-- MODAL 1: PENGUMUMAN -->
<?php if ($popup_enabled): ?>
<div id="custom-announcement-popup" class="h-backdrop" onclick="if(event.target===this)closeCustomPopup()">
  <div class="h-modal" role="dialog" aria-modal="true" aria-labelledby="mdl-ann">
    <button type="button" class="h-modal-x" onclick="closeCustomPopup()" aria-label="Tutup"><i class="ph-bold ph-x" aria-hidden="true"></i></button>
    <div class="h-modal-head"><span class="h-modal-ic"><i class="ph-fill ph-megaphone-simple" aria-hidden="true"></i></span><h3 id="mdl-ann"><?= htmlspecialchars($popup_title) ?></h3></div>
    <p><?= nl2br(htmlspecialchars($popup_body)) ?></p>
    <div class="h-modal-acts">
      <a href="<?= htmlspecialchars($popup_cta_url) ?>" class="h-btn full"><?= htmlspecialchars($popup_cta_text) ?></a>
      <button type="button" onclick="closeCustomPopup()" class="h-btn soft full">Nanti saja</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- MODAL 2: CHECK-IN -->
<?php if (!$has_checked_in_today && !$is_guest): ?>
<div id="checkin-prompt-modal" class="h-backdrop" onclick="if(event.target===this)closeCheckinModal()">
  <div class="h-modal" role="dialog" aria-modal="true" aria-labelledby="mdl-ci">
    <button type="button" class="h-modal-x" onclick="closeCheckinModal()" aria-label="Tutup"><i class="ph-bold ph-x" aria-hidden="true"></i></button>
    <div class="h-modal-head"><span class="h-modal-ic"><img src="<?= $ic3d('basket') ?>" alt="" width="42" height="42"></span><h3 id="mdl-ci">Hadiah check-in hari ini belum diambil</h3></div>
    <p>Check-in tiap hari untuk tambahan saldo tarik. Makin panjang streak-nya, makin besar hadiahnya.</p>
    <div class="h-modal-acts">
      <a href="/checkin" class="h-btn gold full">Ambil hadiah</a>
      <button type="button" onclick="closeCheckinModal()" class="h-btn soft full">Nanti saja</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- MODAL 3: PILIH PANDUAN -->
<div id="tour-select-modal" class="h-backdrop" onclick="if(event.target===this)closeTourSelectModal()">
  <div class="h-modal" role="dialog" aria-modal="true" aria-labelledby="mdl-tour">
    <button type="button" class="h-modal-x" onclick="closeTourSelectModal()" aria-label="Tutup"><i class="ph-bold ph-x" aria-hidden="true"></i></button>
    <div class="h-modal-head"><span class="h-modal-ic"><i class="ph-fill ph-compass" aria-hidden="true"></i></span><h3 id="mdl-tour">Panduan singkat</h3></div>
    <p>Kami tunjukkan langkahnya satu per satu: kuota video, cara nonton, sampai tarik saldo. Sekitar 1 menit.</p>
    <div class="h-modal-acts">
      <button type="button" class="h-btn full" onclick="startInteractiveTour('watch')">Mulai panduan nonton video</button>
      <button type="button" onclick="closeTourSelectModal()" class="h-btn soft full">Tutup</button>
    </div>
  </div>
</div>

<!-- MODAL 4: EVENT THREADS -->
<?php if ($threads_campaign_enabled): ?>
<div id="threads-promo-modal" class="h-backdrop" onclick="if(event.target===this)closeThreadsPromoModal()">
  <div class="h-modal" role="dialog" aria-modal="true" aria-labelledby="mdl-th">
    <button type="button" class="h-modal-x" onclick="closeThreadsPromoModal()" aria-label="Tutup"><i class="ph-bold ph-x" aria-hidden="true"></i></button>
    <div class="h-modal-head"><span class="h-modal-ic dark"><i class="ph-bold ph-threads-logo" aria-hidden="true"></i></span><h3 id="mdl-th">Promosi di Threads, dibayar 2 tahap</h3></div>
    <div class="h-step"><span class="h-step-n">1</span><span>Posting tentang PanenCuan di Threads, dapat <em><?= format_rp($threads_step1_reward) ?></em>.</span></div>
    <div class="h-step"><span class="h-step-n">2</span><span>Postinganmu tembus 5.000 views, tambah <em><?= format_rp($threads_step2_reward) ?></em> plus gaji tetap &amp; kontak admin.</span></div>
    <div class="h-modal-acts" style="margin-top:8px">
      <a href="/threads" class="h-btn gold full">Lihat syarat &amp; mulai</a>
      <button type="button" onclick="closeThreadsPromoModal()" class="h-btn soft full">Nanti saja</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- MODAL 5: AJAK TEMAN -->
<div id="promo-hub-modal" class="h-backdrop" onclick="if(event.target===this)closePromoHubModal()">
  <div class="h-modal" role="dialog" aria-modal="true" aria-labelledby="mdl-ref">
    <button type="button" class="h-modal-x" onclick="closePromoHubModal()" aria-label="Tutup"><i class="ph-bold ph-x" aria-hidden="true"></i></button>
    <div class="h-modal-head"><span class="h-modal-ic"><img src="<?= $ic3d('coins') ?>" alt="" width="42" height="42"></span><h3 id="mdl-ref">Ajak teman, dapat komisi</h3></div>
    <p>Bagikan link undanganmu. Komisi masuk tiap kali teman yang kamu undang isi saldo.<?php if ($ref_code !== '-'): ?> Kode kamu: <b class="h-code"><?= htmlspecialchars($ref_code) ?></b><?php endif; ?></p>
    <div class="h-row3">
      <button type="button" class="h-btn soft" onclick="copyPromoFullCaption('<?= htmlspecialchars($ref_url) ?>', '<?= htmlspecialchars($ref_code) ?>')"><i class="ph-bold ph-copy" aria-hidden="true"></i>Teks</button>
      <button type="button" class="h-btn" onclick="shareRefLink('<?= htmlspecialchars($ref_url) ?>', '<?= htmlspecialchars($ref_code) ?>')"><i class="ph-bold ph-share-network" aria-hidden="true"></i>Bagikan</button>
      <a href="/referral" class="h-btn soft">Detail</a>
    </div>
    <?php if ($threads_campaign_enabled): ?>
    <button type="button" class="h-row" style="padding:8px 0" onclick="closePromoHubModal();setTimeout(openThreadsPromoModal,250)">
      <span class="h-row-ic dark"><i class="ph-bold ph-threads-logo" aria-hidden="true"></i></span>
      <span class="h-row-tx"><b>Event promosi Threads</b><small>Dibayar sampai <?= format_rp($threads_step1_reward + $threads_step2_reward) ?></small></span>
      <span class="h-row-end"><i class="ph-bold ph-caret-right" aria-hidden="true"></i></span>
    </button>
    <?php endif; ?>
    <button type="button" onclick="closePromoHubModal()" class="h-btn soft full" style="margin-top:4px">Tutup</button>
  </div>
</div>

<script>
window.__HAS_CHECKED_IN__ = <?= $has_checked_in_today ? 'true' : 'false' ?>;
window.__IS_GUEST__ = <?= $is_guest ? 'true' : 'false' ?>;

function copyRefCode(code) {
  if (!code || code === '-') {
    showCuanToast('<i class="ph-bold ph-warning-circle"></i> Silakan masuk akun untuk melihat kode referral!');
    return;
  }
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(code).then(() => {
      showCuanToast('<i class="ph-bold ph-check"></i> Kode Referral disalin: ' + code);
    }).catch(() => fallbackCopy(code));
  } else {
    fallbackCopy(code);
  }
}

function fallbackCopy(text) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.focus();
  ta.select();
  try {
    document.execCommand('copy');
    showCuanToast('<i class="ph-bold ph-check"></i> Kode disalin: ' + text);
  } catch (err) {
    showCuanToast('<i class="ph-bold ph-warning-circle"></i> Gagal menyalin.');
  }
  document.body.removeChild(ta);
}

function shareRefLink(url, code) {
  if (!code || code === '-') {
    window.location.href = '/login';
    return;
  }
  if (navigator.share) {
    navigator.share({
      title: 'PanenCuan - Nonton Video & Panen Saldo Nyata!',
      text: 'Yuk raih penghasilan uang tunai dari nonton video dan panen hasil sawah! Gunakan kode referralku: ' + code,
      url: url
    }).catch(() => {});
  } else {
    copyRefCode(code);
  }
}

let toastTimer = null;
function showCuanToast(msg) {
  const t = document.getElementById('cuan-toast');
  if (!t) return;
  t.innerHTML = msg;
  t.classList.add('show');
  if (toastTimer) clearTimeout(toastTimer);
  toastTimer = setTimeout(() => {
    t.classList.remove('show');
  }, 2600);
}

/* ── PROMO HUB MODAL CONTROLLER & COPY FUNCTIONS ── */
function openPromoHubModal() {
  const m = document.getElementById('promo-hub-modal');
  if (!m) return;
  m.classList.add('open');
  const b = m.querySelector('.modal');
  if (b) b.classList.add('open');
}

function closePromoHubModal() {
  const m = document.getElementById('promo-hub-modal');
  if (m) {
    const b = m.querySelector('.modal');
    if (b) b.classList.remove('open');
    m.classList.remove('open');
  }
  try {
    sessionStorage.setItem('promo_hub_dismissed', '1');
  } catch(e) {}
  setTimeout(triggerCheckinPopupIfEligible, 350);
}

function copyPromoFullCaption(url, code) {
  if (!code || code === '-') {
    showCuanToast('<i class="ph-bold ph-warning-circle"></i> Silakan masuk akun untuk menyalin teks!');
    return;
  }
  const caption = "🌾 Mau saldo gratis tiap hari sambil santai? Cobain PanenCuan! Nonton video berhadiah uang tunai & panen hasil sawah harian. Cuan siap ditarik ke DANA/Gopay/OVO/Bank setiap hari!\n\nDaftar sekarang lewat link ini:\n" + url + "\n(Gunakan Kode Referralku: " + code + ")";
  
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(caption).then(() => {
      showCuanToast('<i class="ph-bold ph-check"></i> Teks promosi disalin! Siap dibagikan.');
    }).catch(() => fallbackCopy(caption));
  } else {
    fallbackCopy(caption);
  }
}

/* ── INTERACTIVE TOUR & MODAL CONTROLLERS ── */
function openTourSelectModal() {
  const m = document.getElementById('tour-select-modal');
  if (!m) return;
  m.classList.add('open');
  const b = m.querySelector('.modal');
  if (b) b.classList.add('open');
}

function closeTourSelectModal() {
  const m = document.getElementById('tour-select-modal');
  if (m) {
    const b = m.querySelector('.modal');
    if (b) b.classList.remove('open');
    m.classList.remove('open');
  }
  try {
    sessionStorage.setItem('tour_modal_dismissed_session', '1');
  } catch(e) {}
}

function dismissTourBanner() {
  const b = document.getElementById('tour-invite-card');
  if (b) {
    b.style.opacity = '0';
    b.style.transform = 'translateY(-10px)';
    setTimeout(() => { b.style.display = 'none'; }, 280);
  }
  try {
    localStorage.setItem('panen_tour_banner_dismissed', '1');
  } catch (e) {}
}

function startInteractiveTour(track) {
  closeTourSelectModal();
  if (typeof closeCustomPopup === 'function') closeCustomPopup();
  if (typeof closeCheckinModal === 'function') closeCheckinModal();

  if (typeof window.startGlobalTour === 'function') {
    window.startGlobalTour(track || 'watch', 0);
  }
}

/* ── LOGIKA POP-UP BERANTAI (CHAINED MODALS) ── */
function openCustomPopup() {
  try {
    const at = localStorage.getItem('panen_tour_active') || localStorage.getItem('panen_tour_active');
    if (at && JSON.parse(at).isRunning) return;
  } catch(e) {}

  const p = document.getElementById('custom-announcement-popup');
  if (!p) {
    triggerCheckinPopupIfEligible();
    return;
  }
  p.classList.add('open');
  const b = p.querySelector('.modal');
  if (b) b.classList.add('open');
}

function closeCustomPopup() {
  const p = document.getElementById('custom-announcement-popup');
  if (p) {
    const b = p.querySelector('.modal');
    if (b) b.classList.remove('open');
    p.classList.remove('open');
  }
  try {
    localStorage.setItem('panen_popup_seen', JSON.stringify({ ts: Date.now() }));
  } catch (e) {}

  setTimeout(() => {
    const promoDismissed = sessionStorage.getItem('promo_hub_dismissed') === '1';
    if (!promoDismissed) {
      openPromoHubModal();
      return;
    }
    if (typeof triggerThreadsPopupIfEligible === 'function' && triggerThreadsPopupIfEligible()) {
      return;
    }
    triggerCheckinPopupIfEligible();
  }, 350);
}

function openThreadsPromoModal() {
  const m = document.getElementById('threads-promo-modal');
  if (!m) return;
  m.classList.add('open');
  const b = m.querySelector('.modal');
  if (b) b.classList.add('open');
}

function closeThreadsPromoModal() {
  const m = document.getElementById('threads-promo-modal');
  if (m) {
    const b = m.querySelector('.modal');
    if (b) b.classList.remove('open');
    m.classList.remove('open');
  }
  try {
    sessionStorage.setItem('threads_promo_modal_closed', '1');
  } catch(e) {}

  setTimeout(triggerCheckinPopupIfEligible, 350);
}

function triggerThreadsPopupIfEligible() {
  if (window.__IS_GUEST__) return false;
  if (sessionStorage.getItem('threads_promo_modal_closed') === '1') return false;
  const m = document.getElementById('threads-promo-modal');
  if (!m) return false;
  m.classList.add('open');
  const b = m.querySelector('.modal');
  if (b) b.classList.add('open');
  return true;
}

function triggerCheckinPopupIfEligible() {
  if (window.__HAS_CHECKED_IN__ || window.__IS_GUEST__) return;
  if (sessionStorage.getItem('checkin_prompt_closed') === '1') return;

  try {
    const at = localStorage.getItem('panen_tour_active') || localStorage.getItem('panen_tour_active');
    if (at && JSON.parse(at).isRunning) return;
  } catch(e) {}

  const m = document.getElementById('checkin-prompt-modal');
  if (!m) return;
  m.classList.add('open');
  const b = m.querySelector('.modal');
  if (b) b.classList.add('open');
}

function openCheckinModal() {
  const m = document.getElementById('checkin-prompt-modal');
  if (!m) { window.location.href = '/checkin'; return; }
  m.classList.add('open');
  const b = m.querySelector('.modal');
  if (b) b.classList.add('open');
}

function closeCheckinModal() {
  const m = document.getElementById('checkin-prompt-modal');
  if (m) {
    const b = m.querySelector('.modal');
    if (b) b.classList.remove('open');
    m.classList.remove('open');
  }
  try {
    sessionStorage.setItem('checkin_prompt_closed', '1');
  } catch (e) {}
}

document.addEventListener('DOMContentLoaded', () => {
  const allTourDone = localStorage.getItem('panen_tour_done') === '1' || localStorage.getItem('panen_tour_done') === '1';

  try {
    const b = document.getElementById('tour-invite-card');
    if (b) {
      if (allTourDone || localStorage.getItem('panen_tour_banner_dismissed') === '1' || localStorage.getItem('panen_tour_banner_dismissed') === '1') {
        b.style.display = 'none';
      } else {
        b.style.display = 'block';
      }
    }
  } catch (e) {}

  let isTourRunningNow = false;
  try {
    const rawTour = localStorage.getItem('panen_tour_active') || localStorage.getItem('panen_tour_active');
    if (rawTour && JSON.parse(rawTour).isRunning) {
      isTourRunningNow = true;
    }
  } catch(e) {}

  const customPopupEl = document.getElementById('custom-announcement-popup');
  const resetMs = <?= $popup_reset_hours ?> * 3600000;
  let showCustom = <?= $popup_enabled ? 'true' : 'false' ?>;

  if (showCustom && customPopupEl) {
    try {
      const raw = localStorage.getItem('panen_popup_seen') || localStorage.getItem('mancing_popup_seen');
      if (raw) {
        const data = JSON.parse(raw);
        if (resetMs > 0 && (Date.now() - data.ts) < resetMs) {
          showCustom = false;
        }
      }
    } catch(e){}
  }

  if (!isTourRunningNow) {
    if (showCustom && customPopupEl) {
      setTimeout(openCustomPopup, <?= $popup_delay ?>);
    } else {
      const promoDismissed = sessionStorage.getItem('promo_hub_dismissed') === '1';
      if (!promoDismissed) {
        setTimeout(openPromoHubModal, 900);
      } else {
        setTimeout(triggerCheckinPopupIfEligible, 600);
      }
    }
  }
});
</script>

<script>
/* ── ANIMASI BERANDA: muncul bertahap, hitung saldo, cincin progres, carousel promo ── */
(function () {
  const reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  // 1. Elemen [data-rv] muncul saat masuk layar
  const items = document.querySelectorAll('[data-rv]');
  if (reduce || !('IntersectionObserver' in window)) {
    items.forEach((el) => el.classList.add('in'));
  } else {
    const io = new IntersectionObserver((entries) => entries.forEach((e) => {
      if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
    }), { rootMargin: '0px 0px -6% 0px', threshold: 0.05 });
    items.forEach((el) => io.observe(el));
  }

  // 2. Angka saldo menghitung naik
  document.querySelectorAll('[data-count]').forEach((el) => {
    const target = +el.dataset.count || 0;
    if (reduce || target <= 0) return;
    const t0 = performance.now(), dur = 1100;
    const step = (t) => {
      const k = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - k, 3);
      el.textContent = Math.round(target * e).toLocaleString('id-ID');
      if (k < 1) requestAnimationFrame(step);
    };
    el.textContent = '0';
    requestAnimationFrame(step);
  });

  // 3. Cincin progres misi nonton
  document.querySelectorAll('.h-ring[data-ring]').forEach((ring) => {
    const fg = ring.querySelector('.fg');
    if (!fg) return;
    const pct = Math.max(0, Math.min(100, +ring.dataset.ring || 0));
    requestAnimationFrame(() => requestAnimationFrame(() => { fg.style.strokeDashoffset = String(151 - 151 * pct / 100); }));
  });

})();
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
