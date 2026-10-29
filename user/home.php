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
        $plotStmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_planted,
                COALESCE(SUM(CASE WHEN matures_at <= ? THEN 1 ELSE 0 END), 0) as ready_count,
                COALESCE(SUM(CASE WHEN matures_at > ? THEN 1 ELSE 0 END), 0) as growing_count
            FROM farm_plots
            WHERE user_id = ?
        ");
        $plotStmt->execute([$now, $now, $user['id']]);
        $plotsData = $plotStmt->fetch(PDO::FETCH_ASSOC);

        $invStmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM farm_inventory WHERE user_id = ? AND kind = 'harvest'
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

$pageTitle = 'Nonton Video & Panen Cuan';
$activePage = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ============================================================
   BERANDA SAWAH PANEN CUAN — LEMBAH PERKAMEN & KAYU GAME UI
   Tidak ada neo-brutalism: tanpa border hitam tebal, tanpa shadow hitam offset.
   ============================================================ */

body {
  background: linear-gradient(180deg, #8fd3ff 0%, #c9ecff 35%, #dcefff 100%) !important;
  color: var(--ink);
  font-family: var(--font-body);
}

.valley-home-stage {
  position: relative;
  width: 100%;
  max-width: 440px;
  margin: 0 auto;
  padding: 10px 12px 28px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.valley-home-stage > * {
  position: relative;
  z-index: 1;
}

/* 1. Latar Pegunungan Low-Poly Inline SVG di Bagian Atas */
.valley-sky-deco {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 210px;
  pointer-events: none;
  z-index: 0;
  overflow: hidden;
}
.valley-sky-deco svg {
  width: 100%;
  height: 100%;
  display: block;
}

/* 2. User Hero & Saldo Showcase */
.user-hero-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 4px;
}
.user-greet-group {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}
.user-avatar-slot {
  width: 46px;
  height: 46px;
  border-radius: 15px;
  background: radial-gradient(circle at 35% 35%, #fff, var(--parch-2));
  box-shadow: 0 0 0 2px var(--slot-bd), 0 3px 6px rgba(60, 30, 5, .18);
  display: grid;
  place-items: center;
  padding: 5px;
  flex-shrink: 0;
}
.user-avatar-slot img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.user-name-meta {
  display: flex;
  flex-direction: column;
  line-height: 1.15;
  min-width: 0;
}
.user-name-meta b {
  font-family: var(--font-game);
  font-size: 16px;
  font-weight: 700;
  color: var(--ink);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.user-name-meta .lvl {
  margin-top: 3px;
  align-self: flex-start;
}

.today-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 9px;
  border-radius: 999px;
  background: linear-gradient(180deg, #fff3b0, var(--y1));
  box-shadow: 0 2px 0 var(--y2), 0 2px 5px rgba(60, 30, 5, .15);
  font-family: var(--font-game);
  font-size: 11.5px;
  font-weight: 700;
  color: #5b3405;
  white-space: nowrap;
}

/* Showcase Dua Dompet (Saldo Siap Tarik & Saldo Beli/Kas) */
.wallets-container {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 6px 0 4px;
}
.wallet-box {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 12px 8px 8px;
  border-radius: 18px;
  background: rgba(59, 36, 20, .82);
  color: #fff;
  box-shadow: 0 3px 0 rgba(59, 36, 20, .45), 0 5px 12px rgba(0, 0, 0, .22), inset 0 1px 1px rgba(255, 255, 255, .25);
}
.wallet-box.wallet-box--primary {
  background: linear-gradient(180deg, rgba(65, 40, 22, .92), rgba(45, 26, 12, .94));
  padding: 10px 14px 10px 10px;
}
.wallet-lead {
  display: flex;
  align-items: center;
  gap: 9px;
  min-width: 0;
}
.wallet-lead .wal-3d-ic {
  width: 36px;
  height: 36px;
  flex-shrink: 0;
  display: grid;
  place-items: center;
}
.wallet-box.wallet-box--primary .wallet-lead .wal-3d-ic {
  width: 44px;
  height: 44px;
}
.wallet-meta {
  display: flex;
  flex-direction: column;
  line-height: 1;
}
.wallet-meta small {
  font-size: 9.5px;
  font-weight: 800;
  color: #fde68a;
  text-transform: uppercase;
  letter-spacing: .4px;
  margin-bottom: 2px;
}
.wallet-meta b {
  font-family: var(--font-game);
  font-size: 17px;
  font-weight: 700;
  color: #fff;
  font-variant-numeric: tabular-nums;
}
.wallet-box.wallet-box--primary .wallet-meta b {
  font-size: 23px;
  color: #fff;
  text-shadow: 0 1px 2px rgba(0, 0, 0, .5);
}

.hero-actions-row {
  display: grid;
  grid-template-columns: 1.2fr 1.2fr 0.9fr;
  gap: 8px;
  margin-top: 6px;
}

/* 3. Strip Ringan (Mascot, Notice, Tour, Referral) */
.farm-mascot-strip {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  border-radius: 16px;
  background: var(--slot);
  box-shadow: 0 0 0 2px var(--slot-bd), 0 3px 6px rgba(60, 30, 5, .08);
  font-size: 12px;
  font-weight: 700;
  color: var(--ink);
  line-height: 1.35;
}
.farm-mascot-strip img {
  width: 32px;
  height: 32px;
  flex-shrink: 0;
  object-fit: contain;
}

.farm-strip-btn {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  border-radius: 16px;
  background: var(--slot);
  box-shadow: 0 0 0 2px var(--slot-bd), 0 2px 5px rgba(60, 30, 5, .08);
  color: var(--ink);
  text-decoration: none;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  border: 0;
  width: 100%;
  text-align: left;
  transition: transform .08s;
}
.farm-strip-btn:active { transform: translateY(2px); }
.farm-strip-ic {
  width: 32px;
  height: 32px;
  border-radius: 10px;
  background: linear-gradient(180deg, var(--wood-l), var(--wood));
  box-shadow: 0 2px 0 var(--wood-d);
  color: #fff;
  display: grid;
  place-items: center;
  font-size: 17px;
  flex-shrink: 0;
}
.farm-strip-tx {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  line-height: 1.2;
}
.farm-strip-tx b {
  font-family: var(--font-game);
  font-size: 13px;
  font-weight: 600;
  color: var(--ink);
}
.farm-strip-tx small {
  font-size: 10.5px;
  color: var(--muted);
}

/* 4. Portal Cards Grid */
.portals-wrap {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

/* Portal Utama: Sawah Panen Cuan (#tour-sidejob-card) */
.portal-farm-panel {
  position: relative;
  background: var(--wood);
  padding: 7px;
  border-radius: 26px;
  box-shadow: 0 6px 0 var(--wood-d), 0 14px 30px rgba(45, 25, 10, .28), inset 0 2px 0 rgba(255, 255, 255, .35);
}
.portal-farm-card {
  position: relative;
  background: radial-gradient(circle at 20% 0%, rgba(255, 255, 255, .85), transparent 45%), var(--parch);
  border-radius: 20px;
  padding: 16px 14px 14px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  text-decoration: none;
  color: var(--ink);
}
.portal-farm-banner {
  position: relative;
  width: 100%;
  height: 100px;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 0 0 2px var(--slot-bd), inset 0 2px 4px rgba(0, 0, 0, .15);
  background: linear-gradient(180deg, #7dd3fc 0%, #bae6fd 45%, #86efac 75%, #22c55e 100%);
}
.portal-farm-banner svg {
  width: 100%;
  height: 100%;
  display: block;
}
.portal-farm-meta {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
}
.portal-farm-title h2 {
  font-family: var(--font-game);
  font-size: 20px;
  font-weight: 700;
  color: var(--ink);
  line-height: 1.1;
  margin: 0;
}
.portal-farm-title p {
  font-size: 12px;
  color: var(--muted);
  font-weight: 700;
  margin-top: 3px;
  line-height: 1.35;
}

/* Farm Summary Chip (Status Sawah User) */
.farm-chips-row {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.farm-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px 4px 6px;
  border-radius: 999px;
  font-family: var(--font-game);
  font-size: 11.5px;
  font-weight: 700;
  box-shadow: 0 2px 0 rgba(0, 0, 0, .12);
}
.farm-chip img {
  width: 18px;
  height: 18px;
  object-fit: contain;
}
.farm-chip.ready {
  background: linear-gradient(180deg, #fff3b0, var(--y1));
  color: #5b3405;
  box-shadow: 0 2px 0 var(--y2);
  animation: farmPulse 2s infinite ease-in-out;
}
.farm-chip.barn {
  background: linear-gradient(180deg, #dcfce7, var(--g1));
  color: #064e3b;
  box-shadow: 0 2px 0 var(--g2);
}
.farm-chip.growing {
  background: linear-gradient(180deg, #e0f2fe, var(--b1));
  color: #0c4a6e;
  box-shadow: 0 2px 0 var(--b2);
}
.farm-chip.empty {
  background: var(--slot);
  color: var(--muted);
  box-shadow: 0 0 0 1.5px var(--slot-bd);
}
@keyframes farmPulse {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.03); }
}

/* Portal 2: Nonton Video (#tour-video-mission) */
.portal-watch-card {
  position: relative;
  background: radial-gradient(circle at 20% 0%, rgba(255, 255, 255, .8), transparent 45%), var(--parch);
  border-radius: 20px;
  padding: 14px 14px 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  text-decoration: none;
  color: var(--ink);
}
.watch-top-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.watch-title-group {
  display: flex;
  align-items: center;
  gap: 10px;
}
.watch-icon-slot {
  width: 44px;
  height: 44px;
  border-radius: 14px;
  background: linear-gradient(180deg, #7dd3fc, var(--b1));
  box-shadow: 0 3px 0 var(--b2);
  color: #fff;
  display: grid;
  place-items: center;
  font-size: 22px;
  flex-shrink: 0;
}
.watch-title-group b {
  font-family: var(--font-game);
  font-size: 16px;
  font-weight: 700;
  display: block;
  line-height: 1.15;
}
.watch-title-group small {
  font-size: 11px;
  color: var(--muted);
  font-weight: 700;
}

/* 5. Menu Cepat / Bento Grid (#tour-bento-grid) */
.bento-tiles-grid {
  display: grid;
  gap: 8px;
}
.bento-item-slot {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 5px;
  padding: 8px 4px 6px;
  border-radius: 16px;
  background: var(--slot);
  box-shadow: 0 0 0 2px var(--slot-bd), 0 2px 5px rgba(60, 30, 5, .08);
  text-decoration: none;
  color: var(--ink);
  cursor: pointer;
  border: 0;
  transition: transform .08s;
}
.bento-item-slot:active {
  transform: translateY(2px);
}
.bento-slot-ic {
  position: relative;
  width: 44px;
  height: 44px;
  border-radius: 13px;
  display: grid;
  place-items: center;
  padding: 5px;
}
.bento-slot-ic img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}
.bento-slot-ic i {
  font-size: 22px;
}
.bento-ping-dot {
  position: absolute;
  top: -2px;
  right: -2px;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: var(--r1);
  box-shadow: 0 0 0 2px #fff;
}
.bento-label-tx {
  font-family: var(--font-game);
  font-size: 11px;
  font-weight: 700;
  color: var(--ink);
  line-height: 1.1;
  white-space: nowrap;
}

/* 6. Banner Promosi (Survei, Threads, Promo Hub) */
.promo-banners-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.promo-banner-card {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-radius: 18px;
  background: var(--slot);
  box-shadow: 0 0 0 2px var(--slot-bd), 0 3px 6px rgba(60, 30, 5, .08);
  text-decoration: none;
  color: var(--ink);
  cursor: pointer;
  border: 0;
  width: 100%;
  text-align: left;
  transition: transform .08s;
}
.promo-banner-card:active { transform: translateY(2px); }
.promo-banner-ic {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  display: grid;
  place-items: center;
  flex-shrink: 0;
  font-size: 20px;
  color: #fff;
}
.promo-banner-tx {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  line-height: 1.25;
}
.promo-banner-tx b {
  font-family: var(--font-game);
  font-size: 13.5px;
  font-weight: 700;
  color: var(--ink);
}
.promo-banner-tx small {
  font-size: 11px;
  color: var(--muted);
  font-weight: 700;
}
.promo-banner-side {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
  flex-shrink: 0;
}
.promo-banner-badge {
  font-family: var(--font-game);
  font-size: 10.5px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 999px;
  background: linear-gradient(180deg, #fff3b0, var(--y1));
  box-shadow: 0 2px 0 var(--y2);
  color: #5b3405;
  white-space: nowrap;
}

/* 7. Video Pilihan & Riwayat Tontonan */
.video-grid-2col {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
  margin-top: 4px;
}
.video-card-item {
  display: flex;
  flex-direction: column;
  border-radius: 18px;
  background: var(--slot);
  box-shadow: 0 0 0 2px var(--slot-bd), 0 3px 6px rgba(60, 30, 5, .08);
  overflow: hidden;
  text-decoration: none;
  color: var(--ink);
  transition: transform .08s;
}
.video-card-item:active { transform: translateY(2px); }
.video-thumb-slot {
  position: relative;
  width: 100%;
  aspect-ratio: 16/9;
  background: #000;
  overflow: hidden;
}
.video-thumb-slot img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.video-reward-pill {
  position: absolute;
  top: 6px;
  left: 6px;
  padding: 2px 7px;
  border-radius: 999px;
  background: linear-gradient(180deg, #a6f08a, var(--g1));
  box-shadow: 0 2px 0 var(--g2);
  color: #fff;
  font-family: var(--font-game);
  font-size: 11px;
  font-weight: 700;
  text-shadow: 0 1px 0 var(--g2);
}
.video-dur-pill {
  position: absolute;
  bottom: 6px;
  right: 6px;
  padding: 1px 6px;
  border-radius: 6px;
  background: rgba(0, 0, 0, .75);
  color: #fff;
  font-family: var(--font-game);
  font-size: 10px;
  font-weight: 700;
}
.video-card-body {
  padding: 8px 10px 10px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  flex: 1;
}
.video-card-title {
  font-family: var(--font-game);
  font-size: 12px;
  font-weight: 700;
  line-height: 1.3;
  color: var(--ink);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* Lanjutkan Menonton Compact Rows */
.cw-item-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 7px 10px;
  border-radius: 16px;
  background: var(--slot);
  box-shadow: 0 0 0 2px var(--slot-bd);
  text-decoration: none;
  color: var(--ink);
}
.cw-thumb {
  width: 58px;
  height: 38px;
  border-radius: 10px;
  overflow: hidden;
  flex-shrink: 0;
  position: relative;
  background: #000;
}
.cw-thumb img { width: 100%; height: 100%; object-fit: cover; }
.cw-thumb i { position: absolute; inset: 0; display: grid; place-items: center; color: #fff; font-size: 18px; text-shadow: 0 1px 3px rgba(0,0,0,.6); }
.cw-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.cw-info b { font-family: var(--font-game); font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* 8. Floating Promo Button */
.cms-float {
  position: fixed;
  right: 14px;
  bottom: calc(96px + env(safe-area-inset-bottom));
  z-index: 900;
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 6px 12px 6px 6px;
  border-radius: 999px;
  cursor: pointer;
  color: #fff;
  border: 0;
  background: linear-gradient(180deg, #ff8080, var(--r1) 50%, var(--r2));
  box-shadow: 0 4px 0 var(--r2), 0 8px 18px rgba(225, 29, 72, 0.35), inset 0 2px 0 rgba(255, 255, 255, 0.5);
  animation: floatBob 3s ease-in-out infinite;
  text-decoration: none;
}
.cms-float:active { transform: translateY(2px); box-shadow: 0 2px 0 var(--r2); }
.cms-float-ic {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: rgba(255, 255, 255, 0.25);
  font-size: 18px;
}
.cms-float b { font-family: var(--font-game); font-size: 12px; font-weight: 700; line-height: 1.1; display: block; }
.cms-float small { font-size: 9.5px; opacity: 0.95; display: block; }
.cms-float em {
  position: absolute;
  top: -6px;
  right: -3px;
  padding: 1px 6px;
  border-radius: 999px;
  font-style: normal;
  font-family: var(--font-game);
  font-size: 9px;
  font-weight: 700;
  background: var(--y1);
  color: #5b3405;
  box-shadow: 0 2px 0 var(--y2);
}
@keyframes floatBob { 50% { transform: translateY(-5px); } }

/* Toast */
.cuan-toast {
  position: fixed;
  z-index: 9999;
  top: calc(var(--safe-t) + 75px);
  left: 50%;
  transform: translateX(-50%) translateY(-10px);
  background: rgba(59, 36, 20, .94);
  color: #fff;
  border-radius: 999px;
  padding: 10px 18px;
  font-family: var(--font-game);
  font-size: 13px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 4px 14px rgba(0, 0, 0, .3), 0 0 0 2px var(--wood);
  opacity: 0;
  pointer-events: none;
  transition: opacity .25s, transform .25s;
}
.cuan-toast.show {
  opacity: 1;
  pointer-events: auto;
  transform: translateX(-50%) translateY(0);
}
.cuan-toast i { font-size: 18px; color: #ffd43b; }

@media (max-width: 380px) {
  .hero-actions-row { grid-template-columns: 1fr 1fr; }
  .hero-actions-row a:nth-child(3) { grid-column: span 2; }
}
@media (prefers-reduced-motion: reduce) {
  .cms-float, .farm-chip.ready { animation: none !important; }
}
</style>

<div class="valley-home-stage">

  <!-- 1. Siluet Pegunungan & Lembah Sawah Hijau Low-Poly (Inline SVG) -->
  <div class="valley-sky-deco" aria-hidden="true">
    <svg viewBox="0 0 440 210" preserveAspectRatio="xMidYMin slice" fill="none">
      <defs>
        <linearGradient id="vSkySun" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#fef08a" stop-opacity="0.9"/>
          <stop offset="100%" stop-color="#fef08a" stop-opacity="0"/>
        </linearGradient>
        <linearGradient id="vMtnFar" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#bae6fd"/>
          <stop offset="100%" stop-color="#7dd3fc"/>
        </linearGradient>
        <linearGradient id="vMtnMid" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#86efac"/>
          <stop offset="100%" stop-color="#4ade80"/>
        </linearGradient>
        <linearGradient id="vHillNear" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#4ade80"/>
          <stop offset="100%" stop-color="#22c55e"/>
        </linearGradient>
      </defs>

      <!-- Matahari Pagi Hangat -->
      <circle cx="370" cy="40" r="32" fill="url(#vSkySun)"/>
      <circle cx="370" cy="40" r="18" fill="#fde047"/>

      <!-- Awan Lembut Low-Poly -->
      <path d="M40 50 Q50 35 65 40 Q80 30 95 42 Q110 38 120 50 Z" fill="#ffffff" opacity="0.65"/>
      <path d="M220 30 Q230 18 245 22 Q260 14 275 25 Q285 22 295 32 Z" fill="#ffffff" opacity="0.55"/>

      <!-- Lapisan Pegunungan Jauh (Biru Muda Pastel) -->
      <polygon points="0,140 45,95 90,130 150,75 210,125 275,68 340,120 395,85 440,118 440,210 0,210" fill="url(#vMtnFar)" opacity="0.75"/>
      <!-- Salju / Sorot Puncak Gunung -->
      <polygon points="150,75 142,88 150,92 158,86" fill="#ffffff" opacity="0.8"/>
      <polygon points="275,68 266,82 276,86 284,80" fill="#ffffff" opacity="0.8"/>

      <!-- Lapisan Bukit Sedang (Hijau Muda Segar) -->
      <path d="M0 135 C60 110, 140 140, 220 120 C300 100, 380 135, 440 125 V210 H0 Z" fill="url(#vMtnMid)" opacity="0.85"/>

      <!-- Petak Terasering Sawah Berundak -->
      <path d="M0 162 C70 145, 170 170, 270 155 C360 142, 410 160, 440 155 V210 H0 Z" fill="url(#vHillNear)"/>
      <path d="M0 182 C90 170, 200 190, 310 175 C390 165, 420 178, 440 174 V210 H0 Z" fill="#16a34a"/>

      <!-- Pematang Sawah Cokelat Subur -->
      <path d="M0 172 C80 158, 180 180, 280 166 C360 155, 420 170, 440 166" stroke="#b45309" stroke-width="2.5" stroke-linecap="round" opacity="0.45"/>
      <path d="M0 192 C95 182, 210 200, 320 186 C400 176, 430 188, 440 185" stroke="#78350f" stroke-width="2.5" stroke-linecap="round" opacity="0.4"/>
    </svg>
  </div>

  <!-- 2. USER HERO & SALDO SHOWCASE (#tour-balance-box) -->
  <?php if ($H['show_user_bar'] || $H['show_balance_card'] || $H['show_quick_actions']): ?>
  <div class="game-panel" id="tour-balance-box">
    <div class="parch-card">

      <?php if ($H['show_user_bar']): ?>
      <div class="user-hero-top">
        <div class="user-greet-group">
          <div class="user-avatar-slot">
            <img src="/assets/img/icons3d/star.png" alt="Petani">
          </div>
          <div class="user-name-meta">
            <b>Halo, <?= htmlspecialchars($user['username'] ?? 'Petani Cuan') ?></b>
            <div class="lvl">
              <span class="lvl-star"><img src="/assets/img/icons3d/star.png" alt=""></span>
              <b><?= htmlspecialchars($membership_name ?? 'Petani Pemula') ?></b>
            </div>
          </div>
        </div>

        <?php if ($H['show_today_stats']): ?>
        <div class="today-chip">
          <i class="ph-bold ph-trend-up"></i>
          <span>+Rp <?= number_format($today_watch_earned, 0, ',', '.') ?></span>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($H['show_balance_card']): ?>
      <div class="wallets-container">
        <!-- Saldo Siap Tarik (WD) -->
        <div class="wallet-box wallet-box--primary">
          <div class="wallet-lead">
            <div class="wal-3d-ic">
              <img src="/assets/img/icons3d/coins.png" alt="Saldo Tarik">
            </div>
            <div class="wallet-meta">
              <small>Saldo Siap Tarik (WD)</small>
              <b>Rp <?= number_format((float)($user['balance_wd'] ?? 0), 0, ',', '.') ?></b>
            </div>
          </div>
          <span style="font-size:11px;font-weight:800;color:#86efac;display:flex;align-items:center;gap:3px;">
            <i class="ph-fill ph-check-circle"></i> Cair Tunai
          </span>
        </div>

        <!-- Saldo Beli / Kas Petani -->
        <div class="wallet-box">
          <div class="wallet-lead">
            <div class="wal-3d-ic">
              <img src="/assets/img/icons3d/seed.png" alt="Kas Beli">
            </div>
            <div class="wallet-meta">
              <small>Saldo Beli / Kas</small>
              <b>Rp <?= number_format((float)($user['balance_dep'] ?? 0), 0, ',', '.') ?></b>
            </div>
          </div>
          <a href="/deposit" class="wal-add" title="Isi Kas"><i class="ph-bold ph-plus"></i></a>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($H['show_quick_actions']): ?>
      <div class="hero-actions-row">
        <a href="/withdraw" class="nb-btn g lg" id="tour-btn-withdraw">
          <i class="ph-bold ph-arrow-up-right"></i>
          <span>Tarik</span>
        </a>
        <a href="/deposit" class="nb-btn y lg">
          <i class="ph-bold ph-plus-circle"></i>
          <span>Isi Kas</span>
        </a>
        <a href="/history" class="nb-btn ghost" style="font-size:13.5px;">
          <i class="ph-bold ph-clock-counter-clockwise"></i>
          <span>Riwayat</span>
        </a>
      </div>
      <?php endif; ?>

    </div>
  </div>
  <?php endif; ?>

  <!-- 3. MASKOT, NOTIFIKASI, TOUR & REFERRAL (CMS) -->
  <?php if ($H['show_mascot'] && trim((string)$H['mascot_text']) !== ''): ?>
  <div class="farm-mascot-strip">
    <img src="/assets/img/icons3d/star.png" alt="Mascot">
    <div><?= htmlspecialchars($cms_txt((string)$H['mascot_text'])) ?></div>
  </div>
  <?php endif; ?>

  <?php if (!empty($cms['notif_inbox']['enabled']) && $notif_preview): ?>
  <div style="display:flex;flex-direction:column;gap:6px;">
    <?php foreach ($notif_preview as $nf): ?>
    <a href="/notifications" class="farm-strip-btn">
      <span class="farm-strip-ic"><i class="ph-fill ph-bell-ringing"></i></span>
      <span class="farm-strip-tx">
        <b><?= htmlspecialchars((string)($nf['title'] ?? 'Pengumuman')) ?></b>
        <small><?= htmlspecialchars(mb_strimwidth(strip_tags((string)($nf['message'] ?? $nf['body'] ?? '')), 0, 70, '...')) ?></small>
      </span>
      <i class="ph-bold ph-caret-right" style="color:var(--muted)"></i>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($cms['tour']['show_banner'])): ?>
  <button type="button" class="farm-strip-btn" onclick="openTourSelectModal()">
    <span class="farm-strip-ic" style="background:linear-gradient(180deg,#fde047,var(--y1));color:#5b3405;">
      <i class="ph-fill ph-compass"></i>
    </span>
    <span class="farm-strip-tx">
      <b><?= htmlspecialchars((string)$cms['tour']['title']) ?></b>
      <small><?= htmlspecialchars((string)$cms['tour']['desc']) ?></small>
    </span>
    <i class="ph-bold ph-caret-right" style="color:var(--muted)"></i>
  </button>
  <?php endif; ?>

  <?php if ($H['show_ref_strip'] && !$is_guest && $ref_code !== '-'): ?>
  <div class="slot-row" style="background:var(--parch);">
    <div class="ic sm" style="background:#fff;">
      <img src="/assets/img/icons3d/coins.png" alt="Ref">
    </div>
    <div style="flex:1;min-width:0;display:flex;flex-direction:column;line-height:1.2;">
      <small style="font-size:10px;color:var(--muted);font-weight:800;">Kode Undangan Petani</small>
      <b style="font-family:var(--font-game);font-size:15px;color:var(--wood-d);letter-spacing:0.5px;"><?= htmlspecialchars($ref_code) ?></b>
    </div>
    <button type="button" class="nb-btn sm y" onclick="copyRefCode('<?= htmlspecialchars($ref_code) ?>')">
      <i class="ph-bold ph-copy"></i> Salin
    </button>
  </div>
  <?php endif; ?>

  <!-- 4. DUA PORTAL: SAWAH PANEN CUAN (HERO) & MISI NONTON VIDEO -->
  <?php
    $FS = $cms['farm_spotlight'] ?? ($cms['fishing_spotlight'] ?? []);
    $show_farm_portal  = true; // portal sawah selalu aktif sebagai fokus utama
    $show_watch_portal = !empty($H['show_watch_cta']);
  ?>
  <div class="portals-wrap">

    <!-- PORTAL 1: SAWAH PANEN CUAN (KARTU PALING MENONJOL) -->
    <?php if ($show_farm_portal): ?>
    <div class="portal-farm-panel" id="tour-sidejob-card">
      <div class="portal-farm-card">

        <!-- Ribbon Judul Game -->
        <div class="ribbon y" style="margin-top:-24px;margin-bottom:2px;">
          <img src="/assets/img/icons3d/padi.png" alt="" style="width:20px;height:20px;">
          <span>SAWAH PANEN CUAN</span>
        </div>

        <!-- Banner Ilustrasi Lembah Sawah -->
        <div class="portal-farm-banner">
          <svg viewBox="0 0 380 100" preserveAspectRatio="xMidYMid slice" fill="none">
            <!-- Matahari & Cahaya -->
            <circle cx="320" cy="24" r="16" fill="#fde047"/>
            <circle cx="320" cy="24" r="28" fill="#fde047" opacity="0.3"/>
            <!-- Awan -->
            <path d="M40 22 Q50 12 65 16 Q80 8 95 18 Z" fill="#ffffff" opacity="0.7"/>
            <!-- Gunung Hijau -->
            <polygon points="0,65 50,30 110,60 170,25 240,65 310,28 380,62 380,100 0,100" fill="#86efac"/>
            <polygon points="170,25 162,35 170,38 176,33" fill="#ffffff" opacity="0.75"/>
            <!-- Bukit Terasering & Pematang -->
            <path d="M0 68 C80 55, 180 75, 280 62 C340 54, 380 64, 380 64 V100 H0 Z" fill="#4ade80"/>
            <path d="M0 80 C90 70, 200 88, 300 76 C350 70, 380 78, 380 78 V100 H0 Z" fill="#22c55e"/>
            <path d="M0 90 C100 82, 220 96, 320 86 C360 82, 380 88, 380 88 V100 H0 Z" fill="#15803d"/>
            <!-- Padi Emas Bergoyang -->
            <g stroke="#ca8a04" stroke-width="2" stroke-linecap="round">
              <path d="M45 88 V70"/><path d="M52 87 V68"/><path d="M59 89 V72"/>
              <path d="M190 85 V68"/><path d="M197 84 V66"/><path d="M204 86 V70"/>
              <path d="M330 84 V68"/><path d="M337 83 V65"/><path d="M344 85 V69"/>
            </g>
            <g fill="#fde047">
              <ellipse cx="46" cy="68" rx="2.5" ry="5" transform="rotate(20 46 68)"/>
              <ellipse cx="53" cy="66" rx="2.5" ry="5" transform="rotate(25 53 66)"/>
              <ellipse cx="60" cy="70" rx="2.5" ry="5" transform="rotate(18 60 70)"/>
              <ellipse cx="191" cy="66" rx="2.5" ry="5" transform="rotate(20 191 66)"/>
              <ellipse cx="198" cy="64" rx="2.5" ry="5" transform="rotate(25 198 64)"/>
              <ellipse cx="205" cy="68" rx="2.5" ry="5" transform="rotate(18 205 68)"/>
              <ellipse cx="331" cy="66" rx="2.5" ry="5" transform="rotate(20 331 66)"/>
              <ellipse cx="338" cy="63" rx="2.5" ry="5" transform="rotate(25 338 63)"/>
              <ellipse cx="345" cy="67" rx="2.5" ry="5" transform="rotate(18 345 67)"/>
            </g>
            <!-- Kilau Bintang Emas -->
            <polygon points="120,40 122,46 128,48 122,50 120,56 118,50 112,48 118,46" fill="#fff" opacity="0.9"/>
            <polygon points="260,35 262,40 267,42 262,44 260,49 258,44 253,42 258,40" fill="#fff" opacity="0.9"/>
          </svg>
        </div>

        <div class="portal-farm-meta">
          <div class="portal-farm-title">
            <h2>Sawah Panen Cuan</h2>
            <p>Tanam bibit unggul, rawat petak sawah, & jual hasil panen langsung ke Saldo Tarik!</p>
          </div>
          <div class="ic lg" style="margin-top:-2px;">
            <img src="/assets/img/icons3d/basket.png" alt="Panen">
          </div>
        </div>

        <!-- Ringkasan Status Sawah User (Realtime dari farm_plots & farm_inventory) -->
        <div class="farm-chips-row">
          <?php if (!empty($farm_summary['ready_count']) && $farm_summary['ready_count'] > 0): ?>
          <span class="farm-chip ready">
            <img src="/assets/img/icons3d/padi.png" alt="">
            <b><?= $farm_summary['ready_count'] ?> Petak Siap Panen!</b>
          </span>
          <?php endif; ?>

          <?php if (!empty($farm_summary['harvest_count']) && $farm_summary['harvest_count'] > 0): ?>
          <span class="farm-chip barn">
            <img src="/assets/img/icons3d/barn.png" alt="">
            <b><?= $farm_summary['harvest_count'] ?> Hasil di Lumbung</b>
          </span>
          <?php endif; ?>

          <?php if (!empty($farm_summary['growing_count']) && $farm_summary['growing_count'] > 0): ?>
          <span class="farm-chip growing">
            <img src="/assets/img/icons3d/seed.png" alt="">
            <b><?= $farm_summary['growing_count'] ?> Sedang Tumbuh</b>
          </span>
          <?php endif; ?>

          <?php if (empty($farm_summary['total_planted']) || $farm_summary['total_planted'] === 0): ?>
          <span class="farm-chip empty">
            <i class="ph-fill ph-plant" style="color:var(--g2);"></i>
            <b>Petak Lahan Siap Ditanami</b>
          </span>
          <?php endif; ?>
        </div>

        <!-- Tombol Aksi Utama Sawah -->
        <a href="/farm" class="nb-btn g lg full" style="font-size:16px;">
          <img src="/assets/img/icons3d/padi.png" alt="">
          <span>Masuk ke Sawah Panen</span>
          <i class="ph-bold ph-arrow-right"></i>
        </a>

      </div>
    </div>
    <?php endif; ?>

    <!-- PORTAL 2: NONTON VIDEO (#tour-video-mission) -->
    <?php if ($show_watch_portal): ?>
    <div class="game-panel" id="tour-video-mission">
      <div class="portal-watch-card">

        <div class="ribbon b" style="margin-top:-22px;margin-bottom:2px;">
          <i class="ph-fill ph-play-circle" style="font-size:18px;"></i>
          <span>MISI NONTON VIDEO</span>
        </div>

        <div class="watch-top-row">
          <div class="watch-title-group">
            <div class="watch-icon-slot">
              <i class="ph-fill ph-film-strip"></i>
            </div>
            <div>
              <b>Tonton Video Harian</b>
              <small><?= $H['show_watch_mission'] ? $watch_today . ' / ' . $watch_limit . ' Video Tuntas' : 'Nonton & raih saldo' ?></small>
            </div>
          </div>
          <span class="today-chip" style="background:linear-gradient(180deg,#cffafe,var(--b1));box-shadow:0 2px 0 var(--b2);color:#083344;">
            <i class="ph-fill ph-sparkle"></i> Kuota Harian
          </span>
        </div>

        <?php if ($H['show_watch_mission']): ?>
        <div class="prog">
          <div class="prog-fill" style="width: <?= $watch_pct ?>%;"></div>
          <span class="prog-text"><?= $watch_today ?> / <?= $watch_limit ?> Video (<?= $watch_pct ?>%)</span>
        </div>
        <?php endif; ?>

        <a href="/videos" class="nb-btn b full" id="tour-btn-watch">
          <span><?= htmlspecialchars((string)($H['watch_cta_text'] ?: 'Mulai Nonton Sekarang')) ?></span>
          <i class="ph-bold ph-arrow-right"></i>
        </a>

      </div>
    </div>
    <?php endif; ?>

  </div>

  <!-- 5. MENU CEPAT / BENTO TILES (#tour-bento-grid) -->
  <?php
    $tiles = array_values(array_filter((array)($cms['bento']['tiles'] ?? []), function ($t) {
        return !empty($t['enabled']) && ($t['id'] ?? '') !== 'fishing'; // tile mancing lama dihapus
    }));
    $bento_cnt = count($tiles);
    $bento_cols = ($bento_cnt >= 9 || $bento_cnt === 5) ? 5 : ($bento_cnt === 6 ? 3 : min(4, max(3, $bento_cnt)));
  ?>
  <?php if (!empty($cms['bento']['show_section']) && $tiles): ?>
  <div class="game-panel" id="tour-bento-grid">
    <div class="parch-card" style="padding:14px 10px 10px;">

      <div class="ribbon wood" style="margin-top:-22px;margin-bottom:6px;font-size:15px;padding:4px 18px 5px;">
        <span><?= htmlspecialchars((string)($cms['bento']['title'] ?: 'Menu Cepat & Fitur Cuan')) ?></span>
      </div>

      <div class="bento-tiles-grid" style="grid-template-columns: repeat(<?= $bento_cols ?>, 1fr)">
        <?php foreach ($tiles as $t):
          $tid = (string)($t['id'] ?? '');
          $lbl = ($tid === 'checkin' && $has_checked_in_today) ? 'Absen ✓' : (string)$t['label'];

          // Ikon 3D jika cocok, atau Phosphor Icon dalam frame slot
          $iconImg = '';
          if ($tid === 'farming' || $tid === 'minigame') {
              $iconImg = '<img src="/assets/img/icons3d/padi.png" alt="">';
          } elseif ($tid === 'redeem') {
              $iconImg = '<img src="/assets/img/icons3d/basket.png" alt="">';
          } elseif ($tid === 'upgrade') {
              $iconImg = '<img src="/assets/img/icons3d/star.png" alt="">';
          } elseif ($tid === 'referral') {
              $iconImg = '<img src="/assets/img/icons3d/coins.png" alt="">';
          } else {
              $iconImg = '<i class="ph-fill ' . htmlspecialchars((string)$t['icon']) . '" style="color:' . htmlspecialchars((string)($t['bg'] ?? '#3b2414')) . '"></i>';
          }
        ?>

          <?php if ($tid === 'checkin'): ?>
            <button type="button" class="bento-item-slot" onclick="openCheckinModal()">
              <div class="bento-slot-ic">
                <?= $iconImg ?>
                <?php if (!$has_checked_in_today && !$is_guest): ?><span class="bento-ping-dot"></span><?php endif; ?>
              </div>
              <span class="bento-label-tx"><?= htmlspecialchars($lbl) ?></span>
            </button>
          <?php elseif ($tid === 'threads'): ?>
            <button type="button" class="bento-item-slot" id="tour-threads-promo-card" onclick="openThreadsPromoModal()">
              <div class="bento-slot-ic">
                <?= $iconImg ?>
              </div>
              <span class="bento-label-tx"><?= htmlspecialchars($lbl) ?></span>
            </button>
          <?php else: ?>
            <a href="<?= htmlspecialchars((string)$t['url']) ?>" class="bento-item-slot">
              <div class="bento-slot-ic">
                <?= $iconImg ?>
              </div>
              <span class="bento-label-tx"><?= htmlspecialchars($lbl) ?></span>
            </a>
          <?php endif; ?>

        <?php endforeach; ?>
      </div>

    </div>
  </div>
  <?php endif; ?>

  <!-- 6. BANNER PROMOSI (Survei, Threads, Promo Hub) -->
  <?php
    $SB = $cms['survey_banner']; $PB = $cms['promo_banner']; $TC = $cms['threads_card'];
    if (setting($pdo, 'survey_enabled', '1') !== '1') $SB['enabled'] = false;
  ?>
  <?php if (!empty($SB['enabled']) || !empty($PB['enabled']) || !empty($TC['enabled'])): ?>
  <div class="promo-banners-list">

    <?php if (!empty($SB['enabled'])): ?>
    <a href="/survey" class="promo-banner-card">
      <div class="promo-banner-ic" style="background:linear-gradient(180deg,#34d399,var(--g2));box-shadow:0 2px 0 var(--g2);">
        <i class="ph-fill ph-clipboard-text"></i>
      </div>
      <div class="promo-banner-tx">
        <b><?= htmlspecialchars((string)$SB['title']) ?></b>
        <small><?= htmlspecialchars((string)$SB['desc']) ?></small>
      </div>
      <div class="promo-banner-side">
        <?php if ($SB['badge']): ?><span class="promo-banner-badge"><?= htmlspecialchars((string)$SB['badge']) ?></span><?php endif; ?>
        <span class="nb-btn sm g" style="padding:4px 10px;font-size:11.5px;"><?= htmlspecialchars((string)($SB['cta'] ?: 'Isi')) ?></span>
      </div>
    </a>
    <?php endif; ?>

    <?php if (!empty($TC['enabled'])): ?>
    <button type="button" class="promo-banner-card" onclick="openThreadsPromoModal()">
      <div class="promo-banner-ic" style="background:linear-gradient(180deg,#52525b,#27272a);box-shadow:0 2px 0 #18181b;">
        <i class="ph-bold ph-threads-logo"></i>
      </div>
      <div class="promo-banner-tx">
        <b><?= htmlspecialchars((string)$TC['title']) ?></b>
        <small><?= htmlspecialchars((string)$TC['desc']) ?></small>
      </div>
      <div class="promo-banner-side">
        <?php if ($TC['badge_cuan']): ?><span class="promo-banner-badge"><?= htmlspecialchars((string)$TC['badge_cuan']) ?></span><?php endif; ?>
        <span class="nb-btn sm y" style="padding:4px 10px;font-size:11.5px;">Ikuti</span>
      </div>
    </button>
    <?php endif; ?>

    <?php if (!empty($PB['enabled'])): ?>
    <button type="button" class="promo-banner-card" onclick="openPromoHubModal()">
      <div class="promo-banner-ic" style="background:linear-gradient(180deg,#fde047,var(--y1));box-shadow:0 2px 0 var(--y2);color:#5b3405;">
        <i class="ph-fill ph-megaphone"></i>
      </div>
      <div class="promo-banner-tx">
        <b><?= htmlspecialchars((string)$PB['title']) ?></b>
        <small><?= htmlspecialchars((string)$PB['desc']) ?></small>
      </div>
      <div class="promo-banner-side">
        <?php if ($PB['badge']): ?><span class="promo-banner-badge"><?= htmlspecialchars((string)$PB['badge']) ?></span><?php endif; ?>
        <span class="nb-btn sm b" style="padding:4px 10px;font-size:11.5px;"><?= htmlspecialchars((string)($PB['cta'] ?: 'Mulai')) ?></span>
      </div>
    </button>
    <?php endif; ?>

  </div>
  <?php endif; ?>

  <!-- 7. LANJUTKAN MENONTON (CMS) -->
  <?php if (!empty($cms['continue_watching']['enabled']) && $continue_watching_home): ?>
  <div class="game-panel">
    <div class="parch-card" style="gap:8px;">
      <div style="display:flex;align-items:center;gap:8px;font-family:var(--font-game);font-size:14.5px;font-weight:700;">
        <i class="ph-fill ph-clock-counter-clockwise" style="color:var(--b2);"></i>
        <span><?= htmlspecialchars((string)$cms['continue_watching']['title']) ?></span>
      </div>
      <div style="display:flex;flex-direction:column;gap:6px;">
        <?php foreach ($continue_watching_home as $cw):
          $cwDur = max(1, (int)($cw['watch_duration'] ?: $cw['duration'] ?: 1));
          $cwPct = min(100, (int)round(((float)$cw['seconds_watched'] / $cwDur) * 100));
        ?>
        <a href="/watch/<?= (int)$cw['id'] ?>" class="cw-item-row">
          <div class="cw-thumb">
            <img src="<?= htmlspecialchars(yt_thumb($cw['youtube_id'])) ?>" alt="" loading="lazy">
            <i class="ph-fill ph-play-circle"></i>
          </div>
          <div class="cw-info">
            <b><?= htmlspecialchars((string)$cw['title']) ?></b>
            <div class="prog" style="height:12px;">
              <div class="prog-fill" style="width:<?= $cwPct ?>%"></div>
            </div>
            <small style="font-size:10px;color:var(--muted);font-weight:700;"><?= $cwPct ?>% • +<?= format_rp((float)$cw['reward_amount']) ?></small>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- 8. REKOMENDASI VIDEO PILIHAN BERHADIAH -->
  <?php if (!empty($cms['featured_videos']['enabled']) && !empty($featured_videos)): ?>
  <div class="game-panel">
    <div class="parch-card">

      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
        <div style="display:flex;align-items:center;gap:7px;font-family:var(--font-game);font-size:15px;font-weight:700;">
          <img src="/assets/img/icons3d/star.png" alt="" style="width:20px;height:20px;">
          <span><?= htmlspecialchars((string)($cms['featured_videos']['title'] ?: 'Video Pilihan Berhadiah')) ?></span>
        </div>
        <a href="/videos" style="font-family:var(--font-game);font-size:12px;font-weight:700;color:var(--b2);text-decoration:none;">Lihat Semua &rarr;</a>
      </div>

      <?php if (trim((string)$cms['featured_videos']['desc']) !== ''): ?>
      <p style="margin:-4px 0 2px;font-size:11.5px;color:var(--muted);font-weight:700;"><?= htmlspecialchars((string)$cms['featured_videos']['desc']) ?></p>
      <?php endif; ?>

      <div class="video-grid-2col">
        <?php foreach ($featured_videos as $fv): ?>
        <a href="/watch/<?= (int)$fv['id'] ?>" class="video-card-item">
          <div class="video-thumb-slot">
            <img src="<?= htmlspecialchars(yt_thumb($fv['youtube_id'])) ?>"
                 alt="<?= htmlspecialchars($fv['title']) ?>"
                 loading="lazy"
                 onerror="this.onerror=null;this.src='https://img.youtube.com/vi/<?= htmlspecialchars($fv['youtube_id']) ?>/hqdefault.jpg'">
            <span class="video-reward-pill">+<?= format_rp((float)$fv['reward_amount']) ?></span>
            <span class="video-dur-pill"><?= (int)($fv['watch_duration'] ?? 0) ?>s</span>
          </div>
          <div class="video-card-body">
            <div class="video-card-title"><?= htmlspecialchars($fv['title']) ?></div>
            <span class="nb-btn sm b full" style="margin-top:auto;padding:5px 8px;font-size:11.5px;">
              <i class="ph-fill ph-play"></i> Tonton
            </span>
          </div>
        </a>
        <?php endforeach; ?>
      </div>

    </div>
  </div>
  <?php endif; ?>

  <!-- 9. TIPS CUAN HARIAN -->
  <?php if (!empty($cms['tips']['enabled']) && trim((string)$cms['tips']['text']) !== ''): ?>
  <div class="slot-row" style="background:var(--parch);align-items:flex-start;">
    <div class="ic sm" style="background:#fff;margin-top:2px;">
      <i class="ph-fill ph-lightbulb" style="font-size:20px;color:var(--y2)"></i>
    </div>
    <div style="flex:1;min-width:0;font-size:11.5px;line-height:1.4;color:var(--ink);font-weight:700;">
      <b style="font-family:var(--font-game);font-size:13px;display:block;margin-bottom:2px;color:var(--wood-d);"><?= htmlspecialchars((string)$cms['tips']['title']) ?></b>
      <?= htmlspecialchars($cms_txt((string)$cms['tips']['text'])) ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<!-- 10. FLOATING PROMO BUTTON -->
<?php if (!empty($cms['floating_promo']['enabled'])): $FP = $cms['floating_promo']; ?>
<button type="button" class="cms-float" onclick="openPromoHubModal()" aria-label="<?= htmlspecialchars((string)$FP['title']) ?>">
  <?php if ($FP['badge']): ?><em><?= htmlspecialchars((string)$FP['badge']) ?></em><?php endif; ?>
  <div class="cms-float-ic">
    <i class="ph-fill ph-gift"></i>
  </div>
  <div>
    <b><?= htmlspecialchars((string)$FP['title']) ?></b>
    <small><?= htmlspecialchars((string)$FP['sub']) ?></small>
  </div>
</button>
<?php endif; ?>

<!-- TOAST CONTAINER -->
<div id="cuan-toast" class="cuan-toast"></div>

<!-- ============================================================
     MODAL POPUP 1: PENGUMUMAN / PROMO CONSOLE (JIKA AKTIF)
     Menggunakan gaya game UI (.modal + .ribbon + .close)
     ============================================================ -->
<?php if ($popup_enabled): ?>
<div id="custom-announcement-popup" class="backdrop">
  <div class="modal">
    <button type="button" class="close" onclick="closeCustomPopup()" aria-label="Tutup">
      <i class="ph-bold ph-x"></i>
    </button>
    <div class="modal-head">
      <div class="hic">
        <img src="/assets/img/icons3d/star.png" alt="">
      </div>
      <div class="ribbon y" style="margin-top:-32px;margin-bottom:4px;">
        <span>PENGUMUMAN</span>
      </div>
      <h3 style="font-family:var(--font-game);font-size:18px;margin:8px 0 2px;color:var(--ink);"><?= htmlspecialchars($popup_title) ?></h3>
    </div>
    <div class="modal-body">
      <p style="font-size:12.5px;color:var(--muted);font-weight:700;line-height:1.5;margin:0 0 10px;text-align:center;">
        <?= nl2br(htmlspecialchars($popup_body)) ?>
      </p>
      <div style="display:flex;flex-direction:column;gap:8px;">
        <a href="<?= htmlspecialchars($popup_cta_url) ?>" class="nb-btn g full">
          <i class="ph-bold ph-arrow-up-right"></i>
          <span><?= htmlspecialchars($popup_cta_text) ?></span>
        </a>
        <button type="button" onclick="closeCustomPopup()" class="nb-btn ghost sm full">Nanti Saja</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ============================================================
     MODAL POPUP 2: AJAKAN CHECK-IN HARIAN (KOTAK REWARD)
     ============================================================ -->
<?php if (!$has_checked_in_today && !$is_guest): ?>
<div id="checkin-prompt-modal" class="backdrop">
  <div class="modal">
    <button type="button" class="close" onclick="closeCheckinModal()" aria-label="Tutup">
      <i class="ph-bold ph-x"></i>
    </button>
    <div class="modal-head">
      <div class="hic">
        <img src="/assets/img/icons3d/basket.png" alt="">
      </div>
      <div class="ribbon y" style="margin-top:-32px;margin-bottom:4px;">
        <span>CHECK-IN HARIAN</span>
      </div>
      <h3 style="font-family:var(--font-game);font-size:18px;margin:8px 0 2px;color:var(--ink);">Hadiah Panen Menantimu!</h3>
    </div>
    <div class="modal-body">
      <p style="font-size:12.5px;color:var(--muted);font-weight:700;line-height:1.45;margin:0 0 10px;text-align:center;">
        Bonus login harian sudah siap dipetik! Buka check-in sekarang untuk mendapatkan <strong>saldo tarik tunai & bibit</strong> langsung hari ini!
      </p>
      <div style="display:flex;flex-direction:column;gap:8px;">
        <a href="/checkin" class="nb-btn g full">
          <i class="ph-fill ph-sparkle"></i>
          <span>Klaim Hadiah Check-in Sekarang</span>
        </a>
        <button type="button" onclick="closeCheckinModal()" class="nb-btn ghost sm full">Nanti Saja</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ============================================================
     MODAL POPUP 3: PILIH PANDUAN TOUR INTERAKTIF
     ============================================================ -->
<div id="tour-select-modal" class="backdrop">
  <div class="modal">
    <button type="button" class="close" onclick="closeTourSelectModal()" aria-label="Tutup">
      <i class="ph-bold ph-x"></i>
    </button>
    <div class="modal-head">
      <div class="hic">
        <img src="/assets/img/icons3d/star.png" alt="">
      </div>
      <div class="ribbon b" style="margin-top:-32px;margin-bottom:4px;">
        <span>PANDUAN CUAN</span>
      </div>
      <h3 style="font-family:var(--font-game);font-size:18px;margin:8px 0 2px;color:var(--ink);">Panduan Interaktif 1 Menit</h3>
    </div>
    <div class="modal-body">
      <p style="font-size:12px;color:var(--muted);font-weight:700;text-align:center;margin:0 0 8px;">
        Pelajari cara kerja menu dan fitur cuan dengan panduan singkat:
      </p>

      <button type="button" class="slot-row" style="cursor:pointer;border:0;width:100%;text-align:left;background:#fff;" onclick="startInteractiveTour('watch')">
        <div class="ic sm" style="background:linear-gradient(180deg,#7dd3fc,var(--b1));color:#fff;">
          <i class="ph-fill ph-film-strip"></i>
        </div>
        <div style="flex:1;min-width:0;">
          <b style="font-family:var(--font-game);font-size:13px;color:var(--ink);display:block;">Panduan Nonton Video</b>
          <small style="font-size:10.5px;color:var(--muted);font-weight:700;">Pelajari kuota harian, tugas video cuan, & penarikan saldo tunai.</small>
        </div>
      </button>

      <button type="button" onclick="closeTourSelectModal()" class="nb-btn ghost sm full" style="margin-top:4px;">Tutup</button>
    </div>
  </div>
</div>

<!-- ============================================================
     MODAL POPUP 4: AJAKAN PROMOSI THREADS
     ============================================================ -->
<?php if ($threads_campaign_enabled): ?>
<div id="threads-promo-modal" class="backdrop">
  <div class="modal">
    <button type="button" class="close" onclick="closeThreadsPromoModal()" aria-label="Tutup">
      <i class="ph-bold ph-x"></i>
    </button>
    <div class="modal-head">
      <div class="hic" style="background:#18181b;color:#fde68a;">
        <i class="ph-bold ph-threads-logo" style="font-size:28px;"></i>
      </div>
      <div class="ribbon y" style="margin-top:-32px;margin-bottom:4px;">
        <span>EVENT RESMI THREADS</span>
      </div>
      <h3 style="font-family:var(--font-game);font-size:18px;margin:8px 0 2px;color:var(--ink);">Raih s/d Rp 75.000 di Threads!</h3>
    </div>
    <div class="modal-body">
      <div style="font-size:12px;color:var(--muted);font-weight:700;line-height:1.45;">
        Bagikan ceritamu di Threads dan dapatkan saldo tarik tunai langsung:
        <div style="background:var(--slot);box-shadow:0 0 0 2px var(--slot-bd);border-radius:12px;padding:8px 10px;margin:8px 0;">
          <div style="margin-bottom:5px;color:var(--wood-d);font-weight:800;">
            • <strong>Langkah 1:</strong> Buat postingan di Threads &rarr; Bonus <strong><?= format_rp($threads_step1_reward) ?></strong>.
          </div>
          <div style="color:var(--ink);font-weight:800;">
            • <strong>Langkah 2:</strong> Postingan tembus <strong>minimal 5K views</strong> &rarr; Bonus <strong><?= format_rp($threads_step2_reward) ?></strong> + <strong>Gaji Pasti & Chat Admin WA</strong>!
          </div>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;">
        <a href="/threads" class="nb-btn y full">
          <i class="ph-bold ph-threads-logo"></i>
          <span>Mulai Promosi Threads Sekarang</span>
        </a>
        <button type="button" onclick="closeThreadsPromoModal()" class="nb-btn ghost sm full">Nanti Saja</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ============================================================
     MODAL POPUP 5: PROMO HUB & REFERRAL SHARE
     ============================================================ -->
<div id="promo-hub-modal" class="backdrop">
  <div class="modal">
    <button type="button" class="close" onclick="closePromoHubModal()" aria-label="Tutup">
      <i class="ph-bold ph-x"></i>
    </button>
    <div class="modal-head">
      <div class="hic">
        <img src="/assets/img/icons3d/coins.png" alt="">
      </div>
      <div class="ribbon y" style="margin-top:-32px;margin-bottom:4px;">
        <span>PELUANG CUAN</span>
      </div>
      <h3 style="font-family:var(--font-game);font-size:18px;margin:8px 0 2px;color:var(--ink);">Ajak Teman & Promosi Cuan</h3>
    </div>
    <div class="modal-body">
      <p style="font-size:11.5px;color:var(--muted);font-weight:700;margin:0 0 6px;text-align:center;">
        Pilih jalur promosi dan panen komisi saldo tarik tunai instan ke dompetmu:
      </p>

      <!-- Kartu 1: Threads -->
      <div style="display:flex;flex-direction:column;gap:4px;padding:8px 10px;border-radius:14px;background:var(--slot);box-shadow:0 0 0 2px var(--slot-bd);">
        <div style="display:flex;align-items:center;justify-content:space-between;">
          <span style="font-family:var(--font-game);font-size:11px;font-weight:700;color:var(--wood-d);"><i class="ph-bold ph-threads-logo"></i> EVENT THREADS</span>
          <span class="today-chip" style="font-size:10px;padding:2px 6px;">+Rp 75.000</span>
        </div>
        <b style="font-family:var(--font-game);font-size:13px;color:var(--ink);">Posting Cerita di Threads</b>
        <small style="font-size:10.5px;color:var(--muted);font-weight:700;">Raih bonus di Langkah 1 & bonus gaji pasti jika tembus 5K views!</small>
        <a href="/threads" class="nb-btn sm y" style="margin-top:4px;">Ikuti Event Threads &rarr;</a>
      </div>

      <!-- Kartu 2: Bagikan Link Referral -->
      <div style="display:flex;flex-direction:column;gap:4px;padding:8px 10px;border-radius:14px;background:var(--slot);box-shadow:0 0 0 2px var(--slot-bd);">
        <div style="display:flex;align-items:center;justify-content:space-between;">
          <span style="font-family:var(--font-game);font-size:11px;font-weight:700;color:var(--g2);"><i class="ph-fill ph-users-three"></i> UNDANG TEMAN</span>
          <span class="today-chip" style="font-size:10px;padding:2px 6px;">Komisi Pasif</span>
        </div>
        <b style="font-family:var(--font-game);font-size:13px;color:var(--ink);">Sebar Link & Kode Referral</b>
        <div style="background:#fff;border-radius:10px;padding:6px 8px;font-size:10.5px;color:var(--muted);font-weight:700;line-height:1.35;margin:3px 0;">
          Mau saldo gratis tiap hari? Yuk gabung di PanenCuan! Tonton video dibayar uang tunai & selesaikan misi seru.
          <div style="margin-top:2px;font-weight:800;color:var(--wood-d);">
            Kode: <span style="font-family:monospace;letter-spacing:0.5px;"><?= htmlspecialchars($ref_code) ?></span>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-top:4px;">
          <button type="button" class="nb-btn sm ghost" onclick="copyPromoFullCaption('<?= htmlspecialchars($ref_url) ?>', '<?= htmlspecialchars($ref_code) ?>')">
            <i class="ph-bold ph-copy"></i> Teks
          </button>
          <button type="button" class="nb-btn sm g" onclick="shareRefLink('<?= htmlspecialchars($ref_url) ?>', '<?= htmlspecialchars($ref_code) ?>')">
            <i class="ph-bold ph-share-network"></i> WA
          </button>
          <a href="/referral" class="nb-btn sm b">
            <span>Detail</span>
          </a>
        </div>
      </div>

      <button type="button" onclick="closePromoHubModal()" class="nb-btn ghost sm full" style="margin-top:2px;">Tutup</button>
    </div>
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
    const at = localStorage.getItem('panen_tour_active') || localStorage.getItem('mancing_tour_active');
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
    const at = localStorage.getItem('panen_tour_active') || localStorage.getItem('mancing_tour_active');
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
  const allTourDone = localStorage.getItem('panen_tour_done') === '1' || localStorage.getItem('mancing_tour_done') === '1';

  try {
    const b = document.getElementById('tour-invite-card');
    if (b) {
      if (allTourDone || localStorage.getItem('panen_tour_banner_dismissed') === '1' || localStorage.getItem('mancing_tour_banner_dismissed') === '1') {
        b.style.display = 'none';
      } else {
        b.style.display = 'block';
      }
    }
  } catch (e) {}

  let isTourRunningNow = false;
  try {
    const rawTour = localStorage.getItem('panen_tour_active') || localStorage.getItem('mancing_tour_active');
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

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
