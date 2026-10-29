<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Fetch membership info including allow_edit_bank
$user_mem = null;
$membership_active = $user['membership_id']
    && $user['membership_expires_at']
    && strtotime((string)$user['membership_expires_at']) > time();

if ($membership_active) {
    $stmt = $pdo->prepare("SELECT name, allow_edit_bank FROM memberships WHERE id=? AND is_active=1");
    $stmt->execute([$user['membership_id']]);
    $user_mem = $stmt->fetch() ?: null;
}
if (!$user_mem) {
    $stmt = $pdo->prepare("SELECT name, allow_edit_bank FROM memberships WHERE price=0 AND is_active=1 ORDER BY sort_order ASC LIMIT 1");
    $stmt->execute();
    $user_mem = $stmt->fetch() ?: null;
}

$can_edit_bank     = (bool)($user_mem['allow_edit_bank'] ?? 0);
$level_name        = $user_mem['name'] ?? 'Free';

// Promotor bypass: skip semua pembatasan level
$is_promotor = ((int)($user['is_promotor'] ?? 0) === 1);
if ($is_promotor) {
    $can_edit_bank   = true;
}

$min_saldo_edit = (float)($user['edit_bank_deposit_min'] ?? 0);
if ($min_saldo_edit <= 0) {
    $min_saldo_edit = 50000;
}
$has_enough_balance = $is_promotor || ((float)$user['balance_dep'] >= $min_saldo_edit);

$flash = $flashType = '';

// Cek apakah ada request ganti rekening yang masih pending
$stmtPending = $pdo->prepare("SELECT id FROM admin_requests WHERE user_id=? AND type='change_bank' AND status='pending'");
$stmtPending->execute([$user['id']]);
$has_pending_bank = (bool)$stmtPending->fetchColumn();

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($has_pending_bank) {
        $flash = 'Data pengajuan ganti rekening kamu sebelumnya masih dalam proses verifikasi admin.'; 
        $flashType = 'warn';
    } elseif (!$can_edit_bank) {
        $flash = 'Level akun kamu belum memiliki izin untuk mengubah data rekening.'; 
        $flashType = 'error';
    } elseif (!$has_enough_balance) {
        $flash = 'Kamu harus memiliki Saldo Beli minimal ' . format_rp($min_saldo_edit) . ' yang mengendap untuk mengubah rekening.'; 
        $flashType = 'error';
    } else {
        $new_bank    = trim($_POST['bank_name']      ?? '');
        $new_accnum  = trim($_POST['account_number'] ?? '');
        $new_accname = trim($_POST['account_name']   ?? '');

        if (!$new_bank || !$new_accnum || !$new_accname) {
            $flash = 'Semua kolom formulir wajib diisi dengan lengkap.'; 
            $flashType = 'error';
        } else {
            $payload = json_encode(['bank_name' => $new_bank, 'account_number' => $new_accnum, 'account_name' => $new_accname]);
            $pdo->prepare("INSERT INTO admin_requests (user_id, type, payload) VALUES (?, 'change_bank', ?)")
                ->execute([$user['id'], $payload]);
            $req_id = $pdo->lastInsertId();
            
            $msg  = "🏦 <b>REQUEST GANTI REKENING</b>\n\n";
            $msg .= "👤 User: <code>{$user['username']}</code>\n";
            $msg .= "💳 Rekening Baru:\n";
            $msg .= "- Bank: <b>{$new_bank}</b>\n";
            $msg .= "- No. Rek: <code>{$new_accnum}</code>\n";
            $msg .= "- A.N: <b>{$new_accname}</b>\n";
            $kb = [
                [['text'=>'Approve', 'callback_data'=>'req_approve_'.$req_id], ['text'=>'Reject', 'callback_data'=>'req_reject_'.$req_id]]
            ];
            tg_store_msg_id($pdo, 'admin_requests', (int)$req_id, send_telegram_notif($pdo, $msg, $kb, 'permintaan'));
            
            $flash = 'Pengajuan perubahan rekening baru berhasil dikirim dan sedang diverifikasi admin!';
            $flashType = 'success';
            $has_pending_bank = true;
        }
    }
}

$has_bank = !empty($user['bank_name']) && !empty($user['account_number']) && !empty($user['account_name']);

// Load available payment channels
$channels = $pdo->query("SELECT name, type, logo FROM payment_channels WHERE is_active=1 ORDER BY type ASC, sort_order ASC, name ASC")->fetchAll();
$channel_logos = [];
foreach ($channels as $c) {
    if (!empty($c['logo'])) $channel_logos[strtolower($c['name'])] = $c['logo'];
}
$banks    = array_filter($channels, fn($c) => $c['type'] === 'bank');
$ewallets = array_filter($channels, fn($c) => $c['type'] === 'ewallet');

$pageTitle  = 'Edit Rekening';
$activePage = 'profile';
require dirname(__DIR__) . '/partials/header.php';
$rk_initials = static function (string $n): string {
    $n = preg_replace('/[^A-Za-z0-9 ]/', '', $n);
    $parts = preg_split('/\s+/', trim($n)) ?: [];
    return strtoupper(count($parts) > 1 ? $parts[0][0] . $parts[1][0] : substr($n, 0, 3));
};
$rk_tones = ['#0891b2', '#7c3aed', '#e11d48', '#059669', '#ea580c', '#2563eb'];
$rk_tone  = static fn(string $n): string => $rk_tones[abs(crc32(strtolower($n))) % count($rk_tones)];
$user_wl  = $channel_logos[strtolower((string)($user['bank_name'] ?? ''))] ?? null;
$rk_state = $has_pending_bank ? 'pending' : (!$can_edit_bank ? 'locked' : (!$has_enough_balance ? 'saldo' : 'open'));
// Pangkat yang diizinkan ganti rekening (untuk ditampilkan sebagai aturan)
try {
    $rk_allowed = $pdo->query("SELECT name FROM memberships WHERE is_active = 1 AND allow_edit_bank = 1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable) { $rk_allowed = []; }
$rk_level_ok = (bool)($user_mem['allow_edit_bank'] ?? 0);
$rk_saldo_ok = (float)$user['balance_dep'] >= $min_saldo_edit;
$rk_pct      = $min_saldo_edit > 0 ? min(100, max(0, ((float)$user['balance_dep'] / $min_saldo_edit) * 100)) : 0;
$rk_flash_tone = $flashType === 'error' ? 'err' : ($flashType === 'warn' ? 'warn' : 'ok');
?>

<style>
/* ══ EDIT REKENING — Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.rk-chip { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 900; }
.rk-chip.ok { color: #14532d; background: #dcfce7; } .rk-chip.no { color: #9a3412; background: #ffedd5; }

/* Kartu rekening */
.rk-acc { position: relative; overflow: hidden; display: flex; align-items: center; gap: 11px; min-height: 76px; padding: 12px 13px; border-radius: 18px; color: #fff;
  background: radial-gradient(circle at 95% 120%, rgba(255, 230, 120, .55) 0, transparent 45%), linear-gradient(135deg, #15803d 0%, #22c55e 60%, #4ade80 100%);
  box-shadow: 0 4px 0 #11622f, 0 10px 18px rgba(22, 163, 74, .25), inset 0 2px 0 rgba(255, 255, 255, .35); }
.rk-acc::after { content: ''; position: absolute; right: -20px; top: -24px; width: 92px; height: 92px; border-radius: 50%; border: 12px solid rgba(255, 255, 255, .1); }
.rk-acc.new { background: radial-gradient(circle at 95% 120%, rgba(255, 255, 255, .45) 0, transparent 45%), linear-gradient(135deg, #f6b70a 0%, #ffd54f 70%, #ffe58a 100%); color: #4a3000; box-shadow: 0 4px 0 #b07800, 0 10px 18px rgba(246, 183, 10, .25), inset 0 2px 0 rgba(255, 255, 255, .5); }
.rk-acc.empty { background: linear-gradient(135deg, #94a3b8, #64748b); box-shadow: 0 4px 0 #475569; }
.rk-logo { position: relative; z-index: 1; width: 46px; height: 46px; flex-shrink: 0; border-radius: 14px; display: flex; align-items: center; justify-content: center; background: #fff; font-size: 12px; font-weight: 900; box-shadow: 0 3px 0 rgba(0, 0, 0, .15); }
.rk-logo img { width: 32px; height: 32px; object-fit: contain; }
.rk-acc-tx { position: relative; z-index: 1; flex: 1; min-width: 0; line-height: 1.2; }
.rk-acc-tx small { display: block; font-size: 10.5px; font-weight: 900; opacity: .9; }
.rk-acc-tx b { display: block; margin: 2px 0 1px; font-family: var(--pc-font); font-size: 17px; font-weight: 700; letter-spacing: .4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rk-acc-tx span { display: block; font-size: 11.5px; font-weight: 800; opacity: .92; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.rk-note { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; border-radius: 16px; font-size: 12px; font-weight: 800; line-height: 1.4; }
.rk-note i { font-size: 18px; flex-shrink: 0; }
.rk-note b { display: block; font-size: 12.5px; font-weight: 900; }
.rk-note.err  { background: #ffe4e6; color: #9f1239; box-shadow: inset 0 0 0 2px #fecdd3; }
.rk-note.warn { background: #fff3c4; color: #7a4f00; box-shadow: inset 0 0 0 2px #fde68a; }
.rk-note.ok   { background: #dcfce7; color: #14532d; box-shadow: inset 0 0 0 2px #bbf7d0; }

/* Syarat */
.rk-rules { display: flex; flex-direction: column; gap: 8px; }
.rk-rules-h { display: flex; align-items: center; justify-content: space-between; gap: 6px; flex-wrap: wrap; }
.rk-rule { display: flex; align-items: center; gap: 10px; padding: 8px 9px; border-radius: 15px; background: #fff; box-shadow: inset 0 0 0 1.5px #f1dfb4; }
.rk-rule .tx { flex: 1; min-width: 0; line-height: 1.3; }
.rk-rule b { display: block; font-size: 12.5px; font-weight: 900; color: var(--pc-ink); }
.rk-rule span { display: block; font-size: 11px; font-weight: 700; color: var(--pc-muted); }
.rk-rule .g-btn { min-height: 32px; padding: 0 10px; font-size: 12.5px; border-radius: 11px; flex-shrink: 0; }
.rk-rule .g-bar { height: 8px; margin: 5px 0 3px; }

/* Form */
.rk-form { display: flex; flex-direction: column; }
.rk-step { display: flex; align-items: center; gap: 8px; margin: 0 2px 8px; font-family: var(--pc-font); font-size: 15px; font-weight: 600; color: #4a2b00; }
.rk-step em { width: 24px; height: 24px; border-radius: 50%; font-style: normal; font-size: 12.5px; color: #4a3000; display: inline-grid; place-items: center; background: linear-gradient(180deg, #ffd54f, #f6b70a); box-shadow: 0 2px 0 #b07800; }
.rk-step.sp { margin-top: 14px; }
.rk-tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 4px; padding: 4px; margin-bottom: 10px; border-radius: 14px; background: #f2e6c8; box-shadow: inset 0 2px 3px rgba(120, 70, 10, .15); }
.rk-tab { min-height: 36px; border: 0; border-radius: 11px; background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 5px; font-family: var(--pc-font); font-size: 14px; font-weight: 600; color: #8a6a3a; }
.rk-tab.on { color: #fff; background: linear-gradient(180deg, #22c55e, #15803d); box-shadow: 0 3px 0 #11622f; }
.rk-chs { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 7px; }
.rk-chs[hidden] { display: none; }
.rk-ch { position: relative; min-width: 0; padding: 8px 2px 6px; border: 0; border-radius: 15px; background: #fff; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 4px;
  font: 800 10.5px/1.1 'Nunito', sans-serif; color: var(--pc-ink); box-shadow: inset 0 0 0 2px #f1dfb4, 0 3px 0 #ecd59c; transition: transform .15s var(--pc-ease); }
.rk-ch:active { transform: scale(.93); }
.rk-ch .lg { width: 34px; height: 34px; border-radius: 11px; display: flex; align-items: center; justify-content: center; }
.rk-ch .lg img { width: 28px; height: 28px; object-fit: contain; }
.rk-ch .mono { color: #fff; font-size: 11px; font-weight: 900; }
.rk-ch span { max-width: 100%; padding: 0 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rk-ch.on { transform: translateY(-2px); box-shadow: inset 0 0 0 2.5px #22c55e, 0 4px 0 #15803d, 0 8px 14px rgba(22, 163, 74, .2); }
.rk-ch.on::after { content: '\2713'; position: absolute; top: -6px; right: -6px; width: 19px; height: 19px; border-radius: 50%; display: grid; place-items: center; font-size: 11px; color: #fff; background: #16a34a; box-shadow: 0 0 0 2px #fff; }
.rk-in { display: flex; align-items: center; gap: 8px; height: 46px; padding: 0 11px 0 8px; border-radius: 15px; background: #fff; box-shadow: inset 0 0 0 2px #f1dfb4; transition: box-shadow .15s; }
.rk-in + .rk-in { margin-top: 8px; }
.rk-in:focus-within { box-shadow: inset 0 0 0 2px #22c55e, 0 0 0 4px rgba(34, 197, 94, .15); }
.rk-in.bad { box-shadow: inset 0 0 0 2px #f43f5e, 0 0 0 4px rgba(244, 63, 94, .15); }
.rk-in > i { width: 30px; height: 30px; flex-shrink: 0; border-radius: 10px; font-size: 15px; color: #15803d; background: #dcfce7; }
.rk-in input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; font: 800 14px/1 'Nunito', sans-serif; color: var(--pc-ink); }
.rk-in input::placeholder { color: #a3a89a; font-weight: 700; font-size: 13px; }
.rk-in input:disabled { cursor: not-allowed; }
.rk-hint { margin: 6px 4px 0; font-size: 11px; font-weight: 700; color: var(--pc-muted); }
.rk-prev-l { display: flex; align-items: center; gap: 6px; margin: 14px 2px 7px; font-size: 11.5px; font-weight: 900; color: #8a5a00; }
.rk-go { margin-top: 14px; }

/* Konfirmasi */
.cg-modal { display: none; position: fixed; inset: 0; z-index: 10000; align-items: flex-end; justify-content: center; background: rgba(40, 25, 5, .5); backdrop-filter: blur(4px); }
.cg-modal-box { width: 100%; max-width: 440px; padding: 10px 16px calc(16px + env(safe-area-inset-bottom)); border-radius: 26px 26px 0 0; background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 -10px 30px rgba(40, 25, 5, .25); animation: rkUp .35s var(--pc-out); }
@keyframes rkUp { from { transform: translateY(100%); } }
.cg-grab { width: 40px; height: 5px; margin: 0 auto 12px; border-radius: 5px; background: #ecd59c; }
.cg-t { margin-bottom: 10px; text-align: center; font-family: var(--pc-font); font-size: 18px; font-weight: 700; color: #4a2b00; }
.cg-info { display: flex; align-items: flex-start; gap: 6px; margin-top: 10px; padding: 9px 11px; border-radius: 13px; background: #dcfce7; font-size: 11.5px; font-weight: 800; color: #14532d; }
.cg-modal-actions { display: flex; gap: 8px; margin-top: 12px; }
.cg-modal-actions .g-btn { flex: 1; }
.cg-modal-actions .cg-btn-confirm { flex: 1.6; }
</style>

<div class="g-page">

  <section class="g-hero" data-rv>
    <span class="g-sun"></span><span class="g-cloud c1"></span><span class="g-cloud c2"></span>
    <div class="g-hero-row">
      <div style="flex:1;min-width:0">
        <h1>Rekening Withdraw</h1>
        <p>Tujuan pencairan saldomu.</p>
        <div style="margin-top:8px">
          <?php if ($has_bank): ?><span class="rk-chip ok"><i class="ph-fill ph-shield-check"></i>Terhubung</span><?php else: ?><span class="rk-chip no"><i class="ph-bold ph-warning"></i>Belum diatur</span><?php endif; ?>
        </div>
      </div>
      <div class="g-hero-art"><img src="/assets/img/icons3d/piggy.png" alt=""></div>
    </div>
  </section>

  <!-- Rekening aktif -->
  <div class="rk-acc <?= $has_bank ? '' : 'empty' ?>" data-rv style="--d:.05s">
    <span class="rk-logo" style="color:<?= $has_bank ? $rk_tone((string)$user['bank_name']) : '#64748b' ?>">
      <?php if ($user_wl): ?><img src="/assets/banks/<?= htmlspecialchars($user_wl) ?>" alt="<?= htmlspecialchars((string)$user['bank_name']) ?>">
      <?php elseif ($has_bank): ?><?= htmlspecialchars($rk_initials((string)$user['bank_name'])) ?>
      <?php else: ?><i class="ph-bold ph-bank" style="font-size:20px"></i><?php endif; ?>
    </span>
    <div class="rk-acc-tx">
      <small>Rekening aktif</small>
      <?php if ($has_bank): ?>
        <b><?= htmlspecialchars((string)$user['bank_name']) ?> · <?= htmlspecialchars(mask_account((string)$user['account_number'])) ?></b>
        <span>a.n. <?= htmlspecialchars((string)$user['account_name']) ?></span>
      <?php else: ?>
        <b>Belum diatur</b><span>Isi formulir di bawah</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($flash && !($has_pending_bank && $flashType === 'warn')): ?>
    <div class="rk-note <?= $rk_flash_tone ?>"><i class="ph-fill <?= $rk_flash_tone === 'err' ? 'ph-x-circle' : ($rk_flash_tone === 'warn' ? 'ph-clock' : 'ph-check-circle') ?>"></i><span><?= htmlspecialchars($flash) ?></span></div>
  <?php endif; ?>

  <!-- Syarat ganti rekening -->
  <div class="g-panel rk-rules" data-rv style="--d:.1s">
    <div class="rk-rules-h">
      <span class="g-ribbon gold"><img src="/assets/img/icons3d/lock.png" alt="">Syarat ganti</span>
      <?php if ($is_promotor): ?><span class="rk-chip ok">Promotor · bebas syarat</span><?php endif; ?>
    </div>

    <div class="rk-rule">
      <span class="g-slot sm"><img src="/assets/img/icons3d/upgrade.png" alt=""><?php if ($rk_level_ok || $is_promotor): ?><span class="g-ok"><i class="ph-bold ph-check"></i></span><?php endif; ?></span>
      <div class="tx"><b>Pangkat yang diizinkan</b>
        <span><?= $rk_allowed ? htmlspecialchars(implode(', ', $rk_allowed)) : 'Belum ada pangkat yang diizinkan' ?> · kamu: <?= htmlspecialchars($level_name) ?></span></div>
      <?php if (!$rk_level_ok && !$is_promotor): ?><a href="/upgrade" class="g-btn gold">Level Up</a><?php endif; ?>
    </div>

    <div class="rk-rule">
      <span class="g-slot sm"><img src="/assets/img/icons3d/coins.png" alt=""><?php if ($rk_saldo_ok || $is_promotor): ?><span class="g-ok"><i class="ph-bold ph-check"></i></span><?php endif; ?></span>
      <div class="tx"><b>Saldo Beli min. <?= format_rp($min_saldo_edit) ?></b>
        <div class="g-bar <?= $rk_saldo_ok ? '' : 'gold' ?>"><i style="width:<?= round($rk_pct, 1) ?>%"></i></div>
        <span>Saldo beli kamu <?= format_rp((float)$user['balance_dep']) ?></span></div>
      <?php if (!$rk_saldo_ok && !$is_promotor): ?><a href="/deposit" class="g-btn">Deposit</a><?php endif; ?>
    </div>

    <div class="rk-rule">
      <span class="g-slot sm"><img src="/assets/img/icons3d/receipt.png" alt=""></span>
      <div class="tx"><b>Dicek admin</b><span>Satu pengajuan dalam satu waktu, nama harus sesuai pemilik rekening.</span></div>
    </div>
  </div>

  <?php if ($rk_state === 'pending'): ?>
    <div class="rk-note warn"><i class="ph-fill ph-hourglass-medium"></i><span><b>Sedang dicek admin</b>Pengajuan rekening barumu sedang diverifikasi.</span></div>

  <?php elseif ($rk_state === 'locked'): ?>
    <div class="rk-note err"><i class="ph-fill ph-lock-key"></i><span><b>Ganti rekening terkunci</b>Pangkat <?= htmlspecialchars($level_name) ?> belum punya izin ganti rekening.</span></div>

  <?php elseif ($rk_state === 'saldo'): ?>
    <div class="rk-note warn"><i class="ph-fill ph-coins"></i><span><b>Saldo Beli belum cukup</b>Butuh <?= format_rp(max(0, $min_saldo_edit - (float)$user['balance_dep'])) ?> lagi untuk membuka form.</span></div>

  <?php else: ?>
    <form method="POST" id="edit-rek-form" class="g-panel rk-form" novalidate data-rv style="--d:.15s">
      <?= csrf_field() ?>
      <input type="hidden" name="bank_name" id="bank_name" value="">

      <div class="rk-step"><em>1</em>Pilih tujuan</div>
      <div class="rk-tabs" role="tablist">
        <button type="button" class="rk-tab on" data-tab="ewallet" onclick="rkTab(this)"><i class="ph-fill ph-wallet"></i>E-Wallet</button>
        <button type="button" class="rk-tab" data-tab="bank" onclick="rkTab(this)"><i class="ph-fill ph-bank"></i>Bank</button>
      </div>
      <?php foreach (['ewallet' => $ewallets, 'bank' => $banks] as $type => $list): ?>
        <div class="rk-chs" data-group="<?= $type ?>" <?= $type === 'ewallet' ? '' : 'hidden' ?>>
          <?php foreach ($list as $c): $tone = $rk_tone($c['name']); ?>
            <button type="button" class="rk-ch" data-name="<?= htmlspecialchars($c['name']) ?>" data-type="<?= $type ?>" data-logo="<?= htmlspecialchars((string)($c['logo'] ?? '')) ?>" data-tone="<?= $tone ?>" onclick="rkPick(this)">
              <?php if (!empty($c['logo'])): ?><span class="lg"><img src="/assets/banks/<?= htmlspecialchars($c['logo']) ?>" alt=""></span>
              <?php else: ?><span class="lg mono" style="background:<?= $tone ?>"><?= htmlspecialchars($rk_initials($c['name'])) ?></span><?php endif; ?>
              <span><?= htmlspecialchars($c['name']) ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>

      <div class="rk-step sp"><em>2</em>Isi data</div>
      <div class="rk-in" id="w-num"><i class="ph-bold ph-hash" id="ico-num"></i>
        <input type="text" name="account_number" id="account_number" placeholder="Pilih tujuan dulu" inputmode="numeric" autocomplete="off" disabled oninput="rkPreview()"></div>
      <div class="rk-in" id="w-name"><i class="ph-bold ph-user"></i>
        <input type="text" name="account_name" id="account_name" placeholder="Nama pemilik sesuai rekening" autocomplete="off" disabled oninput="rkPreview()"></div>
      <div class="rk-hint" id="rk-hint">Nama harus sama dengan pemilik rekening agar pencairan lancar.</div>

      <div class="rk-prev-l"><i class="ph-bold ph-arrow-down"></i>Rekening baru</div>
      <div class="rk-acc new">
        <span class="rk-logo" id="pv-logo" style="color:#b07800"><i class="ph-bold ph-question" style="font-size:20px"></i></span>
        <div class="rk-acc-tx">
          <small id="pv-type">Belum dipilih</small>
          <b id="pv-num">—</b>
          <span id="pv-name">a.n. —</span>
        </div>
      </div>

      <button type="button" class="g-btn full shine rk-go" id="rk-go" onclick="showConfirm()" disabled><i class="ph-fill ph-paper-plane-tilt"></i>Ajukan perubahan</button>
    </form>
  <?php endif; ?>

</div>

<div id="cg-modal" class="cg-modal" onclick="if(event.target===this)closeConfirm()">
  <div class="cg-modal-box">
    <div class="cg-grab"></div>
    <div class="cg-t">Kirim pengajuan rekening ini?</div>
    <div class="rk-acc new" id="cg-card"></div>
    <div class="cg-info"><i class="ph-fill ph-shield-check" style="font-size:15px"></i><span>Admin akan memverifikasi dulu sebelum rekening diganti.</span></div>
    <div class="cg-modal-actions">
      <button type="button" class="g-btn soft cg-btn-cancel" onclick="closeConfirm()">Batal</button>
      <button type="button" class="g-btn cg-btn-confirm" onclick="submitForm()">Ya, kirim</button>
    </div>
  </div>
</div>

<script>
const rkEsc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
let rkSel = null;

function rkTab(btn) {
  document.querySelectorAll('.rk-tab').forEach(t => t.classList.toggle('on', t === btn));
  document.querySelectorAll('.rk-chs').forEach(g => g.hidden = g.dataset.group !== btn.dataset.tab);
}

function rkPick(btn) {
  document.querySelectorAll('.rk-ch').forEach(c => c.classList.toggle('on', c === btn));
  rkSel = { name: btn.dataset.name, type: btn.dataset.type, logo: btn.dataset.logo, tone: btn.dataset.tone, mono: btn.querySelector('.mono')?.textContent || '' };
  document.getElementById('bank_name').value = rkSel.name;
  const num = document.getElementById('account_number'), nm = document.getElementById('account_name');
  num.disabled = nm.disabled = false;
  const ew = rkSel.type === 'ewallet';
  num.placeholder = ew ? 'No. HP ' + rkSel.name + ' (08xx)' : 'No. rekening ' + rkSel.name;
  nm.placeholder  = ew ? 'Nama pemilik akun ' + rkSel.name : 'Nama sesuai buku tabungan';
  document.getElementById('ico-num').className = 'ph-bold ' + (ew ? 'ph-device-mobile' : 'ph-credit-card');
  document.getElementById('rk-hint').textContent = ew ? 'Pastikan nomor HP sudah terdaftar di ' + rkSel.name + '.' : 'Nama harus sama dengan pemilik rekening ' + rkSel.name + '.';
  num.focus();
  rkPreview();
}

function rkLogoHtml() {
  if (!rkSel) return '<i class="ph-bold ph-question" style="font-size:20px"></i>';
  return rkSel.logo ? '<img src="/assets/banks/' + rkEsc(rkSel.logo) + '" alt="">' : rkEsc(rkSel.mono);
}

function rkPreview() {
  const num = document.getElementById('account_number').value.trim();
  const nm  = document.getElementById('account_name').value.trim();
  const logo = document.getElementById('pv-logo');
  logo.innerHTML = rkLogoHtml();
  logo.style.color = rkSel ? rkSel.tone : '#7c3aed';
  document.getElementById('pv-type').textContent = rkSel ? (rkSel.type === 'ewallet' ? 'E-Wallet · ' : 'Bank · ') + rkSel.name : 'Belum dipilih';
  document.getElementById('pv-num').textContent  = num || '—';
  document.getElementById('pv-name').textContent = 'a.n. ' + (nm || '—');
  document.getElementById('rk-go').disabled = !(rkSel && num && nm);
  document.getElementById('w-num').classList.remove('bad');
  document.getElementById('w-name').classList.remove('bad');
}

function showConfirm() {
  const num = document.getElementById('account_number').value.trim();
  const nm  = document.getElementById('account_name').value.trim();
  if (!rkSel) { if (typeof nToast !== 'undefined') nToast('Pilih bank atau e-wallet dulu ya!', 'warn'); return; }
  if (!num) { document.getElementById('w-num').classList.add('bad'); return; }
  if (!nm)  { document.getElementById('w-name').classList.add('bad'); return; }
  document.getElementById('cg-card').innerHTML = document.querySelector('.rk-form .rk-acc.new').innerHTML.replace(/ id="[^"]*"/g, '');
  document.getElementById('cg-modal').style.display = 'flex';
}

function closeConfirm() {
  document.getElementById('cg-modal').style.display = 'none';
}

function submitForm() {
  const btn = document.querySelector('.cg-btn-confirm');
  btn.disabled = true;
  btn.innerHTML = '<i class="ph-bold ph-spinner"></i> Mengirim...';
  document.getElementById('edit-rek-form').submit();
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
