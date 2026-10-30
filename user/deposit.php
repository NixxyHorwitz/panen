<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
$min_deposit  = (float) setting($pdo, 'min_deposit', '10000');
$bank_enabled = setting($pdo, 'bank_enabled', '1') === '1';
$qris_enabled = setting($pdo, 'qris_enabled', '1') === '1';
$bankName     = setting($pdo, 'bank_name', 'BCA');
$bankAccount  = setting($pdo, 'bank_account', '-');
$bankHolder   = setting($pdo, 'bank_holder', 'Admin');
$qris_raw     = $_ENV['QRIS_RAW'] ?? '';

$u_enabled = setting($pdo, 'depo_unique_code_enabled', '0') === '1';
$u_min = (int)setting($pdo, 'depo_unique_code_min', '1');
$u_max = (int)setting($pdo, 'depo_unique_code_max', '999');
$u_start = max(1, min($u_min, $u_max));
$u_cap   = max(1, $u_min, $u_max);

/**
 * Kode unik terkecil (mulai +1) yang belum dipakai untuk nominal dasar yang sama.
 * Nominal dianggap terpakai bila masih pending ATAU dibuat dalam 3 jam terakhir (status apa pun,
 * termasuk yang sudah expired/dibatalkan), supaya pembayaran telat tidak tertukar ke deposit lain.
 * Bila semua kode sampai batas maks terpakai, lanjut naik sampai ketemu yang kosong.
 */
const DEPO_UNIQUE_LOCK_HOURS = 3;
function depo_next_unique_code(PDO $pdo, int $base, int $start, int $cap): int {
    $s = $pdo->prepare("SELECT DISTINCT CAST(amount AS UNSIGNED) FROM deposits
                        WHERE (status = 'pending' OR created_at >= DATE_SUB(NOW(), INTERVAL " . DEPO_UNIQUE_LOCK_HOURS . " HOUR))
                          AND amount > ? AND amount <= ?");
    $s->execute([$base, $base + $cap + 1000]);
    $used = array_flip(array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)));
    for ($c = $start; $c < $cap + 1000; $c++) {
        if (!isset($used[$base + $c])) return $c;
    }
    return $cap + 1000;
}

/** Simpan deposit dengan kode unik di dalam kunci, supaya dua request bersamaan tidak dapat kode yang sama. */
function depo_insert_with_code(PDO $pdo, bool $enabled, int $base, int $start, int $cap, callable $insert): array {
    if (!$enabled) return [$insert($base), 0];
    $pdo->query("SELECT GET_LOCK('depo_unique_code', 5)");
    try {
        $code = depo_next_unique_code($pdo, $base, $start, $cap);
        return [$insert($base + $code), $code];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('depo_unique_code')");
    }
}

// AJAX: pratinjau kode unik untuk nominal tertentu (belum dipesan, final ditentukan saat kirim)
if (($_GET['action'] ?? '') === 'unique_preview') {
    header('Content-Type: application/json');
    $base = (int)preg_replace('/\D/', '', (string)($_GET['amount'] ?? '0'));
    $code = ($u_enabled && $base > 0) ? depo_next_unique_code($pdo, $base, $u_start, $u_cap) : 0;
    echo json_encode(['code' => $code, 'total' => $base + $code]);
    exit;
}

// ── Double-submit prevention ──────────────────────────────────────────────
$_ftk = 'dep_form_token';
if (empty($_SESSION[$_ftk])) $_SESSION[$_ftk] = bin2hex(random_bytes(16));
$_form_token = $_SESSION[$_ftk];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reconnect MySQL in case connection has gone away (error 2006/2013)
    pdo_reconnect($pdo);
    
    $submitted_ftk = $_POST['form_token'] ?? '';
    if (!hash_equals($_SESSION[$_ftk] ?? '', $submitted_ftk)) {
        $flash = '⚠️ Request kamu gagal diproses atau gak valid. Coba refresh halaman dulu ya!';
        $flashType = 'error';
        goto end_dep;
    }
    // Invalidate immediately to prevent double-submit
    unset($_SESSION[$_ftk]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_bank') {
    $amount = (int) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
    if ($amount < $min_deposit) {
        $flash = 'Minimal deposit ' . format_rp($min_deposit) . ' ya.'; $flashType = 'error';
    } elseif (!$bank_enabled) {
        $flash = 'Transfer bank lagi gak tersedia nih.'; $flashType = 'error';
    } else {
        $proof = null;
        if (!empty($_FILES['proof']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
                $flash = 'Bukti transfer harus format JPG/PNG/WEBP ya.'; $flashType = 'error';
                goto end_dep;
            }
            $dir = dirname(__DIR__) . '/uploads/deposits/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'dep_' . $user['id'] . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['proof']['tmp_name'], $dir . $fname);
            $proof = 'deposits/' . $fname;
        }
        [$dep_id] = depo_insert_with_code($pdo, $u_enabled, $amount, $u_start, $u_cap, function (int $total) use ($pdo, $user, $proof, &$amount) {
            $amount = $total;
            $pdo->prepare("INSERT INTO deposits (user_id,amount,method,proof_image) VALUES (?,?,?,?)")
                ->execute([$user['id'], $total, 'transfer', $proof]);
            return $pdo->lastInsertId();
        });
        
        $msg = "<b>📢 DEPOSIT BARU (Transfer)</b>\nUser: {$user['username']}\nAmount: " . format_rp((float)$amount) . "\nStatus: Pending";
        $kb = depo_pending_kb((int)$dep_id);
        $tg_msg_id = send_telegram_notif($pdo, $msg, $kb, 'depo');
        if ($tg_msg_id) {
            $pdo->prepare("UPDATE deposits SET tg_msg_id = ? WHERE id = ?")->execute([$tg_msg_id, $dep_id]);
        }
        
        // Success — regenerate token for next request
        $_SESSION[$_ftk] = bin2hex(random_bytes(16));
        $flash = '✅ Bukti transfer berhasil dikirim! Admin bakal memproses dalam 1×24 jam ya.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_qris') {
    $amount = (int) preg_replace('/\D/', '', $_POST['amount'] ?? '0');
    if ($amount < $min_deposit) {
        $flash = 'Minimal deposit ' . format_rp($min_deposit) . ' ya.'; $flashType = 'error';
    } elseif (!$qris_enabled || empty($qris_raw)) {
        $flash = 'QRIS lagi gak tersedia nih.'; $flashType = 'error';
    } else {
        [$dep_id] = depo_insert_with_code($pdo, $u_enabled, $amount, $u_start, $u_cap, function (int $total) use ($pdo, $user, &$amount) {
            $amount = $total;
            $pdo->prepare("INSERT INTO deposits (user_id,amount,method,status) VALUES (?,?,'qris','pending')")
                ->execute([$user['id'], $total]);
            return $pdo->lastInsertId();
        });
        
        $merchant_name = 'Unknown';
        $idx = 0;
        while ($idx < strlen($qris_raw) - 4) {
            $tag = substr($qris_raw, $idx, 2);
            $len = (int)substr($qris_raw, $idx+2, 2);
            if ($tag === '59') {
                $merchant_name = substr($qris_raw, $idx+4, $len);
                break;
            }
            $idx += 4 + $len;
        }

        $fmt_amount = format_rp((float)$amount);
        $msg = "📢 <b>DEPOSIT BARU ({$fmt_amount})</b>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "👤 <b>User:</b> <code>" . htmlspecialchars($user['username']) . "</code>\n";
        $msg .= "💵 <b>Amount:</b> <code>" . format_rp((float)$amount) . "</code>\n";
        $msg .= "🕒 <b>Time:</b> <code>" . date('d-m-Y H:i:s') . " WIB</code>\n";
        $msg .= "🏪 <b>QRIS:</b> <code>" . htmlspecialchars($merchant_name) . "</code>\n";
        $msg .= "💳 <b>Method:</b> <code>QRIS Otomatis</code>\n";
        $msg .= "⏳ <b>Status:</b> <code>Pending</code>\n";
        $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $msg .= "<i>Sistem memantau secara otomatis. Status di Telegram ini akan diperbarui ketika sukses terbayar via callback!</i>";
        
        $kb = depo_pending_kb((int)$dep_id);
        
        $tg_msg_id = send_telegram_notif($pdo, $msg, $kb, 'depo');
        if ($tg_msg_id) {
            $pdo->prepare("UPDATE deposits SET tg_msg_id = ? WHERE id = ?")->execute([$tg_msg_id, $dep_id]);
        }

        redirect('/pay?id=' . $dep_id);
    }
}
end_dep:

$deps = $pdo->prepare("SELECT * FROM deposits WHERE user_id=? ORDER BY created_at DESC LIMIT 6");
$deps->execute([$user['id']]); $deps = $deps->fetchAll();

$pageTitle  = 'Deposit';
$activePage = 'deposit';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══ DEPOSIT — Mesin Kasir Lumbung · Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.dp-top { display: flex; align-items: center; gap: 10px; }
.dp-top .t { flex: 1; min-width: 0; }
.dp-top h1 { margin: 0; font-family: var(--pc-font); font-size: 22px; font-weight: 700; line-height: 1.1; color: #4a2b00; }
.dp-top p { margin: 1px 0 0; font-size: 11.5px; font-weight: 800; color: var(--pc-muted); }
.dp-wallet { display: flex; align-items: center; gap: 6px; padding: 5px 10px 5px 5px; border-radius: 999px; background: #fff; box-shadow: 0 0 0 2px #f6e2b0, 0 3px 0 #ecd59c; }
.dp-wallet img { width: 26px; height: 26px; object-fit: contain; }
.dp-wallet small { display: block; font-size: 9.5px; font-weight: 900; color: var(--pc-muted); line-height: 1; }
.dp-wallet b { display: block; font-family: var(--pc-font); font-size: 14px; font-weight: 700; color: #0369a1; line-height: 1.1; white-space: nowrap; }

/* Langkah */
.dp-steps { display: flex; align-items: center; gap: 6px; }
.dp-steps span { display: inline-flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 900; color: #a3906a; }
.dp-steps em { width: 20px; height: 20px; border-radius: 50%; display: grid; place-items: center; font-style: normal; font-size: 11px; color: #fff; background: #d9c79c; }
.dp-steps span.on { color: #15803d; } .dp-steps span.on em { background: linear-gradient(180deg, #22c55e, #15803d); box-shadow: 0 2px 0 #11622f; }
.dp-steps i { flex: 1; height: 2px; border-radius: 2px; background: repeating-linear-gradient(90deg, #e6c886 0 5px, transparent 5px 9px); }

.dp-flash { display: flex; align-items: flex-start; gap: 8px; padding: 9px 12px; border-radius: 16px; font-size: 12px; font-weight: 800; line-height: 1.4; }
.dp-flash i { font-size: 17px; flex-shrink: 0; }
.dp-flash.success { background: #dcfce7; color: #15803d; box-shadow: 0 0 0 2px #86efac; }
.dp-flash.error { background: #ffe4e6; color: #9f1239; box-shadow: 0 0 0 2px #fecdd3; }

/* ── Mesin kasir ── */
.dp-reg { position: relative; padding: 12px 12px 14px; border-radius: 26px; background: linear-gradient(180deg, #c0813f 0%, #a0652a 45%, #8a5420 100%); box-shadow: 0 6px 0 #5a3412, 0 16px 28px rgba(90, 52, 18, .3), inset 0 2px 0 rgba(255, 255, 255, .3); }
.dp-reg::before { content: ''; position: absolute; left: 50%; top: -9px; width: 74px; height: 14px; margin-left: -37px; border-radius: 10px 10px 4px 4px; background: linear-gradient(180deg, #ffd54f, #f6b70a); box-shadow: 0 2px 0 #b07800; }
.dp-screen { position: relative; overflow: hidden; padding: 10px 12px 9px; border-radius: 16px; background: linear-gradient(180deg, #0f3d22, #14532d); box-shadow: inset 0 3px 8px rgba(0, 0, 0, .45), 0 0 0 3px #5a3412, 0 0 0 5px #d9a05b; }
.dp-screen::after { content: ''; position: absolute; inset: 0; pointer-events: none; background: repeating-linear-gradient(0deg, rgba(255, 255, 255, .04) 0 1px, transparent 1px 3px), linear-gradient(115deg, rgba(255, 255, 255, .12) 0 30%, transparent 30%); }
.dp-screen-top { display: flex; justify-content: space-between; gap: 6px; font-size: 10.5px; font-weight: 900; color: #86efac; letter-spacing: .4px; }
.depo-capsule-input { display: flex; align-items: center; gap: 6px; margin-top: 2px; }
.depo-prefix { font-family: var(--pc-font); font-size: 20px; font-weight: 700; color: #4ade80; }
.depo-amount-field { flex: 1; min-width: 0; height: 46px; padding: 0; border: 0; outline: none; background: transparent; text-align: right; caret-color: #ffd54f;
  font-family: var(--pc-font); font-size: 34px; font-weight: 700; letter-spacing: .5px; color: #dcfce7; text-shadow: 0 0 10px rgba(74, 222, 128, .55); -moz-appearance: textfield; }
.depo-amount-field::placeholder { color: rgba(220, 252, 231, .3); }
.depo-amount-field::-webkit-outer-spin-button, .depo-amount-field::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.depo-clear-btn { display: none; width: 30px; height: 30px; border: 0; border-radius: 10px; cursor: pointer; place-items: center; color: #fecdd3; background: rgba(255, 255, 255, .1); }
.depo-clear-btn.show { display: grid; }
#live-amount-preview { font-size: 11px; font-weight: 900; }
.dp-after { margin-top: 2px; text-align: right; font-size: 11px; font-weight: 800; color: #86efac; }
.dp-after b { color: #ffd54f; }
@keyframes depoShake { 20%, 60% { transform: translateX(-6px); } 40%, 80% { transform: translateX(6px); } }

/* Koin cepat */
.depo-quick-grid { display: flex; gap: 6px; overflow-x: auto; margin: 12px -12px 0; padding: 2px 12px 5px; scrollbar-width: none; }
.depo-quick-grid::-webkit-scrollbar { display: none; }
.depo-quick-chip { flex: 0 0 auto; display: flex; align-items: center; gap: 4px; padding: 6px 11px 6px 6px; border-radius: 999px; cursor: pointer; user-select: none; font-family: var(--pc-font); font-size: 14px; font-weight: 700; color: #6b4300;
  background: linear-gradient(180deg, #fff3c4, #ffe58a); box-shadow: 0 3px 0 #b07800; transition: transform .12s; }
.depo-quick-chip img { width: 20px; height: 20px; object-fit: contain; }
.depo-quick-chip:active { transform: translateY(2px); box-shadow: 0 1px 0 #b07800; }
.depo-quick-chip.active { color: #fff; text-shadow: 0 1px 0 rgba(0, 0, 0, .2); background: linear-gradient(180deg, #4ade80, #16a34a); box-shadow: 0 3px 0 #11622f, 0 0 0 2px #fff; }

/* Tombol angka */
.dp-keys { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 7px; margin-top: 10px; }
.dp-key { min-height: 46px; border: 0; border-radius: 14px; cursor: pointer; font-family: var(--pc-font); font-size: 21px; font-weight: 700; color: #4a2b00;
  background: linear-gradient(180deg, #fffaf0, #f3e2bd); box-shadow: 0 4px 0 #6b3f16, inset 0 2px 0 #fff; transition: transform .08s; -webkit-user-select: none; user-select: none; touch-action: manipulation; }
.dp-key:active { transform: translateY(3px); box-shadow: 0 1px 0 #6b3f16, inset 0 2px 0 #fff; }
.dp-key.fn { font-size: 16px; color: #fff; background: linear-gradient(180deg, #fb923c, #ea580c); box-shadow: 0 4px 0 #9a3412, inset 0 2px 0 rgba(255, 255, 255, .35); }
.dp-key.fn:active { box-shadow: 0 1px 0 #9a3412; }

/* Cara bayar */
.dp-pay-h { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
.dp-methods { display: flex; flex-direction: column; gap: 7px; margin-top: 10px; }
.depo-segment-btn { position: relative; display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 12px 9px 9px; border: 0; border-radius: 17px; cursor: pointer; text-align: left; font: inherit; color: var(--pc-ink);
  background: #fff; box-shadow: inset 0 0 0 2px #f1dfb4; transition: box-shadow .2s, background .2s; }
.depo-segment-btn .ic { width: 42px; height: 42px; flex-shrink: 0; border-radius: 13px; display: grid; place-items: center; background: radial-gradient(circle at 50% 35%, #fff, #fff6dc); box-shadow: inset 0 0 0 2px #f6e2b0; }
.depo-segment-btn .ic img { max-width: 80%; max-height: 80%; object-fit: contain; }
.depo-segment-btn .t { flex: 1; min-width: 0; line-height: 1.2; }
.depo-segment-btn b { display: flex; align-items: center; gap: 6px; font-family: var(--pc-font); font-size: 15.5px; font-weight: 700; }
.depo-segment-btn b em { padding: 0 7px; border-radius: 999px; font-style: normal; font-family: 'Nunito', sans-serif; font-size: 9.5px; font-weight: 900; color: #fff; background: #16a34a; }
.depo-segment-btn small { display: block; font-size: 11px; font-weight: 800; color: var(--pc-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.depo-segment-btn .radio { width: 22px; height: 22px; flex-shrink: 0; border-radius: 50%; box-shadow: inset 0 0 0 2px #d9c79c; transition: box-shadow .2s; }
.depo-segment-btn.active { background: #f0fdf4; box-shadow: inset 0 0 0 2.5px #22c55e; }
.depo-segment-btn.active .radio { box-shadow: inset 0 0 0 7px #16a34a; }

.depo-form-box { display: none; }
.depo-form-box.active { display: block; animation: dpIn .35s var(--pc-out) both; }
@keyframes dpIn { from { opacity: 0; transform: translateY(8px); } }
.depo-action-btn.g-btn { width: 100%; min-height: 54px; margin-top: 12px; font-size: 17px; border-radius: 17px; }
.depo-action-btn .qris-logo { height: 18px; width: auto; padding: 3px 6px; border-radius: 6px; background: #fff; }
.depo-wallets-subline { margin-top: 8px; text-align: center; font-size: 10.5px; font-weight: 800; color: var(--pc-muted); }
.depo-u-pill { display: flex; align-items: center; gap: 6px; margin-top: 10px; padding: 8px 11px; border-radius: 13px; font-size: 11.5px; font-weight: 800; color: #8a5a00; background: #fff3c4; box-shadow: inset 0 0 0 1.5px #fcd34d; }
.depo-u-pill i { color: #f59e0b; font-size: 15px; }
.depo-bank-box { display: flex; align-items: center; gap: 10px; margin-top: 10px; padding: 10px; border-radius: 16px; background: linear-gradient(135deg, #e0f2fe, #fff); box-shadow: inset 0 0 0 2px #bae6fd; }
.depo-bank-meta { flex: 1; min-width: 0; }
.depo-bank-badge-title { font-size: 10.5px; font-weight: 900; color: #0369a1; }
.depo-bank-num-clean { font-family: var(--pc-font); font-size: 19px; font-weight: 700; letter-spacing: 1px; color: var(--pc-ink); overflow-wrap: anywhere; }
.depo-bank-holder-txt { font-size: 11px; font-weight: 800; color: var(--pc-muted); }
.depo-copy-btn-mini.copied { --c1: #4ade80; --c2: #16a34a; }
.depo-upload-compact { position: relative; display: flex; align-items: center; gap: 10px; margin-top: 10px; padding: 10px; border-radius: 16px; background: #fffaf0; border: 2px dashed #e6c886; cursor: pointer; }
.depo-upload-input { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 2; }
.depo-upload-icon { width: 46px; height: 46px; flex-shrink: 0; border-radius: 14px; display: grid; place-items: center; background: radial-gradient(circle at 50% 35%, #fff, #fff6dc); box-shadow: inset 0 0 0 2px #f6e2b0; }
.depo-upload-icon img { width: 34px; height: 34px; object-fit: contain; }
.depo-proof-thumb { display: none; width: 46px; height: 46px; flex-shrink: 0; border-radius: 12px; object-fit: cover; box-shadow: 0 0 0 2px #fff, 0 3px 8px rgba(0, 0, 0, .15); }
.depo-upload-txt { min-width: 0; }
.depo-upload-main-txt { font-size: 12.5px; font-weight: 900; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.depo-upload-sub-txt { font-size: 10.5px; font-weight: 800; color: var(--pc-muted); }

/* Struk terakhir (geser) */
.dp-hist { display: flex; gap: 8px; overflow-x: auto; margin: 10px -12px 0; padding: 4px 12px 8px; scrollbar-width: none; }
.dp-hist::-webkit-scrollbar { display: none; }
.dp-tk { position: relative; flex: 0 0 136px; padding: 10px 10px 9px; border-radius: 14px; background: #fff; box-shadow: 0 0 0 2px #efe6cf, 0 3px 0 #e6dbbd;
  -webkit-mask: radial-gradient(circle 6px at 0 58%, transparent 98%, #000) left / 51% 100% no-repeat, radial-gradient(circle 6px at 100% 58%, transparent 98%, #000) right / 51% 100% no-repeat;
  mask: radial-gradient(circle 6px at 0 58%, transparent 98%, #000) left / 51% 100% no-repeat, radial-gradient(circle 6px at 100% 58%, transparent 98%, #000) right / 51% 100% no-repeat; }
.dp-tk-h { display: flex; align-items: center; justify-content: space-between; gap: 4px; }
.dp-tk-h img { height: 18px; max-width: 46px; object-fit: contain; }
.dp-tk b { display: block; margin-top: 6px; font-family: var(--pc-font); font-size: 16px; font-weight: 700; color: var(--pc-ink); white-space: nowrap; }
.dp-tk small { display: block; padding-bottom: 7px; border-bottom: 1.5px dashed #f1dfb4; font-size: 10.5px; font-weight: 800; color: var(--pc-muted); }
.dp-tk .foot { display: flex; align-items: center; justify-content: space-between; gap: 4px; margin-top: 7px; min-height: 26px; }
.dp-tk .g-btn { min-height: 26px; padding: 0 9px; font-size: 12px; border-radius: 9px; }
.depo-badge-mini { font-size: 10.5px; font-weight: 900; padding: 2px 8px; border-radius: 999px; }
.depo-badge-mini.pending { color: #b45309; background: #fef3c7; }
.depo-badge-mini.confirmed { color: #15803d; background: #dcfce7; }
.depo-badge-mini.rejected, .depo-badge-mini.error { color: #be123c; background: #ffe4e6; }
@media (max-width: 350px) { .depo-amount-field { font-size: 28px; } .dp-key { min-height: 42px; font-size: 19px; } .dp-steps span b { display: none; } }
</style>

<div class="g-page">

  <div class="dp-top" data-rv>
    <div class="t"><h1>Mesin Kasir</h1><p>Isi Saldo Beli, QRIS masuk otomatis.</p></div>
    <div class="dp-wallet"><img src="/assets/img/icons3d/piggy.png" alt=""><span><small>Saldo Beli</small><b>Rp <?= number_format((float)$user['balance_dep'], 0, ',', '.') ?></b></span></div>
  </div>

  <div class="dp-steps" data-rv style="--d:.03s" aria-hidden="true">
    <span class="on"><em>1</em><b>Nominal</b></span><i></i><span class="on" id="dp-step2"><em>2</em><b>Cara bayar</b></span><i></i><span><em>3</em><b>Bayar</b></span>
  </div>

  <?php if ($flash): ?>
  <div class="dp-flash <?= $flashType === 'error' ? 'error' : 'success' ?>" data-rv>
    <i class="ph-fill ph-<?= $flashType === 'error' ? 'warning-circle' : 'check-circle' ?>"></i>
    <span><?= htmlspecialchars($flash) ?></span>
  </div>
  <?php endif; ?>

  <?php if (!$bank_enabled && (!$qris_enabled || empty($qris_raw))): ?>
  <div class="g-panel g-empty" data-rv>
    <img src="/assets/img/icons3d/lock.png" alt="">
    <b>Deposit sedang offline</b>
    <p>Semua metode isi saldo ditutup sementara. Hubungi admin ya.</p>
  </div>
  <?php else: ?>

  <!-- Mesin kasir: layar + koin + tombol angka -->
  <section class="dp-reg" data-rv style="--d:.06s">
    <div class="dp-screen" id="master-amount-capsule">
      <div class="dp-screen-top"><span>NOMINAL DEPOSIT</span><span id="live-amount-preview">Min <?= format_rp($min_deposit) ?></span></div>
      <div class="depo-capsule-input">
        <span class="depo-prefix">Rp</span>
        <input type="number" id="master-amount-input" class="depo-amount-field" min="<?= $min_deposit ?>" step="any" inputmode="none" placeholder="0" oninput="handleManualInput(this)" aria-label="Nominal deposit">
        <button type="button" class="depo-clear-btn" id="master-amount-clear" onclick="clearAmount()" title="Hapus"><i class="ph-bold ph-x"></i></button>
      </div>
      <div class="dp-after">Saldo Beli jadi <b id="dp-after">Rp <?= number_format((float)$user['balance_dep'], 0, ',', '.') ?></b></div>
    </div>

    <div class="depo-quick-grid">
      <?php
      $quick_list = array_values(array_filter([10000, 25000, 50000, 100000, 200000, 500000], fn($q) => $q >= $min_deposit)) ?: [(int)$min_deposit];
      foreach ($quick_list as $q):
        $fmt_label = ($q >= 1000000) ? ($q / 1000000) . 'jt' : ($q / 1000) . 'rb';
      ?>
      <div class="depo-quick-chip" onclick="applyAmount(<?= $q ?>, this)"><img src="/assets/img/icons3d/coins.png" alt=""><?= $fmt_label ?></div>
      <?php endforeach; ?>
    </div>

    <div class="dp-keys">
      <?php foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '000', '0'] as $k): ?>
        <button type="button" class="dp-key" onclick="depKey('<?= $k ?>')"><?= $k ?></button>
      <?php endforeach; ?>
      <button type="button" class="dp-key fn" onclick="depKey('del')" aria-label="Hapus angka"><i class="ph-bold ph-backspace"></i></button>
    </div>
  </section>

  <?php if ($u_enabled): ?>
  <div class="depo-u-pill">
    <i class="ph-fill ph-lightning"></i>
    <span id="u-code-txt">Kode unik <strong>+Rp <?= $u_start ?></strong> ditambahkan otomatis (naik kalau sudah dipakai).</span>
  </div>
  <?php endif; ?>

  <!-- Cara bayar + kirim -->
  <section class="g-panel" data-rv style="--d:.1s">
    <div class="dp-pay-h"><span class="g-ribbon"><img src="/assets/img/icons3d/shop.png" alt="">Cara Bayar</span></div>
    <div class="dp-methods">
      <?php if ($qris_enabled && !empty($qris_raw)): ?>
      <button type="button" class="depo-segment-btn active" id="seg-btn-qris" onclick="switchMethod('qris')">
        <span class="ic"><img src="/assets/qris.png?v=3" alt="QRIS"></span>
        <span class="t"><b>QRIS <em>OTOMATIS</em></b><small>Semua e-wallet &amp; m-banking</small></span>
        <span class="radio"></span>
      </button>
      <?php endif; ?>
      <?php if ($bank_enabled): ?>
      <button type="button" class="depo-segment-btn <?= (!$qris_enabled || empty($qris_raw)) ? 'active' : '' ?>" id="seg-btn-bank" onclick="switchMethod('bank')">
        <span class="ic"><img src="/assets/img/icons3d/barn.png" alt=""></span>
        <span class="t"><b>Transfer <?= htmlspecialchars($bankName) ?></b><small>Upload bukti · dicek admin</small></span>
        <span class="radio"></span>
      </button>
      <?php endif; ?>
    </div>

    <?php if ($qris_enabled && !empty($qris_raw)): ?>
    <div class="depo-form-box active" id="form-box-qris">
      <form method="POST" id="form-qris-submit" onsubmit="return validateDepoSubmit(event, 'qris')">
        <?= csrf_field() ?>
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token) ?>">
        <input type="hidden" name="action" value="submit_qris">
        <input type="hidden" name="amount" id="qris-hidden-amount" value="<?= (int)$min_deposit ?>">
        <button type="submit" class="depo-action-btn g-btn gold shine no-dbl-submit" id="btn-submit-qris">
          <img src="/assets/qris.png?v=3" alt="QRIS" class="qris-logo"><span>Bayar sekarang</span><i class="ph-bold ph-arrow-right"></i>
        </button>
      </form>
      <div class="depo-wallets-subline">DANA · OVO · GoPay · ShopeePay · BCA · Mandiri · BRI · BNI</div>
    </div>
    <?php endif; ?>

    <?php if ($bank_enabled): ?>
    <div class="depo-form-box <?= (!$qris_enabled || empty($qris_raw)) ? 'active' : '' ?>" id="form-box-bank">
      <div class="depo-bank-box">
        <span class="g-slot sm"><img src="/assets/img/icons3d/barn.png" alt=""></span>
        <div class="depo-bank-meta">
          <div class="depo-bank-badge-title">Bank <?= htmlspecialchars($bankName) ?></div>
          <div class="depo-bank-num-clean" id="bank-acc-display"><?= htmlspecialchars($bankAccount) ?></div>
          <div class="depo-bank-holder-txt">a.n <?= htmlspecialchars($bankHolder) ?></div>
        </div>
        <button type="button" class="depo-copy-btn-mini g-btn sky sm" id="btn-copy-acc" onclick="copyAccNumber()"><i class="ph-bold ph-copy"></i><span id="copy-btn-txt">Salin</span></button>
      </div>

      <form method="POST" enctype="multipart/form-data" id="form-bank-submit" onsubmit="return validateDepoSubmit(event, 'bank')">
        <?= csrf_field() ?>
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token) ?>">
        <input type="hidden" name="action" value="submit_bank">
        <input type="hidden" name="amount" id="bank-hidden-amount" value="<?= (int)$min_deposit ?>">
        <div class="depo-upload-compact">
          <input type="file" name="proof" id="proof-file-input" class="depo-upload-input" accept="image/*" required onchange="handleProofSelect(this)">
          <div class="depo-upload-icon" id="proof-icon-box"><img src="/assets/img/icons3d/receipt.png" alt=""></div>
          <img src="" alt="Bukti" id="proof-preview-img" class="depo-proof-thumb">
          <div class="depo-upload-txt">
            <div class="depo-upload-main-txt" id="proof-label-main">Sentuh untuk pilih foto struk</div>
            <div class="depo-upload-sub-txt" id="proof-label-sub">JPG, PNG, atau WEBP</div>
          </div>
        </div>
        <button type="submit" class="depo-action-btn g-btn sky no-dbl-submit" id="btn-submit-bank"><i class="ph-bold ph-paper-plane-tilt"></i><span>Kirim bukti transfer</span></button>
      </form>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- Struk terakhir -->
  <?php if (!empty($deps)): ?>
  <section data-rv style="--d:.15s">
    <div class="g-sec"><span class="g-ribbon gold"><img src="/assets/img/icons3d/receipt.png" alt="">Struk Terakhir</span><a href="/history?tab=deposit" class="g-chip" style="text-decoration:none">Semua ›</a></div>
    <div class="dp-hist">
      <?php foreach ($deps as $d):
        $st = strtolower($d['status'] ?? 'pending');
        $bclass = 'error';
        if ($st === 'pending') $bclass = 'pending';
        elseif ($st === 'confirmed' || $st === 'approved') $bclass = 'confirmed';
        elseif ($st === 'rejected') $bclass = 'rejected'; ?>
        <div class="dp-tk">
          <div class="dp-tk-h"><img src="<?= $d['method'] === 'qris' ? '/assets/qris.png?v=3' : '/assets/img/icons3d/barn.png' ?>" alt=""><span class="depo-badge-mini <?= $bclass ?>"><?= ucfirst($d['status']) ?></span></div>
          <b><?= format_rp((float)$d['amount']) ?></b>
          <small><?= date('d M, H:i', strtotime($d['created_at'])) ?></small>
          <div class="foot">
            <span class="depo-badge-mini" style="padding:0;color:var(--pc-muted)">#<?= (int)$d['id'] ?></span>
            <?php if ($st === 'pending' && $d['method'] === 'qris'): ?><a href="/pay?id=<?= $d['id'] ?>" class="g-btn gold">Bayar</a><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

</div>

<script>
/* Tombol angka mesin kasir + saldo setelah deposit */
const DEP_BAL = <?= (float)$user['balance_dep'] ?>;
function depKey(k) {
  const input = document.getElementById('master-amount-input');
  if (!input) return;
  let v = String(input.value || '').replace(/\D/g, '');
  if (k === 'del') v = v.slice(0, -1);
  else if (v.length < 9) v = (v === '' || v === '0') ? (k === '000' ? '' : k) : (v + k).slice(0, 9);
  input.value = v;
  handleManualInput(input);
  if (navigator.vibrate) navigator.vibrate(8);
}
document.addEventListener('DOMContentLoaded', () => {
  const after = document.getElementById('dp-after');
  const orig = window.updateLivePreview;
  if (!after || typeof orig !== 'function') return;
  window.updateLivePreview = function (val) {
    orig(val);
    after.textContent = 'Rp ' + Math.round(DEP_BAL + (val >= minDeposit ? val : 0)).toLocaleString('id-ID');
  };
  const cur = parseInt(document.getElementById('master-amount-input')?.value || '0') || 0;
  window.updateLivePreview(cur);
});
</script>

<script>
let currentMethod = '<?= ($qris_enabled && !empty($qris_raw)) ? "qris" : "bank" ?>';
const minDeposit = <?= (int)$min_deposit ?>;

function formatRp(n) {
  return 'Rp ' + Number(n).toLocaleString('id-ID');
}

function switchMethod(method) {
  currentMethod = method;
  const btnQris = document.getElementById('seg-btn-qris');
  const btnBank = document.getElementById('seg-btn-bank');
  const boxQris = document.getElementById('form-box-qris');
  const boxBank = document.getElementById('form-box-bank');

  if (btnQris) btnQris.classList.toggle('active', method === 'qris');
  if (btnBank) btnBank.classList.toggle('active', method === 'bank');
  if (boxQris) boxQris.classList.toggle('active', method === 'qris');
  if (boxBank) boxBank.classList.toggle('active', method === 'bank');
}

const uniqueEnabled = <?= $u_enabled ? 'true' : 'false' ?>;
let uniqueTimer = null;
function previewUniqueCode(val) {
  const txt = document.getElementById('u-code-txt');
  if (!uniqueEnabled || !txt || val < minDeposit) return;
  clearTimeout(uniqueTimer);
  uniqueTimer = setTimeout(() => {
    fetch('/deposit?action=unique_preview&amount=' + val, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => {
        if (!d || !d.code) return;
        txt.innerHTML = 'Kode unik <strong>+Rp ' + d.code + '</strong> &rarr; bayar <strong>' + formatRp(d.total) + '</strong>';
      }).catch(() => {});
  }, 250);
}

function updateLivePreview(val) {
  const lbl = document.getElementById('live-amount-preview');
  previewUniqueCode(val);
  if (!lbl) return;
  if (val >= minDeposit) {
    lbl.style.color = '#ffd54f';
    lbl.textContent = formatRp(val);
  } else if (val > 0) {
    lbl.style.color = '#fda4af';
    lbl.textContent = 'Min. ' + formatRp(minDeposit);
  } else {
    lbl.style.color = '';
    lbl.textContent = 'Min. ' + formatRp(minDeposit);
  }
}

function applyAmount(val, btnEl) {
  const input = document.getElementById('master-amount-input');
  const clearBtn = document.getElementById('master-amount-clear');
  input.value = val;
  if (clearBtn) clearBtn.classList.add('show');

  const qrisHidden = document.getElementById('qris-hidden-amount');
  const bankHidden = document.getElementById('bank-hidden-amount');
  if (qrisHidden) qrisHidden.value = val;
  if (bankHidden) bankHidden.value = val;

  updateLivePreview(val);

  document.querySelectorAll('.depo-quick-chip').forEach(b => b.classList.remove('active'));
  if (btnEl) btnEl.classList.add('active');
}

function handleManualInput(inputEl) {
  const val = parseInt(inputEl.value.replace(/\D/g, '')) || 0;
  const clearBtn = document.getElementById('master-amount-clear');
  if (clearBtn) clearBtn.classList.toggle('show', inputEl.value.length > 0);

  document.querySelectorAll('.depo-quick-chip').forEach(b => b.classList.remove('active'));

  const qrisHidden = document.getElementById('qris-hidden-amount');
  const bankHidden = document.getElementById('bank-hidden-amount');
  if (qrisHidden) qrisHidden.value = val;
  if (bankHidden) bankHidden.value = val;

  updateLivePreview(val);
}

function clearAmount() {
  const input = document.getElementById('master-amount-input');
  const clearBtn = document.getElementById('master-amount-clear');
  input.value = '';
  if (clearBtn) clearBtn.classList.remove('show');
  document.querySelectorAll('.depo-quick-chip').forEach(b => b.classList.remove('active'));

  const qrisHidden = document.getElementById('qris-hidden-amount');
  const bankHidden = document.getElementById('bank-hidden-amount');
  if (qrisHidden) qrisHidden.value = minDeposit;
  if (bankHidden) bankHidden.value = minDeposit;

  updateLivePreview(0);
}

function validateDepoSubmit(e, type) {
  const hidden = document.getElementById(type + '-hidden-amount');
  const val = parseInt(hidden ? hidden.value : 0) || 0;
  if (val < minDeposit) {
    e.preventDefault();
    const capsule = document.getElementById('master-amount-capsule');
    const input = document.getElementById('master-amount-input');
    if (capsule) {
      capsule.style.animation = 'depoShake 0.35s ease';
      setTimeout(() => { capsule.style.animation = ''; }, 400);
    }
    if (input) input.focus();

    if (typeof nToast === 'function') {
      nToast('Minimal isi saldo adalah ' + formatRp(minDeposit), 'warning');
    } else {
      alert('Minimal isi saldo adalah ' + formatRp(minDeposit));
    }
    return false;
  }
  return true;
}

function copyAccNumber() {
  const acc = document.getElementById('bank-acc-display').textContent.trim();
  const btn = document.getElementById('btn-copy-acc');
  const txt = document.getElementById('copy-btn-txt');

  function onSuccess() {
    if (btn) btn.classList.add('copied');
    if (txt) txt.textContent = '✓ Tersalin';
    if (typeof nToast !== 'undefined' && nToast.copy) {
      nToast.copy(acc, 'Nomor rekening ' + acc);
    } else if (typeof nToast === 'function') {
      nToast('Nomor rekening disalin: ' + acc, 'copy');
    }
    setTimeout(() => {
      if (btn) btn.classList.remove('copied');
      if (txt) txt.textContent = 'Salin';
    }, 2000);
  }

  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(acc).then(onSuccess).catch(() => {
      fallbackCopy(acc, onSuccess);
    });
  } else {
    fallbackCopy(acc, onSuccess);
  }
}

function fallbackCopy(text, cb) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();
  try {
    document.execCommand('copy');
    if (cb) cb();
  } catch (e) {}
  document.body.removeChild(ta);
}

function handleProofSelect(input) {
  const file = input.files && input.files[0];
  const icon = document.getElementById('proof-icon-box');
  const img = document.getElementById('proof-preview-img');
  const lblMain = document.getElementById('proof-label-main');
  const lblSub = document.getElementById('proof-label-sub');

  if (!file) return;

  const validTypes = ['image/jpeg', 'image/png', 'image/webp'];
  if (!validTypes.includes(file.type)) {
    alert('Harap pilih file gambar JPG, PNG, atau WEBP.');
    input.value = '';
    return;
  }

  if (lblMain) lblMain.textContent = file.name;
  if (lblSub) lblSub.textContent = (file.size / 1024).toFixed(1) + ' KB (Sentuh untuk ganti)';

  const reader = new FileReader();
  reader.onload = function(e) {
    if (img) {
      img.src = e.target.result;
      img.style.display = 'block';
    }
    if (icon) icon.style.display = 'none';
  };
  reader.readAsDataURL(file);
}

document.addEventListener('DOMContentLoaded', () => {
  // Preselect chip pertama
  const chips = document.querySelectorAll('.depo-quick-chip');
  if (chips.length > 0) {
    chips[0].click();
  }

  // Handle enter key on input
  const masterInput = document.getElementById('master-amount-input');
  if (masterInput) {
    masterInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (currentMethod === 'qris') {
          document.getElementById('btn-submit-qris')?.click();
        } else {
          document.getElementById('btn-submit-bank')?.click();
        }
      }
    });
  }
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
