<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$user = require_auth($pdo);

$dep_id = (int)($_GET['id'] ?? 0);
if (!$dep_id) redirect('/deposit');

$dep = $pdo->prepare("SELECT * FROM deposits WHERE id=? AND user_id=?");
$dep->execute([$dep_id, $user['id']]);
$dep = $dep->fetch();
if (!$dep || $dep['method'] !== 'qris') redirect('/deposit');

// ── AJAX: check_status — HARUS sebelum redirect confirmed ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pdo_reconnect($pdo);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_status') {
    header('Content-Type: application/json; charset=utf-8');
    $st = $pdo->prepare("SELECT status, admin_note, proof_image FROM deposits WHERE id=? AND user_id=?");
    $st->execute([$dep_id, $user['id']]);
    $row = $st->fetch() ?: ['status' => 'rejected', 'admin_note' => '', 'proof_image' => null];
    echo json_encode([
        'status'    => $row['status'],
        'confirmed' => $row['status'] === 'confirmed',
        'rejected'  => $row['status'] === 'rejected',
        'proof'     => !empty($row['proof_image']),
    ]);
    exit;
}

// ── PHP Proxy: download QR image (avoid exposing external URL to browser) ──
if (($_GET['action'] ?? '') === 'dl_qr') {
    $qris_raw_dl = $_ENV['QRIS_RAW'] ?? '';
    $qris_str_dl = !empty($qris_raw_dl) ? qris_with_amount($qris_raw_dl, (int)(float)$dep['amount']) : '';
    if (!$qris_str_dl) { http_response_code(404); exit('QR not available'); }
    $remote = 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($qris_str_dl);
    $img    = @file_get_contents($remote);
    if (!$img) { http_response_code(502); exit('Failed to generate QR'); }
    header('Content-Type: image/png');
    header('Content-Disposition: attachment; filename="QRIS-PanenCuan-dep' . $dep_id . '.png"');
    header('Content-Length: ' . strlen($img));
    header('Cache-Control: no-store');
    echo $img;
    exit;
}

if ($dep['status'] === 'confirmed') redirect('/history');

$qris_raw     = $_ENV['QRIS_RAW'] ?? '';
$confirm_mode = setting($pdo, 'deposit_confirm_mode', 'manual');
$amount       = (float)$dep['amount'];
$qris_str     = !empty($qris_raw) ? qris_with_amount($qris_raw, (int)$amount) : '';
$_favicon     = setting($pdo, 'favicon_path', '');
$fav_url      = $_favicon ? '/' . ltrim($_favicon, '/') : '';

// Upload bukti
$flash = $flashType = '';
$is_ajax = ($_POST['ajax'] ?? '') === '1';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_proof') {
    if ($dep['status'] !== 'pending') {
        $flash = 'Deposit ini sudah tidak menunggu pembayaran.'; $flashType = 'error';
    } elseif (empty($_FILES['proof']['tmp_name'])) {
        $flash = 'Pilih file bukti pembayaran.'; $flashType = 'error';
    } else {
        $ext = strtolower(pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
            $flash = 'Format harus JPG/PNG/WEBP.'; $flashType = 'error';
        } else {
            $dir = dirname(__DIR__) . '/uploads/deposits/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fname = 'dep_' . $user['id'] . '_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['proof']['tmp_name'], $dir . $fname);
            $pdo->prepare("UPDATE deposits SET proof_image=? WHERE id=?")->execute(['deposits/' . $fname, $dep_id]);
            $flash = 'Bukti pembayaran berhasil diunggah! Admin akan segera memverifikasi.';
            $flashType = 'success';
            
            // Telegram Notif
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'mancingcuan.com';
            $proofUrl = $scheme . '://' . $host . '/uploads/deposits/' . $fname;
            
            $msg = "<b>BUKTI DEPOSIT DIUPLOAD</b>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "User: <code>" . htmlspecialchars($user['username']) . "</code>\n";
            $msg .= "Amount: <code>" . format_rp((float)$dep['amount']) . "</code>\n";
            $msg .= "Bukti: <a href=\"{$proofUrl}\">Klik untuk lihat struk</a>\n";
            $msg .= "━━━━━━━━━━━━━━━━━━━━━━\n";
            $msg .= "<i>Silakan periksa gambar bukti di atas sebelum melakukan tindakan.</i>";
            
            $kb = depo_pending_kb((int)$dep_id);
            
            $tg_msg_id = send_telegram_notif($pdo, $msg, $kb, 'depo');
            if ($tg_msg_id) {
                $pdo->prepare("UPDATE deposits SET tg_msg_id = ? WHERE id = ?")->execute([$tg_msg_id, $dep_id]);
            }
        }
    }
    $dep2 = $pdo->prepare("SELECT * FROM deposits WHERE id=?"); $dep2->execute([$dep_id]); $dep = $dep2->fetch();
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $flashType === 'success', 'message' => $flash]);
        exit;
    }
}

// Cancel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_deposit') {
    if (time() - strtotime($dep['created_at']) >= 60) {
        $pdo->prepare("UPDATE deposits SET status='rejected', admin_note='Dibatalkan oleh Pengguna' WHERE id=? AND user_id=? AND status='pending'")
            ->execute([$dep_id, $user['id']]);
        redirect('/deposit');
    } else {
        $flash = 'Harap tunggu 1 menit sejak deposit dibuat sebelum membatalkan.'; $flashType = 'error';
    }
}

// Countdown sampai deposit expired otomatis (DEPO_EXPIRE_HOURS), tidak reset saat refresh
$created_ts       = strtotime($dep['created_at']);
$expire_total     = DEPO_EXPIRE_HOURS * 3600;
$expire_secs      = max(0, $expire_total - (time() - $created_ts));
$cancel_secs_left = max(0, 60   - (time() - $created_ts));   // sisa cooldown batal

$qr_url      = !empty($qris_str)
    ? 'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($qris_str)
    : '';
$qr_dl_url   = '?id=' . $dep_id . '&action=dl_qr';

$pageTitle  = 'Kasir QRIS';
$activePage = 'deposit';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══ PAY — Kasir QRIS · Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.py-timer { display: inline-flex; align-items: center; gap: 4px; margin-top: 6px; padding: 4px 11px; border-radius: 999px; font-family: var(--pc-font); font-size: 14px; font-weight: 700; color: #fff; background: linear-gradient(180deg, #fb7185, #e11d48); box-shadow: 0 3px 0 #9f1239; }
.py-timer i { animation: pyFlip 2s ease-in-out infinite; }
@keyframes pyFlip { 0%, 40% { transform: none; } 50%, 90% { transform: rotate(180deg); } }
.py-flash { display: flex; align-items: flex-start; gap: 8px; padding: 9px 12px; border-radius: 16px; font-size: 12px; font-weight: 800; line-height: 1.4; }
.py-flash i { font-size: 17px; flex-shrink: 0; }
.py-flash.success { background: #dcfce7; color: #15803d; box-shadow: 0 0 0 2px #86efac; }
.py-flash.error { background: #ffe4e6; color: #9f1239; box-shadow: 0 0 0 2px #fecdd3; }

/* Kartu hasil (sukses / ditolak / verifikasi) */
.py-res { text-align: center; padding: 18px 16px 16px; }
.py-res img { display: block; width: 92px; height: 92px; margin: 0 auto 4px; object-fit: contain; filter: drop-shadow(0 8px 10px rgba(120, 70, 10, .3)); animation: gBob 2.6s ease-in-out infinite; }
.py-res h2 { margin: 0; font-family: var(--pc-font); font-size: 21px; font-weight: 700; color: var(--pc-ink); }
.py-res p { margin: 5px 0 14px; font-size: 12.5px; font-weight: 700; line-height: 1.5; color: var(--pc-muted); }
.py-res p strong { color: #15803d; }

/* Kartu QRIS */
.py-amt { display: flex; align-items: center; gap: 10px; }
.py-amt .t { flex: 1; min-width: 0; }
.py-amt small { display: block; font-size: 11px; font-weight: 900; color: var(--pc-muted); }
.py-amt b { display: block; font-family: var(--pc-font); font-size: 28px; font-weight: 700; line-height: 1.1; color: #15803d; text-shadow: 0 2px 0 #dcfce7; }
.py-amt .u { display: block; margin-top: 2px; font-size: 11px; font-weight: 800; color: #b45309; }
.pay-copy-btn.copied { --c1: #4ade80; --c2: #16a34a; }
.py-brand { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin: 12px 0 10px; padding: 7px 10px; border-radius: 12px; background: #f0fdf4; font-size: 11.5px; font-weight: 800; color: #15803d; }
.py-brand span { display: inline-flex; align-items: center; gap: 5px; }
.py-brand img { height: 20px; width: auto; }
.pay-qr-frame { position: relative; width: 236px; max-width: 78%; margin: 0 auto; padding: 12px; border-radius: 22px; background: #fff; box-shadow: 0 0 0 3px #f6e2b0, 0 6px 0 #e6c886, 0 14px 26px rgba(120, 70, 10, .15); }
.pay-qr-frame img { display: block; width: 100%; aspect-ratio: 1; border-radius: 10px; }
.pay-qr-frame::before, .pay-qr-frame::after { content: ''; position: absolute; left: 12px; right: 12px; height: 3px; border-radius: 3px; background: linear-gradient(90deg, transparent, #22c55e, transparent); box-shadow: 0 0 10px #22c55e; animation: pyScan 2.4s ease-in-out infinite; }
.pay-qr-frame::after { display: none; }
@keyframes pyScan { 0%, 100% { top: 14px; opacity: .2; } 50% { top: calc(100% - 17px); opacity: 1; } }
.py-partners { margin-top: 12px; text-align: center; font-size: 10.5px; font-weight: 800; color: var(--pc-muted); }
.py-acts { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }

/* Radar status */
.pay-radar-strip { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 8px 8px 12px; border-radius: 16px; background: #fff; box-shadow: inset 0 0 0 2px #bbf7d0, 0 3px 0 #86efac; }
.pay-radar-left { display: flex; align-items: center; gap: 8px; min-width: 0; font-size: 11.5px; font-weight: 800; color: #15803d; }
.pay-radar-left span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pay-radar-dot { position: relative; width: 10px; height: 10px; flex-shrink: 0; border-radius: 50%; background: #22c55e; }
.pay-radar-dot::after { content: ''; position: absolute; inset: 0; border-radius: 50%; background: #22c55e; animation: pyPing 1.4s ease-out infinite; }
@keyframes pyPing { to { transform: scale(3); opacity: 0; } }
.ph-spin { animation: pySpin .8s linear infinite; display: inline-block; }
@keyframes pySpin { to { transform: rotate(360deg); } }

/* Upload bukti */
.pay-up-card.pulse { animation: pyPulse 1s ease-in-out 3; }
@keyframes pyPulse { 50% { box-shadow: 0 0 0 2px #f6e2b0, 0 0 0 8px rgba(246, 183, 10, .35); } }
.py-up-head { display: flex; align-items: center; gap: 10px; }
.py-up-head b { display: block; font-family: var(--pc-font); font-size: 15px; font-weight: 700; color: var(--pc-ink); }
.py-up-head small { display: block; font-size: 11.5px; font-weight: 700; color: var(--pc-muted); }
.pay-drop { display: flex; align-items: center; gap: 10px; margin-top: 10px; padding: 12px; border-radius: 16px; cursor: pointer; background: #fffaf0; border: 2px dashed #e6c886; }
.pay-drop img { width: 52px; height: 52px; flex-shrink: 0; border-radius: 12px; object-fit: cover; box-shadow: 0 0 0 2px #fff, 0 3px 8px rgba(0, 0, 0, .15); }
.pay-drop-txt { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.pay-drop-txt i { font-size: 22px; color: #c98a00; }
.pay-drop-txt b { font-size: 12.5px; font-weight: 900; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pay-drop-txt small { font-size: 10.5px; font-weight: 800; color: var(--pc-muted); }
.pay-btn-upload { margin-top: 10px; }
.pay-btn-upload:disabled { --c1: #e5e7eb; --c2: #d1d5db; --lip: #9ca3af; --sh: transparent; --tx: #6b7280; text-shadow: none; filter: none; opacity: 1; }

/* Cara bayar */
.pay-acc-header { display: flex; align-items: center; justify-content: space-between; cursor: pointer; }
.pay-acc-header > i { font-size: 16px; color: #c98a00; transition: transform .3s var(--pc-ease); }
.pay-acc-header.open > i { transform: rotate(180deg); }
.pay-acc-content { display: none; flex-direction: column; gap: 8px; margin-top: 10px; }
.pay-acc-content.open { display: flex; animation: pyIn .3s var(--pc-out) both; }
@keyframes pyIn { from { opacity: 0; transform: translateY(-6px); } }
.pay-step-row { display: flex; gap: 10px; align-items: flex-start; font-size: 12px; font-weight: 700; line-height: 1.45; color: var(--pc-ink); }
.pay-step-badge { width: 24px; height: 24px; flex-shrink: 0; border-radius: 8px; display: grid; place-items: center; font-family: var(--pc-font); font-size: 13px; font-weight: 700; color: #4a3000; background: linear-gradient(180deg, #ffd54f, #f6b70a); box-shadow: 0 2px 0 #b07800; }
.pay-btn-cancel { width: 100%; padding: 11px; border: 0; border-radius: 14px; cursor: pointer; font-family: var(--pc-font); font-size: 14px; font-weight: 600; color: #be123c; background: transparent; box-shadow: inset 0 0 0 2px #fecdd3; }
.pay-btn-cancel:disabled { color: #9ca3af; box-shadow: inset 0 0 0 2px #e5e7eb; cursor: not-allowed; }
.pay-up-fab { position: fixed; left: 50%; bottom: calc(96px + env(safe-area-inset-bottom)); z-index: 600; transform: translateX(-50%); animation: pyFab 1.6s var(--pc-ease) infinite; }
@keyframes pyFab { 50% { transform: translateX(-50%) translateY(-4px); } }
.pay-up-fab[hidden] { display: none; }
</style>

<div class="g-page">

  <section class="g-hero" data-rv>
    <span class="g-sun"></span><span class="g-cloud c1"></span><span class="g-cloud c2"></span>
    <div class="g-hero-row">
      <div style="flex:1;min-width:0">
        <h1>Kasir QRIS</h1>
        <p>Deposit #<?= $dep_id ?><?= $dep['status'] === 'pending' ? ' · scan &amp; bayar sebelum waktu habis.' : '' ?></p>
        <?php if ($dep['status'] === 'pending'): ?><span class="py-timer"><i class="ph-fill ph-hourglass-medium"></i><span id="exp-timer">--:--</span></span><?php endif; ?>
      </div>
      <div class="g-hero-art"><img src="/assets/img/icons3d/shop.png" alt=""></div>
    </div>
  </section>

  <?php if ($flash): ?>
  <div class="py-flash <?= $flashType === 'error' ? 'error' : 'success' ?>" data-rv>
    <i class="ph-fill ph-<?= $flashType === 'error' ? 'warning-circle' : 'check-circle' ?>"></i>
    <span><?= htmlspecialchars($flash) ?></span>
  </div>
  <?php endif; ?>

  <?php if ($dep['status'] === 'confirmed'): ?>
  <section class="g-panel py-res" data-rv>
    <img src="/assets/img/icons3d/chest_open.png" alt="">
    <h2>Pembayaran berhasil!</h2>
    <p>Saldo Beli <strong><?= format_rp((float)$amount) ?></strong> sudah masuk dan siap dipakai.</p>
    <a href="/home" class="g-btn full"><i class="ph-bold ph-house"></i>Kembali ke Beranda</a>
  </section>

  <?php elseif ($dep['status'] === 'rejected'): ?>
  <section class="g-panel py-res" data-rv>
    <img src="/assets/img/icons3d/lock.png" alt="">
    <h2>Deposit tidak aktif</h2>
    <p><?= htmlspecialchars($dep['admin_note'] ?: 'Deposit ini sudah tidak menunggu pembayaran.') ?><br>Jangan bayar ke QR ini. Buat deposit baru ya.</p>
    <a href="/deposit" class="g-btn gold full"><i class="ph-bold ph-plus-circle"></i>Buat Deposit Baru</a>
  </section>

  <?php elseif ($dep['proof_image']): ?>
  <section class="g-panel py-res" data-rv>
    <img src="/assets/img/icons3d/receipt.png" alt="">
    <h2>Bukti sedang dicek</h2>
    <p>Struk sudah kami terima dan sedang dicek admin. Halaman ini pindah otomatis begitu saldo masuk.</p>
    <a href="/history" class="g-btn sky full"><i class="ph-bold ph-receipt"></i>Cek Riwayat Transaksi</a>
  </section>
  <div class="pay-radar-strip" data-rv style="--d:.05s">
    <div class="pay-radar-left"><div class="pay-radar-dot"></div><span id="radar-txt">Status diperbarui otomatis</span></div>
    <button id="btn-check-status" onclick="manualCheckStatus()" class="g-btn sm" type="button"><i class="ph-bold ph-arrows-clockwise"></i><span>Cek</span></button>
  </div>

  <?php else: ?>

  <section class="g-panel" data-rv style="--d:.04s">
    <div class="py-amt">
      <span class="g-slot"><img src="/assets/img/icons3d/coins.png" alt=""></span>
      <div class="t">
        <small>Total tagihan</small>
        <b><?= format_rp((float)$amount) ?></b>
        <?php if (setting($pdo, 'depo_unique_code_enabled', '0') === '1'): ?>
          <span class="u">Bayar persis sampai digit terakhir (termasuk kode unik)</span>
        <?php endif; ?>
      </div>
      <button type="button" class="pay-copy-btn g-btn gold sm" id="btn-copy-amt" onclick="copyAmount()"><i class="ph-bold ph-copy"></i><span id="copy-amt-txt">Salin</span></button>
    </div>

    <div class="py-brand"><span><i class="ph-fill ph-shield-check"></i>Standar Pembayaran Nasional</span><img src="/assets/qris.png?v=3" alt="QRIS"></div>

    <?php if ($qr_url): ?>
    <div class="pay-qr-frame"><img id="qr-img" src="<?= htmlspecialchars($qr_url) ?>" alt="QRIS Code"></div>
    <div class="py-partners">DANA · OVO · GoPay · ShopeePay · BCA · Mandiri · BRI · BNI</div>
    <div class="py-acts">
      <a href="<?= htmlspecialchars($qr_dl_url) ?>" class="g-btn sky sm"><i class="ph-bold ph-download-simple"></i>Unduh QR</a>
      <a href="<?= htmlspecialchars($qr_url) ?>" target="_blank" rel="noopener" class="g-btn soft sm"><i class="ph-bold ph-arrows-out-simple"></i>Perbesar</a>
    </div>
    <?php else: ?>
    <div class="py-flash error"><i class="ph-fill ph-warning-circle"></i><span>QRIS belum dikonfigurasi. Hubungi Admin.</span></div>
    <?php endif; ?>
  </section>

  <div class="pay-radar-strip" data-rv style="--d:.08s">
    <div class="pay-radar-left"><div class="pay-radar-dot"></div><span id="radar-txt">Cek otomatis setiap 5 detik</span></div>
    <button id="btn-check-status" onclick="manualCheckStatus()" class="g-btn sm" type="button"><i class="ph-bold ph-arrows-clockwise"></i><span>Cek</span></button>
  </div>

  <?php
  $pending_secs = time() - $created_ts;
  $show_upload  = ($confirm_mode !== 'auto' || $pending_secs >= 300);
  ?>
  <section class="g-panel pay-up-card" id="upload-card" style="<?= $show_upload ? '' : 'display:none;' ?>">
    <div class="py-up-head">
      <span class="g-slot"><img src="/assets/img/icons3d/receipt.png" alt=""></span>
      <div><b>Sudah bayar, saldo belum masuk?</b><small>Kirim screenshot bukti bayar, admin cek manual.</small></div>
    </div>
    <form id="upload-form" method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_proof">
      <label class="pay-drop" id="drop-zone">
        <input type="file" name="proof" id="proof-input" accept="image/*" required hidden>
        <img id="proof-preview" alt="" hidden>
        <span class="pay-drop-txt" id="drop-txt">
          <i class="ph-fill ph-image-square"></i>
          <b>Pilih / foto bukti pembayaran</b>
          <small>JPG, PNG, atau WEBP</small>
        </span>
      </label>
      <button type="submit" class="pay-btn-upload g-btn full" id="btn-upload" disabled><i class="ph-bold ph-paper-plane-tilt"></i> <span>Kirim Bukti Pembayaran</span></button>
    </form>
  </section>

  <section class="g-panel" data-rv style="--d:.12s">
    <div class="pay-acc-header" onclick="toggleAcc('steps')" id="hdr-steps">
      <span class="g-ribbon gold"><img src="/assets/img/icons3d/compass.png" alt="">Cara Bayar</span>
      <i class="ph-bold ph-caret-down"></i>
    </div>
    <div class="pay-acc-content" id="body-steps">
      <div class="pay-step-row"><div class="pay-step-badge">1</div><div>Buka m-Banking (BCA, Mandiri, BRI, BNI) atau e-wallet (DANA, GoPay, OVO, ShopeePay).</div></div>
      <div class="pay-step-row"><div class="pay-step-badge">2</div><div>Pilih menu <strong>Scan QRIS</strong>, arahkan kamera ke kode di atas (atau pilih dari galeri).</div></div>
      <div class="pay-step-row"><div class="pay-step-badge">3</div><div>Konfirmasi pembayaran. Saldo Beli masuk otomatis dalam hitungan detik!</div></div>
    </div>
  </section>

  <form method="POST" style="margin:0" data-rv>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="cancel_deposit">
    <button id="btn-cancel-dep" type="submit" class="pay-btn-cancel">Batalkan Deposit</button>
  </form>

  <button type="button" class="pay-up-fab g-btn gold" id="upload-fab" onclick="goUpload()" hidden><i class="ph-bold ph-upload-simple"></i>Upload Bukti Bayar</button>
  <?php endif; ?>

  <?php if ($dep['status'] === 'pending'): ?>
  <script>
  const DEP_ID      = <?= $dep_id ?>;
  const CSRF_TOK    = '<?= csrf_token() ?>';
  const EXPIRE_SECS = <?= $expire_secs ?>;
  const EXPIRE_TOTAL = <?= $expire_total ?>;
  const RAW_AMOUNT  = <?= (int)$amount ?>;
  const HAS_PROOF   = <?= $dep['proof_image'] ? 'true' : 'false' ?>;
  let isChecking    = false;

  function toggleAcc(id) {
    const b = document.getElementById('body-' + id);
    const h = document.getElementById('hdr-' + id);
    if (!b || !h) return;
    b.classList.toggle('open');
    h.classList.toggle('open');
  }

  function copyAmount() {
    const textToCopy = RAW_AMOUNT.toString();
    const btn = document.getElementById('btn-copy-amt');
    const txt = document.getElementById('copy-amt-txt');
    function onCopied() {
      if (btn) btn.classList.add('copied');
      if (txt) txt.textContent = '✓ Tersalin!';
      if (typeof nToast !== 'undefined' && nToast.copy) nToast.copy(textToCopy, 'Nominal Transfer');
      else if (typeof nToast === 'function') nToast('Nominal Rp ' + Number(textToCopy).toLocaleString('id-ID') + ' disalin!', 'success');
      setTimeout(() => { if (btn) btn.classList.remove('copied'); if (txt) txt.textContent = 'Salin'; }, 2000);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(textToCopy).then(onCopied).catch(onCopied);
    else onCopied();
  }

  // ── Hitung mundur sampai expired ─────────────────────────────────────────
  const startedAt = Date.now();
  const timerEl = document.getElementById('exp-timer');
  const secsLeft = () => Math.max(0, EXPIRE_SECS - Math.floor((Date.now() - startedAt) / 1000));
  function updateExpTimer() {
    const s = secsLeft();
    if (!timerEl) return;
    if (s <= 0) { timerEl.textContent = 'Kedaluwarsa'; return; }
    const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), r = s % 60;
    timerEl.textContent = (h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(r).padStart(2, '0');
  }

  // ── Form upload: tampil setelah 5 menit, langsung terlihat ──────────────
  const upCard = document.getElementById('upload-card');
  const fab = document.getElementById('upload-fab');
  let upShownLive = false;
  function showUpload(announce) {
    if (!upCard || upCard.style.display !== 'none') return;
    upCard.style.display = '';
    upShownLive = true;
    if (announce) {
      upCard.classList.add('pulse');
      upCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
      if (typeof nToast === 'function') nToast('Belum terdeteksi? Kamu bisa kirim bukti bayar sekarang.', 'info');
      setTimeout(() => upCard.classList.remove('pulse'), 4000);
    }
    watchFab();
  }
  function goUpload() {
    if (!upCard) return;
    upCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
    upCard.classList.add('pulse');
    setTimeout(() => upCard.classList.remove('pulse'), 2500);
  }
  function watchFab() {
    if (!fab || !upCard || !('IntersectionObserver' in window)) return;
    new IntersectionObserver(([e]) => { fab.hidden = e.isIntersecting || upCard.style.display === 'none'; }, { threshold: 0.25 }).observe(upCard);
  }
  if (upCard && upCard.style.display !== 'none') watchFab();

  function tick() {
    updateExpTimer();
    if (EXPIRE_TOTAL - secsLeft() >= 300) showUpload(true);
  }
  tick();
  const expTimer = setInterval(tick, 1000);

  // Pilih file → pratinjau + aktifkan tombol
  const proofInput = document.getElementById('proof-input');
  if (proofInput) proofInput.addEventListener('change', () => {
    const f = proofInput.files[0];
    const prev = document.getElementById('proof-preview');
    const txt = document.getElementById('drop-txt');
    document.getElementById('btn-upload').disabled = !f;
    if (!f) return;
    prev.src = URL.createObjectURL(f);
    prev.hidden = false;
    txt.innerHTML = '<b>' + f.name.replace(/[<>&]/g, '') + '</b><small>Ketuk untuk ganti foto</small>';
  });

  // Kirim bukti di latar belakang (tanpa reload)
  const upForm = document.getElementById('upload-form');
  if (upForm) upForm.addEventListener('submit', e => {
    e.preventDefault();
    const btn = document.getElementById('btn-upload');
    const fd = new FormData(upForm);
    fd.append('ajax', '1');
    btn.disabled = true;
    btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> <span>Mengirim…</span>';
    fetch('?id=' + DEP_ID, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(d => {
        if (typeof nToast === 'function') nToast(d.message || (d.ok ? 'Terkirim' : 'Gagal mengirim'), d.ok ? 'success' : 'error');
        if (d.ok) setTimeout(() => location.reload(), 900);
        else { btn.disabled = false; btn.innerHTML = '<i class="ph-bold ph-paper-plane-tilt"></i> <span>Kirim Bukti Pembayaran</span>'; }
      })
      .catch(() => {
        if (typeof nToast === 'function') nToast('Gagal mengirim, cek koneksi lalu coba lagi.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="ph-bold ph-paper-plane-tilt"></i> <span>Kirim Bukti Pembayaran</span>';
      });
  });

  // ── Cek status otomatis di latar belakang ───────────────────────────────
  const radarTxt = document.getElementById('radar-txt');
  function checkStatus() {
    return fetch('?id=' + DEP_ID, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: '_csrf=' + encodeURIComponent(CSRF_TOK) + '&action=check_status',
      cache: 'no-store'
    }).then(r => r.json()).then(d => {
      if (radarTxt) radarTxt.textContent = 'Dicek otomatis · ' + new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
      if (d.confirmed) confirmAndRedirect();
      else if (d.rejected) { stopAll(); location.reload(); }
      else if (d.proof && !HAS_PROOF) { stopAll(); location.reload(); }
      return d;
    });
  }
  let pollTimer = setInterval(() => { if (!isChecking) checkStatus().catch(() => {}); }, 5000);
  // Kembali ke tab / layar menyala → langsung cek
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { tick(); checkStatus().catch(() => {}); } });
  window.addEventListener('focus', () => checkStatus().catch(() => {}));

  function stopAll() { clearInterval(pollTimer); clearInterval(expTimer); }
  function confirmAndRedirect() {
    stopAll();
    if (timerEl) timerEl.textContent = 'Sukses';
    if (typeof nToast === 'function') nToast('Pembayaran berhasil dikonfirmasi!', 'success');
    setTimeout(() => location.href = '/history?tab=deposit', 1000);
  }

  function manualCheckStatus() {
    if (isChecking) return;
    isChecking = true;
    const btn = document.getElementById('btn-check-status');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Cek...';
    checkStatus().then(d => {
      if (!d.confirmed && !d.rejected && typeof nToast === 'function') nToast('Pembayaran belum terdeteksi. Silakan coba sesaat lagi.', 'warn');
    }).catch(() => {
      if (typeof nToast === 'function') nToast('Gagal menghubungi server.', 'error');
    }).finally(() => { isChecking = false; btn.disabled = false; btn.innerHTML = orig; });
  }

  let cancelSecs = <?= $cancel_secs_left ?>;
  const cancelBtn = document.getElementById('btn-cancel-dep');
  if (cancelBtn && cancelSecs > 0) {
    cancelBtn.disabled = true;
    cancelBtn.textContent = 'Tunggu ' + cancelSecs + 's untuk membatalkan';
    const ci = setInterval(() => {
      cancelSecs--;
      cancelBtn.textContent = cancelSecs > 0 ? 'Tunggu ' + cancelSecs + 's untuk membatalkan' : 'Batalkan Deposit';
      if (cancelSecs <= 0) { clearInterval(ci); cancelBtn.disabled = false; }
    }, 1000);
  }
  </script>
  <?php endif; ?>
</div>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
