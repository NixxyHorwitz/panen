<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$user = require_auth($pdo);

$vid_id = (int)($_GET['id'] ?? 0);
if (!$vid_id) redirect('/videos');

$vs = $pdo->prepare("SELECT * FROM videos WHERE id=? AND is_active=1");
$vs->execute([$vid_id]);
$video = $vs->fetch();
if (!$video) redirect('/videos');

$is_debug_user = !empty($user['is_debug']);
$orig_duration = (int)$video['watch_duration'];
$orig_reward   = (float)$video['reward_amount'];

// Hitung durasi dan reward aktif untuk user ini (mode debug per user)
$active_duration = ($is_debug_user && !empty($user['debug_watch_duration'])) 
    ? (int)$user['debug_watch_duration'] 
    : $orig_duration;

$active_reward = ($is_debug_user && isset($user['debug_watch_reward']) && $user['debug_watch_reward'] !== null) 
    ? (float)$user['debug_watch_reward'] 
    : $orig_reward;

$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);

$chk = $pdo->prepare("SELECT id FROM watch_history WHERE user_id=? AND video_id=? AND DATE(watched_at)=CURDATE()");
$chk->execute([$user['id'], $vid_id]);
$already_watched = (bool)$chk->fetch();
$canWatch = (!$already_watched || $is_debug_user) && ($watch_today < $watch_limit || $is_debug_user);

// Check initial like status
$chk_like = $pdo->prepare("SELECT id FROM video_likes WHERE user_id=? AND video_id=?");
$chk_like->execute([$user['id'], $vid_id]);
$user_has_liked = (bool)$chk_like->fetch();

// ── Watch History / In-progress Watch State ──────────────────
if ($already_watched && !$is_debug_user) {
    // Jika sudah selesai ditonton hari ini, bersihkan riwayat progres yang tersisa
    $pdo->prepare("DELETE FROM user_watch_progress WHERE user_id=? AND video_id=?")->execute([$user['id'], $vid_id]);
    $saved_seconds = 0;
    $saved_position = 0.0;
} else {
    $prog_stmt = $pdo->prepare("SELECT seconds_watched, last_position FROM user_watch_progress WHERE user_id=? AND video_id=?");
    $prog_stmt->execute([$user['id'], $vid_id]);
    $prog_row = $prog_stmt->fetch();
    $saved_seconds = $prog_row ? min($active_duration - 1, max(0, (int)$prog_row['seconds_watched'])) : 0;
    $saved_position = $prog_row ? max(0.0, (float)$prog_row['last_position']) : 0.0;
}

// ─────────────────────────────────────────────────────────────
// AJAX: save_progress — simpan progres realtime (maksimal 3 per user)
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_progress') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token.']); exit; }
    if (!$canWatch && !$is_debug_user) { echo json_encode(['ok'=>false,'msg'=>'Tidak dapat menyimpan sesi ini.']); exit; }

    $sw = (int)($_POST['seconds_watched'] ?? 0);
    $lp = (float)($_POST['last_position'] ?? 0);
    $max_d = $active_duration;

    if ($sw <= 0) {
        echo json_encode(['ok'=>true]); exit;
    }
    // Batasi progres agar tidak melebihi durasi misi minus 1 detik
    $sw = min($max_d - 1, $sw);
    $lp = max(0.0, $lp);

    try {
        // Hapus progres video yang sudah pernah selesai hari ini
        $pdo->prepare(
            "DELETE uwp FROM user_watch_progress uwp
             JOIN watch_history wh ON wh.user_id=uwp.user_id AND wh.video_id=uwp.video_id AND DATE(wh.watched_at)=CURDATE()
             WHERE uwp.user_id=?"
        )->execute([$user['id']]);

        // Cek apakah video ini sudah tercatat sebelumnya
        $chk_ex = $pdo->prepare("SELECT id FROM user_watch_progress WHERE user_id=? AND video_id=?");
        $chk_ex->execute([$user['id'], $vid_id]);
        if (!$chk_ex->fetch()) {
            // Batasi maksimal 3 history per user: sisakan maksimal 2 row terlama agar ketika row ke-3 masuk total tetap <= 3
            $pdo->prepare(
                "DELETE FROM user_watch_progress 
                 WHERE user_id = ? 
                   AND id NOT IN (
                     SELECT id FROM (
                       SELECT id FROM user_watch_progress 
                       WHERE user_id = ? 
                       ORDER BY updated_at DESC LIMIT 2
                     ) as _lim
                   )"
            )->execute([$user['id'], $user['id']]);
        }

        // Upsert progres saat ini
        $upsert = $pdo->prepare(
            "INSERT INTO user_watch_progress (user_id, video_id, seconds_watched, duration, last_position, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE 
               seconds_watched = VALUES(seconds_watched),
               duration = VALUES(duration),
               last_position = VALUES(last_position),
               updated_at = NOW()"
        );
        $upsert->execute([$user['id'], $vid_id, $sw, $max_d, $lp]);

        echo json_encode(['ok'=>true, 'saved_seconds'=>$sw]);
    } catch (\Throwable $e) {
        echo json_encode(['ok'=>false, 'msg'=>$e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// AJAX: reset_progress — ulangi tontonan video dari awal (0 detik)
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_progress') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token.']); exit; }

    $pdo->prepare("DELETE FROM user_watch_progress WHERE user_id=? AND video_id=?")
        ->execute([$user['id'], $vid_id]);

    echo json_encode(['ok'=>true]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// AJAX: set_debug_watch — ubah durasi & reward tester per user
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_debug_watch') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token.']); exit; }
    if (!$is_debug_user) { echo json_encode(['ok'=>false,'msg'=>'Akses debug ditolak.']); exit; }

    $dur = isset($_POST['duration']) ? (int)$_POST['duration'] : null;
    $rwd = isset($_POST['reward']) ? (float)$_POST['reward'] : null;

    $dur = ($dur !== null && $dur > 0) ? $dur : null;
    $rwd = ($rwd !== null && $rwd >= 0) ? $rwd : null;

    $pdo->prepare("UPDATE users SET debug_watch_duration = ?, debug_watch_reward = ? WHERE id = ?")
        ->execute([$dur, $rwd, $user['id']]);

    $effective_dur = $dur ?? $orig_duration;
    $effective_rwd = $rwd ?? $orig_reward;

    echo json_encode([
        'ok' => true,
        'msg' => 'Pengaturan debug tester berhasil diperbarui.',
        'duration' => $effective_dur,
        'reward' => $effective_rwd,
        'reward_formatted' => format_rp($effective_rwd)
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// AJAX: reset_debug_status — reset riwayat video ini agar bisa dites lagi (khusus user debug)
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_debug_status') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token.']); exit; }
    if (!$is_debug_user) { echo json_encode(['ok'=>false,'msg'=>'Akses debug ditolak.']); exit; }

    try {
        $pdo->prepare("DELETE FROM watch_history WHERE user_id=? AND video_id=? AND DATE(watched_at)=CURDATE()")
            ->execute([$user['id'], $vid_id]);
        $pdo->prepare("DELETE FROM user_watch_progress WHERE user_id=? AND video_id=?")
            ->execute([$user['id'], $vid_id]);
        echo json_encode(['ok'=>true, 'msg'=>'Status tonton video ini telah di-reset untuk akun tester Anda.']);
    } catch (\Throwable $e) {
        echo json_encode(['ok'=>false, 'msg'=>$e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// AJAX: start_watch — server issues a signed token with saved seconds
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_watch') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token.']); exit; }
    if (!$canWatch && !$is_debug_user) { echo json_encode(['ok'=>false,'msg'=>'Tidak dapat menonton video ini.']); exit; }

    // Ambil saved_seconds terkini dari DB
    $st = $pdo->prepare("SELECT seconds_watched FROM user_watch_progress WHERE user_id=? AND video_id=?");
    $st->execute([$user['id'], $vid_id]);
    $curr_saved = (int)($st->fetchColumn() ?: 0);
    $curr_saved = min($active_duration - 1, max(0, $curr_saved));

    $ts     = time();
    $secret = $_ENV['APP_SECRET'] ?? 'tonton_secret_2024';

    if ($is_debug_user) {
        $sig   = hash_hmac('sha256', $user['id'] . '|' . $vid_id . '|' . $ts . '|' . $curr_saved . '|DBG|' . $active_duration . '|' . $active_reward, $secret);
        $token = base64_encode($user['id'] . '|' . $vid_id . '|' . $ts . '|' . $curr_saved . '|DBG|' . $active_duration . '|' . $active_reward . '|' . $sig);
    } else {
        $sig   = hash_hmac('sha256', $user['id'] . '|' . $vid_id . '|' . $ts . '|' . $curr_saved, $secret);
        $token = base64_encode($user['id'] . '|' . $vid_id . '|' . $ts . '|' . $curr_saved . '|' . $sig);
    }

    echo json_encode([
        'ok'=>true,
        'watch_token'=>$token,
        'saved_seconds'=>$curr_saved,
        'remaining_seconds'=>max(0, $active_duration - $curr_saved),
        'duration'=>$active_duration,
        'reward'=>$active_reward
    ]);
    exit;
}

// ─────────────────────────────────────────────────────────────
// AJAX: claim reward — wajib sertakan watch_token
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token.']); exit; }

    $raw_token = $_POST['watch_token'] ?? '';
    if (empty($raw_token)) {
        echo json_encode(['ok'=>false,'msg'=>'Sesi tonton tidak valid. Putar video dari awal.']); exit;
    }

    $decoded = base64_decode($raw_token, true);
    if ($decoded === false) {
        echo json_encode(['ok'=>false,'msg'=>'Token rusak.']); exit;
    }

    $parts = explode('|', $decoded);
    $secret = $_ENV['APP_SECRET'] ?? 'tonton_secret_2024';
    $is_dbg_token = false;

    if ($is_debug_user && count($parts) === 8 && $parts[4] === 'DBG') {
        [$tok_uid, $tok_vid, $tok_ts, $tok_saved, $tok_marker, $tok_dur, $tok_rwd, $tok_sig] = $parts;
        $tok_saved = (int)$tok_saved;
        $tok_dur   = (int)$tok_dur;
        $tok_rwd   = (float)$tok_rwd;
        $expected  = hash_hmac('sha256', $tok_uid . '|' . $tok_vid . '|' . $tok_ts . '|' . $tok_saved . '|DBG|' . $tok_dur . '|' . $tok_rwd, $secret);
        $is_dbg_token = true;
    } elseif (count($parts) === 5) {
        [$tok_uid, $tok_vid, $tok_ts, $tok_saved, $tok_sig] = $parts;
        $tok_saved = (int)$tok_saved;
        $expected = hash_hmac('sha256', $tok_uid . '|' . $tok_vid . '|' . $tok_ts . '|' . $tok_saved, $secret);
    } elseif (count($parts) === 4) {
        [$tok_uid, $tok_vid, $tok_ts, $tok_sig] = $parts;
        $tok_saved = 0;
        $expected = hash_hmac('sha256', $tok_uid . '|' . $tok_vid . '|' . $tok_ts, $secret);
    } else {
        echo json_encode(['ok'=>false,'msg'=>'Format token tidak valid.']); exit;
    }

    if ((int)$tok_uid !== (int)$user['id'] || (int)$tok_vid !== $vid_id) {
        echo json_encode(['ok'=>false,'msg'=>'Token tidak cocok dengan akun ini.']); exit;
    }
    if (!hash_equals($expected, $tok_sig)) {
        echo json_encode(['ok'=>false,'msg'=>'Signature token tidak valid.']); exit;
    }

    $is_instant = !empty($_POST['instant']) && $is_dbg_token && $is_debug_user;
    $required   = ($is_dbg_token && $is_debug_user) ? $tok_dur : (int)$video['watch_duration'];
    $reward     = ($is_dbg_token && $is_debug_user) ? $tok_rwd : (float)$video['reward_amount'];

    if (!$is_instant) {
        $elapsed = time() - (int)$tok_ts;
        $total_watched = $elapsed + $tok_saved;
        if ($total_watched < $required) {
            $kurang = $required - $total_watched;
            echo json_encode(['ok'=>false,'msg'=>"Waktu belum cukup. Tunggu {$kurang} detik lagi."]); exit;
        }
        if (!$is_debug_user && $elapsed > ($required - $tok_saved) * 4 + 300) {
            echo json_encode(['ok'=>false,'msg'=>'Sesi telah kedaluwarsa. Refresh dan putar kembali.']); exit;
        }
    }

    try {
        $pdo->beginTransaction();
        
        $pdo->prepare("SELECT id FROM users WHERE id=? FOR UPDATE")->execute([$user['id']]);
        
        if ($is_debug_user) {
            // Mode debug tester: bersihkan riwayat hari ini untuk video ini agar pengujian berulang kali lancar
            $pdo->prepare("DELETE FROM watch_history WHERE user_id=? AND video_id=? AND DATE(watched_at)=CURDATE()")
                ->execute([$user['id'], $vid_id]);
        } else {
            $chk2 = $pdo->prepare("SELECT id FROM watch_history WHERE user_id=? AND video_id=? AND DATE(watched_at)=CURDATE()");
            $chk2->execute([$user['id'], $vid_id]);
            if ($chk2->fetch()) { 
                $pdo->rollBack();
                echo json_encode(['ok'=>false,'msg'=>'Video ini sudah ditonton hari ini.']); exit; 
            }

            $wt = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=? AND DATE(watched_at)=CURDATE()");
            $wt->execute([$user['id']]);
            if ((int)$wt->fetchColumn() >= $watch_limit) {
                $pdo->rollBack();
                echo json_encode(['ok'=>false,'msg'=>'Batas tonton harian telah tercapai!']); exit;
            }
        }

        $pdo->prepare("INSERT INTO watch_history (user_id,video_id,reward_given) VALUES (?,?,?)")
            ->execute([$user['id'], $vid_id, $reward]);
        $pdo->prepare("UPDATE users SET balance_wd=balance_wd+?,total_earned=total_earned+? WHERE id=?")
            ->execute([$reward, $reward, $user['id']]);

        // Sinkronkan total_watches = fake_watches + real_watches (watch_history)
        try {
            $pdo->prepare("UPDATE videos SET total_watches = COALESCE(fake_watches, 0) + (SELECT COUNT(*) FROM watch_history WHERE video_id=?) WHERE id=?")
                ->execute([$vid_id, $vid_id]);
        } catch (\Throwable) {
            $pdo->prepare("UPDATE videos SET total_watches = total_watches + 1 WHERE id=?")
                ->execute([$vid_id]);
        }

        // Hapus progres video dari user_watch_progress karena misi sudah selesai diklaim
        try {
            $pdo->prepare("DELETE FROM user_watch_progress WHERE user_id=? AND video_id=?")
                ->execute([$user['id'], $vid_id]);
        } catch (\Throwable) {}

        $pdo->commit();

        $debug_notice = $is_debug_user ? ' [Mode Debug Tester]' : '';
        $_SESSION['flash_videos_msg'] = 'Reward ' . format_rp($reward) . ' berhasil diklaim!' . $debug_notice;
        $_SESSION['flash_videos_type'] = 'success';
        echo json_encode(['ok'=>true,'reward'=>format_rp($reward),'msg'=>'+' . format_rp($reward) . ' berhasil ditambahkan!' . $debug_notice]);
    } catch (\Throwable) {
        $pdo->rollBack();
        echo json_encode(['ok'=>false,'msg'=>'Terjadi kendala pada server. Silakan coba lagi.']);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// AJAX: toggle_like — suka atau batal suka video
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_like') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { 
        echo json_encode(['ok' => false, 'msg' => 'Sesi kedaluwarsa, silakan refresh.']); 
        exit; 
    }

    $chk_l = $pdo->prepare("SELECT id FROM video_likes WHERE user_id=? AND video_id=?");
    $chk_l->execute([$user['id'], $vid_id]);
    $already_liked = (bool)$chk_l->fetch();

    if ($already_liked) {
        $pdo->prepare("DELETE FROM video_likes WHERE user_id=? AND video_id=?")->execute([$user['id'], $vid_id]);
        $is_liked = false;
    } else {
        $pdo->prepare("INSERT IGNORE INTO video_likes (user_id, video_id) VALUES (?, ?)")->execute([$user['id'], $vid_id]);
        $is_liked = true;
    }

    // Selalu sinkronkan total_likes = fake_likes + real_likes
    try {
        $pdo->prepare("UPDATE videos SET total_likes = COALESCE(fake_likes, 0) + (SELECT COUNT(*) FROM video_likes WHERE video_id=?) WHERE id=?")->execute([$vid_id, $vid_id]);
    } catch (\Throwable) {
        $pdo->prepare("UPDATE videos SET total_likes = (SELECT COUNT(*) FROM video_likes WHERE video_id=?) WHERE id=?")->execute([$vid_id, $vid_id]);
    }

    $cnt = $pdo->prepare("SELECT total_likes FROM videos WHERE id=?");
    $cnt->execute([$vid_id]);
    $total_likes = (int)$cnt->fetchColumn();

    echo json_encode([
        'ok' => true,
        'liked' => $is_liked,
        'total_likes' => $total_likes,
        'msg' => $is_liked ? 'Anda menyukai video ini.' : 'Batal menyukai video.'
    ]);
    exit;
}

// Load recommended videos
$others = $pdo->prepare(
    "SELECT v.*,
       (SELECT COUNT(*) FROM watch_history wh WHERE wh.user_id=? AND wh.video_id=v.id AND DATE(wh.watched_at)=CURDATE()) AS watched_today
     FROM videos v
     WHERE v.is_active=1 AND v.id != ?
     ORDER BY RAND() LIMIT 4"
);
$others->execute([$user['id'], $vid_id]);
$other_videos = $others->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title><?= htmlspecialchars($video['title']) ?> — Nonton &amp; Dapatkan Saldo</title>
<meta name="robots" content="noindex">
<meta name="theme-color" content="#ffb46b">
<?php require dirname(__DIR__) . '/partials/head_icons.php'; ?>

<!-- Fonts & Phosphor Icons -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1"></script>
<link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/app.css') ?: time() ?>">

<style>
/* ══════════════════════════════════════════════════════════
   WATCH — Bioskop Bawah Laut · living coral reef clay
   Player dalam bingkai akuarium + dock misi (timer, klaim).
   Seragam dengan /videos.
   ══════════════════════════════════════════════════════════ */
:root {
  --ink: #1b2a1e; --muted: #5c6b5f; --pc-font: 'Fredoka', 'Nunito', sans-serif;
  --ease: cubic-bezier(.34, 1.56, .64, 1); --out: cubic-bezier(.22, 1, .36, 1);
  --panel: linear-gradient(180deg, #fffaf0, #fff);
  --panel-sh: 0 0 0 2px #f6e2b0, 0 4px 0 #ecd59c, 0 10px 20px rgba(120, 70, 10, .08);
}
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
html, body { margin: 0; padding: 0; overflow-x: hidden; }
body {
  min-height: 100vh; font-family: 'Nunito', -apple-system, sans-serif; color: var(--ink); -webkit-font-smoothing: antialiased;
  background: radial-gradient(120% 40% at 50% 0%, #ffd59a 0%, transparent 60%), linear-gradient(180deg, #fff0cf 0%, #fff8e8 40%, #eef7e2 100%) fixed;
}
i[class^="ph-"] { display: inline-flex; align-items: center; justify-content: center; }
.watch-container { width: 100%; max-width: 480px; margin: 0 auto; padding: 0 0 28px; }

/* ── Topbar ── */
.watch-topbar { position: sticky; top: 0; z-index: 50; display: flex; align-items: center; gap: 8px; padding: 9px 12px;
  background: linear-gradient(180deg, #ffb46b, #ffd08a); border-radius: 0 0 20px 20px; box-shadow: 0 4px 0 #e59a4a, 0 10px 22px rgba(120, 70, 10, .18); }
.back-btn { width: 36px; height: 36px; flex-shrink: 0; display: grid; place-items: center; border-radius: 12px; text-decoration: none; color: #4a2b00; font-size: 17px; background: rgba(255, 255, 255, .85); box-shadow: 0 3px 0 rgba(120, 70, 10, .25); }
.back-btn span { display: none; }
.back-btn:active { transform: translateY(2px); box-shadow: none; }
.wt-title { flex: 1; min-width: 0; line-height: 1.15; }
.wt-title small { display: block; font-size: 11px; font-weight: 900; color: #7a4f00; }
.wt-title b { display: block; font-family: var(--pc-font); font-size: 15px; font-weight: 600; color: #4a2b00; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.watch-topbar__bal { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px 3px 4px; border-radius: 999px; white-space: nowrap; font-family: var(--pc-font); font-size: 13px; font-weight: 700; color: #4a3000; background: rgba(255, 255, 255, .88); }
.watch-topbar__bal img { width: 22px; height: 22px; object-fit: contain; }

/* ── Layar bioskop ── */
.tank { margin: 12px 12px 0; padding: 7px; border-radius: 24px; background: linear-gradient(180deg, #a0652a, #7a4a1c); box-shadow: 0 5px 0 #5a3412, 0 14px 26px rgba(90, 52, 18, .3), inset 0 2px 0 rgba(255, 255, 255, .25); }
.tank-in { position: relative; overflow: hidden; border-radius: 18px; background: #1b1206; box-shadow: inset 0 0 0 2px rgba(255, 213, 79, .35); }
.player-frame { position: relative; width: 100%; aspect-ratio: 16 / 9; background: #1b1206; }
.player-frame iframe, .player-frame #yt-player { position: absolute; inset: 0; width: 100% !important; height: 100% !important; border: 0; }
.watch-track { height: 8px; background: rgba(0, 0, 0, .45); }
.watch-fill { width: 0; height: 100%; background: repeating-linear-gradient(-45deg, #ffd54f 0 6px, #f6b70a 6px 12px); background-size: 200% 100%; animation: wtStripes 1.4s linear infinite; box-shadow: 0 0 10px rgba(255, 213, 79, .6); transition: width .4s linear; }
.watch-fill.done { background: linear-gradient(90deg, #4ade80, #16a34a); animation: none; }
@keyframes wtStripes { to { background-position: -34px 0; } }

/* ── Dock quest ── */
.watch-status-box { margin: 12px 12px 0; display: flex; align-items: center; gap: 11px; padding: 10px 11px; border-radius: 22px; background: var(--panel); box-shadow: var(--panel-sh); }
.watch-timer-wrap { flex: 1; min-width: 0; display: flex; align-items: center; gap: 11px; }
.watch-timer-wrap > div:last-child { min-width: 0; }
.timer-pill { width: 56px; height: 56px; flex-shrink: 0; border-radius: 50%; display: grid; place-items: center; font-family: var(--pc-font); font-size: 19px; font-weight: 700; color: #4a3000; font-variant-numeric: tabular-nums;
  background: radial-gradient(circle at 35% 30%, #fff7c2 0, #ffd54f 50%, #f6b70a 100%); box-shadow: 0 0 0 3px #fff, 0 4px 0 #b07800, 0 8px 16px rgba(246, 183, 10, .35); }
.timer-pill.done { color: #fff; font-size: 24px; background: radial-gradient(circle at 35% 30%, #bbf7d0 0, #22c55e 50%, #15803d 100%); box-shadow: 0 0 0 3px #fff, 0 4px 0 #11622f, 0 8px 16px rgba(22, 163, 74, .35); }
.watch-status-text { font-size: 13px; font-weight: 900; line-height: 1.25; color: var(--ink); }
.watch-status-text i { vertical-align: -2px; }
.watch-status-hint { margin-top: 2px; font-size: 11.5px; font-weight: 700; line-height: 1.35; color: var(--muted); }
.btn-claim { flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px; min-height: 44px; padding: 0 14px; border: 0; border-radius: 14px; cursor: pointer; text-decoration: none; white-space: nowrap;
  font-family: var(--pc-font); font-size: 15px; font-weight: 600; color: #4a3000; text-shadow: 0 1px 0 rgba(255, 255, 255, .5);
  background: linear-gradient(135deg, #ffd54f, #f6b70a); box-shadow: 0 4px 0 #b07800, 0 8px 14px rgba(246, 183, 10, .4), inset 0 2px 0 rgba(255, 255, 255, .45); animation: pulseClaim 1.4s var(--ease) infinite; }
.btn-claim:active { transform: translateY(3px); box-shadow: 0 1px 0 #b07800; }
.btn-claim:disabled { opacity: .8; animation: none; }
.btn-claim.up { color: #fff; text-shadow: 0 1px 0 rgba(0, 0, 0, .2); background: linear-gradient(135deg, #22c55e, #15803d); box-shadow: 0 4px 0 #11622f, 0 8px 14px rgba(22, 163, 74, .3); animation: none; }
@keyframes pulseClaim { 0%, 60%, 100% { transform: none; } 70% { transform: translateY(-4px) scale(1.05); } 80% { transform: none; } }

/* ── Lanjutkan ── */
.resume-banner { margin: 10px 12px 0; display: flex; align-items: center; gap: 9px; padding: 8px 10px; border-radius: 18px; background: #fff7d1; box-shadow: 0 0 0 2px #fcd34d, 0 3px 0 #e9b949; }
.resume-banner__icon { width: 34px; height: 34px; flex-shrink: 0; display: grid; place-items: center; border-radius: 11px; color: #4a3000; font-size: 16px; background: linear-gradient(135deg, #ffd54f, #f6b70a); box-shadow: 0 2px 0 #b07800; }
.resume-banner__content { flex: 1; min-width: 0; }
.resume-banner__title { font-size: 12.5px; font-weight: 900; color: #4a2b00; }
.resume-banner__desc { font-size: 11px; font-weight: 700; color: #7a4f00; }
.resume-banner__btn-reset { flex-shrink: 0; display: inline-flex; align-items: center; gap: 3px; padding: 6px 10px; border: 0; border-radius: 10px; cursor: pointer; font: 900 11.5px/1 'Nunito', sans-serif; color: #8a5a00; background: #fff; box-shadow: 0 2px 0 #ecd59c; }

/* ── Konten ── */
.watch-content { display: flex; flex-direction: column; gap: 10px; padding: 12px 12px 0; }
.video-title { margin: 0 2px; font-family: var(--pc-font); font-size: 18px; font-weight: 600; line-height: 1.25; color: #4a2b00; }
.action-strip { display: flex; gap: 6px; overflow-x: auto; padding: 2px 2px 5px; scrollbar-width: none; }
.action-strip::-webkit-scrollbar { display: none; }
.btn-action, .action-stat { flex-shrink: 0; display: inline-flex; align-items: center; gap: 5px; padding: 7px 11px; border: 0; border-radius: 999px; white-space: nowrap; font: 900 12px/1 'Nunito', sans-serif; color: var(--ink); background: #fff; box-shadow: 0 0 0 2px #efe6cf, 0 3px 0 #e6dbbd; }
.btn-action { cursor: pointer; }
.btn-action:active { transform: translateY(2px); }
.btn-action i, .action-stat i { font-size: 15px; color: #15803d; }
.btn-action--liked { color: #fff; background: linear-gradient(135deg, #fb7185, #e11d48); box-shadow: 0 3px 0 #9f1239; }
.btn-action--liked i { color: #fff; }
.action-pill { padding: 2px 7px; border-radius: 999px; font-size: 10.5px; color: #14532d; background: #dcfce7; }
.btn-action--liked .action-pill { color: #fff; background: rgba(255, 255, 255, .25); }
.action-stat { color: var(--muted); }
.action-stat--reward { color: #6b4300; background: linear-gradient(180deg, #fff3c4, #ffe9a0); box-shadow: inset 0 0 0 1.5px rgba(246, 183, 10, .5); }
.action-stat--reward img { width: 18px; height: 18px; object-fit: contain; }

.guide-card { padding: 11px; border-radius: 20px; background: var(--panel); box-shadow: var(--panel-sh); }
.guide-card__header { display: flex; align-items: center; gap: 6px; margin: 0 2px 9px; font-family: var(--pc-font); font-size: 15px; font-weight: 600; color: #4a2b00; }
.guide-card__header img { width: 22px; height: 22px; object-fit: contain; }
.guide-card__list { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; counter-reset: g; }
.guide-card__list li { counter-increment: g; position: relative; padding: 28px 8px 9px; border-radius: 14px; font-size: 11px; font-weight: 700; line-height: 1.35; color: var(--muted); background: #fff; box-shadow: inset 0 0 0 1.5px #f1dfb4; }
.guide-card__list li::before { content: counter(g); position: absolute; top: 6px; left: 8px; width: 19px; height: 19px; border-radius: 50%; display: grid; place-items: center; font-family: var(--pc-font); font-size: 11.5px; font-weight: 700; color: #4a3000; background: linear-gradient(180deg, #ffd54f, #f6b70a); box-shadow: 0 2px 0 #b07800; }
.guide-card__list b { color: #15803d; }

.banner-watched { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 16px; font-size: 12.5px; font-weight: 900; color: #14532d; background: #dcfce7; box-shadow: inset 0 0 0 2px #bbf7d0; }
.banner-watched img { width: 26px; height: 26px; object-fit: contain; }
.btn-more-vids { display: flex; align-items: center; justify-content: center; gap: 6px; min-height: 44px; border-radius: 14px; text-decoration: none; font-family: var(--pc-font); font-size: 15px; font-weight: 600; color: #fff; text-shadow: 0 1px 0 rgba(0, 0, 0, .15);
  background: linear-gradient(135deg, #22c55e, #15803d); box-shadow: 0 4px 0 #11622f, 0 8px 14px rgba(22, 163, 74, .3); }
.btn-more-vids:active { transform: translateY(3px); box-shadow: 0 1px 0 #11622f; }
.btn-more-vids img { width: 22px; height: 22px; object-fit: contain; }

/* Rekomendasi */
.recom-section { display: flex; flex-direction: column; gap: 8px; }
.recom-title { display: inline-flex; align-self: flex-start; align-items: center; gap: 6px; margin: 4px 0 0 8px; padding: 4px 16px 5px 12px; border-radius: 6px 10px 10px 6px; font-family: var(--pc-font); font-size: 15px; font-weight: 700; color: #fff; text-shadow: 0 1px 0 rgba(0, 0, 0, .25); background: linear-gradient(180deg, #22c55e, #15803d); box-shadow: 0 3px 0 #11622f; }
.recom-title img { width: 22px; height: 22px; margin: -5px 0; object-fit: contain; }
.recom-card { display: flex; align-items: center; gap: 10px; padding: 7px; border-radius: 18px; text-decoration: none; color: inherit; background: #fff; box-shadow: 0 0 0 2px #efe6cf, 0 3px 0 #e6dbbd; transition: transform .15s var(--ease); }
.recom-card:active { transform: translateY(2px); }
.recom-thumb { position: relative; width: 112px; aspect-ratio: 16 / 9; flex-shrink: 0; overflow: hidden; border-radius: 12px; background: #1b1206; }
.recom-thumb img { display: block; width: 100%; height: 100%; object-fit: cover; }
.recom-info { flex: 1; min-width: 0; }
.recom-name { font-size: 12.5px; font-weight: 800; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.recom-meta { display: flex; align-items: center; gap: 6px; margin-top: 5px; font-size: 11px; font-weight: 900; }
.recom-meta .coin { padding: 1px 8px; border-radius: 999px; color: #6b4300; background: #fff3c4; }

/* Loader & popup */
#page-loader { position: fixed; inset: 0; z-index: 9999; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; background: linear-gradient(180deg, #fff0cf, #eef7e2); transition: opacity .35s; }
#page-loader.hidden { opacity: 0; pointer-events: none; }
.loader-art { width: 84px; height: 84px; object-fit: contain; animation: wtBob 1.2s ease-in-out infinite; filter: drop-shadow(0 8px 8px rgba(120, 70, 10, .25)); }
@keyframes wtBob { 50% { transform: translateY(-8px) rotate(-6deg); } }
.loader-label { font-family: var(--pc-font); font-size: 15px; font-weight: 600; color: #7a4f00; }
@keyframes spin { to { transform: rotate(360deg); } }
.reward-popup { position: fixed; left: 50%; top: 16px; z-index: 10000; max-width: calc(100% - 32px); padding: 10px 18px; border-radius: 16px; transform: translate(-50%, -220%); visibility: hidden; text-align: center;
  font-family: var(--pc-font); font-size: 16px; font-weight: 600; color: #4a3000; background: linear-gradient(135deg, #ffd54f, #f6b70a); box-shadow: 0 4px 0 #b07800, 0 14px 30px rgba(246, 183, 10, .4);
  transition: transform .35s var(--ease), visibility .35s; }
.reward-popup.show { transform: translate(-50%, 0); visibility: visible; }

@media (max-width: 350px) { .guide-card__list { grid-template-columns: 1fr; } .guide-card__list li { padding: 8px 8px 8px 34px; } .recom-thumb { width: 96px; } }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; } }

/* ── MODE DEBUG TESTER STYLES ── */
.debug-badge-top {
  background: #7c3aed;
  color: #fff;
  border: 1.5px solid #a78bfa;
  border-radius: 12px;
  padding: 3.5px 8px;
  font-size: 11px;
  font-weight: 900;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  box-shadow: 0 0 8px rgba(124, 58, 237, 0.4);
}
.debug-panel {
  background: linear-gradient(135deg, #1e1e2f 0%, #111827 100%);
  border: 2px solid #8b5cf6;
  border-radius: 16px;
  margin: 10px 14px 12px 14px;
  overflow: hidden;
  box-shadow: 0 4px 16px rgba(139, 92, 246, 0.25);
  color: #f8fafc;
}
.debug-panel__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  background: rgba(139, 92, 246, 0.15);
  border-bottom: 1px solid rgba(139, 92, 246, 0.3);
}
.debug-panel__title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  font-weight: 900;
  color: #c4b5fd;
}
.debug-panel__tag {
  background: #7c3aed;
  color: #fff;
  font-size: 9.5px;
  font-weight: 800;
  padding: 2px 7px;
  border-radius: 6px;
  letter-spacing: 0.3px;
}
.debug-panel__toggle {
  background: transparent;
  border: none;
  color: #a78bfa;
  font-size: 16px;
  cursor: pointer;
  padding: 2px 6px;
  border-radius: 6px;
  transition: all 0.15s;
}
.debug-panel__toggle:hover {
  background: rgba(255,255,255,0.1);
  color: #fff;
}
.debug-panel__body {
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 11px;
}
.debug-panel__body.collapsed {
  display: none;
}
.debug-panel__info {
  font-size: 10.5px;
  color: #94a3b8;
  line-height: 1.4;
  background: rgba(0,0,0,0.25);
  padding: 6px 10px;
  border-radius: 8px;
  border-left: 3px solid #8b5cf6;
}
.debug-panel__row {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.debug-panel__label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 800;
  color: #e2e8f0;
}
.debug-panel__label b {
  color: #38bdf8;
}
.debug-panel__sub {
  font-size: 10px;
  color: #64748b;
  font-weight: 700;
}
.debug-panel__pills {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.dbg-pill {
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 8px;
  color: #cbd5e1;
  font-size: 11px;
  font-weight: 800;
  padding: 4px 10px;
  cursor: pointer;
  transition: all 0.15s ease;
}
.dbg-pill:hover {
  background: rgba(139, 92, 246, 0.3);
  border-color: #8b5cf6;
  color: #fff;
}
.dbg-pill.active {
  background: #8b5cf6;
  border-color: #a78bfa;
  color: #fff;
  box-shadow: 0 0 10px rgba(139, 92, 246, 0.5);
}
.debug-panel__custom {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 2px;
}
.debug-panel__custom input {
  background: rgba(0, 0, 0, 0.35);
  border: 1px solid rgba(255, 255, 255, 0.2);
  border-radius: 8px;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  padding: 4px 8px;
  width: 120px;
}
.debug-panel__custom input:focus {
  outline: none;
  border-color: #8b5cf6;
}
.debug-panel__custom button {
  background: #3b82f6;
  border: none;
  border-radius: 8px;
  color: #fff;
  font-size: 11px;
  font-weight: 800;
  padding: 5px 10px;
  cursor: pointer;
  transition: all 0.15s;
}
.debug-panel__custom button:hover {
  background: #2563eb;
}
.debug-panel__actions {
  display: flex;
  gap: 8px;
  margin-top: 4px;
}
.dbg-btn-instant {
  flex: 1.3;
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  border: 1.5px solid #34d399;
  border-radius: 10px;
  color: #fff;
  font-size: 11.5px;
  font-weight: 900;
  padding: 8px 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  cursor: pointer;
  box-shadow: 0 2px 8px rgba(16, 185, 129, 0.35);
  transition: all 0.15s;
}
.dbg-btn-instant:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(16, 185, 129, 0.5);
}
.dbg-btn-instant:active {
  transform: translateY(1px);
}
.dbg-btn-reset {
  flex: 1;
  background: rgba(239, 68, 68, 0.15);
  border: 1.5px solid #ef4444;
  border-radius: 10px;
  color: #fca5a5;
  font-size: 11px;
  font-weight: 800;
  padding: 8px 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  cursor: pointer;
  transition: all 0.15s;
}
.dbg-btn-reset:hover {
  background: #ef4444;
  color: #fff;
}
</style>
</head>
<body>

<!-- Page Loader -->
<div id="page-loader">
  <img class="loader-art" src="/assets/img/icons3d/watch.png" alt="">
  <div class="loader-label">Menyiapkan bioskop...</div>
</div>

<div class="watch-container">

  <!-- ── TOPBAR ── -->
  <div class="watch-topbar">
    <a href="/videos" class="back-btn" title="Kembali ke Video"><i class="ph-bold ph-caret-left"></i><span>Kembali ke Video</span></a>
    <div class="wt-title"><small>Bioskop Sawah</small><b><?= htmlspecialchars($video['title']) ?></b></div>
    <?php if ($is_debug_user): ?>
      <span class="debug-badge-top" title="Akun ini berada dalam Mode Debug Tester"><i class="ph-bold ph-wrench"></i> DEBUG</span>
    <?php endif; ?>
    <div class="watch-topbar__bal" title="Saldo Siap Tarik"><img src="/assets/img/icons3d/coins.png" alt=""><span><?= format_rp((float)$user['balance_wd']) ?></span></div>
  </div>

  <?php if ($is_debug_user): ?>
  <!-- ── MODE DEBUG TESTER PANEL ── -->
  <div class="debug-panel" id="debugPanel">
    <div class="debug-panel__header">
      <div class="debug-panel__title">
        <i class="ph-bold ph-wrench"></i>
        <span>MODE DEBUG TESTER</span>
        <span class="debug-panel__tag">@<?= htmlspecialchars($user['username']) ?></span>
      </div>
      <button type="button" class="debug-panel__toggle" onclick="toggleDebugPanel()" id="dbgToggleBtn" title="Perkecil / Buka Panel">
        <i class="ph-bold ph-caret-up" id="dbgToggleIcon"></i>
      </button>
    </div>
    
    <div class="debug-panel__body" id="dbgBody">
      <div class="debug-panel__info">
        Pengaturan ini <b>hanya berlaku untuk akun Anda</b>. Perubahan durasi & reward langsung aktif seketika tanpa mengubah konfigurasi video asli untuk pengguna lain.
      </div>

      <!-- Durasi Section -->
      <div class="debug-panel__row">
        <div class="debug-panel__label">
          <span>⏱ Durasi Tonton Tester:</span>
          <b id="dbgActiveDurLbl"><?= $active_duration ?>s</b>
          <span class="debug-panel__sub">(Asli: <?= $orig_duration ?>s)</span>
        </div>
        <div class="debug-panel__pills">
          <button type="button" class="dbg-pill <?= $active_duration === 1 ? 'active' : '' ?>" onclick="setDebugDur(1)">1s</button>
          <button type="button" class="dbg-pill <?= $active_duration === 3 ? 'active' : '' ?>" onclick="setDebugDur(3)">3s</button>
          <button type="button" class="dbg-pill <?= $active_duration === 5 ? 'active' : '' ?>" onclick="setDebugDur(5)">5s</button>
          <button type="button" class="dbg-pill <?= $active_duration === 10 ? 'active' : '' ?>" onclick="setDebugDur(10)">10s</button>
          <button type="button" class="dbg-pill <?= $active_duration === $orig_duration ? 'active' : '' ?>" onclick="setDebugDur(<?= $orig_duration ?>)">Asli (<?= $orig_duration ?>s)</button>
        </div>
        <div class="debug-panel__custom">
          <input type="number" id="dbgInpDur" min="1" max="3600" placeholder="Detik..." value="<?= $active_duration ?>">
          <button type="button" onclick="setDebugDur(document.getElementById('dbgInpDur').value)">Set Durasi</button>
        </div>
      </div>

      <!-- Reward / Benefit Section -->
      <div class="debug-panel__row">
        <div class="debug-panel__label">
          <span>💰 Benefit / Reward Tester:</span>
          <b id="dbgActiveRwdLbl"><?= format_rp($active_reward) ?></b>
          <span class="debug-panel__sub">(Asli: <?= format_rp($orig_reward) ?>)</span>
        </div>
        <div class="debug-panel__pills">
          <button type="button" class="dbg-pill <?= (float)$active_reward === 5000.0 ? 'active' : '' ?>" onclick="setDebugRwd(5000)">Rp 5.000</button>
          <button type="button" class="dbg-pill <?= (float)$active_reward === 25000.0 ? 'active' : '' ?>" onclick="setDebugRwd(25000)">Rp 25.000</button>
          <button type="button" class="dbg-pill <?= (float)$active_reward === 50000.0 ? 'active' : '' ?>" onclick="setDebugRwd(50000)">Rp 50.000</button>
          <button type="button" class="dbg-pill <?= (float)$active_reward === 100000.0 ? 'active' : '' ?>" onclick="setDebugRwd(100000)">Rp 100.000</button>
          <button type="button" class="dbg-pill <?= (float)$active_reward === $orig_reward ? 'active' : '' ?>" onclick="setDebugRwd(<?= $orig_reward ?>)">Asli</button>
        </div>
        <div class="debug-panel__custom">
          <input type="number" id="dbgInpRwd" min="0" step="500" placeholder="Nominal Rp..." value="<?= (int)$active_reward ?>">
          <button type="button" onclick="setDebugRwd(document.getElementById('dbgInpRwd').value)">Set Reward</button>
        </div>
      </div>

      <!-- Quick Action Section -->
      <div class="debug-panel__actions">
        <button type="button" class="dbg-btn-instant" onclick="triggerInstantClaim()" title="Selesaikan detik dan klaim langsung">
          <i class="ph-bold ph-lightning"></i>
          <span>⚡ Selesai & Klaim Seketika</span>
        </button>
        <button type="button" class="dbg-btn-reset" onclick="resetDebugVideoStatus()" title="Hapus riwayat tonton video ini agar bisa dicoba ulang">
          <i class="ph-bold ph-arrow-counter-clockwise"></i>
          <span>Reset Status Misi</span>
        </button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── PLAYER (bingkai akuarium) ── -->
  <div class="tank">
    <div class="tank-in">
      <div class="player-frame"><div id="yt-player"></div></div>
      <div class="watch-track"><div class="watch-fill" id="prog-fill"></div></div>
    </div>
  </div>

  <?php if ($saved_seconds > 0 && ($canWatch || $is_debug_user)): ?>
  <div class="resume-banner" id="resume-banner">
    <div class="resume-banner__icon"><i class="ph-bold ph-arrow-counter-clockwise"></i></div>
    <div class="resume-banner__content">
      <div class="resume-banner__title">Lanjut dari <?= $saved_seconds ?>s</div>
      <div class="resume-banner__desc">Sisa <b id="resume-left-sec"><?= max(0, $active_duration - $saved_seconds) ?></b> detik lagi</div>
    </div>
    <button type="button" class="resume-banner__btn-reset" onclick="resetWatchProgress()" title="Ulangi video dari detik 0"><i class="ph-bold ph-arrow-u-down-left"></i> Ulang</button>
  </div>
  <?php endif; ?>

  <!-- ── DOCK MISI ── -->
  <div class="watch-status-box" id="status-bar">
    <div class="watch-timer-wrap">
      <div class="timer-pill <?= ($already_watched && !$is_debug_user) ? 'done' : '' ?>" id="timer-badge">
        <?php if ($already_watched && !$is_debug_user): ?>
          <i class="ph-bold ph-check"></i>
        <?php elseif ($canWatch || $is_debug_user): ?>
          <?= $active_duration ?>
        <?php else: ?>
          –
        <?php endif; ?>
      </div>
      <div>
        <div class="watch-status-text" id="status-text">
          <?php if ($already_watched && !$is_debug_user): ?>
            <span style="color:#059669;"><i class="ph-bold ph-check-circle"></i> Selesai ditonton hari ini</span>
          <?php elseif ($watch_today >= $watch_limit && !$is_debug_user): ?>
            <span style="color:#dc2626;"><i class="ph-bold ph-warning-circle"></i> Kuota harian habis</span>
          <?php else: ?>
            <span>Putar video untuk mulai quest <?= $is_debug_user ? '(Mode Debug)' : '' ?></span>
          <?php endif; ?>
        </div>
        <div class="watch-status-hint" id="status-hint">
          <?php if ($canWatch || $is_debug_user): ?>
            Reward: +<?= format_rp((float)$active_reward) ?> setelah <?= $active_duration ?> detik<?= $is_debug_user ? ' <b style="color:#7c3aed;">[Debug]</b>' : '' ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($canWatch || $is_debug_user): ?>
      <div id="claim-wrap" style="display:none;">
        <button type="button" class="btn-claim" id="claim-btn" onclick="claimReward()"><i class="ph-bold ph-coins"></i><span>Klaim Cuan</span></button>
      </div>
    <?php elseif ($watch_today >= $watch_limit): ?>
      <a href="/upgrade" class="btn-claim up"><i class="ph-fill ph-crown"></i><span>Level Up</span></a>
    <?php endif; ?>
  </div>

  <!-- ── KONTEN ── -->
  <div class="watch-content">
    <h1 class="video-title"><?= htmlspecialchars($video['title']) ?></h1>

    <div class="action-strip">
      <button type="button" id="btnLike" onclick="toggleLike()" class="btn-action <?= $user_has_liked ? 'btn-action--liked' : '' ?>" title="Suka Video Ini">
        <i class="ph-<?= $user_has_liked ? 'fill' : 'bold' ?> ph-thumbs-up" id="likeIco"></i>
        <span id="likeTxt"><?= $user_has_liked ? 'Disukai' : 'Suka' ?></span>
        <span class="action-pill" id="likeCnt"><?= number_format((int)($video['total_likes'] ?? 0)) ?></span>
      </button>
      <div class="action-stat action-stat--reward" title="Reward"><img src="/assets/img/icons3d/coins.png" alt=""><span>+<?= format_rp((float)$active_reward) ?></span></div>
      <div class="action-stat" title="Total Tayangan"><i class="ph-bold ph-eye"></i><span><?= number_format((int)$video['total_watches']) ?></span></div>
      <button type="button" class="btn-action" onclick="shareVideo()" title="Bagikan Video"><i class="ph-bold ph-share-network"></i><span>Bagikan</span></button>
    </div>

    <?php if ($already_watched): ?>
      <div class="banner-watched">
        <img src="/assets/img/icons3d/chest_open.png" alt="">
        <span>Quest video ini sudah beres hari ini!</span>
        <?php if ($is_debug_user): ?>
          <button type="button" class="resume-banner__btn-reset" onclick="resetDebugVideoStatus()" style="margin-left:auto;"><i class="ph-bold ph-arrow-counter-clockwise"></i> Reset</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="guide-card">
      <div class="guide-card__header"><img src="/assets/img/icons3d/compass.png" alt=""><span>Cara klaim reward</span></div>
      <ol class="guide-card__list">
        <li>Putar video, timer mulai jalan.</li>
        <li>Tonton sampai timer habis, jangan dijeda.</li>
        <li>Tekan <b>Klaim Cuan</b>, saldo langsung masuk.</li>
      </ol>
    </div>

    <?php if (!empty($other_videos)): ?>
    <div class="recom-section">
      <div class="recom-title"><img src="/assets/img/icons3d/watch.png" alt=""><span>Tonton berikutnya</span></div>
      <?php foreach ($other_videos as $ov):
        $ov_done    = (bool)$ov['watched_today'];
        $ov_blocked = !$ov_done && ($watch_today >= $watch_limit);
        $ov_href    = ($ov_done || $ov_blocked) ? '#' : '/watch?id=' . $ov['id'];
      ?>
      <a href="<?= $ov_href ?>" class="recom-card" style="<?= ($ov_done || $ov_blocked) ? 'opacity:.6;pointer-events:none' : '' ?>">
        <div class="recom-thumb">
          <img src="<?= yt_thumb($ov['youtube_id']) ?>" alt="" onerror="this.src='https://img.youtube.com/vi/<?= $ov['youtube_id'] ?>/hqdefault.jpg'">
          <?php if ($ov_done): ?>
            <div style="position:absolute;inset:0;background:rgba(22,163,74,.75);display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;"><i class="ph-bold ph-check"></i></div>
          <?php endif; ?>
        </div>
        <div class="recom-info">
          <div class="recom-name"><?= htmlspecialchars($ov['title']) ?></div>
          <div class="recom-meta">
            <?php if ($ov_done): ?>
              <span style="color:#059669;"><i class="ph-bold ph-check-circle"></i> Selesai</span>
            <?php else: ?>
              <span class="coin">+<?= format_rp((float)$ov['reward_amount']) ?></span>
            <?php endif; ?>
            <span style="color:#5c6b5f;"><i class="ph-bold ph-clock"></i> <?= (int)$ov['watch_duration'] ?>s</span>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <a href="/videos" class="btn-more-vids"><img src="/assets/img/icons3d/watch.png" alt="">Semua Video</a>
  </div>

</div>

<!-- Reward Toast Notification -->
<div class="reward-popup" id="reward-popup"></div>

<script>
// ── Server Constants ─────────────────────────────────
let DURATION        = <?= (int)$active_duration ?>;
const ORIG_DURATION = <?= (int)$orig_duration ?>;
const CAN_WATCH     = <?= ($canWatch || $is_debug_user) ? 'true' : 'false' ?>;
const CSRF          = '<?= csrf_token() ?>';
const WATCH_URL     = '';
const IS_DEBUG_USER = <?= $is_debug_user ? 'true' : 'false' ?>;
let currentActiveDur = <?= (int)$active_duration ?>;
let currentActiveRwd = <?= (float)$active_reward ?>;
let savedSeconds    = <?= (int)$saved_seconds ?>;
let savedPosition   = <?= (float)$saved_position ?>;

// ── State ────────────────────────────────────────────
let watchToken      = null;
let totalWatched    = savedSeconds;
let timerLeft       = Math.max(0, DURATION - savedSeconds);
let timerHandle     = null;
let watchStarted    = false;
let claimReady      = false;
let playerReady     = false;
let ytPlayer        = null;
let lastSavedTick   = savedSeconds;
let hasSeekedToSaved= false;

// ── Mode Debug Tester Controls ───────────────────────
function toggleDebugPanel() {
  const b = document.getElementById('dbgBody');
  const ic = document.getElementById('dbgToggleIcon');
  if (!b) return;
  b.classList.toggle('collapsed');
  if (b.classList.contains('collapsed')) {
    if (ic) ic.className = 'ph-bold ph-caret-down';
  } else {
    if (ic) ic.className = 'ph-bold ph-caret-up';
  }
}

async function setDebugDur(dur) {
  dur = parseInt(dur);
  if (!dur || dur <= 0) {
    if (typeof nToast === 'function') nToast('Durasi harus lebih dari 0 detik!', 'error');
    else alert('Durasi harus lebih dari 0 detik!');
    return;
  }
  await submitDebugSettings(dur, currentActiveRwd);
}

async function setDebugRwd(rwd) {
  rwd = parseFloat(rwd);
  if (isNaN(rwd) || rwd < 0) {
    if (typeof nToast === 'function') nToast('Nominal reward tidak valid!', 'error');
    else alert('Nominal reward tidak valid!');
    return;
  }
  await submitDebugSettings(currentActiveDur, rwd);
}

async function submitDebugSettings(dur, rwd) {
  const fd = new FormData();
  fd.append('action', 'set_debug_watch');
  fd.append('_csrf', CSRF);
  fd.append('duration', dur);
  fd.append('reward', rwd);

  try {
    const res = await fetch(WATCH_URL, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      currentActiveDur = data.duration;
      currentActiveRwd = data.reward;
      DURATION = data.duration;

      // Update badge label di panel
      const dLbl = document.getElementById('dbgActiveDurLbl');
      if (dLbl) dLbl.textContent = data.duration + 's';
      const rLbl = document.getElementById('dbgActiveRwdLbl');
      if (rLbl) rLbl.textContent = data.reward_formatted;

      const inDur = document.getElementById('dbgInpDur');
      if (inDur) inDur.value = data.duration;
      const inRwd = document.getElementById('dbgInpRwd');
      if (inRwd) inRwd.value = Math.round(data.reward);

      // Update pill active styling
      document.querySelectorAll('.debug-panel__row:first-of-type .dbg-pill').forEach(btn => {
        const txt = btn.textContent.trim();
        if (txt === data.duration + 's' || (data.duration === ORIG_DURATION && txt.startsWith('Asli'))) {
          btn.classList.add('active');
        } else {
          btn.classList.remove('active');
        }
      });

      document.querySelectorAll('.debug-panel__row:nth-of-type(2) .dbg-pill').forEach(btn => {
        const txt = btn.textContent.trim();
        if ((data.reward === 5000 && txt.includes('5.000')) ||
            (data.reward === 25000 && txt.includes('25.000')) ||
            (data.reward === 50000 && txt.includes('50000')) ||
            (data.reward === 100000 && txt.includes('100.000')) ||
            (data.reward === <?= (float)$orig_reward ?> && txt.startsWith('Asli'))) {
          btn.classList.add('active');
        } else {
          btn.classList.remove('active');
        }
      });

      // Update UI timer badge & hints
      const tb = document.getElementById('timer-badge');
      if (tb && !claimReady) {
        timerLeft = Math.max(0, DURATION - totalWatched);
        tb.textContent = timerLeft > 0 ? timerLeft : '✓';
      }
      const sh = document.getElementById('status-hint');
      if (sh) {
        sh.innerHTML = `Reward: +${data.reward_formatted} setelah ${data.duration} detik <b style="color:#7c3aed;">[Debug]</b>`;
      }

      if (typeof nToast === 'function') {
        nToast(`Mode Debug: Durasi ${data.duration}s • Reward ${data.reward_formatted}`, 'success');
      }

      // Re-sign token if session already active
      if (watchStarted && !claimReady) {
        startWatchSession(true);
      }
    } else {
      if (typeof nToast === 'function') nToast(data.msg || 'Gagal update debug', 'error');
      else alert(data.msg);
    }
  } catch(e) {
    if (typeof nToast === 'function') nToast('Kendala jaringan saat update mode debug.', 'error');
  }
}

async function triggerInstantClaim() {
  if (!IS_DEBUG_USER) return;

  const btn = document.getElementById('claim-btn');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Memproses instant claim...';
  }

  // Jika token belum ada, generate token terlebih dahulu
  if (!watchToken) {
    const fd = new FormData();
    fd.append('action', 'start_watch');
    fd.append('_csrf', CSRF);
    try {
      const res = await fetch(WATCH_URL, { method: 'POST', body: fd });
      const data = await res.json();
      if (!data.ok) {
        alert(data.msg || 'Gagal memulai sesi.');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ph-bold ph-coins"></i> Klaim Cuan'; }
        return;
      }
      watchToken = data.watch_token;
    } catch(err) {
      alert('Kendala jaringan saat inisialisasi sesi.');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ph-bold ph-coins"></i> Klaim Cuan'; }
      return;
    }
  }

  const fdClaim = new FormData();
  fdClaim.append('action', 'claim');
  fdClaim.append('_csrf', CSRF);
  fdClaim.append('watch_token', watchToken);
  fdClaim.append('instant', '1');

  try {
    const res  = await fetch(WATCH_URL, {method:'POST', body:fdClaim});
    const data = await res.json();
    if (data.ok) {
      showPop(data.msg || 'Reward berhasil diklaim!');
      if (btn) btn.innerHTML = '<i class="ph-bold ph-check"></i> Berhasil!';
      setStatus('⚡ Mode Debug: Reward berhasil diklaim seketika! Mengalihkan...', '');
      if (typeof nToast === 'function') nToast(data.msg, 'success');
      setTimeout(() => location.href = '/videos', 1500);
    } else {
      if (typeof nToast === 'function') nToast(data.msg || 'Gagal instant claim', 'error');
      else alert(data.msg || 'Gagal instant claim');
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="ph-bold ph-coins"></i> Klaim Cuan';
      }
    }
  } catch(e) {
    if (typeof nToast === 'function') nToast('Kendala jaringan saat instant claim.', 'error');
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="ph-bold ph-coins"></i> Klaim Cuan';
    }
  }
}

async function resetDebugVideoStatus() {
  if (!confirm('Reset status video ini untuk akun tester Anda? Anda akan bisa menonton dan mengklaim video ini kembali dari awal.')) return;
  const fd = new FormData();
  fd.append('action', 'reset_debug_status');
  fd.append('_csrf', CSRF);
  try {
    const res = await fetch(WATCH_URL, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      if (typeof nToast === 'function') nToast(data.msg, 'success');
      setTimeout(() => location.reload(), 500);
    } else {
      alert(data.msg || 'Gagal mereset status.');
    }
  } catch(e) {
    alert('Kendala jaringan saat reset status.');
  }
}

// ── YouTube IFrame API ───────────────────────────────
window.onYouTubeIframeAPIReady = function() {
  ytPlayer = new YT.Player('yt-player', {
    videoId: '<?= htmlspecialchars($video['youtube_id']) ?>',
    playerVars: {
      rel: 0,
      modestbranding: 1,
      playsinline: 1,
      autoplay: 1,
      origin: window.location.origin
    },
    events: {
      onReady: onPlayerReady,
      onStateChange: onPlayerStateChange,
      onError: function(e) {
        setStatus('Kendala pemutaran video', 'ID: <?= htmlspecialchars($video['youtube_id']) ?>');
      }
    }
  });
};

const tag = document.createElement('script');
tag.src = 'https://www.youtube.com/iframe_api';
document.head.appendChild(tag);

function onPlayerReady(e) {
  playerReady = true;
  const loader = document.getElementById('page-loader');
  if (loader) {
    loader.classList.add('hidden');
    setTimeout(() => loader.remove(), 400);
  }

  // Jika ada riwayat tontonan, langsung seek ke detik tontonan terakhir
  if (savedPosition > 0 && !hasSeekedToSaved) {
    try {
      ytPlayer.seekTo(savedPosition, true);
      hasSeekedToSaved = true;
    } catch(err) {}
  }
}

function onPlayerStateChange(e) {
  if (!CAN_WATCH) return;

  if (e.data === YT.PlayerState.PLAYING) {
    if (!hasSeekedToSaved && savedPosition > 0) {
      try {
        ytPlayer.seekTo(savedPosition, true);
        hasSeekedToSaved = true;
      } catch(err) {}
    }

    if (!watchStarted) {
      startWatchSession();
    } else if (timerHandle === null && !claimReady) {
      resumeCountdown();
    }
  } else if (e.data === YT.PlayerState.PAUSED || e.data === YT.PlayerState.BUFFERING) {
    pauseCountdown();
  }
}

// ── Request Watch Token (Sertakan Saved Seconds) ─────
async function startWatchSession(forceRestart = false) {
  const fd = new FormData();
  fd.append('action', 'start_watch');
  fd.append('_csrf', CSRF);
  try {
    const res  = await fetch(WATCH_URL, {method:'POST', body:fd});
    const data = await res.json();
    if (!data.ok) {
      setStatus(data.msg || 'Gagal memulai sesi', '');
      return false;
    }
    watchToken   = data.watch_token;
    watchStarted = true;
    if (data.saved_seconds !== undefined && !forceRestart) {
      savedSeconds = data.saved_seconds;
      totalWatched = savedSeconds;
      timerLeft    = Math.max(0, DURATION - totalWatched);
    }
    if (data.duration) {
      DURATION = data.duration;
      timerLeft = Math.max(0, DURATION - totalWatched);
    }
    startCountdown();
    return true;
  } catch(e) {
    setStatus('Error jaringan saat memulai sesi.', '');
    return false;
  }
}

// ── Countdown & Progress Tracker ────────────────────
function startCountdown() {
  updateTimerUI();
  if (timerHandle) clearInterval(timerHandle);

  timerHandle = setInterval(() => {
    totalWatched++;
    timerLeft = Math.max(0, DURATION - totalWatched);
    updateTimerUI();

    // Simpan progres ke server secara berkala tiap 4 detik (agar refresh tidak reset)
    if (totalWatched - lastSavedTick >= 4 && totalWatched < DURATION) {
      lastSavedTick = totalWatched;
      saveProgressToServer(totalWatched, ytPlayer && typeof ytPlayer.getCurrentTime === 'function' ? ytPlayer.getCurrentTime() : 0);
    }

    if (totalWatched >= DURATION || timerLeft <= 0) {
      clearInterval(timerHandle);
      timerHandle = null;
      claimReady  = true;
      const rb = document.getElementById('resume-banner');
      if (rb) rb.style.display = 'none';
      showClaimButton();
    }
  }, 1000);
}

function pauseCountdown() {
  if (timerHandle) { clearInterval(timerHandle); timerHandle = null; }
  if (!claimReady) {
    setStatus('Video dijeda — lanjutkan pemutaran untuk lanjut', '');
    // Simpan progres saat dijeda
    if (totalWatched > 0 && totalWatched < DURATION) {
      saveProgressToServer(totalWatched, ytPlayer && typeof ytPlayer.getCurrentTime === 'function' ? ytPlayer.getCurrentTime() : 0);
    }
  }
}

function resumeCountdown() {
  startCountdown();
}

function updateTimerUI() {
  const badge = document.getElementById('timer-badge');
  const fill  = document.getElementById('prog-fill');
  const pct   = Math.min(100, (totalWatched / DURATION) * 100);

  if (badge) {
    badge.textContent = timerLeft > 0 ? timerLeft : '✓';
    if (timerLeft <= 0) badge.classList.add('done');
  }
  if (fill) {
    fill.style.width = pct + '%';
    if (timerLeft <= 0) fill.classList.add('done');
  }

  const rLeft = document.getElementById('resume-left-sec');
  if (rLeft) rLeft.textContent = timerLeft;

  setStatus(
    timerLeft > 0 ? `Menonton: ${timerLeft} detik lagi... (${Math.round(pct)}%)` : 'Misi selesai! Klaim saldo Anda sekarang',
    timerLeft > 0 ? 'Jangan jeda atau tutup halaman' : ''
  );
}

function showClaimButton() {
  const w = document.getElementById('claim-wrap');
  if (w) w.style.display = 'block';
  const fill = document.getElementById('prog-fill');
  if (fill) fill.classList.add('done');
}

function setStatus(text, hint) {
  const el = document.getElementById('status-text');
  const eh = document.getElementById('status-hint');
  if (el) el.textContent = text;
  if (eh) eh.textContent = hint;
}

// ── Auto Save Progress ke Server ─────────────────────
function saveProgressToServer(sec, pos) {
  if (!CAN_WATCH || sec <= 0 || sec >= DURATION || claimReady) return;
  try {
    const fd = new FormData();
    fd.append('action', 'save_progress');
    fd.append('_csrf', CSRF);
    fd.append('seconds_watched', sec);
    fd.append('last_position', pos || 0);
    fetch(WATCH_URL, { method: 'POST', body: fd, keepalive: true }).catch(() => {});
  } catch(e) {}
}

// Simpan progres saat halaman direfresh atau ditutup mendadak
window.addEventListener('beforeunload', () => {
  if (watchStarted && !claimReady && totalWatched > 0 && totalWatched < DURATION) {
    const fd = new FormData();
    fd.append('action', 'save_progress');
    fd.append('_csrf', CSRF);
    fd.append('seconds_watched', totalWatched);
    fd.append('last_position', ytPlayer && typeof ytPlayer.getCurrentTime === 'function' ? ytPlayer.getCurrentTime() : 0);
    navigator.sendBeacon(WATCH_URL, fd);
  }
});

// ── Reset Watch Progress ─────────────────────────────
async function resetWatchProgress() {
  if (!confirm('Ulangi tontonan video ini dari awal (0 detik)?')) return;
  try {
    const fd = new FormData();
    fd.append('action', 'reset_progress');
    fd.append('_csrf', CSRF);
    await fetch(WATCH_URL, { method: 'POST', body: fd });
  } catch(e) {}

  savedSeconds     = 0;
  savedPosition    = 0;
  totalWatched     = 0;
  lastSavedTick    = 0;
  timerLeft        = DURATION;
  watchStarted     = false;
  claimReady       = false;
  hasSeekedToSaved = true;
  if (timerHandle) { clearInterval(timerHandle); timerHandle = null; }

  const rb = document.getElementById('resume-banner');
  if (rb) rb.remove();

  if (ytPlayer && typeof ytPlayer.seekTo === 'function') {
    try { ytPlayer.seekTo(0, true); } catch(e) {}
  }
  updateTimerUI();
  setStatus('Progres diulang dari awal. Putar video untuk mulai misi.', '');
  if (typeof nToast === 'function') nToast('Progres diulang dari 0 detik.', 'info');

  if (ytPlayer && ytPlayer.getPlayerState && ytPlayer.getPlayerState() === YT.PlayerState.PLAYING) {
    startWatchSession();
  }
}

// ── Claim Reward ─────────────────────────────────────
async function claimReward() {
  if (!watchToken) {
    if (typeof nToast === 'function') nToast('Sesi tidak ditemukan. Putar video dari awal.', 'error');
    return;
  }
  const btn = document.getElementById('claim-btn');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Memproses...';
  }

  const fd = new FormData();
  fd.append('action', 'claim');
  fd.append('_csrf', CSRF);
  fd.append('watch_token', watchToken);

  try {
    const res  = await fetch(WATCH_URL, {method:'POST', body:fd});
    const data = await res.json();

    if (data.ok) {
      showPop(data.msg || 'Reward berhasil diklaim!');
      if (btn) btn.innerHTML = '<i class="ph-bold ph-check"></i> Berhasil!';
      setStatus('Reward berhasil diklaim! Mengalihkan...', '');
      setTimeout(() => location.href = '/videos', 2000);
    } else {
      if (typeof nToast === 'function') nToast(data.msg || 'Gagal klaim', 'error');
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="ph-bold ph-coins"></i> Klaim Cuan';
      }
    }
  } catch(e) {
    if (typeof nToast === 'function') nToast('Error jaringan saat mengklaim saldo.', 'error');
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="ph-bold ph-coins"></i> Klaim Cuan';
    }
  }
}

// ── Toggle Like Feature ──────────────────────────────
async function toggleLike() {
  const btn = document.getElementById('btnLike');
  const ico = document.getElementById('likeIco');
  const txt = document.getElementById('likeTxt');
  const cnt = document.getElementById('likeCnt');
  if (!btn) return;

  btn.style.transform = 'scale(1.15)';
  setTimeout(() => btn.style.transform = '', 200);

  const fd = new FormData();
  fd.append('action', 'toggle_like');
  fd.append('_csrf', CSRF);

  try {
    const res  = await fetch(WATCH_URL, {method:'POST', body:fd});
    const data = await res.json();
    if (data.ok) {
      if (data.liked) {
        btn.classList.add('btn-action--liked');
        if (ico) ico.className = 'ph-fill ph-thumbs-up';
        if (txt) txt.textContent = 'Disukai';
      } else {
        btn.classList.remove('btn-action--liked');
        if (ico) ico.className = 'ph-bold ph-thumbs-up';
        if (txt) txt.textContent = 'Suka';
      }
      if (cnt && data.total_likes !== undefined) {
        cnt.textContent = Number(data.total_likes).toLocaleString('id-ID');
      }
      if (typeof nToast === 'function') {
        nToast(data.msg, 'success');
      }
    } else {
      if (typeof nToast === 'function') nToast(data.msg || 'Gagal menyukai video', 'error');
    }
  } catch(e) {
    if (typeof nToast === 'function') nToast('Kendala jaringan saat like video.', 'error');
  }
}

// ── Share Video ──────────────────────────────────────
function shareVideo() {
  const url = window.location.href;
  const title = <?= json_encode($video['title']) ?>;
  if (navigator.share) {
    navigator.share({ title: title, url: url }).catch(() => {});
  } else {
    navigator.clipboard.writeText(url).then(() => {
      if (typeof nToast === 'function') nToast('Tautan video berhasil disalin ke clipboard!', 'success');
    }).catch(() => {
      prompt('Salin tautan video berikut:', url);
    });
  }
}

// ── Reward Popup ─────────────────────────────────────
function showPop(msg) {
  const el = document.getElementById('reward-popup');
  if (!el) return;
  el.textContent = msg;
  el.classList.add('show');
  setTimeout(() => el.classList.remove('show'), 3000);
}
</script>
<script src="/assets/js/toast.js"></script>
</body>
</html>
