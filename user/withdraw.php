<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
$wd_lock_notice = setting($pdo, 'wd_lock_notice', 'Penarikan hanya bisa dilakukan pada jam tertentu.');
$wd_lock_start  = setting($pdo, 'wd_lock_start', '');
$wd_lock_end    = setting($pdo, 'wd_lock_end', '');

// Fetch membership min/max WD — only if membership is still active (not expired)
$user_mem = null;
$membership_active = $user['membership_id']
    && $user['membership_expires_at']
    && strtotime((string)$user['membership_expires_at']) > time();

if ($membership_active) {
    $stmt = $pdo->prepare("SELECT id, name, min_wd, max_wd, wd_hold, allow_edit_bank, is_wd_disabled FROM memberships WHERE id = ? AND is_active = 1");
    $stmt->execute([$user['membership_id']]);
    $user_mem = $stmt->fetch() ?: null;
}

// Fallback ke paket gratis jika tidak ada membership aktif
if (!$user_mem) {
    $stmt = $pdo->prepare("SELECT id, name, min_wd, max_wd, wd_hold, allow_edit_bank, is_wd_disabled FROM memberships WHERE price = 0 AND is_active = 1 ORDER BY sort_order ASC LIMIT 1");
    $stmt->execute();
    $user_mem = $stmt->fetch() ?: null;
}

$min_withdraw  = $user_mem ? (float)$user_mem['min_wd'] : 0;
$max_withdraw  = $user_mem ? (float)$user_mem['max_wd'] : 0;
$max_available = min((float)$user['balance_wd'], $max_withdraw > 0 ? $max_withdraw : (float)$user['balance_wd']);

$predefined_amounts = [10000,  50000, 100000, 150000, 250000, 500000, 1000000, 2500000, 5000000];

if ($min_withdraw > 0 && !in_array((int)$min_withdraw, $predefined_amounts, true)) {
    $predefined_amounts[] = (int)$min_withdraw;
}
if ($max_withdraw > 0 && !in_array((int)$max_withdraw, $predefined_amounts, true)) {
    $predefined_amounts[] = (int)$max_withdraw;
}

sort($predefined_amounts);

$has_bank = !empty($user['bank_name']) && !empty($user['account_number']) && !empty($user['account_name']);

$wd_locked = is_wd_locked($pdo);
$wd_global_enabled = setting($pdo, 'wd_global_enabled', '1') === '1';

// Level block — only enforced if admin enables the toggle
$wd_require_level = setting($pdo, 'wd_require_level', '0') === '1';
$wd_min_level  = (int) setting($pdo, 'wd_min_level', '0');
$user_level    = user_membership_level($pdo, $user);
$level_blocked = $wd_require_level && $wd_min_level > 0 && $user_level < $wd_min_level;

$available_amounts = [];
foreach ($predefined_amounts as $amt) {
    if ($level_blocked) {
        // Jika terblokir karena butuh upgrade, pamerkan opsi 50rb s/d 500rb
        if ($amt >= 50000 && $amt <= 500000) {
            $available_amounts[] = $amt;
        }
    } else {
        if ($amt >= $min_withdraw && ($max_withdraw == 0 || $amt <= $max_withdraw)) {
            $available_amounts[] = $amt;
        }
    }
}

$min_level_name = '';
if ($wd_require_level && $wd_min_level > 0) {
    $lv = $pdo->prepare("SELECT name FROM memberships WHERE sort_order=? AND is_active=1 LIMIT 1");
    $lv->execute([$wd_min_level]);
    $min_level_name = $lv->fetchColumn() ?: "Level {$wd_min_level}";
}

// Cek apakah ada WD pending
$pending_wd = $pdo->prepare("SELECT id FROM withdrawals WHERE user_id=? AND status IN ('pending', 'hold') LIMIT 1");
$pending_wd->execute([$user['id']]);
$has_pending_wd = (bool)$pending_wd->fetchColumn();

$is_free_level = !$membership_active;
$free_wd_limit_reached = false;
$free_wrong_bank = false;
$free_age_blocked = false;

if ($is_free_level) {
    $wd_free_only_dana = setting($pdo, 'wd_free_only_dana', '1') === '1';
    $wd_free_limit_1x  = setting($pdo, 'wd_free_limit_1x', '1') === '1';
    $wd_free_require_1day = setting($pdo, 'wd_free_require_1day', '1') === '1';
    
    if ($wd_free_require_1day && strtotime($user['created_at']) > strtotime('-1 day')) {
        $free_age_blocked = true;
    }
    
    if ($wd_free_only_dana && $has_bank && strtolower(trim($user['bank_name'])) !== 'dana') {
        $free_wrong_bank = true;
    }
    
    if ($wd_free_limit_1x) {
        $wd_cnt = $pdo->prepare("SELECT COUNT(*) FROM withdrawals WHERE user_id=? AND status='approved'");
        $wd_cnt->execute([$user['id']]);
        if ($wd_cnt->fetchColumn() >= 1) {
            $free_wd_limit_reached = true;
        }
    }
}

// ── Double-submit prevention ──────────────────────────────────────────────
$_ftk_wd = 'wd_form_token';
if (empty($_SESSION[$_ftk_wd])) $_SESSION[$_ftk_wd] = bin2hex(random_bytes(16));
$_form_token_wd = $_SESSION[$_ftk_wd];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_ftk_wd = $_POST['form_token'] ?? '';
    if (!hash_equals($_SESSION[$_ftk_wd] ?? '', $submitted_ftk_wd)) {
        $flash = '⚠️ Request kamu gagal diproses atau gak valid. Coba refresh halaman dulu ya!';
        $flashType = 'error';
    } elseif (!$user['can_withdraw']) {
        $flash = '❌ Akses withdraw kamu dibatasi nih. Hubungi admin yuk buat info lebih lanjut!'; $flashType = 'error';
    } elseif (!$wd_global_enabled) {
        $flash = '🔴 Fitur penarikan saat ini sedang dinonaktifkan secara global (Maintenance).'; $flashType = 'error';
    } elseif ($wd_locked) {
        $flash = '⏰ ' . $wd_lock_notice; $flashType = 'error';
    } elseif ($free_wd_limit_reached) {
        $flash = '❌ Level ' . $user_mem['name'] . ' maksimal WD 1 kali. Yuk upgrade level buat tarik dana sepuasnya!'; $flashType = 'error';
    } elseif ($free_wrong_bank) {
        $flash = '❌ Level ' . $user_mem['name'] . ' hanya bisa menarik ke e-wallet DANA. Silakan ganti rekening Anda!'; $flashType = 'error';
    } elseif ($has_pending_wd) {
        $flash = '⏳ Kamu masih punya request WD yang lagi diproses nih. Tunggu kelar dulu ya!'; $flashType = 'error';
    } elseif (!empty($user_mem['is_wd_disabled'])) {
        $flash = '🔴 Penarikan untuk level Anda saat ini sedang ditutup (Maintenance). Silakan upgrade level Anda!'; $flashType = 'error';
    } elseif ($level_blocked) {
        if ((float)$user['balance_wd'] < 50000) {
            $flash = 'Minimal withdraw Rp 50.000 ya.'; $flashType = 'error';
        } else {
            $flash = "Upgrade ke {$min_level_name} dulu yuk biar bisa tarik saldo!"; $flashType = 'error';
        }
    } elseif ($free_age_blocked) {
        $flash = 'Akun harus berumur min. 1 hari untuk WD (Level ' . $user_mem['name'] . ').'; $flashType = 'error';
    } else {
        $amount  = (float) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
        
        $bank    = $has_bank ? $user['bank_name'] : trim($_POST['bank_name'] ?? '');
        $accnum  = $has_bank ? $user['account_number'] : trim($_POST['account_number'] ?? '');
        $accname = $has_bank ? $user['account_name'] : trim($_POST['account_name'] ?? '');

        if (!in_array((int)$amount, $available_amounts, true)) {
            $flash = 'Nominal penarikan gak valid nih. Harus pilih dari daftar ya!'; $flashType = 'error';
        } elseif ($is_free_level && setting($pdo, 'wd_free_only_dana', '1') === '1' && strtolower($bank) !== 'dana') {
            $flash = 'Level ' . $user_mem['name'] . ' hanya bisa menggunakan e-wallet DANA.'; $flashType = 'error';
        } elseif ($amount < $min_withdraw) {
            $flash = 'Minimal withdraw ' . format_rp($min_withdraw) . ' ya.'; $flashType = 'error';
        } elseif ($max_withdraw > 0 && $amount > $max_withdraw) {
            $flash = 'Maksimal withdraw ' . format_rp($max_withdraw) . ' ya.'; $flashType = 'error';
        } elseif ($amount > (float)$user['balance_wd']) {
            $flash = 'Saldo penarikan kamu gak cukup nih.'; $flashType = 'error';
        } elseif (!$bank || !$accnum || !$accname) {
            $flash = 'Lengkapi dulu data rekeningmu ya.'; $flashType = 'error';
        } else {
            $pdo->beginTransaction();
            if (!$has_bank) {
                $pdo->prepare("UPDATE users SET bank_name=?, account_number=?, account_name=? WHERE id=?")->execute([$bank, $accnum, $accname, $user['id']]);
                $has_bank = true;
                $user['bank_name'] = $bank;
                $user['account_number'] = $accnum;
                $user['account_name'] = $accname;
            }
            $is_auto_hold = (isset($user_mem['wd_hold']) && $user_mem['wd_hold'] == 1);
            $wd_status = $is_auto_hold ? 'hold' : 'pending';
            $admin_note = null;
            
            $pdo->prepare("UPDATE users SET balance_wd=balance_wd-? WHERE id=?")->execute([$amount, $user['id']]);
            $pdo->prepare("INSERT INTO withdrawals (user_id,amount,bank_name,account_number,account_name,status,admin_note) VALUES (?,?,?,?,?,?,?)")
                ->execute([$user['id'], $amount, $bank, $accnum, $accname, $wd_status, $admin_note]);
            $wd_id = $pdo->lastInsertId();
            $pdo->commit();
            $us = $pdo->prepare("SELECT * FROM users WHERE id=?"); $us->execute([$user['id']]); $user = $us->fetch();
            
            $levelInfo = $user_mem ? ($user_mem['name'] ?? 'Free') : 'Free';
            $wdHoldNote = $is_auto_hold ? ' ⏳ (Auto Hold Scheduled)' : '';
            $msg = "<b>💸 WITHDRAW BARU</b>\n👤 User: {$user['username']}\n🏅 Level: {$levelInfo}{$wdHoldNote}\n💰 Amount: " . format_rp((float)$amount) . "\n🏦 Bank: {$bank} - {$accnum}\n👨‍💼 a/n: {$accname}\n📋 Status: " . ucfirst($wd_status);
            $kb = [
                [['text'=>'✅ Approve', 'callback_data'=>'wd_approve_'.$wd_id], ['text'=>'❌ Reject', 'callback_data'=>'wd_reject_'.$wd_id]],
                [['text'=>'⏸ Hold (Selesai non-refund)', 'callback_data'=>'wd_hold_'.$wd_id]],
                [['text'=>'🔄 Refresh Status', 'callback_data'=>'refresh_wd_'.$wd_id]]
            ];
            tg_store_msg_id($pdo, 'withdrawals', (int)$wd_id, send_telegram_notif($pdo, $msg, $kb, 'wd'));
            
            // Regenerate token
            $_SESSION[$_ftk_wd] = bin2hex(random_bytes(16));
            $flash = '✅ Request withdraw berhasil dikirim! Proses 1-10 menit ya.';
            $flashType = 'success';
        }
    }
    
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        if ($flashType === 'error' || $flash === '') {
            echo json_encode(['error' => $flash ?: 'Terjadi kesalahan.']);
        } else {
            echo json_encode(['ok' => true, 'message' => $flash]);
        }
        exit;
    }
}
end_wd:

$wds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 6");
$wds->execute([$user['id']]);
$wds = $wds->fetchAll();

$channels = $pdo->query("SELECT name, logo FROM payment_channels WHERE logo IS NOT NULL AND logo != ''")->fetchAll();
$channel_logos = [];
foreach ($channels as $c) {
    $channel_logos[strtolower($c['name'])] = $c['logo'];
}

$stmtPendingBank = $pdo->prepare("SELECT id FROM admin_requests WHERE user_id=? AND type='change_bank' AND status='pending'");
$stmtPendingBank->execute([$user['id']]);
$has_pending_bank = (bool)$stmtPendingBank->fetchColumn();

$wd_estimation = '';
if (!$wd_global_enabled) {
    $wd_estimation = "🔴 Penarikan saat ini <strong>DINONAKTIFKAN</strong> (Maintenance).";
} elseif ($wd_lock_start && $wd_lock_end) {
    $now_ts = time();
    $s_ts = strtotime(date('Y-m-d ') . $wd_lock_start);
    $e_ts = strtotime(date('Y-m-d ') . $wd_lock_end);
    
    if ($wd_locked) {
        if ($e_ts <= $now_ts) $e_ts += 86400;
        $diff = $e_ts - $now_ts;
        $h = floor($diff / 3600);
        $m = floor(($diff % 3600) / 60);
        $wd_estimation = "Penarikan saat ini <strong>DITUTUP</strong>. Akan dibuka dalam <strong>{$h} jam {$m} menit</strong>.";
    } else {
        $wd_estimation = "✅ Penarikan Buka (Tutup Jam " . date('H:i', strtotime($wd_lock_start)) . ")";
    }
}

$pageTitle  = 'Withdraw';
$activePage = 'withdraw';
require dirname(__DIR__) . '/partials/header.php';

// Label nominal ringkas: 50rb, 1jt, 2,5jt
$wd_short = static function (float $v): string {
    if ($v >= 1000000) return rtrim(rtrim(number_format($v / 1000000, 1, ',', ''), '0'), ',') . 'jt';
    return number_format($v / 1000, 0, ',', '.') . 'rb';
};

// Status tombol utama: [teks, aktif?]
$wd_btn = ['Tarik Sekarang', true];
if ($free_wd_limit_reached)                         $wd_btn = ['Batas WD tercapai', false];
elseif ($free_wrong_bank)                           $wd_btn = ['Hanya DANA untuk ' . $user_mem['name'], false];
elseif ($wd_locked)                                 $wd_btn = ['Penarikan sedang ditutup', false];
elseif ($level_blocked)                             $wd_btn = [(float)$user['balance_wd'] < 50000 ? 'Minimal tarik Rp 50.000' : 'Butuh upgrade level', false];
elseif ($free_age_blocked)                          $wd_btn = ['Akun minimal berumur 1 hari', false];
elseif (!$user['can_withdraw'])                     $wd_btn = ['Akses penarikan dibatasi', false];
elseif ($has_pending_bank)                          $wd_btn = ['Rekening sedang diverifikasi', false];
elseif ($has_pending_wd)                            $wd_btn = ['Ada penarikan diproses', false];
elseif ((float)$user['balance_wd'] < $min_withdraw) $wd_btn = ['Saldo belum cukup', false];

// Satu notifikasi teratas (urutan sama seperti sebelumnya)
$wd_note = null;
if ($wd_estimation && $wd_locked) {
    $wd_note = ['err', 'ph-lock-key', strip_tags($wd_estimation)];
} elseif ($has_pending_wd) {
    $wd_note = ['warn', 'ph-hourglass-medium', 'Penarikanmu sedang diproses kasir.'];
} elseif (!empty($wds) && strtolower($wds[0]['status']) === 'rejected' && strtotime($wds[0]['created_at']) > strtotime('-2 days')) {
    $wd_note = ['err', 'ph-warning-circle', 'Penarikan terakhir ditolak, saldo dikembalikan. Alasan: ' . ($wds[0]['admin_note'] ?: 'Rekening tidak valid')];
} elseif ($flash) {
    $wd_note = [$flashType === 'error' ? 'err' : 'ok', $flashType === 'error' ? 'ph-x-circle' : 'ph-check-circle', $flash];
}

$wd_status_map = [
    'pending'   => ['Diproses', 'warn'],
    'approved'  => ['Sukses', 'ok'],
    'confirmed' => ['Sukses', 'ok'],
    'rejected'  => ['Ditolak', 'err'],
    'hold'      => ['Ditahan', 'mute'],
    'refunded'  => ['Kembali', 'info'],
];
$user_wl = $has_bank ? ($channel_logos[strtolower($user['bank_name'] ?? '')] ?? null) : null;

// Status antrian WD untuk level user ini saja (Lancar / Ramai / Withdraw Overload)
require_once dirname(__DIR__) . '/lib/wd_levels.php';
$wd_lv = $user_mem ? wd_level_status($pdo, (int)$user_mem['id']) : null;
?>

<style>
/* ══ WITHDRAW — Kirim Panen: meteran saldo + penggeser · Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.wx-top { display: flex; align-items: center; gap: 10px; }
.wx-top .t { flex: 1; min-width: 0; }
.wx-top h1 { margin: 0; font-family: var(--pc-font); font-size: 22px; font-weight: 700; line-height: 1.1; color: #4a2b00; }
.wx-top p { margin: 1px 0 0; font-size: 11.5px; font-weight: 800; color: var(--pc-muted); }
.wx-top .g-chip { background: #fff3c4; color: #6b4300; }

.wx-note { display: flex; align-items: flex-start; gap: 8px; padding: 9px 12px; border-radius: 16px; font-size: 12px; font-weight: 800; line-height: 1.4; }
.wx-note i { font-size: 17px; flex-shrink: 0; }
.wx-note.err  { background: #ffe4e6; color: #9f1239; box-shadow: 0 0 0 2px #fecdd3; }
.wx-note.warn { background: #fff3c4; color: #8a5a00; box-shadow: 0 0 0 2px #fcd34d; }
.wx-note.ok   { background: #dcfce7; color: #15803d; box-shadow: 0 0 0 2px #86efac; }

/* ── Meteran saldo ── */
.wx-meter { position: relative; overflow: hidden; padding: 14px 14px 12px; border-radius: 26px; text-align: center;
  background: radial-gradient(90% 60% at 50% 115%, #4f9f3a 0 40%, transparent 41%), radial-gradient(70% 45% at 8% 112%, #7cc95a 0 40%, transparent 41%), linear-gradient(180deg, #ffb46b 0%, #ffd59a 45%, #fff0cf 80%);
  box-shadow: 0 16px 30px rgba(120, 70, 10, .18); }
.wx-gauge { position: relative; width: 230px; max-width: 100%; margin: 0 auto; }
.wx-gauge svg { display: block; width: 100%; height: auto; overflow: visible; }
.wx-gauge .trk { fill: none; stroke: rgba(255, 255, 255, .7); stroke-width: 16; stroke-linecap: round; }
.wx-gauge .val { fill: none; stroke: url(#wxGrad); stroke-width: 16; stroke-linecap: round; stroke-dasharray: 0 999; transition: stroke-dasharray 1.4s var(--pc-ease) .2s; filter: drop-shadow(0 2px 4px rgba(120, 70, 10, .35)); }
.wx-gauge .tick { stroke: #4a2b00; stroke-width: 3; stroke-linecap: round; }
.wx-gauge-c { position: absolute; left: 0; right: 0; bottom: 2px; }
.wx-gauge-c img { width: 50px; height: 50px; object-fit: contain; animation: gBob 3s ease-in-out infinite; filter: drop-shadow(0 6px 6px rgba(120, 70, 10, .3)); }
.wx-gauge-c small { display: block; font-size: 11px; font-weight: 900; color: #7a4f00; }
.wx-gauge-c b { display: block; font-family: var(--pc-font); font-size: 28px; font-weight: 700; line-height: 1.05; color: #15803d; text-shadow: 0 2px 0 #fff; }
.wx-ends { display: flex; justify-content: space-between; width: 250px; max-width: 100%; margin: 4px auto 0; font-size: 10.5px; font-weight: 900; color: #4a2b00; }
.wx-ends span { padding: 1px 8px; border-radius: 999px; background: rgba(255, 255, 255, .75); }
.wx-mstat { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; margin-top: 10px; }
.wx-mstat div { min-width: 0; padding: 6px 4px; border-radius: 13px; background: rgba(255, 255, 255, .82); }
.wx-mstat small { display: block; font-size: 9.5px; font-weight: 900; color: #7a4f00; }
.wx-mstat b { display: block; font-size: 12px; font-weight: 900; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Status antrian */
.wx-lvl { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 18px; background: #fff; box-shadow: 0 0 0 2px var(--lb), 0 3px 0 var(--ll); }
.wx-lvl.s-normal   { --lc: #15803d; --lb: #bbf7d0; --ll: #86efac; }
.wx-lvl.s-busy     { --lc: #b45309; --lb: #fde68a; --ll: #fcd34d; }
.wx-lvl.s-overload { --lc: #be123c; --lb: #fecdd3; --ll: #fda4af; }
.wx-lvl .g-slot { width: 40px; height: 40px; }
.wx-lvl.s-overload .g-slot { animation: gGlow 1.6s ease-in-out infinite; }
.wx-lvl-tx { flex: 1; min-width: 0; }
.wx-lvl-tx b { font-family: var(--pc-font); font-size: 14.5px; font-weight: 700; color: var(--lc); }
.wx-lvl-tx p { margin: 1px 0 0; font-size: 11px; font-weight: 700; color: var(--pc-ink); line-height: 1.35; }
.wx-lvl-eta { flex-shrink: 0; padding: 3px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 900; color: var(--lc); background: color-mix(in srgb, var(--lc) 12%, #fff); }

/* ── Pilih nominal: penggeser ── */
.wx-pick { text-align: center; }
.wx-pick small { display: block; font-size: 11px; font-weight: 900; color: var(--pc-muted); }
.wx-pick-amt { font-family: var(--pc-font); font-size: 34px; font-weight: 700; line-height: 1.1; color: #15803d; transition: transform .2s var(--pc-ease); }
.wx-pick-amt.pop { transform: scale(1.08); }
.wx-pick-amt.none { color: #c9b48a; }
.wx-left { margin-top: 1px; font-size: 11.5px; font-weight: 800; color: var(--pc-muted); }
.wx-left b { color: var(--pc-ink); }
.wx-slider { position: relative; margin: 12px 4px 4px; }
.wx-range { -webkit-appearance: none; appearance: none; width: 100%; height: 34px; margin: 0; background: transparent; cursor: pointer; }
.wx-range:disabled { cursor: not-allowed; opacity: .5; }
.wx-range::-webkit-slider-runnable-track { height: 14px; border-radius: 999px; background: linear-gradient(90deg, #22c55e var(--p, 0%), #f2e6c8 var(--p, 0%)); box-shadow: inset 0 2px 3px rgba(120, 70, 10, .2); }
.wx-range::-moz-range-track { height: 14px; border-radius: 999px; background: linear-gradient(90deg, #22c55e var(--p, 0%), #f2e6c8 var(--p, 0%)); }
.wx-range::-webkit-slider-thumb { -webkit-appearance: none; width: 34px; height: 34px; margin-top: -10px; border: 0; border-radius: 50%;
  background: url(/assets/img/icons3d/coins.png) center / 82% no-repeat, radial-gradient(circle at 40% 35%, #fff7c2, #ffd54f 60%, #f6b70a); box-shadow: 0 0 0 3px #fff, 0 3px 0 #b07800, 0 6px 12px rgba(120, 70, 10, .3); }
.wx-range::-moz-range-thumb { width: 34px; height: 34px; border: 0; border-radius: 50%; background: radial-gradient(circle at 40% 35%, #fff7c2, #ffd54f 60%, #f6b70a); box-shadow: 0 0 0 3px #fff, 0 3px 0 #b07800; }
.wx-amts { display: flex; gap: 6px; overflow-x: auto; margin: 6px -12px 0; padding: 4px 12px 6px; scrollbar-width: none; }
.wx-amts::-webkit-scrollbar { display: none; }
.wx-amt { flex: 0 0 auto; min-height: 32px; padding: 0 11px; border: 0; border-radius: 10px; cursor: pointer; font-family: var(--pc-font); font-size: 13.5px; font-weight: 600; color: #6b4300; background: #fff; box-shadow: inset 0 0 0 2px #f1dfb4, 0 3px 0 #ecd59c; transition: transform .12s; }
.wx-amt:active { transform: translateY(2px); }
.wx-amt.active { color: #fff; text-shadow: 0 1px 0 rgba(0, 0, 0, .2); background: linear-gradient(180deg, #22c55e, #15803d); box-shadow: 0 3px 0 #11622f; }
.wx-amt:disabled { opacity: .4; cursor: not-allowed; text-decoration: line-through; }
.wx-empty { padding: 10px; border-radius: 12px; background: #fff3c4; color: #8a5a00; font-size: 12px; font-weight: 800; text-align: center; }

/* ── Label kiriman ── */
.wx-label { position: relative; padding: 12px 12px 11px; border-radius: 18px; background: repeating-linear-gradient(135deg, #fff 0 10px, #fffaf0 10px 20px); box-shadow: 0 0 0 2px #e6c886, 0 4px 0 #d9b25a; }
.wx-label::before { content: ''; position: absolute; inset: 6px; border-radius: 13px; border: 2px dashed #e6c886; pointer-events: none; }
.wx-label-h { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 8px; padding: 0 4px; }
.wx-label-h span { font-size: 10.5px; font-weight: 900; letter-spacing: 1px; color: #a06c00; }
.wx-stamp { padding: 1px 8px; border: 2px solid #16a34a; border-radius: 7px; font-family: var(--pc-font); font-size: 11.5px; font-weight: 700; color: #16a34a; transform: rotate(-6deg); }
.wx-dest { position: relative; display: flex; align-items: center; gap: 10px; padding: 0 4px; text-decoration: none; color: inherit; }
.wx-dest .g-slot img { width: 70%; height: 70%; }
.wx-dest-tx { flex: 1; min-width: 0; line-height: 1.25; }
.wx-dest-tx b { display: block; font-family: var(--pc-font); font-size: 16px; font-weight: 700; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wx-dest-tx span { display: block; font-size: 12px; font-weight: 800; color: var(--pc-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wx-bankform { position: relative; display: grid; gap: 6px; padding: 0 4px; }
.wx-bankform label { margin: 0 0 -2px 2px; font-size: 11px; font-weight: 900; color: var(--pc-muted); }
.wx-bankform input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 0; border-radius: 13px; outline: none; font: 700 14px var(--pc-font); color: var(--pc-ink); background: #fff; box-shadow: inset 0 0 0 2px #f6e2b0; }
.wx-bankform input:focus { box-shadow: inset 0 0 0 2px #22c55e, 0 0 0 4px rgba(34, 197, 94, .15); }
.wx-bankform input[readonly] { background: #f6f8f2; }

.wx-go.g-btn { min-height: 54px; font-size: 17px; border-radius: 17px; }
.wx-go:disabled { --c1: #e5e7eb; --c2: #d1d5db; --lip: #9ca3af; --sh: transparent; --tx: #6b7280; text-shadow: none; filter: none; opacity: 1; }

/* Jejak kiriman */
.wx-hist { display: flex; flex-direction: column; margin-top: 8px; }
.wx-row { position: relative; display: grid; grid-template-columns: 14px 1fr auto; column-gap: 10px; align-items: center; padding: 7px 0; }
.wx-row + .wx-row { border-top: 1.5px dashed #f1dfb4; }
.wx-row i.dot { width: 12px; height: 12px; border-radius: 50%; background: var(--c); box-shadow: 0 0 0 3px color-mix(in srgb, var(--c) 20%, #fff); }
.wx-row b { font-family: var(--pc-font); font-size: 15px; font-weight: 700; color: var(--pc-ink); }
.wx-row small { display: block; font-size: 10.5px; font-weight: 800; color: var(--pc-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wx-st { font-size: 11px; font-weight: 900; padding: 3px 9px; border-radius: 999px; color: var(--c); background: color-mix(in srgb, var(--c) 14%, #fff); }
.wx-row p { grid-column: 2 / -1; margin: 3px 0 0; font-size: 11px; font-weight: 700; color: var(--pc-muted); line-height: 1.35; }
.t-ok { --c: #15803d; } .t-warn { --c: #b45309; } .t-err { --c: #e11d48; } .t-mute { --c: #64748b; } .t-info { --c: #0284c7; }
.wx-row.t-err p { color: #be123c; }

/* Konfirmasi (bottom sheet) */
.cg-modal { display: none; position: fixed; inset: 0; z-index: 99999; background: rgba(27, 42, 30, .55); backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px); align-items: flex-end; justify-content: center; }
.cg-modal-box { position: relative; overflow: hidden; width: 100%; max-width: 440px; padding: 8px 16px calc(18px + env(safe-area-inset-bottom)); border-radius: 28px 28px 0 0; text-align: center;
  background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 0 0 3px #f6e2b0, 0 -16px 40px rgba(0, 0, 0, .25); animation: wxUp .4s var(--pc-ease); }
@keyframes wxUp { from { transform: translateY(100%); } }
.cg-grab { width: 44px; height: 5px; border-radius: 999px; background: #e6d6ad; margin: 0 auto 6px; }
.cg-art { display: block; width: 84px; height: 84px; margin: 0 auto; object-fit: contain; filter: drop-shadow(0 8px 10px rgba(120, 70, 10, .3)); animation: gBob 2.4s ease-in-out infinite; }
.cg-sum small { font-size: 12px; font-weight: 800; color: var(--pc-muted); }
.cg-sum b { display: block; margin: 0 0 10px; font-family: var(--pc-font); font-size: 32px; font-weight: 700; color: #15803d; text-shadow: 0 3px 0 #dcfce7; }
.cg-to { display: flex; justify-content: space-between; gap: 8px; padding: 10px 12px; border-radius: 14px; background: #fff; box-shadow: inset 0 0 0 2px #f6e2b0; font-size: 12px; font-weight: 800; color: var(--pc-muted); }
.cg-to span:last-child { color: var(--pc-ink); text-align: right; }
.cg-warn { padding: 8px 10px; border-radius: 12px; background: #ffe4e6; color: #9f1239; font-size: 11.5px; font-weight: 800; }
.cg-modal-actions { display: grid; grid-template-columns: 1fr 1.6fr; gap: 8px; margin-top: 14px; }
@media (max-width: 350px) { .wx-gauge-c b { font-size: 23px; } .wx-pick-amt { font-size: 29px; } }
</style>

<?php
// Meteran: saldo dibanding batas maks (atau nominal terbesar)
$wx_cap = $max_withdraw > 0 ? $max_withdraw : (float)max($available_amounts ?: [max(1.0, (float)$user['balance_wd'])]);
$wx_cap = max(1.0, $wx_cap);
$wx_frac = min(1, (float)$user['balance_wd'] / $wx_cap);
$wx_minf = min(1, $min_withdraw / $wx_cap);
$wx_arc = 282.7; // panjang busur setengah lingkaran r=90
$wx_mx = 110 - 90 * cos(M_PI * $wx_minf); $wx_my = 106 - 90 * sin(M_PI * $wx_minf);
?>
<div class="g-page">

  <div class="wx-top" data-rv>
    <div class="t"><h1>Kirim Panen</h1><p><i class="ph-fill ph-lightning"></i> Cair 1–10 menit ke rekeningmu</p></div>
    <?php if ($user_mem): ?><span class="g-chip"><i class="ph-fill ph-crown-simple"></i><?= htmlspecialchars($user_mem['name']) ?></span><?php endif; ?>
  </div>

  <section class="wx-meter" data-rv style="--d:.04s">
    <div class="wx-gauge">
      <svg viewBox="0 0 220 116" aria-hidden="true">
        <defs><linearGradient id="wxGrad" x1="0" x2="1"><stop offset="0" stop-color="#4ade80"/><stop offset=".6" stop-color="#16a34a"/><stop offset="1" stop-color="#f6b70a"/></linearGradient></defs>
        <path class="trk" d="M20 106 A90 90 0 0 1 200 106"/>
        <path class="val" id="wx-arc" d="M20 106 A90 90 0 0 1 200 106" data-len="<?= round($wx_arc * $wx_frac, 1) ?>"/>
        <?php if ($min_withdraw > 0 && $wx_minf < 1): ?>
          <line class="tick" x1="<?= round(110 - 76 * cos(M_PI * $wx_minf), 1) ?>" y1="<?= round(106 - 76 * sin(M_PI * $wx_minf), 1) ?>" x2="<?= round($wx_mx + ($wx_mx - 110) * .12, 1) ?>" y2="<?= round($wx_my + ($wx_my - 106) * .12, 1) ?>"/>
        <?php endif; ?>
      </svg>
      <div class="wx-gauge-c">
        <img src="/assets/img/icons3d/moneybag.png" alt="">
        <small>Saldo siap tarik</small>
        <b>Rp <?= number_format((float)$user['balance_wd'], 0, ',', '.') ?></b>
      </div>
    </div>
    <div class="wx-ends"><span>Rp 0</span><span><?= $max_withdraw > 0 ? 'Maks ' : '' ?><?= $wd_short($wx_cap) ?></span></div>
    <div class="wx-mstat">
      <div><small>Minimal</small><b><?= format_rp($min_withdraw) ?></b></div>
      <div><small>Maksimal</small><b><?= $max_withdraw > 0 ? format_rp($max_withdraw) : 'Bebas' ?></b></div>
      <div><small>Proses</small><b><?= $wd_lv ? htmlspecialchars($wd_lv['eta']) : '1–10 mnt' ?></b></div>
    </div>
  </section>

  <?php if ($wd_note): ?>
    <div class="wx-note <?= $wd_note[0] ?>" data-rv><i class="ph-fill <?= $wd_note[1] ?>"></i><span><?= htmlspecialchars($wd_note[2]) ?></span></div>
  <?php endif; ?>

  <?php if ($wd_lv && $wd_lv['show']): ?>
    <div class="wx-lvl s-<?= $wd_lv['state'] ?>" data-rv>
      <span class="g-slot"><img src="/assets/img/icons3d/<?= $wd_lv['state'] === 'normal' ? 'speed' : 'bell' ?>.png" alt=""></span>
      <div class="wx-lvl-tx"><b><?= htmlspecialchars($wd_lv['label']) ?></b><p><?= htmlspecialchars($wd_lv['message']) ?></p></div>
      <span class="wx-lvl-eta"><?= htmlspecialchars($wd_lv['eta']) ?></span>
    </div>
  <?php endif; ?>

  <form method="POST" id="wd-form" style="display:flex;flex-direction:column;gap:12px;">
    <?= csrf_field() ?>
    <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token_wd) ?>">
    <input type="hidden" name="amount" id="selected-amount" value="" required>

    <section class="g-panel" data-rv style="--d:.06s">
      <div class="wx-pick">
        <small>Mau tarik berapa?</small>
        <div class="wx-pick-amt none" id="wx-pick-amt">Rp 0</div>
        <div class="wx-left">Sisa saldo <b id="wx-left">Rp <?= number_format((float)$user['balance_wd'], 0, ',', '.') ?></b></div>
      </div>
      <?php if ($available_amounts): ?>
        <div class="wx-slider"><input type="range" class="wx-range" id="wx-range" min="0" max="<?= count($available_amounts) - 1 ?>" step="1" value="0" aria-label="Geser nominal withdraw"></div>
      <?php endif; ?>
      <div class="wx-amts">
        <?php foreach ($available_amounts as $amt): ?>
          <button type="button" class="wd-amt-btn wx-amt" data-value="<?= $amt ?>" title="<?= format_rp($amt) ?>"
            onclick="selectWdAmount(this, <?= $amt ?>)" <?= !$level_blocked && $amt > (float)$user['balance_wd'] ? 'disabled' : '' ?>><?= $wd_short((float)$amt) ?></button>
        <?php endforeach; ?>
      </div>
      <?php if (!$available_amounts): ?><div class="wx-empty">Belum ada nominal yang tersedia.</div><?php endif; ?>
    </section>

    <section class="wx-label" data-rv style="--d:.1s">
      <div class="wx-label-h"><span>LABEL KIRIMAN</span><span class="wx-stamp">TUJUAN</span></div>
      <?php if ($has_bank): ?>
        <a href="/edit-rekening" class="wx-dest" title="Ganti rekening">
          <span class="g-slot">
            <?php if ($user_wl): ?><img src="/assets/banks/<?= htmlspecialchars($user_wl) ?>" alt="<?= htmlspecialchars($user['bank_name']) ?>"><?php else: ?><img src="/assets/img/icons3d/piggy.png" alt=""><?php endif; ?>
          </span>
          <span class="wx-dest-tx">
            <b><?= htmlspecialchars($user['bank_name']) ?> · <?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?></b>
            <span>a.n. <?= htmlspecialchars($user['account_name'] ?? '') ?></span>
          </span>
          <span class="g-btn soft sm">Ubah</span>
        </a>
      <?php else: ?>
        <div class="wx-bankform">
          <label>Bank / E-Wallet</label>
          <input type="text" name="bank_name" value="<?= $is_free_level ? 'DANA' : '' ?>" <?= $is_free_level ? 'readonly' : 'placeholder="BCA, DANA, OVO, BRI..." required' ?>>
          <label>No. Rekening / HP</label>
          <input type="text" name="account_number" placeholder="081234567890" inputmode="numeric" required>
          <label>Nama Pemilik</label>
          <input type="text" name="account_name" placeholder="Sesuai rekening" required>
        </div>
      <?php endif; ?>
    </section>

    <div data-rv style="--d:.14s;display:flex;flex-direction:column;gap:10px">
      <?php if ($wd_btn[1]): ?>
        <button type="submit" id="wd-submit-btn" class="wx-go g-btn gold full shine"><img src="/assets/img/icons3d/moneybag.png" alt=""><span>Kirim ke rekening</span></button>
      <?php else: ?>
        <button type="button" class="wx-go g-btn full" disabled><i class="ph-fill ph-lock-simple"></i><?= htmlspecialchars($wd_btn[0]) ?></button>
        <?php if ($free_wd_limit_reached || ($wd_btn[0] === 'Butuh upgrade level')): ?>
          <a href="/upgrade" class="g-btn gold full shine"><img src="/assets/img/icons3d/upgrade.png" alt="">Level Up untuk buka withdraw</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </form>

  <?php if (!empty($wds)): ?>
    <section class="g-panel" data-rv style="--d:.18s">
      <div class="g-sec"><span class="g-ribbon"><img src="/assets/img/icons3d/receipt.png" alt="">Jejak Kiriman</span><a href="/history?tab=withdraw" class="g-chip" style="text-decoration:none">Semua ›</a></div>
      <div class="wx-hist">
        <?php foreach ($wds as $w):
          $st = strtolower((string)$w['status']);
          [$stLabel, $tone] = $wd_status_map[$st] ?? [ucfirst($st), 'mute']; ?>
          <div class="wx-row t-<?= $tone ?>">
            <i class="dot"></i>
            <div style="min-width:0"><b><?= format_rp((float)$w['amount']) ?></b><small><?= htmlspecialchars($w['bank_name']) ?> · <?= date('d M, H:i', strtotime($w['created_at'])) ?></small></div>
            <span class="wx-st"><?= $stLabel ?></span>
            <?php if (!empty($w['admin_note'])): ?><p><?= htmlspecialchars((string)$w['admin_note']) ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

</div>

<div id="cg-modal" class="cg-modal" onclick="if(event.target===this)this.style.display='none'">
  <div class="cg-modal-box">
    <div class="cg-grab"></div>
    <img class="cg-art" src="/assets/img/icons3d/moneybag.png" alt="">
    <div class="cg-sum"><small>Kamu akan menarik</small><b id="cg-modal-amt"></b></div>
    <?php if ($has_bank): ?>
      <div class="cg-to"><span>Ke</span><span><?= htmlspecialchars($user['bank_name']) ?> · <?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?></span></div>
    <?php else: ?>
      <div class="cg-warn">Pastikan nomor &amp; nama pemilik rekening sudah benar.</div>
    <?php endif; ?>
    <div class="cg-modal-actions">
      <button type="button" class="g-btn soft" onclick="document.getElementById('cg-modal').style.display='none'">Batal</button>
      <button type="button" class="g-btn gold shine" onclick="confirmCGWd()">Ya, Tarik</button>
    </div>
  </div>
</div>

<script>
/* Meteran & penggeser nominal (memakai tombol .wd-amt-btn yang sama) */
document.addEventListener('DOMContentLoaded', function () {
  const arc = document.getElementById('wx-arc');
  if (arc) requestAnimationFrame(() => setTimeout(() => { arc.style.strokeDasharray = arc.dataset.len + ' 999'; }, 60));
  const range = document.getElementById('wx-range');
  const btns = [...document.querySelectorAll('.wd-amt-btn')];
  const out = document.getElementById('wx-pick-amt'), left = document.getElementById('wx-left');
  const BAL = <?= (float)$user['balance_wd'] ?>;
  const fmt = (n) => 'Rp ' + Math.round(n).toLocaleString('id-ID');
  const paint = (i) => { if (range) range.style.setProperty('--p', (btns.length > 1 ? i / (btns.length - 1) * 100 : 100) + '%'); };
  window.wxShow = (val, i) => {
    out.textContent = fmt(val); out.classList.toggle('none', !val);
    out.classList.remove('pop'); void out.offsetWidth; out.classList.add('pop');
    left.textContent = fmt(Math.max(0, BAL - val));
    if (range && i >= 0) { range.value = i; paint(i); }
    btns[i]?.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
  };
  btns.forEach((b, i) => b.addEventListener('click', () => { if (!b.disabled) wxShow(+b.dataset.value, i); }));
  const enabled = btns.map((b, i) => b.disabled ? -1 : i).filter((i) => i >= 0);
  if (range) {
    if (!enabled.length) range.disabled = true;
    range.addEventListener('input', () => {
      let i = +range.value;
      const ok = enabled.filter((k) => k <= i);
      i = ok.length ? ok[ok.length - 1] : (enabled[0] ?? -1);
      if (i < 0) return;
      range.value = i; paint(i);
      selectWdAmount(btns[i], +btns[i].dataset.value);
      wxShow(+btns[i].dataset.value, i);
    });
    // Mulai dari nominal terkecil yang bisa ditarik
    if (enabled.length) { selectWdAmount(btns[enabled[0]], +btns[enabled[0]].dataset.value); wxShow(+btns[enabled[0]].dataset.value, enabled[0]); }
    else paint(0);
  }
});
</script>

<script>
function selectWdAmount(btn, val) {
  document.querySelectorAll('.wd-amt-btn').forEach(el => el.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('selected-amount').value = val;
}

(function(){
  const form  = document.getElementById('wd-form');
  const btn   = document.getElementById('wd-submit-btn');
  const minWd = <?= (int)$min_withdraw ?>;
  const maxWd = <?= (float)$max_available ?>;
  const label = '<img src="/assets/img/icons3d/moneybag.png" alt=""><span>Kirim ke rekening</span>';

  if (!form) return;

  form.addEventListener('submit', function(e) {
    const amtInput = document.querySelector('[name=amount]');
    const amt = amtInput ? parseFloat(amtInput.value) : 0;

    if (!amt || isNaN(amt)) {
      e.preventDefault();
      if(typeof nToast !== 'undefined') nToast('Pilih nominal penarikan dulu ya!', 'warn');
      return;
    }
    if (amt < minWd) {
      e.preventDefault();
      if(typeof nToast !== 'undefined') nToast('Minimal penarikan Rp ' + minWd.toLocaleString('id-ID'), 'error');
      return;
    }
    if (amt > maxWd) {
      e.preventDefault();
      if(typeof nToast !== 'undefined') nToast('Maksimal penarikan Rp ' + maxWd.toLocaleString('id-ID'), 'error');
      return;
    }

    if (!form.dataset.confirmed) {
      e.preventDefault();
      document.getElementById('cg-modal-amt').innerText = 'Rp ' + amt.toLocaleString('id-ID');
      document.getElementById('cg-modal').style.display = 'flex';
    }
  });

  window.confirmCGWd = function() {
    document.getElementById('cg-modal').style.display = 'none';
    if (btn) { btn.disabled = true; btn.innerText = 'Memproses...'; }

    const fd = new FormData(form);
    fd.append('ajax', '1');
    fetch('', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.error) {
        if (typeof nToast !== 'undefined') nToast(res.error, 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = label; }
      } else {
        if (typeof nToast !== 'undefined') nToast(res.message, 'success');
        setTimeout(() => window.location.href = '/history?tab=withdraw', 1500);
      }
    })
    .catch(() => {
      if (typeof nToast !== 'undefined') nToast('Koneksi terputus. Silakan coba lagi.', 'error');
      if (btn) { btn.disabled = false; btn.innerHTML = label; }
    });
  };
})();
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
