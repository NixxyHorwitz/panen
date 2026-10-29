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
/* ══ DEPOSIT — Isi Lumbung · Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.dp-bal { display: flex; align-items: center; gap: 10px; margin-top: 12px; padding: 10px 12px; border-radius: 18px; background: rgba(255, 255, 255, .78); box-shadow: 0 0 0 2px rgba(255, 255, 255, .85), 0 8px 18px rgba(120, 70, 10, .12); }
.dp-bal .t { flex: 1; min-width: 0; }
.dp-bal small { display: block; font-size: 11px; font-weight: 900; color: #8a5a00; }
.dp-bal b { display: block; font-family: var(--pc-font); font-size: 24px; font-weight: 700; line-height: 1.1; color: #0369a1; }
.dp-bal b em { font-style: normal; font-size: 14px; margin-right: 3px; }
.dp-bal .g-chip { background: #fff3c4; color: #6b4300; }

.dp-flash { display: flex; align-items: flex-start; gap: 8px; padding: 9px 12px; border-radius: 16px; font-size: 12px; font-weight: 800; line-height: 1.4; }
.dp-flash i { font-size: 17px; flex-shrink: 0; }
.dp-flash.success { background: #dcfce7; color: #15803d; box-shadow: 0 0 0 2px #86efac; }
.dp-flash.error { background: #ffe4e6; color: #9f1239; box-shadow: 0 0 0 2px #fecdd3; }

/* Pilih metode */
.dp-methods { display: grid; grid-auto-flow: column; grid-auto-columns: 1fr; gap: 8px; }
.depo-segment-btn { position: relative; display: flex; align-items: center; gap: 8px; padding: 9px 10px; border: 0; border-radius: 18px; cursor: pointer; text-align: left; font: inherit; color: var(--pc-ink);
  background: #fff; box-shadow: inset 0 0 0 2px #f6e2b0, 0 4px 0 #ecd59c; transition: transform .15s var(--pc-ease); }
.depo-segment-btn:active { transform: translateY(3px); box-shadow: inset 0 0 0 2px #f6e2b0, 0 1px 0 #ecd59c; }
.depo-segment-btn .ic { width: 38px; height: 38px; flex-shrink: 0; border-radius: 12px; display: grid; place-items: center; background: radial-gradient(circle at 50% 35%, #fff, #fff6dc); box-shadow: inset 0 0 0 2px #f6e2b0; }
.depo-segment-btn .ic img { max-width: 80%; max-height: 80%; object-fit: contain; }
.depo-segment-btn .t { min-width: 0; line-height: 1.2; }
.depo-segment-btn b { display: block; font-family: var(--pc-font); font-size: 14.5px; font-weight: 700; }
.depo-segment-btn small { display: block; font-size: 10.5px; font-weight: 800; color: var(--pc-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.depo-segment-btn.active { background: linear-gradient(180deg, #f0fdf4, #fff); box-shadow: inset 0 0 0 2.5px #22c55e, 0 4px 0 #16a34a, 0 8px 16px rgba(22, 163, 74, .2); }
.depo-segment-btn.active::after { content: '\2713'; position: absolute; top: -6px; right: -4px; width: 20px; height: 20px; border-radius: 50%; display: grid; place-items: center; font-size: 11px; font-weight: 900; color: #fff; background: #16a34a; box-shadow: 0 0 0 2px #fff; animation: gStamp .4s var(--pc-ease) both; }

/* Nominal */
.depo-capsule-input { display: flex; align-items: center; gap: 6px; margin-top: 10px; padding: 4px 6px 4px 14px; border-radius: 16px; background: #fff; box-shadow: inset 0 0 0 2px #f6e2b0, inset 0 2px 5px rgba(120, 70, 10, .08); transition: box-shadow .2s; }
.depo-capsule-input:focus-within { box-shadow: inset 0 0 0 2px #22c55e, 0 0 0 4px rgba(34, 197, 94, .15); }
.depo-prefix { font-family: var(--pc-font); font-size: 18px; font-weight: 700; color: #15803d; }
.depo-amount-field { flex: 1; min-width: 0; height: 44px; border: 0; outline: none; background: transparent; font-family: var(--pc-font); font-size: 24px; font-weight: 700; color: var(--pc-ink); -moz-appearance: textfield; }
.depo-amount-field::-webkit-outer-spin-button, .depo-amount-field::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
.depo-clear-btn { display: none; width: 30px; height: 30px; border: 0; border-radius: 10px; cursor: pointer; place-items: center; color: #9f1239; background: #ffe4e6; }
.depo-clear-btn.show { display: grid; }
#live-amount-preview { font-size: 11.5px; font-weight: 900; }
.depo-quick-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 10px; }
.depo-quick-chip { display: flex; align-items: center; justify-content: center; gap: 5px; padding: 9px 0; border-radius: 14px; cursor: pointer; user-select: none; font-family: var(--pc-font); font-size: 15px; font-weight: 700; color: #6b4300;
  background: linear-gradient(180deg, #fff, #fff6dc); box-shadow: inset 0 0 0 2px #f6e2b0, 0 4px 0 #ecd59c; transition: transform .12s; }
.depo-quick-chip img { width: 22px; height: 22px; object-fit: contain; transition: transform .3s var(--pc-ease); }
.depo-quick-chip:active { transform: translateY(3px); box-shadow: inset 0 0 0 2px #f6e2b0, 0 1px 0 #ecd59c; }
.depo-quick-chip.active { color: #fff; text-shadow: 0 1px 0 rgba(0, 0, 0, .2); background: linear-gradient(180deg, #22c55e, #15803d); box-shadow: inset 0 0 0 2px rgba(255, 255, 255, .5), 0 4px 0 #11622f, 0 8px 16px rgba(22, 163, 74, .3); }
.depo-quick-chip.active img { transform: scale(1.2) rotate(-12deg); }
.depo-u-pill { display: flex; align-items: center; gap: 6px; margin-top: 10px; padding: 8px 11px; border-radius: 13px; font-size: 11.5px; font-weight: 800; color: #8a5a00; background: #fff3c4; box-shadow: inset 0 0 0 1.5px #fcd34d; }
.depo-u-pill i { color: #f59e0b; font-size: 15px; }
@keyframes depoShake { 20%, 60% { transform: translateX(-6px); } 40%, 80% { transform: translateX(6px); } }

/* Form per metode */
.depo-form-box { display: none; }
.depo-form-box.active { display: block; animation: dpIn .35s var(--pc-out) both; }
@keyframes dpIn { from { opacity: 0; transform: translateY(8px); } }
.depo-action-btn.g-btn { width: 100%; min-height: 52px; margin-top: 12px; font-size: 16.5px; border-radius: 17px; }
.depo-action-btn .qris-logo { height: 18px; width: auto; padding: 3px 6px; border-radius: 6px; background: #fff; }
.depo-wallets-subline { margin-top: 8px; text-align: center; font-size: 10.5px; font-weight: 800; color: var(--pc-muted); }
.depo-bank-box { display: flex; align-items: center; gap: 10px; margin-top: 12px; padding: 10px; border-radius: 16px; background: linear-gradient(135deg, #e0f2fe, #fff); box-shadow: inset 0 0 0 2px #bae6fd; }
.depo-bank-meta { flex: 1; min-width: 0; }
.depo-bank-badge-title { font-size: 10.5px; font-weight: 900; color: #0369a1; }
.depo-bank-num-clean { font-family: var(--pc-font); font-size: 20px; font-weight: 700; letter-spacing: 1px; color: var(--pc-ink); }
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

/* Riwayat */
.dp-hist { display: flex; flex-direction: column; gap: 7px; margin-top: 10px; }
.dp-row { display: flex; align-items: center; gap: 10px; padding: 8px 10px 8px 8px; border-radius: 16px; background: #fff; box-shadow: inset 0 0 0 2px #f6e2b0; }
.dp-row .g-slot { width: 36px; height: 36px; border-radius: 12px; }
.dp-row .t { flex: 1; min-width: 0; }
.dp-row b { display: block; font-family: var(--pc-font); font-size: 15px; font-weight: 700; color: var(--pc-ink); }
.dp-row small { display: block; font-size: 10.5px; font-weight: 800; color: var(--pc-muted); }
.dp-row .r { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.depo-badge-mini { font-size: 11px; font-weight: 900; padding: 3px 9px; border-radius: 999px; }
.depo-badge-mini.pending { color: #b45309; background: #fef3c7; }
.depo-badge-mini.confirmed { color: #15803d; background: #dcfce7; }
.depo-badge-mini.rejected, .depo-badge-mini.error { color: #be123c; background: #ffe4e6; }
</style>

<div class="g-page">

  <section class="g-hero" data-rv>
    <span class="g-sun"></span><span class="g-cloud c1"></span><span class="g-cloud c2"></span>
    <div class="g-hero-row">
      <div style="flex:1;min-width:0">
        <h1>Deposit</h1>
        <p><i class="ph-fill ph-lightning"></i> QRIS masuk otomatis dalam hitungan detik.</p>
      </div>
      <div class="g-hero-art"><img src="/assets/img/icons3d/piggy.png" alt=""></div>
    </div>
    <div class="dp-bal">
      <span class="g-slot sm"><img src="/assets/img/icons3d/coins.png" alt=""></span>
      <div class="t"><small>Saldo Beli kamu</small><b><em>Rp</em><?= number_format((float)$user['balance_dep'], 0, ',', '.') ?></b></div>
      <span class="g-chip">Min <?= format_rp($min_deposit) ?></span>
    </div>
  </section>

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

  <!-- Metode -->
  <div class="dp-methods" data-rv style="--d:.05s">
    <?php if ($qris_enabled && !empty($qris_raw)): ?>
    <button type="button" class="depo-segment-btn active" id="seg-btn-qris" onclick="switchMethod('qris')">
      <span class="ic"><img src="/assets/qris.png?v=3" alt="QRIS"></span>
      <span class="t"><b>QRIS</b><small>Otomatis · semua e-wallet</small></span>
    </button>
    <?php endif; ?>
    <?php if ($bank_enabled): ?>
    <button type="button" class="depo-segment-btn <?= (!$qris_enabled || empty($qris_raw)) ? 'active' : '' ?>" id="seg-btn-bank" onclick="switchMethod('bank')">
      <span class="ic"><img src="/assets/img/icons3d/barn.png" alt=""></span>
      <span class="t"><b>Transfer</b><small><?= htmlspecialchars($bankName) ?> · cek manual</small></span>
    </button>
    <?php endif; ?>
  </div>

  <!-- Nominal + form -->
  <section class="g-panel" data-rv style="--d:.1s">
    <div class="g-sec"><span class="g-ribbon gold"><img src="/assets/img/icons3d/coins.png" alt="">Nominal</span><span id="live-amount-preview" style="color:var(--pc-muted)">Pilih atau ketik nominal</span></div>

    <div class="depo-capsule-input" id="master-amount-capsule">
      <span class="depo-prefix">Rp</span>
      <input type="number" id="master-amount-input" class="depo-amount-field" min="<?= $min_deposit ?>" step="any" placeholder="<?= number_format($min_deposit, 0, '', '') ?>" oninput="handleManualInput(this)" aria-label="Nominal isi saldo">
      <button type="button" class="depo-clear-btn" id="master-amount-clear" onclick="clearAmount()" title="Hapus"><i class="ph-bold ph-x"></i></button>
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

    <?php if ($u_enabled): ?>
    <div class="depo-u-pill">
      <i class="ph-fill ph-lightning"></i>
      <span id="u-code-txt">Kode unik <strong>+Rp <?= $u_start ?></strong> ditambahkan otomatis (naik kalau sudah dipakai).</span>
    </div>
    <?php endif; ?>

    <?php if ($qris_enabled && !empty($qris_raw)): ?>
    <div class="depo-form-box active" id="form-box-qris">
      <form method="POST" id="form-qris-submit" onsubmit="return validateDepoSubmit(event, 'qris')">
        <?= csrf_field() ?>
        <input type="hidden" name="form_token" value="<?= htmlspecialchars($_form_token) ?>">
        <input type="hidden" name="action" value="submit_qris">
        <input type="hidden" name="amount" id="qris-hidden-amount" value="<?= (int)$min_deposit ?>">
        <button type="submit" class="depo-action-btn g-btn shine no-dbl-submit" id="btn-submit-qris">
          <img src="/assets/qris.png?v=3" alt="QRIS" class="qris-logo"><span>Lanjut Bayar</span><i class="ph-bold ph-arrow-right"></i>
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
        <button type="submit" class="depo-action-btn g-btn sky no-dbl-submit" id="btn-submit-bank"><i class="ph-bold ph-paper-plane-tilt"></i><span>Kirim Bukti Transfer</span></button>
      </form>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <!-- Riwayat -->
  <?php if (!empty($deps)): ?>
  <section class="g-panel" data-rv style="--d:.15s">
    <div class="g-sec"><span class="g-ribbon"><img src="/assets/img/icons3d/receipt.png" alt="">Riwayat</span><a href="/history" class="g-chip" style="text-decoration:none">Semua ›</a></div>
    <div class="dp-hist">
      <?php foreach (array_slice($deps, 0, 4) as $d):
        $st = strtolower($d['status'] ?? 'pending');
        $bclass = 'error';
        if ($st === 'pending') $bclass = 'pending';
        elseif ($st === 'confirmed' || $st === 'approved') $bclass = 'confirmed';
        elseif ($st === 'rejected') $bclass = 'rejected'; ?>
        <div class="dp-row">
          <span class="g-slot"><img src="<?= $d['method'] === 'qris' ? '/assets/qris.png?v=3' : '/assets/img/icons3d/barn.png' ?>" alt=""></span>
          <div class="t"><b><?= format_rp((float)$d['amount']) ?></b><small><?= strtoupper($d['method']) ?> · <?= date('d M, H:i', strtotime($d['created_at'])) ?></small></div>
          <div class="r">
            <span class="depo-badge-mini <?= $bclass ?>"><?= ucfirst($d['status']) ?></span>
            <?php if ($st === 'pending' && $d['method'] === 'qris'): ?><a href="/pay?id=<?= $d['id'] ?>" class="g-btn gold sm" style="min-height:28px;font-size:12px">Bayar</a><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

</div>

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
    lbl.style.color = '#059669';
    lbl.textContent = formatRp(val);
  } else if (val > 0) {
    lbl.style.color = '#dc2626';
    lbl.textContent = 'Min. ' + formatRp(minDeposit);
  } else {
    lbl.style.color = 'var(--reef-muted)';
    lbl.textContent = 'Pilih atau ketik nominal';
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
