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

// ── GAMIFIKASI: level petani, streak, panen hari ini, misi harian ──
$farm_lv = farm_level(0);
$streak = 0;
$harvest_today = 0;
if (!$is_guest) {
    try {
        $xs = $pdo->prepare("SELECT xp FROM farm_users WHERE user_id = ?");
        $xs->execute([$user['id']]);
        $farm_lv = farm_level((int)$xs->fetchColumn());
    } catch (\Throwable) {}
    // Streak dihitung sama seperti halaman check-in
    $lc = (string)($user['last_checkin'] ?? '');
    if ($lc !== '' && (int)((strtotime(date('Y-m-d')) - strtotime($lc)) / 86400) <= 1) {
        try {
            $sq = $pdo->prepare("SELECT COUNT(DISTINCT DATE(watched_at)) FROM watch_history WHERE user_id = ? AND watched_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
            $sq->execute([$user['id']]);
            $streak = max(1, (int)$sq->fetchColumn());
        } catch (\Throwable) { $streak = 1; }
    }
    try {
        $hs = $pdo->prepare("SELECT COUNT(*) FROM farm_logs WHERE user_id = ? AND action = 'harvest' AND created_at >= CURDATE()");
        $hs->execute([$user['id']]);
        $harvest_today = (int)$hs->fetchColumn();
    } catch (\Throwable) {}
}
$xp_span = max(1, $farm_lv['xp_next'] - $farm_lv['xp_cur']);
$xp_pct  = min(100, (int)round(($farm_lv['xp'] - $farm_lv['xp_cur']) / $xp_span * 100));

// Misi harian di beranda: [kunci, ikon 3D, judul, progres, target, aksi (url atau js), label hadiah]
$daily_quests = [];
if (!$is_guest) {
    $daily_quests[] = ['checkin', 'checkin', 'Check-in harian', $has_checked_in_today ? 1 : 0, 1, $has_checked_in_today ? '/checkin' : 'js:openCheckinModal()', 'Bonus saldo'];
}
if (!empty($cms['hero']['show_watch_cta'])) {
    $daily_quests[] = ['watch', 'watch', 'Nonton video', $watch_today, max(1, $watch_limit), '/videos', 'Rp per video'];
}
if (!empty($cms['farm_spotlight']['enabled'])) {
    $daily_quests[] = ['farm', 'padi', 'Panen sawah', min(1, $harvest_today), 1, (string)($cms['farm_spotlight']['url'] ?: '/farm'), '+XP petani'];
}
$quests_done = count(array_filter($daily_quests, fn($q) => $q[3] >= $q[4]));

$pageTitle = 'Beranda';
$activePage = 'home';
require dirname(__DIR__) . '/partials/header.php';
?>
<style>
/* ══════════════════════════════════════════════════════════
   BERANDA PANENCUAN · "GOLDEN HOUR HARVEST"
   Token warna dari partials/header.php (--pc-*). Arah: DESIGN.md.
   ══════════════════════════════════════════════════════════ */
.h-wrap { position: relative; z-index: 1; display: flex; flex-direction: column; gap: 12px; padding: 4px 12px 8px; }
.h-wrap img { -webkit-user-drag: none; }

/* Muncul bertahap: naik + memantul */
[data-rv] { opacity: 0; transform: translateY(18px) scale(.97); transition: opacity .55s var(--pc-out), transform .7s var(--pc-ease); transition-delay: var(--d, 0s); }
[data-rv].in { opacity: 1; transform: none; }

/* Tombol */
.h-btn {
  position: relative; overflow: hidden;
  --c1: #22c55e; --c2: var(--pc-green-m); --sh: rgba(22, 163, 74, .4); --tx: #fff;
  display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 44px; padding: 0 18px;
  border: 0; border-radius: 15px; cursor: pointer; text-decoration: none; white-space: nowrap;
  font-family: var(--pc-font); font-size: 15px; font-weight: 600; color: var(--tx);
  background: linear-gradient(135deg, var(--c1), var(--c2)); box-shadow: 0 8px 18px var(--sh), inset 0 1px 0 rgba(255, 255, 255, .4);
  transition: transform .18s var(--pc-ease), box-shadow .18s;
}
.h-btn:active { transform: scale(.94); box-shadow: 0 3px 8px var(--sh); }
.h-btn img { width: 24px; height: 24px; object-fit: contain; }
.h-btn.gold { --c1: #ffd54f; --c2: var(--pc-gold); --sh: rgba(246, 183, 10, .45); --tx: #4a3000; }
.h-btn.sky { --c1: #7dd3fc; --c2: #0ea5e9; --sh: rgba(14, 165, 233, .4); }
.h-btn.dark { --c1: #3f3f46; --c2: #18181b; --sh: rgba(0, 0, 0, .3); }
.h-btn.soft { --tx: var(--pc-muted); background: var(--pc-soft); box-shadow: none; }
.h-btn.sm { min-height: 38px; padding: 0 14px; font-size: 13.5px; border-radius: 12px; }
.h-btn.full { width: 100%; }
.h-btn.shine::after {
  content: ''; position: absolute; top: 0; left: -70%; width: 45%; height: 100%; transform: skewX(-20deg); pointer-events: none;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .6), transparent); animation: hShine 3s ease-in-out infinite;
}
@keyframes hShine { 0%, 50% { left: -70%; } 85%, 100% { left: 140%; } }

.h-sec { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin: 4px 2px -4px; }
.h-sec b { font-family: var(--pc-font); font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 6px; }
.h-sec b img { width: 26px; height: 26px; object-fit: contain; animation: hBob 3s ease-in-out infinite; }
.h-sec a { display: inline-flex; align-items: center; gap: 2px; min-height: 40px; padding: 0 4px; font-size: 12.5px; font-weight: 900; color: var(--pc-green-m); text-decoration: none; }
@keyframes hBob { 50% { transform: translateY(-4px) rotate(-5deg); } }

/* ── 1. HERO: pemandangan sawah hidup + saldo ── */
.h-hero { position: relative; overflow: hidden; border-radius: 28px; background: #fff; box-shadow: 0 20px 40px rgba(120, 70, 10, .18); }
.h-scene { position: relative; height: 148px; overflow: hidden; background: linear-gradient(180deg, #ffb46b 0%, #ffd59a 38%, #fff0cf 70%, #e7f6d5 100%); }
.h-scene svg { position: absolute; left: 0; right: 0; bottom: 0; width: 100%; height: 100%; }
.h-layer { will-change: transform; }
.h-rays { position: absolute; top: -70px; right: -40px; width: 220px; height: 220px; border-radius: 50%; opacity: .55;
  background: repeating-conic-gradient(from 0deg, rgba(255, 255, 255, .55) 0deg 8deg, transparent 8deg 22deg);
  -webkit-mask: radial-gradient(circle, #000 20%, transparent 70%); mask: radial-gradient(circle, #000 20%, transparent 70%);
  animation: hSpin 40s linear infinite; }
@keyframes hSpin { to { transform: rotate(360deg); } }
.h-sun { position: absolute; top: 18px; right: 46px; width: 38px; height: 38px; border-radius: 50%; background: radial-gradient(circle at 40% 40%, #fff7c2, #ffd54f 60%, #f6b70a); box-shadow: 0 0 30px 10px rgba(255, 213, 79, .55); animation: hGlow 4s ease-in-out infinite; }
@keyframes hGlow { 50% { box-shadow: 0 0 44px 16px rgba(255, 213, 79, .7); transform: scale(1.06); } }
.h-cloud { position: absolute; height: 16px; border-radius: 999px; background: rgba(255, 255, 255, .85); animation: hCloud linear infinite; filter: drop-shadow(0 4px 6px rgba(200, 120, 40, .15)); }
.h-cloud::before, .h-cloud::after { content: ''; position: absolute; bottom: 5px; border-radius: 50%; background: inherit; }
.h-cloud::before { left: 9px; width: 20px; height: 20px; } .h-cloud::after { left: 22px; width: 28px; height: 26px; }
.h-cloud.c1 { top: 22px; width: 64px; animation-duration: 28s; } .h-cloud.c2 { top: 50px; width: 48px; animation-duration: 38s; animation-delay: -18s; opacity: .75; }
.h-cloud.c3 { top: 12px; width: 40px; animation-duration: 46s; animation-delay: -30s; opacity: .6; }
@keyframes hCloud { from { transform: translateX(-90px); } to { transform: translateX(470px); } }
.h-bird { position: absolute; top: 34px; left: -20px; width: 14px; height: 6px; border-top: 2px solid #7c4a1f; border-radius: 50% 50% 0 0; animation: hBird 16s linear infinite; }
.h-bird::after { content: ''; position: absolute; left: 7px; top: -2px; width: 7px; height: 6px; border-top: 2px solid #7c4a1f; border-radius: 50% 50% 0 0; }
.h-bird.b2 { top: 46px; animation-delay: -7s; transform: scale(.8); }
@keyframes hBird { 0% { transform: translate(0, 0); } 50% { transform: translate(230px, -10px); } 100% { transform: translate(470px, 4px); } }
.h-rice { transform-origin: 50% 100%; transform-box: fill-box; animation: hRice 2.8s ease-in-out infinite; }
.h-rice.d2 { animation-delay: -.9s; } .h-rice.d3 { animation-delay: -1.8s; }
@keyframes hRice { 50% { transform: rotate(-9deg); } }
.h-spark { position: absolute; width: 8px; height: 8px; background: #fff; clip-path: polygon(50% 0, 62% 38%, 100% 50%, 62% 62%, 50% 100%, 38% 62%, 0 50%, 38% 38%); animation: hTwinkle 2.2s ease-in-out infinite; }
@keyframes hTwinkle { 0%, 100% { opacity: 0; transform: scale(.3) rotate(0); } 50% { opacity: 1; transform: scale(1) rotate(45deg); } }

.h-greet { position: absolute; top: 10px; left: 10px; z-index: 2; display: flex; align-items: center; gap: 8px; padding: 4px 12px 4px 4px; border-radius: 999px;
  background: rgba(255, 255, 255, .72); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); box-shadow: 0 6px 16px rgba(120, 70, 10, .15); }
.h-ava { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; font-family: var(--pc-font); font-weight: 700; font-size: 16px; color: #fff; background: linear-gradient(135deg, #22c55e, #15803d); box-shadow: 0 0 0 2px #fff; }
.h-greet b { display: block; font-family: var(--pc-font); font-size: 14px; font-weight: 600; line-height: 1.05; max-width: 150px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.h-greet small { display: inline-flex; align-items: center; gap: 3px; font-size: 10.5px; font-weight: 800; color: #8a5a00; }
.h-greet small i { color: var(--pc-gold); }
.h-today { position: absolute; top: 58px; left: 10px; z-index: 2; display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 900; color: #fff;
  background: linear-gradient(135deg, #22c55e, #15803d); box-shadow: 0 6px 14px rgba(21, 128, 61, .35); animation: hFloat 3.4s ease-in-out infinite; }
@keyframes hFloat { 50% { transform: translateY(-3px); } }

.h-money { position: relative; margin-top: -26px; padding: 0 12px 12px; z-index: 3; }
.h-panel { position: relative; padding: 12px 14px; border-radius: 22px; background: rgba(255, 255, 255, .92); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); box-shadow: 0 10px 26px rgba(120, 70, 10, .14), inset 0 0 0 1px rgba(255, 255, 255, .9); }
.h-bal { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.h-bal small { display: block; font-size: 11.5px; font-weight: 800; color: var(--pc-muted); }
.h-bal-num {
  font-family: var(--pc-font); font-size: clamp(27px, 8.6vw, 34px); font-weight: 700; line-height: 1.05; letter-spacing: -.5px; font-variant-numeric: tabular-nums;
  background: linear-gradient(90deg, #14532d, #15803d 40%, #c98a00 60%, #14532d); background-size: 250% 100%;
  -webkit-background-clip: text; background-clip: text; color: transparent; animation: hGoldText 5s linear infinite;
}
@keyframes hGoldText { to { background-position: -250% 0; } }
.h-bal-num span { font-size: .5em; margin-right: 3px; }
.h-coins { position: relative; width: 66px; height: 60px; flex-shrink: 0; }
.h-coins img { position: absolute; object-fit: contain; filter: drop-shadow(0 6px 6px rgba(120, 70, 10, .25)); }
.h-coins .k1 { width: 56px; height: 56px; right: 0; top: 2px; animation: hBob 3s ease-in-out infinite; }
.h-coins .k2 { width: 30px; height: 30px; left: 0; bottom: 0; animation: hBob 2.4s ease-in-out infinite -1s; }
.h-dep { display: flex; align-items: center; gap: 6px; margin-top: 4px; font-size: 12px; font-weight: 800; color: var(--pc-muted); }
.h-dep b { font-family: var(--pc-font); font-size: 13.5px; font-weight: 600; color: var(--pc-ink); }
.h-dep a { margin-left: auto; display: inline-flex; align-items: center; gap: 3px; min-height: 30px; padding: 0 10px; border-radius: 10px; font-size: 12px; font-weight: 900; color: var(--pc-green-m); text-decoration: none; background: var(--pc-green-l); }
.h-acts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-top: 12px; }
.h-act { display: flex; flex-direction: column; align-items: center; gap: 5px; text-decoration: none; color: var(--pc-ink); font-size: 11.5px; font-weight: 800; }
.h-act-ic {
  position: relative; width: 50px; height: 50px; border-radius: 18px; display: grid; place-items: center; font-size: 23px; color: #fff;
  box-shadow: 0 8px 16px var(--s, rgba(22, 163, 74, .35)), inset 0 1px 0 rgba(255, 255, 255, .45); transition: transform .2s var(--pc-ease);
}
.h-act:active .h-act-ic { transform: scale(.85) rotate(-6deg); }
.h-act.a1 .h-act-ic { background: linear-gradient(135deg, #ffd54f, #f6b70a); color: #4a3000; --s: rgba(246, 183, 10, .5); animation: hPulse 2.4s ease-in-out infinite; }
.h-act.a2 .h-act-ic { background: linear-gradient(135deg, #4ade80, #16a34a); }
.h-act.a3 .h-act-ic { background: linear-gradient(135deg, #c4b5fd, #8b5cf6); --s: rgba(139, 92, 246, .35); }
.h-act.a4 .h-act-ic { background: linear-gradient(135deg, #7dd3fc, #0ea5e9); --s: rgba(14, 165, 233, .35); }
@keyframes hPulse { 0%, 100% { box-shadow: 0 8px 16px rgba(246, 183, 10, .5), 0 0 0 0 rgba(246, 183, 10, .5); } 50% { box-shadow: 0 8px 16px rgba(246, 183, 10, .5), 0 0 0 8px rgba(246, 183, 10, 0); } }

/* ── 2. PITA PENGUMUMAN ── */
.h-ticker { display: flex; align-items: center; gap: 8px; height: 40px; padding: 0 4px 0 6px; border-radius: 14px; background: #fff; box-shadow: var(--pc-sh); overflow: hidden; text-decoration: none; color: var(--pc-ink); }
.h-ticker-ic { flex-shrink: 0; width: 30px; height: 30px; border-radius: 10px; display: grid; place-items: center; font-size: 17px; color: #fff; background: linear-gradient(135deg, #fb7185, #f43f5e); animation: hWiggle 3s ease-in-out infinite; }
@keyframes hWiggle { 0%, 82%, 100% { transform: rotate(0); } 86% { transform: rotate(-14deg); } 90% { transform: rotate(12deg); } 94% { transform: rotate(-6deg); } }
.h-ticker-mask { flex: 1; overflow: hidden; -webkit-mask: linear-gradient(90deg, transparent, #000 6%, #000 94%, transparent); mask: linear-gradient(90deg, transparent, #000 6%, #000 94%, transparent); }
.h-ticker-track { display: inline-flex; gap: 40px; white-space: nowrap; font-size: 12.5px; font-weight: 800; animation: hMarquee var(--dur, 22s) linear infinite; }
.h-ticker-track b { color: var(--pc-green-m); }
@keyframes hMarquee { to { transform: translateX(-50%); } }

/* ── 3. QUEST: Nonton & Sawah ── */
.h-quests { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.h-quests.one { grid-template-columns: 1fr; }
.h-quest { position: relative; overflow: hidden; display: flex; flex-direction: column; gap: 8px; min-height: 168px; padding: 12px; border-radius: 24px; color: #fff; text-decoration: none; box-shadow: var(--pc-sh); }
.h-quest::before { content: ''; position: absolute; right: -30px; top: -30px; width: 110px; height: 110px; border-radius: 50%; background: rgba(255, 255, 255, .16); }
.h-quest::after { content: ''; position: absolute; left: -20px; bottom: -40px; width: 90px; height: 90px; border-radius: 50%; background: rgba(255, 255, 255, .1); }
.h-quest.watch { background: linear-gradient(150deg, #38bdf8 0%, #0284c7 100%); }
.h-quest.farm { background: linear-gradient(150deg, #4ade80 0%, #15803d 100%); }
.h-quest > * { position: relative; z-index: 1; }
.h-quest-top { display: flex; align-items: flex-start; justify-content: space-between; }
.h-quest-tag { padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 900; background: rgba(255, 255, 255, .22); }
.h-quest h3 { margin: 0; font-family: var(--pc-font); font-size: 16px; font-weight: 700; line-height: 1.15; }
.h-quest small { font-size: 11.5px; font-weight: 800; opacity: .92; line-height: 1.3; }
.h-quest .h-btn { margin-top: auto; min-height: 38px; font-size: 13.5px; border-radius: 12px; background: #fff; box-shadow: 0 6px 14px rgba(0, 0, 0, .15); }
.h-quest.watch .h-btn { color: #0369a1; } .h-quest.farm .h-btn { color: #15803d; }
.h-ring { position: relative; width: 54px; height: 54px; }
.h-ring svg { width: 100%; height: 100%; transform: rotate(-90deg); }
.h-ring circle { fill: none; stroke-width: 6; }
.h-ring .bg { stroke: rgba(255, 255, 255, .25); }
.h-ring .fg { stroke: #fff; stroke-linecap: round; stroke-dasharray: 151; stroke-dashoffset: 151; transition: stroke-dashoffset 1.4s var(--pc-ease) .3s; }
.h-ring span { position: absolute; inset: 0; display: grid; place-items: center; font-family: var(--pc-font); font-size: 13px; font-weight: 700; }
.h-quest-art { position: relative; width: 62px; height: 58px; }
.h-quest-art img { position: absolute; object-fit: contain; filter: drop-shadow(0 6px 6px rgba(0, 0, 0, .25)); }
.h-quest-art .q1 { width: 52px; height: 52px; right: 0; top: 0; animation: hBob 2.8s ease-in-out infinite; }
.h-quest-art .q2 { width: 30px; height: 30px; left: 0; bottom: 0; animation: hBob 3.4s ease-in-out infinite -1.2s; }
.h-ready { display: inline-flex; align-items: center; gap: 4px; width: max-content; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 900; color: #4a3000; background: #ffd54f; animation: hFloat 2s ease-in-out infinite; }

/* ── 4. STORY CAROUSEL ── */
.h-story { position: relative; overflow: hidden; border-radius: 24px; box-shadow: var(--pc-sh); }
.h-story-bars { position: absolute; z-index: 3; top: 8px; left: 12px; right: 12px; display: flex; gap: 4px; }
.h-story-bars i { flex: 1; height: 3px; border-radius: 999px; background: rgba(255, 255, 255, .35); overflow: hidden; }
.h-story-bars i::after { content: ''; display: block; height: 100%; width: 0; background: #fff; }
.h-story-bars i.done::after { width: 100%; }
.h-story-bars i.on::after { animation: hBar var(--t, 4.5s) linear forwards; }
.h-story.paused .h-story-bars i.on::after { animation-play-state: paused; }
@keyframes hBar { to { width: 100%; } }
.h-slides { display: flex; overflow-x: auto; scroll-snap-type: x mandatory; scrollbar-width: none; scroll-behavior: smooth; }
.h-slides::-webkit-scrollbar { display: none; }
.h-slide { position: relative; flex: 0 0 100%; scroll-snap-align: start; display: flex; align-items: center; gap: 12px; min-height: 112px; padding: 22px 14px 14px; color: #fff; text-decoration: none; border: 0; text-align: left; font: inherit; cursor: pointer; overflow: hidden; }
.h-slide.sv { background: linear-gradient(135deg, #34d399, #047857); }
.h-slide.th { background: linear-gradient(135deg, #52525b, #111113); }
.h-slide.pr { background: linear-gradient(135deg, #fbbf24, #ea580c); }
.h-slide::before { content: ''; position: absolute; right: -40px; bottom: -50px; width: 150px; height: 150px; border-radius: 50%; background: rgba(255, 255, 255, .12); }
.h-slide-ic { position: relative; width: 62px; height: 62px; flex-shrink: 0; border-radius: 20px; display: grid; place-items: center; font-size: 30px; background: rgba(255, 255, 255, .2); animation: hBob 3s ease-in-out infinite; }
.h-slide-ic img { width: 50px; height: 50px; object-fit: contain; }
.h-slide-tx { position: relative; flex: 1; min-width: 0; }
.h-slide-tx b { display: block; font-family: var(--pc-font); font-size: 16.5px; font-weight: 700; line-height: 1.15; }
.h-slide-tx small { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin-top: 3px; font-size: 11.5px; font-weight: 700; opacity: .92; line-height: 1.35; }
.h-slide-badge { position: absolute; right: 12px; bottom: 12px; z-index: 1; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 900; background: #fff; color: #15803d; box-shadow: 0 4px 10px rgba(0, 0, 0, .15); }
.h-slide.th .h-slide-badge { color: #111; } .h-slide.pr .h-slide-badge { color: #c2410c; }

/* ── 5. MENU CEPAT ── */
.h-menu { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px 6px; padding: 14px 8px 12px; border-radius: 24px; background: #fff; box-shadow: var(--pc-sh); }
.h-tile { position: relative; display: flex; flex-direction: column; align-items: center; gap: 6px; border: 0; background: none; padding: 0; text-decoration: none; color: var(--pc-ink); font: inherit; cursor: pointer; }
.h-tile-ic { position: relative; width: 52px; height: 52px; border-radius: 18px; display: grid; place-items: center; font-size: 25px; color: #fff; box-shadow: 0 8px 14px rgba(0, 0, 0, .12), inset 0 1px 0 rgba(255, 255, 255, .45); transition: transform .25s var(--pc-ease); }
.h-tile-ic.soft { background: linear-gradient(145deg, #fff8e1, #fff3c4) !important; box-shadow: 0 6px 12px rgba(201, 138, 0, .18); }
.h-tile-ic img { width: 42px; height: 42px; object-fit: contain; filter: drop-shadow(0 3px 3px rgba(0, 0, 0, .18)); }
.h-tile:active .h-tile-ic { transform: scale(.82) rotate(-8deg); }
.h-tile span { font-size: 11.5px; font-weight: 800; text-align: center; line-height: 1.15; }
.h-ping { position: absolute; top: -3px; right: -3px; width: 13px; height: 13px; border-radius: 50%; background: var(--pc-red); box-shadow: 0 0 0 2px #fff; }
.h-ping::after { content: ''; position: absolute; inset: 0; border-radius: 50%; background: var(--pc-red); animation: hPing 1.4s ease-out infinite; }
@keyframes hPing { to { transform: scale(2.6); opacity: 0; } }
.h-ok { position: absolute; bottom: -4px; right: -4px; width: 19px; height: 19px; border-radius: 50%; display: grid; place-items: center; font-size: 11px; color: #fff; background: var(--pc-green); box-shadow: 0 0 0 2px #fff; }
.h-menu [data-rv] { transform: scale(.5) rotate(-10deg); }
.h-menu [data-rv].in { transform: none; }

/* ── 6. TIKET UNDANGAN & PANDUAN ── */
.h-ticket { position: relative; display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 20px; border: 0; width: 100%; text-align: left; font: inherit; cursor: pointer;
  background: linear-gradient(135deg, #fff8e1, #fff3c4); box-shadow: 0 8px 20px rgba(201, 138, 0, .16); color: var(--pc-ink); }
.h-ticket::before, .h-ticket::after { content: ''; position: absolute; top: 50%; width: 18px; height: 18px; margin-top: -9px; border-radius: 50%; background: #f4f8ec; }
.h-ticket::before { left: -9px; } .h-ticket::after { right: -9px; }
.h-ticket img { width: 46px; height: 46px; object-fit: contain; animation: hBob 3s ease-in-out infinite; }
.h-ticket-tx { flex: 1; min-width: 0; border-left: 2px dashed rgba(201, 138, 0, .35); padding-left: 12px; }
.h-ticket-tx small { display: block; font-size: 11px; font-weight: 800; color: #8a5a00; }
.h-ticket-tx b { font-family: var(--pc-font); font-size: 20px; font-weight: 700; letter-spacing: 1.5px; color: #7a4f00; }
.h-ticket-cta { flex-shrink: 0; display: inline-flex; align-items: center; gap: 4px; padding: 7px 12px; border-radius: 12px; font-size: 12.5px; font-weight: 900; color: #fff; background: linear-gradient(135deg, #f6b70a, #e08a00); }
.h-guide { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 18px; border: 0; width: 100%; text-align: left; font: inherit; cursor: pointer; background: #fff; box-shadow: var(--pc-sh); color: var(--pc-ink); }
.h-guide-ic { width: 40px; height: 40px; flex-shrink: 0; border-radius: 13px; display: grid; place-items: center; font-size: 22px; color: #fff; background: linear-gradient(135deg, #7dd3fc, #0284c7); animation: hSpinSlow 8s linear infinite; }
@keyframes hSpinSlow { to { transform: rotate(360deg); } }
.h-guide b { display: block; font-size: 13px; font-weight: 900; }
.h-guide small { display: block; font-size: 11px; font-weight: 700; color: var(--pc-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.h-guide > span:nth-child(2) { flex: 1; min-width: 0; }

/* ── 7. VIDEO ── */
.h-hscroll { display: flex; gap: 10px; overflow-x: auto; margin: 0 -12px; padding: 4px 12px 8px; scrollbar-width: none; scroll-snap-type: x proximity; }
.h-hscroll::-webkit-scrollbar { display: none; }
.h-vid { flex: 0 0 156px; scroll-snap-align: start; overflow: hidden; display: flex; flex-direction: column; border-radius: 20px; background: #fff; box-shadow: var(--pc-sh); text-decoration: none; color: var(--pc-ink); transition: transform .25s var(--pc-ease); }
.h-vid:active { transform: scale(.95); }
.h-vid.wide { flex-basis: 224px; }
.h-thumb { position: relative; aspect-ratio: 16 / 10; overflow: hidden; background: linear-gradient(90deg, #eef3e6 25%, #f8fbf3 50%, #eef3e6 75%); background-size: 200% 100%; animation: hSkel 1.4s linear infinite; }
@keyframes hSkel { to { background-position: -200% 0; } }
.h-thumb img { width: 100%; height: 100%; object-fit: cover; }
.h-thumb .play { position: absolute; inset: 0; display: grid; place-items: center; font-size: 32px; color: #fff; text-shadow: 0 2px 10px rgba(0, 0, 0, .45); }
.h-rew { position: absolute; left: 6px; bottom: 6px; padding: 2px 8px; border-radius: 999px; font-size: 11px; font-weight: 900; color: #4a3000; background: linear-gradient(135deg, #ffd54f, #f6b70a); box-shadow: 0 3px 8px rgba(0, 0, 0, .2); }
.h-dur { position: absolute; right: 6px; bottom: 6px; padding: 2px 6px; border-radius: 6px; font-size: 10.5px; font-weight: 900; background: rgba(0, 0, 0, .6); color: #fff; }
.h-vid .h-ok { top: 6px; right: 6px; bottom: auto; }
.h-vid-b { padding: 8px 10px 10px; }
.h-vid-b b { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; font-size: 12.5px; font-weight: 800; line-height: 1.3; min-height: 32px; }
.h-bar { height: 5px; margin-top: 6px; border-radius: 999px; background: var(--pc-soft); overflow: hidden; }
.h-bar i { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #7dd3fc, #0284c7); }

/* ── 8. TIPS + MASKOT ── */
.h-tip { position: relative; display: flex; gap: 10px; align-items: center; padding: 12px; border-radius: 20px; background: linear-gradient(135deg, #e8fbe6, #fff8e1); overflow: hidden; }
.h-tip img { width: 52px; height: 52px; flex-shrink: 0; object-fit: contain; animation: hBob 2.6s ease-in-out infinite; }
.h-tip b { display: block; font-family: var(--pc-font); font-size: 14px; font-weight: 700; color: var(--pc-green-d); }
.h-tip span { font-size: 12px; font-weight: 700; color: #4b5a4d; line-height: 1.4; }

/* ── FAB PROMO ── */
.h-fab {
  position: fixed; z-index: 900; right: max(12px, calc(50% - 208px)); bottom: calc(104px + env(safe-area-inset-bottom));
  width: 54px; height: 54px; border: 0; border-radius: 20px; display: grid; place-items: center; cursor: pointer; color: #fff; font-size: 27px;
  background: linear-gradient(135deg, #fb7185, #e11d48); box-shadow: 0 12px 24px rgba(225, 29, 72, .4); animation: hFabIn .7s var(--pc-ease) 1s both, hWiggle 3.2s ease-in-out 2s infinite;
}
@keyframes hFabIn { from { transform: scale(0) rotate(-90deg); } }
.h-fab em { position: absolute; top: -6px; right: -6px; padding: 1px 6px; border-radius: 999px; font-style: normal; font-size: 10px; font-weight: 900; background: #ffd54f; color: #4a3000; box-shadow: 0 0 0 2px #fff; }

/* Toast */
.cuan-toast {
  position: fixed; z-index: 99998; left: 50%; top: 72px; transform: translate(-50%, -24px) scale(.9); opacity: 0; pointer-events: none;
  display: flex; align-items: center; gap: 6px; max-width: calc(100% - 32px); padding: 10px 16px; border-radius: 16px; font-size: 13px; font-weight: 800;
  background: linear-gradient(135deg, #15803d, #14532d); color: #fff; box-shadow: 0 14px 30px rgba(20, 83, 45, .35); transition: transform .4s var(--pc-ease), opacity .25s;
}
.cuan-toast.show { transform: translate(-50%, 0) scale(1); opacity: 1; }

/* ── MODAL: lembar bawah memantul + taburan konfeti ── */
.h-backdrop {
  position: fixed; inset: 0; z-index: 99990; display: flex; align-items: flex-end; justify-content: center; padding: 0 10px calc(10px + env(safe-area-inset-bottom));
  background: rgba(40, 25, 5, .45); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); opacity: 0; visibility: hidden; transition: opacity .25s, visibility .25s;
}
.h-backdrop.open { opacity: 1; visibility: visible; }
.h-modal {
  position: relative; width: 100%; max-width: 420px; padding: 46px 16px 16px; border-radius: 28px; background: linear-gradient(180deg, #fffaf0 0%, #fff 40%);
  box-shadow: 0 24px 60px rgba(0, 0, 0, .3); transform: translateY(110%); transition: transform .55s var(--pc-ease);
}
.h-backdrop.open .h-modal { transform: none; }
.h-modal-ic {
  position: absolute; top: -40px; left: 50%; width: 80px; height: 80px; margin-left: -40px; border-radius: 26px; display: grid; place-items: center; font-size: 36px; color: #15803d;
  background: linear-gradient(160deg, #fff, #fff3c4); box-shadow: 0 0 0 5px #fff, 0 14px 30px rgba(120, 70, 10, .25);
}
.h-backdrop.open .h-modal-ic { animation: hIconPop .7s var(--pc-ease) .15s both, hBob 3s ease-in-out .9s infinite; }
@keyframes hIconPop { from { transform: scale(0) rotate(-30deg); } }
.h-modal-ic img { width: 60px; height: 60px; object-fit: contain; }
.h-modal-ic.dark { background: linear-gradient(160deg, #3f3f46, #111); color: #fde68a; }
.h-confetti { position: absolute; top: 10px; left: 50%; width: 0; height: 0; pointer-events: none; }
.h-confetti i { position: absolute; width: 7px; height: 11px; border-radius: 2px; opacity: 0; }
.h-backdrop.open .h-confetti i { animation: hConf 1.1s var(--pc-out) .2s forwards; }
@keyframes hConf { 0% { opacity: 1; transform: translate(0, 0) rotate(0); } 100% { opacity: 0; transform: translate(var(--x), var(--y)) rotate(var(--r)); } }
.h-modal-x { position: absolute; top: 12px; right: 12px; width: 40px; height: 40px; border: 0; border-radius: 13px; background: var(--pc-soft); color: var(--pc-muted); font-size: 17px; cursor: pointer; display: grid; place-items: center; transition: transform .2s var(--pc-ease); }
.h-modal-x:active { transform: rotate(90deg) scale(.9); }
.h-modal h3 { margin: 0 0 6px; text-align: center; font-family: var(--pc-font); font-size: 20px; font-weight: 700; line-height: 1.2; }
.h-modal p { margin: 0 0 14px; text-align: center; font-size: 13px; font-weight: 700; color: var(--pc-muted); line-height: 1.5; }
.h-modal-acts { display: flex; flex-direction: column; gap: 8px; }
.h-step { display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; margin-bottom: 8px; border-radius: 16px; background: var(--pc-soft); font-size: 12.5px; font-weight: 700; line-height: 1.45; }
.h-step-n { width: 28px; height: 28px; flex-shrink: 0; border-radius: 10px; display: grid; place-items: center; font-family: var(--pc-font); font-weight: 700; color: #fff; background: linear-gradient(135deg, #22c55e, #15803d); }
.h-step em { font-style: normal; font-weight: 900; color: #7a4f00; background: #fff3c4; padding: 0 6px; border-radius: 6px; }
.h-row3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; margin-bottom: 10px; }
.h-row3 .h-btn { padding: 0 6px; }

@media (prefers-reduced-motion: reduce) {
  [data-rv] { opacity: 1; transform: none; transition: none; }
  .h-wrap *, .h-fab, .h-modal-ic, .h-confetti i { animation: none !important; }
  .h-modal, .h-ring .fg { transition: none; }
}
</style>
<style>
/* ══ LAPISAN GAMIFIKASI ══ */
/* Tombol game: muka gradien + bibir bawah, turun saat ditekan */
.h-btn { --lip: #11622f; box-shadow: 0 4px 0 var(--lip), 0 8px 16px var(--sh), inset 0 2px 0 rgba(255, 255, 255, .45); text-shadow: 0 1px 0 rgba(0, 0, 0, .15); }
.h-btn:active { transform: translateY(3px); box-shadow: 0 1px 0 var(--lip), 0 3px 6px var(--sh), inset 0 2px 0 rgba(255, 255, 255, .4); }
.h-btn.gold { --lip: #b07800; text-shadow: 0 1px 0 rgba(255, 255, 255, .5); }
.h-btn.sky { --lip: #0369a1; } .h-btn.dark { --lip: #000; }
.h-btn.soft { --lip: #d7dfcd; text-shadow: none; box-shadow: 0 3px 0 var(--lip); }
.h-quest .h-btn { --lip: rgba(0, 0, 0, .18); }

/* Kartu pemain */
.h-player { position: absolute; top: 10px; left: 10px; right: 10px; z-index: 2; display: flex; align-items: center; gap: 10px; }
.h-ava-wrap { position: relative; flex-shrink: 0; }
.h-ava-wrap .h-ava {
  width: 50px; height: 50px; border-radius: 16px; font-size: 22px;
  box-shadow: 0 0 0 3px #fff, 0 0 0 5px #f6b70a, 0 8px 16px rgba(120, 70, 10, .3);
}
.h-lv {
  position: absolute; left: 50%; bottom: -9px; transform: translateX(-50%); padding: 1px 7px; border-radius: 999px; font-style: normal; white-space: nowrap;
  font-family: var(--pc-font); font-size: 11px; font-weight: 700; color: #4a3000; background: linear-gradient(180deg, #ffe082, #f6b70a); box-shadow: 0 2px 0 #b07800, 0 0 0 2px #fff;
}
.h-pinfo { min-width: 0; flex: 1; display: flex; flex-direction: column; gap: 2px; padding: 6px 10px; border-radius: 16px; background: rgba(255, 255, 255, .78); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); box-shadow: 0 6px 14px rgba(120, 70, 10, .15); max-width: 210px; }
.h-pinfo b { font-family: var(--pc-font); font-size: 15px; font-weight: 700; line-height: 1.05; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.h-pinfo small { display: inline-flex; align-items: center; gap: 3px; font-size: 10.5px; font-weight: 800; color: #8a5a00; }
.h-pinfo small i { color: var(--pc-gold); }
.h-xp { position: relative; height: 8px; border-radius: 999px; background: rgba(120, 70, 10, .15); overflow: hidden; margin-top: 2px; }
.h-xp i { position: absolute; inset: 0 auto 0 0; border-radius: 999px; background: repeating-linear-gradient(-45deg, #ffd54f 0 6px, #f6b70a 6px 12px); background-size: 200% 100%; animation: hStripes 1.4s linear infinite; box-shadow: inset 0 -2px 0 rgba(0, 0, 0, .1); }
@keyframes hStripes { to { background-position: -34px 0; } }
.h-xp-tx { font-size: 9.5px; font-weight: 900; color: #8a5a00; }
.h-badges { position: absolute; top: 76px; left: 72px; z-index: 2; display: flex; gap: 6px; }
.h-badges .h-today { position: static; animation: none; }
.h-streak {
  display: inline-flex; align-items: center; gap: 2px; padding: 1px 10px 1px 3px; border-radius: 999px; font-size: 11.5px; font-weight: 900; color: #fff;
  background: linear-gradient(135deg, #fb923c, #ea580c); box-shadow: 0 6px 14px rgba(234, 88, 12, .35);
}
.h-streak img { width: 22px; height: 22px; object-fit: contain; animation: hFlame 1.2s ease-in-out infinite; transform-origin: 50% 90%; }
@keyframes hFlame { 50% { transform: scale(1.12, .92) rotate(-4deg); } }
.h-scene { height: 168px; }

/* Ikon 3D di tombol aksi */
.h-act-ic { --lip: rgba(0, 0, 0, .18); box-shadow: 0 4px 0 var(--lip), 0 8px 16px var(--s, rgba(22, 163, 74, .35)), inset 0 2px 0 rgba(255, 255, 255, .5); }
.h-act-ic img { width: 40px; height: 40px; object-fit: contain; filter: drop-shadow(0 3px 3px rgba(0, 0, 0, .25)); transition: transform .3s var(--pc-ease); }
.h-act:active .h-act-ic { transform: translateY(3px) scale(.92); }
.h-act:hover .h-act-ic img { transform: translateY(-3px) rotate(-6deg) scale(1.08); }
.h-act.a1 .h-act-ic { --lip: #b07800; } .h-act.a2 .h-act-ic { --lip: #11622f; } .h-act.a3 .h-act-ic { --lip: #5b21b6; } .h-act.a4 .h-act-ic { --lip: #0369a1; }
.h-ticker-ic { background: linear-gradient(145deg, #fff8e1, #fff3c4); }
.h-ticker-ic img { width: 28px; height: 28px; object-fit: contain; }
.h-guide-ic { background: linear-gradient(145deg, #e0f2fe, #fff); animation: none; }
.h-guide-ic img { width: 34px; height: 34px; object-fit: contain; animation: hSpinSlow 10s linear infinite; }

/* Judul seksi: pita */
.h-ribbon, .h-sec b {
  position: relative; display: inline-flex; align-items: center; gap: 6px; padding: 4px 16px 5px 12px; margin-left: 8px;
  font-family: var(--pc-font); font-size: 15px; font-weight: 700; color: #fff; text-shadow: 0 1px 0 rgba(0, 0, 0, .25);
  background: linear-gradient(180deg, #22c55e, #15803d); border-radius: 6px 10px 10px 6px; box-shadow: 0 3px 0 #11622f;
}
.h-ribbon::before, .h-sec b::before {
  content: ''; position: absolute; left: -8px; top: 5px; bottom: -5px; width: 12px; z-index: -1; background: #0f5a2a; clip-path: polygon(0 0, 100% 0, 100% 100%, 0 100%, 6px 50%);
}
.h-sec b img { width: 24px; height: 24px; margin: -6px 0; }
.h-sec b i { color: #fff3c4 !important; }

/* Papan misi harian */
.h-qboard { position: relative; padding: 12px 12px 6px; border-radius: 24px; background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 0 0 2px #f6e2b0, 0 10px 24px rgba(120, 70, 10, .12); }
.h-qhead { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
.h-chest { position: relative; display: block; width: 54px; height: 54px; margin: -18px -2px -8px 0; }
.h-chest img { width: 100%; height: 100%; object-fit: contain; filter: drop-shadow(0 6px 6px rgba(120, 70, 10, .3)); animation: hBob 2.6s ease-in-out infinite; }
.h-chest.open img { animation: hChest 1s var(--pc-ease) infinite alternate; }
@keyframes hChest { from { transform: scale(1) rotate(-4deg); } to { transform: scale(1.1) rotate(4deg); filter: drop-shadow(0 0 12px rgba(246, 183, 10, .8)); } }
.h-chest em { position: absolute; right: -4px; bottom: 0; padding: 0 6px; border-radius: 999px; font-style: normal; font-family: var(--pc-font); font-size: 11px; font-weight: 700; color: #fff; background: #ea580c; box-shadow: 0 0 0 2px #fff; }
.h-qprog { height: 6px; margin: 0 2px 6px; border-radius: 999px; background: #f2e6c8; overflow: hidden; }
.h-qprog i { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #f6b70a, #fb923c); transition: width 1s var(--pc-ease); }
.h-q { display: flex; align-items: center; gap: 10px; padding: 8px 2px; }
.h-q + .h-q { border-top: 1.5px dashed #f2e6c8; }
.h-q-ic { position: relative; width: 48px; height: 48px; flex-shrink: 0; border-radius: 15px; display: grid; place-items: center; background: linear-gradient(145deg, #fff8e1, #fff3c4); box-shadow: inset 0 0 0 2px #f6e2b0; }
.h-q-ic img { width: 40px; height: 40px; object-fit: contain; filter: drop-shadow(0 3px 3px rgba(0, 0, 0, .2)); }
.h-q-ok { position: absolute; right: -4px; bottom: -4px; width: 20px; height: 20px; border-radius: 50%; display: grid; place-items: center; font-size: 12px; color: #fff; background: var(--pc-green); box-shadow: 0 0 0 2px #fff; animation: pcBadgePop .5s var(--pc-ease); }
.h-q-tx { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.h-q-tx b { font-family: var(--pc-font); font-size: 14.5px; font-weight: 600; line-height: 1.1; }
.h-q-hot { font-style: normal; padding: 0 6px; border-radius: 999px; font-family: 'Nunito', sans-serif; font-size: 10px; font-weight: 900; color: #4a3000; background: #ffd54f; animation: hFloat 1.6s ease-in-out infinite; display: inline-block; }
.h-q-bar { height: 8px; border-radius: 999px; background: #edf2e6; overflow: hidden; }
.h-q-bar i { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #4ade80, #16a34a); box-shadow: inset 0 -2px 0 rgba(0, 0, 0, .1); }
.h-q.done .h-q-bar i { background: linear-gradient(90deg, #ffd54f, #f6b70a); }
.h-q-tx small { display: flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 800; color: var(--pc-muted); }
.h-q-rew { display: inline-flex; align-items: center; gap: 2px; color: #8a5a00; }
.h-q-rew img { width: 14px; height: 14px; object-fit: contain; }
.h-q .h-btn { flex-shrink: 0; min-width: 64px; }
.h-q.done .h-q-tx b { color: var(--pc-muted); }

/* Menu cepat: slot inventori */
.h-menu { background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 0 0 2px #f6e2b0, 0 10px 24px rgba(120, 70, 10, .1); }
.h-tile-ic {
  background: radial-gradient(circle at 50% 35%, #ffffff 0%, #fff6dc 70%) !important; border-radius: 18px;
  box-shadow: inset 0 0 0 2px #f6e2b0, 0 4px 0 #ecd59c, 0 8px 12px rgba(120, 70, 10, .12);
}
.h-tile-ic img { width: 46px; height: 46px; transition: transform .35s var(--pc-ease); }
.h-tile:hover .h-tile-ic img { transform: translateY(-4px) rotate(-8deg) scale(1.1); }
.h-tile:active .h-tile-ic { transform: translateY(3px) scale(.9); box-shadow: inset 0 0 0 2px #f6e2b0, 0 1px 0 #ecd59c; }
.h-tile span { color: #5a4318; }

/* Ikon 3D di kartu story & modal */
.h-slide-ic { background: rgba(255, 255, 255, .9); box-shadow: 0 6px 14px rgba(0, 0, 0, .15); }
.h-slide-ic img { width: 50px; height: 50px; object-fit: contain; }
.h-modal-ic.dark { background: linear-gradient(160deg, #fff, #fff3c4); }

@media (prefers-reduced-motion: reduce) { .h-xp i, .h-streak img, .h-chest img, .h-q-hot, .h-guide-ic img { animation: none !important; } }
</style>

<?php
  $ic3d = static fn(string $n): string => '/assets/img/icons3d/' . $n . '.png';
  $rp0  = static fn(float $n): string => number_format($n, 0, ',', '.');
  $FS = $cms['farm_spotlight'] ?? [];
  $SB = $cms['survey_banner']; $PB = $cms['promo_banner']; $TC = $cms['threads_card']; $FP = $cms['floating_promo'] ?? [];
  if (setting($pdo, 'survey_enabled', '1') !== '1' || $user_has_surveyed) $SB['enabled'] = false;
  // Konfeti modal (posisi acak tetap per render)
  $confetti = static function (): string {
    $c = ['#f6b70a', '#22c55e', '#38bdf8', '#fb7185', '#a78bfa']; $h = '<span class="h-confetti" aria-hidden="true">';
    for ($i = 0; $i < 14; $i++) {
      $a = $i / 14 * 6.283; $d = 70 + ($i * 37 % 50);
      $h .= '<i style="background:' . $c[$i % 5] . ';--x:' . round(cos($a) * $d) . 'px;--y:' . round(sin($a) * $d * .7 - 30) . 'px;--r:' . (180 + $i * 47) . 'deg"></i>';
    }
    return $h . '</span>';
  };
?>
<div class="h-wrap">

  <!-- 1. HERO: pemandangan sawah + saldo (#tour-balance-box) -->
  <?php if ($H['show_user_bar'] || $H['show_balance_card'] || $H['show_quick_actions']): ?>
  <section class="h-hero" id="tour-balance-box" data-rv>
    <div class="h-scene" id="h-scene" aria-hidden="true">
      <span class="h-rays"></span><span class="h-sun"></span>
      <span class="h-cloud c1"></span><span class="h-cloud c2"></span><span class="h-cloud c3"></span>
      <span class="h-bird"></span><span class="h-bird b2"></span>
      <svg viewBox="0 0 400 148" preserveAspectRatio="xMidYMax slice" fill="none">
        <g class="h-layer" data-depth="0.15"><polygon points="0,96 50,58 100,86 160,48 222,90 280,56 340,88 400,62 400,148 0,148" fill="#f8c78d"/><polygon points="160,48 151,60 160,64 168,57" fill="#fff" opacity=".85"/></g>
        <g class="h-layer" data-depth="0.3"><path d="M0 104 C80 88 170 112 260 98 C330 88 370 100 400 96 V148 H0 Z" fill="#b8e48c"/></g>
        <g class="h-layer" data-depth="0.5"><path d="M0 118 C90 106 200 126 300 112 C350 106 380 114 400 112 V148 H0 Z" fill="#7cc95a"/>
          <path d="M0 116 C90 104 200 124 300 110 C350 104 380 112 400 110" stroke="#5a9e3c" stroke-width="2" opacity=".5"/></g>
        <g class="h-layer" data-depth="0.8"><path d="M0 132 C100 122 220 140 320 128 C360 124 390 130 400 129 V148 H0 Z" fill="#4f9f3a"/>
          <g stroke="#c98a00" stroke-width="2.2" stroke-linecap="round">
            <g class="h-rice"><path d="M24 146 V126"/><path d="M31 145 V122"/><path d="M38 146 V127"/></g>
            <g class="h-rice d2"><path d="M300 146 V128"/><path d="M307 145 V124"/><path d="M314 146 V129"/></g>
            <g class="h-rice d3"><path d="M360 146 V127"/><path d="M367 145 V123"/><path d="M374 146 V128"/></g>
          </g>
          <g fill="#ffd54f">
            <ellipse cx="25" cy="124" rx="2.6" ry="5.5" transform="rotate(20 25 124)"/><ellipse cx="32" cy="120" rx="2.6" ry="5.5" transform="rotate(25 32 120)"/><ellipse cx="39" cy="125" rx="2.6" ry="5.5" transform="rotate(18 39 125)"/>
            <ellipse cx="301" cy="126" rx="2.6" ry="5.5" transform="rotate(20 301 126)"/><ellipse cx="308" cy="122" rx="2.6" ry="5.5" transform="rotate(25 308 122)"/>
            <ellipse cx="361" cy="125" rx="2.6" ry="5.5" transform="rotate(20 361 125)"/><ellipse cx="368" cy="121" rx="2.6" ry="5.5" transform="rotate(25 368 121)"/>
          </g></g>
      </svg>
      <span class="h-spark" style="top:30px;left:42%;animation-delay:-.4s"></span>
      <span class="h-spark" style="top:70px;left:70%;animation-delay:-1.3s"></span>
      <span class="h-spark" style="top:88px;left:22%;animation-delay:-1.9s"></span>
    </div>

    <?php if ($H['show_user_bar']): ?>
    <div class="h-player">
      <span class="h-ava-wrap">
        <span class="h-ava"><?= htmlspecialchars(mb_strtoupper(mb_substr((string)($user['username'] ?? 'P'), 0, 1))) ?></span>
        <?php if (!$is_guest): ?><em class="h-lv">Lv <?= $farm_lv['level'] ?></em><?php endif; ?>
      </span>
      <span class="h-pinfo">
        <b><?= $is_guest ? 'Halo, Petani!' : htmlspecialchars((string)$user['username']) ?></b>
        <small><i class="ph-fill ph-crown-simple"></i><?= htmlspecialchars((string)$membership_name) ?></small>
        <?php if (!$is_guest): ?>
        <span class="h-xp" title="XP petani"><i style="width:<?= $xp_pct ?>%"></i></span>
        <span class="h-xp-tx"><?= $farm_lv['xp'] - $farm_lv['xp_cur'] ?>/<?= $xp_span ?> XP</span>
        <?php endif; ?>
      </span>
    </div>
    <?php if (!$is_guest): ?>
    <div class="h-badges">
      <?php if ($streak > 0): ?><span class="h-streak"><img src="<?= $ic3d('flame') ?>" alt="" width="22" height="22"><?= $streak ?> hari</span><?php endif; ?>
      <?php if ($H['show_today_stats']): ?><span class="h-today"><i class="ph-bold ph-trend-up"></i>+Rp <?= $rp0($today_watch_earned) ?></span><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="h-money">
      <div class="h-panel">
        <?php if ($H['show_balance_card']): ?>
        <div class="h-bal">
          <div>
            <small>Saldo siap tarik</small>
            <div class="h-bal-num"><span>Rp</span><b data-count="<?= (int)round((float)($user['balance_wd'] ?? 0)) ?>"><?= $rp0((float)($user['balance_wd'] ?? 0)) ?></b></div>
          </div>
          <div class="h-coins"><img class="k1" src="<?= $ic3d('coins') ?>" alt="" width="56" height="56"><img class="k2" src="<?= $ic3d('coins') ?>" alt="" width="30" height="30"></div>
        </div>
        <?php if (!$is_guest): ?>
        <div class="h-dep">Saldo beli <b>Rp <?= $rp0((float)($user['balance_dep'] ?? 0)) ?></b><a href="/deposit" class="rp"><i class="ph-bold ph-plus"></i>Isi</a></div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($H['show_quick_actions']): ?>
        <div class="h-acts">
          <?php if ($is_guest): ?>
          <a href="/register" class="h-act a1"><span class="h-act-ic rp"><img src="<?= $ic3d('referral') ?>" alt="" width="40" height="40"></span>Daftar</a>
          <a href="/login" class="h-act a2"><span class="h-act-ic rp"><img src="<?= $ic3d('lock') ?>" alt="" width="40" height="40"></span>Masuk</a>
          <a href="/panduan" class="h-act a4"><span class="h-act-ic rp"><img src="<?= $ic3d('compass') ?>" alt="" width="40" height="40"></span>Panduan</a>
          <a href="/farm" class="h-act a3"><span class="h-act-ic rp"><img src="<?= $ic3d('padi') ?>" alt="" width="40" height="40"></span>Sawah</a>
          <?php else: ?>
          <a href="/withdraw" class="h-act a1" id="tour-btn-withdraw"><span class="h-act-ic rp"><img src="<?= $ic3d('moneybag') ?>" alt="" width="40" height="40"></span>Tarik</a>
          <a href="/deposit" class="h-act a2"><span class="h-act-ic rp"><img src="<?= $ic3d('piggy') ?>" alt="" width="40" height="40"></span>Isi Kas</a>
          <a href="/missions" class="h-act a3"><span class="h-act-ic rp"><img src="<?= $ic3d('missions') ?>" alt="" width="40" height="40"></span>Misi</a>
          <a href="/history" class="h-act a4"><span class="h-act-ic rp"><img src="<?= $ic3d('receipt') ?>" alt="" width="40" height="40"></span>Riwayat</a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <!-- 2. PITA PENGUMUMAN (notifikasi / maskot) -->
  <?php
    $ticks = [];
    if (!empty($cms['notif_inbox']['enabled'])) foreach ($notif_preview as $nf) $ticks[] = '<b>' . htmlspecialchars((string)($nf['title'] ?? 'Info')) . '</b> ' . htmlspecialchars(mb_strimwidth(strip_tags((string)($nf['message'] ?? $nf['body'] ?? '')), 0, 90, '…'));
    if ($H['show_mascot'] && trim((string)$H['mascot_text']) !== '') $ticks[] = htmlspecialchars($cms_txt((string)$H['mascot_text']));
  ?>
  <?php if ($ticks): $tickHtml = implode('<span aria-hidden="true">•</span>', array_map(fn($t) => '<span>' . $t . '</span>', $ticks)); ?>
  <a href="/notifications" class="h-ticker" data-rv style="--d:.05s">
    <span class="h-ticker-ic"><img src="<?= $ic3d('bell') ?>" alt="" width="30" height="30"></span>
    <span class="h-ticker-mask"><span class="h-ticker-track" style="--dur:<?= max(14, (int)(mb_strlen(strip_tags($tickHtml)) / 5)) ?>s"><?= $tickHtml ?><span aria-hidden="true">•</span><?= $tickHtml ?></span></span>
  </a>
  <?php endif; ?>

  <!-- 3. PAPAN MISI HARIAN (#tour-video-mission, #tour-sidejob-card) -->
  <?php if ($daily_quests): $all_done = $quests_done === count($daily_quests); ?>
  <section class="h-qboard" data-rv style="--d:.06s">
    <div class="h-qhead">
      <span class="h-ribbon">Misi Harian</span>
      <a href="/missions" class="h-chest<?= $all_done ? ' open' : '' ?>" aria-label="Misi harian <?= $quests_done ?> dari <?= count($daily_quests) ?> selesai">
        <img src="<?= $ic3d($all_done ? 'chest_open' : 'chest') ?>" alt="" width="54" height="54"><em><?= $quests_done ?>/<?= count($daily_quests) ?></em>
      </a>
    </div>
    <div class="h-qprog"><i style="width:<?= (int)round($quests_done / count($daily_quests) * 100) ?>%"></i></div>
    <?php foreach ($daily_quests as $qi => [$qk, $qic, $qt, $qp, $qn, $qact, $qrew]):
      $qdone = $qp >= $qn; $qpct = min(100, (int)round($qp / $qn * 100));
      $rowId = $qk === 'watch' ? ' id="tour-video-mission"' : ($qk === 'farm' ? ' id="tour-sidejob-card"' : '');
      $btnId = $qk === 'watch' ? ' id="tour-btn-watch"' : '';
      $btnTx = $qdone ? ($qk === 'farm' ? 'Lagi' : 'Lihat') : ($qk === 'checkin' ? 'Klaim' : ($qk === 'farm' && $farm_summary && $farm_summary['ready_count'] > 0 ? 'Panen' : 'Gas!'));
      $btnCls = $qdone ? 'h-btn sm soft' : ($qk === 'checkin' ? 'h-btn sm gold' : 'h-btn sm');
    ?>
    <div class="h-q<?= $qdone ? ' done' : '' ?>"<?= $rowId ?> data-rv style="--d:<?= number_format(0.1 + $qi * 0.06, 2) ?>s">
      <span class="h-q-ic"><img src="<?= $ic3d($qic) ?>" alt="" width="40" height="40"><?php if ($qdone): ?><span class="h-q-ok"><i class="ph-bold ph-check"></i></span><?php endif; ?></span>
      <span class="h-q-tx">
        <b><?= $qt ?><?php if ($qk === 'farm' && $farm_summary && $farm_summary['ready_count'] > 0): ?> <em class="h-q-hot"><?= $farm_summary['ready_count'] ?> siap!</em><?php endif; ?></b>
        <span class="h-q-bar"><i style="width:<?= $qpct ?>%"></i></span>
        <small><?= $qp ?>/<?= $qn ?> · <span class="h-q-rew"><img src="<?= $ic3d('coins') ?>" alt="" width="14" height="14"><?= $qrew ?></span></small>
      </span>
      <?php if (str_starts_with($qact, 'js:')): ?>
      <button type="button" class="<?= $btnCls ?> rp" onclick="<?= substr($qact, 3) ?>"<?= $btnId ?>><?= $btnTx ?></button>
      <?php else: ?>
      <a href="<?= htmlspecialchars($qact) ?>" class="<?= $btnCls ?> rp"<?= $btnId ?>><?= $btnTx ?></a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <!-- 4. STORY CAROUSEL: Survei, Threads, Promo -->
  <?php
    $slides = [];
    if (!empty($SB['enabled'])) $slides[] = ['sv', '/survey', '', '<img src="' . $ic3d('survey') . '" alt="">', $SB['title'], $SB['desc'], $SB['badge']];
    if (!empty($TC['enabled'])) $slides[] = ['th', '', 'openThreadsPromoModal()', '<img src="' . $ic3d('megaphone') . '" alt="">', $TC['title'], $TC['desc'], $TC['badge_cuan']];
    if (!empty($PB['enabled']) || !empty($FP['enabled'])) $slides[] = ['pr', '', 'openPromoHubModal()', '<img src="' . $ic3d('coins') . '" alt="">', $PB['title'] ?: ($FP['title'] ?? 'Ajak teman'), $PB['desc'] ?: ($FP['sub'] ?? ''), $PB['badge'] ?? ''];
  ?>
  <?php if ($slides): ?>
  <div class="h-story" id="h-story" data-rv>
    <?php if (count($slides) > 1): ?><div class="h-story-bars"><?= str_repeat('<i></i>', count($slides)) ?></div><?php endif; ?>
    <div class="h-slides" id="h-slides">
      <?php foreach ($slides as [$cls, $href, $onclick, $ic, $title, $desc, $badge]): ?>
      <?php if ($href): ?><a href="<?= $href ?>" class="h-slide <?= $cls ?>"><?php else: ?><button type="button" class="h-slide <?= $cls ?>" onclick="<?= $onclick ?>"><?php endif; ?>
        <span class="h-slide-ic"><?= $ic ?></span>
        <span class="h-slide-tx"><b><?= htmlspecialchars((string)$title) ?></b><small><?= htmlspecialchars((string)$desc) ?></small></span>
        <?php if ($badge): ?><span class="h-slide-badge"><?= htmlspecialchars((string)$badge) ?></span><?php endif; ?>
      <?= $href ? '</a>' : '</button>' ?>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- 5. MENU CEPAT (#tour-bento-grid) -->
  <?php
    $tiles = array_values(array_filter((array)($cms['bento']['tiles'] ?? []), fn($t) => !empty($t['enabled']) && !in_array($t['id'] ?? '', ['fishing', 'farm', 'watch'], true)));
    $tile3d = ['watch' => 'watch', 'threads' => 'megaphone', 'survey' => 'survey', 'checkin' => 'checkin', 'missions' => 'missions', 'referral' => 'referral',
               'upgrade' => 'upgrade', 'redeem' => 'redeem', 'global_chat' => 'chat', 'farm' => 'padi'];
    $iconFor = static function (array $t) use ($tile3d): string {
        $id = (string)($t['id'] ?? ''); $ph = (string)($t['icon'] ?? '');
        if (isset($tile3d[$id])) return $tile3d[$id];
        foreach (['chat' => 'chat', 'gift' => 'redeem', 'crown' => 'upgrade', 'users' => 'referral', 'target' => 'missions', 'calendar' => 'checkin',
                  'clipboard' => 'survey', 'film' => 'watch', 'play' => 'watch', 'megaphone' => 'megaphone', 'threads' => 'megaphone', 'bell' => 'bell',
                  'compass' => 'compass', 'wallet' => 'piggy', 'coin' => 'coins', 'bank' => 'moneybag', 'receipt' => 'receipt', 'plant' => 'padi'] as $k => $v) {
            if (str_contains($ph, $k)) return $v;
        }
        return 'star';
    };
  ?>
  <?php if (!empty($cms['bento']['show_section']) && $tiles): ?>
  <div class="h-sec" data-rv><b><img src="<?= $ic3d('star') ?>" alt=""><?= htmlspecialchars((string)($cms['bento']['title'] ?: 'Menu cepat')) ?></b></div>
  <nav class="h-menu" id="tour-bento-grid">
    <?php foreach ($tiles as $ti => $t):
      $tid = (string)($t['id'] ?? '');
      $mark = '';
      if ($tid === 'checkin' && !$is_guest) $mark = $has_checked_in_today ? '<span class="h-ok"><i class="ph-bold ph-check"></i></span>' : '<span class="h-ping"></span>';
      $ic = '<span class="h-tile-ic"><img src="' . $ic3d($iconFor($t)) . '" alt="" width="46" height="46">' . $mark . '</span>';
      $rv = 'data-rv style="--d:' . number_format(0.05 * $ti, 2) . 's"';
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

  <!-- 6. TIKET UNDANGAN & PANDUAN -->
  <?php if ($H['show_ref_strip'] && !$is_guest && $ref_code !== '-'): ?>
  <button type="button" class="h-ticket rp" data-rv onclick="copyRefCode('<?= htmlspecialchars($ref_code) ?>')">
    <img src="<?= $ic3d('referral') ?>" alt="" width="46" height="46">
    <span class="h-ticket-tx"><small>Kode undangan kamu</small><b><?= htmlspecialchars($ref_code) ?></b></span>
    <span class="h-ticket-cta"><i class="ph-bold ph-copy"></i>Salin</span>
  </button>
  <?php endif; ?>
  <?php if (!empty($cms['tour']['show_banner'])): ?>
  <div id="tour-invite-card" data-rv>
    <button type="button" class="h-guide rp" onclick="openTourSelectModal()">
      <span class="h-guide-ic"><img src="<?= $ic3d('compass') ?>" alt="" width="36" height="36"></span>
      <span><b><?= htmlspecialchars((string)$cms['tour']['title']) ?></b><small><?= htmlspecialchars((string)$cms['tour']['desc']) ?></small></span>
      <i class="ph-bold ph-caret-right" style="color:var(--pc-muted)"></i>
    </button>
  </div>
  <?php endif; ?>

  <!-- 7. LANJUTKAN MENONTON -->
  <?php if (!empty($cms['continue_watching']['enabled']) && $continue_watching_home): ?>
  <div class="h-sec" data-rv><b><i class="ph-fill ph-clock-counter-clockwise" style="color:#0284c7"></i><?= htmlspecialchars((string)$cms['continue_watching']['title']) ?></b></div>
  <div class="h-hscroll" data-rv>
    <?php foreach ($continue_watching_home as $cw):
      $cwDur = max(1, (int)($cw['watch_duration'] ?: $cw['duration'] ?: 1));
      $cwPct = min(100, (int)round(((float)$cw['seconds_watched'] / $cwDur) * 100));
    ?>
    <a href="/watch/<?= (int)$cw['id'] ?>" class="h-vid wide">
      <span class="h-thumb"><img src="<?= htmlspecialchars(yt_thumb($cw['youtube_id'])) ?>" alt="" loading="lazy" width="224" height="140"><span class="play"><i class="ph-fill ph-play-circle"></i></span><span class="h-rew">+<?= format_rp((float)$cw['reward_amount']) ?></span></span>
      <span class="h-vid-b"><b><?= htmlspecialchars((string)$cw['title']) ?></b><span class="h-bar"><i style="width:<?= $cwPct ?>%"></i></span></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- 8. VIDEO PILIHAN -->
  <?php if (!empty($cms['featured_videos']['enabled']) && !empty($featured_videos)): ?>
  <div class="h-sec" data-rv><b><img src="<?= $ic3d('star') ?>" alt=""><?= htmlspecialchars((string)($cms['featured_videos']['title'] ?: 'Video pilihan')) ?></b><a href="/videos">Lihat semua<i class="ph-bold ph-caret-right"></i></a></div>
  <div class="h-hscroll" data-rv>
    <?php foreach ($featured_videos as $fv): ?>
    <a href="/watch/<?= (int)$fv['id'] ?>" class="h-vid">
      <span class="h-thumb">
        <img src="<?= htmlspecialchars(yt_thumb($fv['youtube_id'])) ?>" alt="" loading="lazy" width="156" height="98" onerror="this.onerror=null;this.src='https://img.youtube.com/vi/<?= htmlspecialchars($fv['youtube_id']) ?>/hqdefault.jpg'">
        <span class="h-rew">+<?= format_rp((float)$fv['reward_amount']) ?></span>
        <span class="h-dur"><?= (int)($fv['watch_duration'] ?? 0) ?>s</span>
        <?php if (!empty($fv['watched_today'])): ?><span class="h-ok"><i class="ph-bold ph-check"></i></span><?php endif; ?>
      </span>
      <span class="h-vid-b"><b><?= htmlspecialchars((string)$fv['title']) ?></b></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- 9. TIPS + MASKOT -->
  <?php if (!empty($cms['tips']['enabled']) && trim((string)$cms['tips']['text']) !== ''): ?>
  <div class="h-tip" data-rv>
    <img src="<?= $ic3d('padi') ?>" alt="" width="52" height="52">
    <div><b><?= htmlspecialchars((string)$cms['tips']['title']) ?></b><span><?= htmlspecialchars($cms_txt((string)$cms['tips']['text'])) ?></span></div>
  </div>
  <?php endif; ?>

</div>

<!-- FAB PROMO -->
<?php if (!empty($FP['enabled'])): ?>
<button type="button" class="h-fab" onclick="openPromoHubModal()" aria-label="<?= htmlspecialchars((string)$FP['title']) ?>">
  <i class="ph-fill ph-gift"></i><?php if ($FP['badge']): ?><em><?= htmlspecialchars((string)$FP['badge']) ?></em><?php endif; ?>
</button>
<?php endif; ?>

<div id="cuan-toast" class="cuan-toast" role="status" aria-live="polite"></div>

<!-- MODAL 1: PENGUMUMAN -->
<?php if ($popup_enabled): ?>
<div id="custom-announcement-popup" class="h-backdrop" onclick="if(event.target===this)closeCustomPopup()">
  <div class="h-modal" role="dialog" aria-modal="true" aria-labelledby="mdl-ann">
    <?= $confetti() ?>
    <span class="h-modal-ic"><img src="<?= $ic3d('megaphone') ?>" alt=""></span>
    <button type="button" class="h-modal-x" onclick="closeCustomPopup()" aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
    <h3 id="mdl-ann"><?= htmlspecialchars($popup_title) ?></h3>
    <p><?= nl2br(htmlspecialchars($popup_body)) ?></p>
    <div class="h-modal-acts">
      <a href="<?= htmlspecialchars($popup_cta_url) ?>" class="h-btn full shine rp"><?= htmlspecialchars($popup_cta_text) ?></a>
      <button type="button" onclick="closeCustomPopup()" class="h-btn soft full">Nanti saja</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- MODAL 2: CHECK-IN -->
<?php if (!$has_checked_in_today && !$is_guest): ?>
<div id="checkin-prompt-modal" class="h-backdrop" onclick="if(event.target===this)closeCheckinModal()">
  <div class="h-modal" role="dialog" aria-modal="true" aria-labelledby="mdl-ci">
    <?= $confetti() ?>
    <span class="h-modal-ic"><img src="<?= $ic3d('basket') ?>" alt=""></span>
    <button type="button" class="h-modal-x" onclick="closeCheckinModal()" aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
    <h3 id="mdl-ci">Hadiah harianmu siap dipetik!</h3>
    <p>Check-in sekarang untuk bonus saldo tarik. Makin panjang streak-nya, makin besar hadiahnya.</p>
    <div class="h-modal-acts">
      <a href="/checkin" class="h-btn gold full shine rp">Ambil hadiah</a>
      <button type="button" onclick="closeCheckinModal()" class="h-btn soft full">Nanti saja</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- MODAL 3: PILIH PANDUAN -->
<div id="tour-select-modal" class="h-backdrop" onclick="if(event.target===this)closeTourSelectModal()">
  <div class="h-modal" role="dialog" aria-modal="true" aria-labelledby="mdl-tour">
    <span class="h-modal-ic"><img src="<?= $ic3d('compass') ?>" alt=""></span>
    <button type="button" class="h-modal-x" onclick="closeTourSelectModal()" aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
    <h3 id="mdl-tour">Panduan 1 menit</h3>
    <p>Kami tunjukkan langkahnya satu per satu: kuota video, cara nonton, sampai tarik saldo.</p>
    <div class="h-modal-acts">
      <button type="button" class="h-btn sky full rp" onclick="startInteractiveTour('watch')"><i class="ph-fill ph-film-strip"></i>Mulai panduan nonton video</button>
      <button type="button" onclick="closeTourSelectModal()" class="h-btn soft full">Tutup</button>
    </div>
  </div>
</div>

<!-- MODAL 4: EVENT THREADS -->
<?php if ($threads_campaign_enabled): ?>
<div id="threads-promo-modal" class="h-backdrop" onclick="if(event.target===this)closeThreadsPromoModal()">
  <div class="h-modal" role="dialog" aria-modal="true" aria-labelledby="mdl-th">
    <?= $confetti() ?>
    <span class="h-modal-ic"><img src="<?= $ic3d('megaphone') ?>" alt=""></span>
    <button type="button" class="h-modal-x" onclick="closeThreadsPromoModal()" aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
    <h3 id="mdl-th">Raih s/d <?= format_rp($threads_step1_reward + $threads_step2_reward) ?> di Threads</h3>
    <div class="h-step"><span class="h-step-n">1</span><span>Posting tentang PanenCuan di Threads, dapat <em><?= format_rp($threads_step1_reward) ?></em>.</span></div>
    <div class="h-step"><span class="h-step-n">2</span><span>Tembus 5.000 views, tambah <em><?= format_rp($threads_step2_reward) ?></em> plus gaji tetap &amp; kontak admin.</span></div>
    <div class="h-modal-acts" style="margin-top:6px">
      <a href="/threads" class="h-btn dark full shine rp"><i class="ph-bold ph-threads-logo"></i>Mulai promosi</a>
      <button type="button" onclick="closeThreadsPromoModal()" class="h-btn soft full">Nanti saja</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- MODAL 5: AJAK TEMAN -->
<div id="promo-hub-modal" class="h-backdrop" onclick="if(event.target===this)closePromoHubModal()">
  <div class="h-modal" role="dialog" aria-modal="true" aria-labelledby="mdl-ref">
    <?= $confetti() ?>
    <span class="h-modal-ic"><img src="<?= $ic3d('coins') ?>" alt=""></span>
    <button type="button" class="h-modal-x" onclick="closePromoHubModal()" aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
    <h3 id="mdl-ref">Ajak teman, panen komisi</h3>
    <p>Bagikan link undanganmu. Komisi masuk tiap teman yang kamu undang isi saldo.<?php if ($ref_code !== '-'): ?> Kode kamu <b style="color:#7a4f00"><?= htmlspecialchars($ref_code) ?></b>.<?php endif; ?></p>
    <div class="h-row3">
      <button type="button" class="h-btn soft sm" onclick="copyPromoFullCaption('<?= htmlspecialchars($ref_url) ?>', '<?= htmlspecialchars($ref_code) ?>')"><i class="ph-bold ph-copy"></i>Teks</button>
      <button type="button" class="h-btn sm rp" onclick="shareRefLink('<?= htmlspecialchars($ref_url) ?>', '<?= htmlspecialchars($ref_code) ?>')"><i class="ph-bold ph-share-network"></i>Bagikan</button>
      <a href="/referral" class="h-btn gold sm">Detail</a>
    </div>
    <?php if ($threads_campaign_enabled): ?>
    <button type="button" class="h-guide" style="box-shadow:none;background:var(--pc-soft);margin-bottom:8px" onclick="closePromoHubModal();setTimeout(openThreadsPromoModal,300)">
      <span class="h-guide-ic"><img src="<?= $ic3d('megaphone') ?>" alt="" width="36" height="36"></span>
      <span><b>Event promosi Threads</b><small>Dibayar sampai <?= format_rp($threads_step1_reward + $threads_step2_reward) ?></small></span>
      <i class="ph-bold ph-caret-right" style="color:var(--pc-muted)"></i>
    </button>
    <?php endif; ?>
    <button type="button" onclick="closePromoHubModal()" class="h-btn soft full">Tutup</button>
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
/* ── ANIMASI BERANDA: muncul bertahap, hitung saldo, cincin, story carousel, parallax ── */
(function () {
  const reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  // 1. Elemen [data-rv] muncul saat masuk layar
  const items = document.querySelectorAll('[data-rv]');
  if (reduce || !('IntersectionObserver' in window)) {
    items.forEach((el) => el.classList.add('in'));
  } else {
    const io = new IntersectionObserver((entries) => entries.forEach((e) => {
      if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
    }), { rootMargin: '0px 0px -5% 0px', threshold: 0.05 });
    items.forEach((el) => io.observe(el));
  }

  // 2. Angka saldo menghitung naik
  document.querySelectorAll('[data-count]').forEach((el) => {
    const target = +el.dataset.count || 0;
    if (reduce || target <= 0) return;
    const t0 = performance.now() + 250, dur = 1300;
    const step = (t) => {
      const k = Math.max(0, Math.min(1, (t - t0) / dur)), e = 1 - Math.pow(1 - k, 4);
      el.textContent = Math.round(target * e).toLocaleString('id-ID');
      if (k < 1) requestAnimationFrame(step);
    };
    el.textContent = '0';
    requestAnimationFrame(step);
  });

  // 3. Cincin progres misi nonton
  document.querySelectorAll('.h-ring[data-ring]').forEach((ring) => {
    const fg = ring.querySelector('.fg');
    const pct = Math.max(0, Math.min(100, +ring.dataset.ring || 0));
    if (fg) setTimeout(() => { fg.style.strokeDashoffset = String(151 - 151 * pct / 100); }, 200);
  });

  // 4. Story carousel: bar progres, geser otomatis, jeda saat disentuh
  const story = document.getElementById('h-story'), track = document.getElementById('h-slides');
  if (story && track && track.children.length > 1) {
    const bars = [...story.querySelectorAll('.h-story-bars i')], n = track.children.length, DUR = 4500;
    let idx = 0, timer = null;
    const paint = () => bars.forEach((b, i) => { b.classList.toggle('done', i < idx); b.classList.remove('on'); if (i === idx) { void b.offsetWidth; b.classList.add('on'); } });
    const go = (i, scroll = true) => {
      idx = (i + n) % n; paint();
      if (scroll) track.scrollTo({ left: idx * track.clientWidth, behavior: reduce ? 'auto' : 'smooth' });
      clearTimeout(timer);
      if (!reduce) timer = setTimeout(() => { if (!story.classList.contains('paused')) go(idx + 1); }, DUR);
    };
    story.style.setProperty('--t', DUR + 'ms');
    let scrollT = null;
    track.addEventListener('scroll', () => {
      clearTimeout(scrollT);
      scrollT = setTimeout(() => { const i = Math.round(track.scrollLeft / track.clientWidth); if (i !== idx) go(i, false); }, 120);
    }, { passive: true });
    const pause = () => { story.classList.add('paused'); clearTimeout(timer); };
    const resume = () => { story.classList.remove('paused'); go(idx, false); };
    track.addEventListener('pointerdown', pause, { passive: true });
    track.addEventListener('pointerup', resume, { passive: true });
    track.addEventListener('pointercancel', resume, { passive: true });
    document.addEventListener('visibilitychange', () => (document.hidden ? pause() : resume()));
    go(0, false);
  }

  // 5. Parallax lapisan pemandangan hero saat discroll
  const layers = document.querySelectorAll('#h-scene .h-layer');
  if (layers.length && !reduce) {
    let ticking = false;
    const apply = () => {
      const y = Math.min(window.scrollY, 300);
      layers.forEach((l) => { l.style.transform = 'translateY(' + (y * (+l.dataset.depth || 0) * -0.25).toFixed(1) + 'px)'; });
      ticking = false;
    };
    window.addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(apply); } }, { passive: true });
  }
})();
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
