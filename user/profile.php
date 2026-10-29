<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';
$flash = $flashType = '';
$active_section = 'main';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'change_password') {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        if (!password_verify($old, $user['password_hash'])) { $flash = 'Password lama salah.'; $flashType = 'error'; }
        elseif (strlen($new) < 6) { $flash = 'Password baru minimal 6 karakter.'; $flashType = 'error'; }
        else {
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($new, PASSWORD_BCRYPT), $user['id']]);
            $flash = 'Password berhasil diubah!';
            $flashType = 'success';
        }
        $active_section = 'password';
    }
    $ru = $pdo->prepare("SELECT * FROM users WHERE id=?"); $ru->execute([$user['id']]); $user = $ru->fetch();
}

// Stats
$st = $pdo->prepare("SELECT COUNT(*) FROM watch_history WHERE user_id=?"); $st->execute([$user['id']]); $total_watches = (int)$st->fetchColumn();
$refs = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by=?"); $refs->execute([$user['referral_code']]); $refs = (int)$refs->fetchColumn();

// Additional stats
$total_deposits = 0; $total_withdrawals = 0;
try { $s = $pdo->prepare("SELECT COUNT(*) FROM deposits WHERE user_id=? AND status='approved'"); $s->execute([$user['id']]); $total_deposits = (int)$s->fetchColumn(); } catch (\Throwable) {}
try { $s = $pdo->prepare("SELECT COUNT(*) FROM withdrawals WHERE user_id=? AND status='approved'"); $s->execute([$user['id']]); $total_withdrawals = (int)$s->fetchColumn(); } catch (\Throwable) {}

// Active investments
$active_investments = 0;
try { $s = $pdo->prepare("SELECT COUNT(*) FROM user_investments WHERE user_id=? AND status='active'"); $s->execute([$user['id']]); $active_investments = (int)$s->fetchColumn(); } catch (\Throwable) {}

// Membership
$membership_name = '';
$membership_allow_edit_bank = false;
$is_premium = false;

if ($user['membership_id'] && $user['membership_expires_at'] && strtotime((string)$user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT name, allow_edit_bank FROM memberships WHERE id=?"); $ms->execute([$user['membership_id']]);
    $ms = $ms->fetch();
    $membership_name = $ms['name'] ?? '';
    $membership_allow_edit_bank = (bool)($ms['allow_edit_bank'] ?? false);
    $is_premium = true;
}

if (!$membership_name) {
    $ms_free = $pdo->query("SELECT name, allow_edit_bank FROM memberships WHERE price=0 AND is_active=1 ORDER BY sort_order ASC LIMIT 1")->fetch();
    $membership_name = $ms_free['name'] ?? 'Free Member';
    $membership_allow_edit_bank = (bool)($ms_free['allow_edit_bank'] ?? false);
    $is_premium = false;
}

$edit_bank_min_dep = (int)($user['edit_bank_deposit_min'] ?? 50000);
$dep_ok_for_edit   = (float)$user['balance_dep'] >= $edit_bank_min_dep;
$is_promotor_prof  = ((int)($user['is_promotor'] ?? 0) === 1);
$show_edit_rek_btn = $membership_allow_edit_bank || $is_promotor_prof;

// Member since
$member_since = date('d M Y', strtotime($user['created_at']));
$days_member  = max(1, (int)((time() - strtotime($user['created_at'])) / 86400));

// Contact buttons
try {
    $_contact_btns = $pdo->query("SELECT * FROM contact_buttons WHERE is_active=1 ORDER BY sort_order ASC, id ASC")->fetchAll();
} catch (\Throwable) { $_contact_btns = []; }

$pageTitle  = 'Profil Petani';
$activePage = 'profile';
require dirname(__DIR__) . '/partials/header.php';

$_psvg = [
  'wa'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.123.554 4.118 1.528 5.847L.057 23.883a.5.5 0 00.61.61l6.037-1.472A11.944 11.944 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-1.89 0-3.655-.518-5.17-1.42l-.37-.22-3.823.933.954-3.722-.242-.383A9.958 9.958 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/></svg>',
  'tele' => '<svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M11.944 0A12 12 0 000 12a12 12 0 0012 12 12 12 0 0012-12A12 12 0 0012 0a12 12 0 00-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 01.171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>',
  'cs'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
  'ig'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>',
  'fb'   => '<svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
];
?>

<?php
// Level sawah untuk kartu pemain (sama dengan beranda)
require_once dirname(__DIR__) . '/lib/farm.php';
$farm_lv = farm_level(0);
try {
    $xs = $pdo->prepare("SELECT xp FROM farm_users WHERE user_id = ?");
    $xs->execute([$user['id']]);
    $farm_lv = farm_level((int)$xs->fetchColumn());
} catch (\Throwable) {}
$xp_span = max(1, $farm_lv['xp_next'] - $farm_lv['xp_cur']);
$xp_pct  = (int)min(100, max(0, round(($farm_lv['xp'] - $farm_lv['xp_cur']) / $xp_span * 100)));

$PF_MENU = [
    ['/videos', 'watch', 'Nonton'], ['/farm', 'padi', 'Sawah'], ['/missions', 'missions', 'Misi'], ['/upgrade', 'upgrade', 'Upgrade'],
    ['/history', 'receipt', 'Riwayat'], ['/edit-rekening', 'piggy', 'Rekening'], ['/referral', 'referral', 'Undang'], ['/panduan', 'compass', 'Panduan'],
];
?>
<style>
/* ══ PROFIL — Kartu Pemain · Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.pf-id { display: flex; align-items: center; gap: 12px; }
.pf-ava { position: relative; flex-shrink: 0; width: 70px; height: 70px; border-radius: 22px; display: grid; place-items: center; font-family: var(--pc-font); font-size: 32px; font-weight: 700; color: #fff;
  background: linear-gradient(145deg, #4ade80, #15803d); box-shadow: 0 0 0 3px #fff, 0 0 0 6px #ffd54f, 0 10px 18px rgba(120, 70, 10, .3); text-shadow: 0 2px 0 rgba(0, 0, 0, .2); }
.pf-ava .lv { position: absolute; left: 50%; bottom: -12px; transform: translateX(-50%); padding: 1px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 700; white-space: nowrap; color: #4a3000;
  background: linear-gradient(180deg, #ffd54f, #f6b70a); box-shadow: 0 2px 0 #b07800, 0 0 0 2px #fff; text-shadow: none; }
.pf-ava .on { position: absolute; top: -3px; right: -3px; width: 14px; height: 14px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 3px #fff; animation: gGlow 2s infinite; }
.pf-who { flex: 1; min-width: 0; }
.pf-who h1 { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pf-who .mail { font-size: 11.5px; font-weight: 800; color: #7a4f00; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pf-chips { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }
.pf-chips .g-chip { background: rgba(255, 255, 255, .8); color: #6b4300; }
.pf-chips .g-chip.vip { background: linear-gradient(180deg, #ffd54f, #f6b70a); color: #4a3000; }
.pf-xp { margin-top: 16px; padding: 9px 11px; border-radius: 16px; background: rgba(255, 255, 255, .75); box-shadow: 0 0 0 2px rgba(255, 255, 255, .85); }
.pf-xp-top { display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 11px; font-weight: 900; color: #8a5a00; }
.pf-ticket { position: relative; display: flex; align-items: center; gap: 10px; margin-top: 10px; padding: 8px 8px 8px 10px; border-radius: 16px; background: #fff; box-shadow: 0 0 0 2px #fcd34d, 0 4px 0 #e9b949; }
.pf-ticket img { width: 32px; height: 32px; object-fit: contain; }
.pf-ticket .t { flex: 1; min-width: 0; }
.pf-ticket small { display: block; font-size: 10px; font-weight: 900; letter-spacing: .4px; color: #8a5a00; }
.pf-ticket b { display: block; font-family: var(--pc-font); font-size: 18px; font-weight: 700; letter-spacing: 1.5px; color: var(--pc-ink); }

/* Inventaris saldo */
.pf-inv { display: grid; grid-template-columns: 1fr 1fr; gap: 9px; margin-top: 10px; }
.pf-slot { position: relative; display: flex; flex-direction: column; gap: 6px; padding: 10px; border-radius: 18px; text-decoration: none; color: inherit; background: #fff; box-shadow: inset 0 0 0 2px #f6e2b0, 0 4px 0 #ecd59c; transition: transform .12s; }
.pf-slot:active { transform: translateY(3px); box-shadow: inset 0 0 0 2px #f6e2b0, 0 1px 0 #ecd59c; }
.pf-slot.wide { grid-column: 1 / -1; flex-direction: row; align-items: center; }
.pf-slot-top { display: flex; align-items: center; gap: 8px; }
.pf-slot small { display: block; font-size: 10.5px; font-weight: 900; color: var(--pc-muted); }
.pf-slot b { display: block; font-family: var(--pc-font); font-size: 16px; font-weight: 700; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pf-slot .meta { font-size: 10.5px; font-weight: 800; color: var(--pc-muted); }
.pf-slot .go { position: absolute; top: 8px; right: 8px; font-size: 11px; font-weight: 900; color: #15803d; }
.pf-mini { flex: 1; display: grid; grid-template-columns: repeat(3, 1fr); text-align: center; }
.pf-mini div + div { border-left: 2px dashed #f2e3bd; }
.pf-mini b { font-size: 18px; }

/* Menu ikon 3D */
.pf-menu { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px 6px; margin-top: 10px; }
.pf-menu a { display: flex; flex-direction: column; align-items: center; gap: 5px; text-decoration: none; }
.pf-menu .g-slot { width: 58px; height: 58px; border-radius: 18px; transition: transform .2s var(--pc-ease); }
.pf-menu a:active .g-slot { transform: scale(.88) rotate(-6deg); }
.pf-menu span.l { font-family: var(--pc-font); font-size: 12.5px; font-weight: 600; color: var(--pc-ink); }

/* Pengaturan */
.pf-set { display: flex; flex-direction: column; gap: 8px; margin-top: 10px; }
.pf-row { display: flex; align-items: center; gap: 10px; width: 100%; padding: 8px 10px 8px 8px; border: 0; border-radius: 16px; cursor: pointer; text-align: left; font: inherit; color: inherit; background: #fff; box-shadow: inset 0 0 0 2px #f6e2b0, 0 3px 0 #ecd59c; }
.pf-row:active { transform: translateY(2px); box-shadow: inset 0 0 0 2px #f6e2b0, 0 1px 0 #ecd59c; }
.pf-row .t { flex: 1; min-width: 0; }
.pf-row strong { display: block; font-family: var(--pc-font); font-size: 14.5px; font-weight: 600; color: var(--pc-ink); }
.pf-row small { display: block; font-size: 11px; font-weight: 800; color: var(--pc-muted); }
.pf-row > i { font-size: 16px; color: #c9a560; }

/* Bottom sheet */
.sheet-backdrop { position: fixed; inset: 0; z-index: 10000; background: rgba(27, 42, 30, .5); backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px); opacity: 0; visibility: hidden; transition: opacity .25s, visibility .25s; }
.sheet-backdrop.open { opacity: 1; visibility: visible; }
.cabin-sheet { position: fixed; left: 50%; bottom: 0; z-index: 10001; width: 100%; max-width: 440px; max-height: 86vh; overflow-y: auto; padding: 8px 16px calc(20px + env(safe-area-inset-bottom)); border-radius: 28px 28px 0 0;
  background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 0 0 3px #f6e2b0, 0 -16px 40px rgba(0, 0, 0, .25); transform: translate(-50%, 105%); transition: transform .4s var(--pc-ease); }
.cabin-sheet.open { transform: translate(-50%, 0); }
.sheet-grab { width: 44px; height: 5px; margin: 0 auto 10px; border-radius: 999px; background: #e6d6ad; }
.sheet-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.sheet-title { display: flex; align-items: center; gap: 8px; font-family: var(--pc-font); font-size: 18px; font-weight: 700; color: var(--pc-ink); }
.sheet-title img { width: 34px; height: 34px; object-fit: contain; }
.sheet-close { width: 34px; height: 34px; border: 0; border-radius: 12px; cursor: pointer; display: grid; place-items: center; font-size: 15px; color: #fff; background: linear-gradient(180deg, #fb7185, #e11d48); box-shadow: 0 3px 0 #9f1239; }
.sheet-close:active { transform: translateY(2px); box-shadow: 0 1px 0 #9f1239; }
.doc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.doc-cell { padding: 9px 11px; border-radius: 14px; background: #fff; box-shadow: inset 0 0 0 2px #f6e2b0; min-width: 0; }
.doc-cell--wide { grid-column: 1 / -1; }
.doc-k { display: flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 900; color: var(--pc-muted); }
.doc-v { margin-top: 2px; font-size: 13.5px; font-weight: 900; color: var(--pc-ink); word-break: break-word; }
.cabin-flash { display: flex; align-items: center; gap: 6px; margin-bottom: 10px; padding: 9px 11px; border-radius: 12px; font-size: 12.5px; font-weight: 800; }
.cabin-flash.success { background: #dcfce7; color: #15803d; }
.cabin-flash.error { background: #ffe4e6; color: #be123c; }
.input-lbl { display: block; margin: 0 0 4px 2px; font-size: 11.5px; font-weight: 900; color: var(--pc-muted); }
.prof-input-ctrl { width: 100%; box-sizing: border-box; margin-bottom: 10px; padding: 11px 13px; border: 0; border-radius: 13px; font: 800 14px var(--pc-font, inherit); color: var(--pc-ink); background: #fff; box-shadow: inset 0 0 0 2px #f6e2b0, inset 0 2px 4px rgba(120, 70, 10, .08); outline: none; }
.prof-input-ctrl:focus { box-shadow: inset 0 0 0 2px #22c55e, 0 0 0 4px rgba(34, 197, 94, .15); }
.help-list { display: flex; flex-direction: column; gap: 8px; }
.help-row { display: flex; align-items: center; gap: 10px; padding: 9px 11px; border-radius: 14px; text-decoration: none; color: var(--pc-ink); background: #fff; box-shadow: inset 0 0 0 2px #f6e2b0, 0 3px 0 #ecd59c; }
.help-name { flex: 1; font-size: 13.5px; font-weight: 900; }
.ch-btn { width: 36px; height: 36px; border-radius: 12px; display: grid; place-items: center; color: #fff; }
.ch-btn--wa { background: linear-gradient(145deg, #4ade80, #16a34a); }
.ch-btn--tele { background: linear-gradient(145deg, #38bdf8, #0284c7); }
.ch-btn--ig { background: linear-gradient(145deg, #f472b6, #c026d3); }
.ch-btn--fb { background: linear-gradient(145deg, #60a5fa, #1d4ed8); }
.ch-btn--cs { background: linear-gradient(145deg, #fbbf24, #d97706); }
.ch-empty { padding: 16px; text-align: center; font-size: 12.5px; font-weight: 800; color: var(--pc-muted); }
</style>

<div class="g-page">

  <!-- Kartu pemain -->
  <section class="g-hero" data-rv>
    <span class="g-sun"></span><span class="g-cloud c1"></span><span class="g-cloud c2"></span>
    <div class="pf-id">
      <div class="pf-ava"><?= strtoupper(substr($user['username'], 0, 1)) ?><span class="on"></span><span class="lv">Lv <?= (int)$farm_lv['level'] ?></span></div>
      <div class="pf-who">
        <h1><?= htmlspecialchars($user['username']) ?></h1>
        <div class="mail"><?= htmlspecialchars($user['email']) ?></div>
        <div class="pf-chips">
          <span class="g-chip <?= $is_premium ? 'vip' : '' ?>"><i class="ph-fill ph-crown-simple"></i><?= htmlspecialchars($membership_name) ?></span>
          <span class="g-chip" title="Bergabung <?= $member_since ?>"><i class="ph-fill ph-plant"></i><?= number_format($days_member) ?> hari bertani</span>
        </div>
      </div>
    </div>
    <div class="pf-xp">
      <div class="pf-xp-top"><span>XP Sawah</span><span><?= number_format($farm_lv['xp'] - $farm_lv['xp_cur']) ?> / <?= number_format($xp_span) ?></span></div>
      <div class="g-bar gold"><i style="width:<?= $xp_pct ?>%"></i></div>
    </div>
    <div class="pf-ticket">
      <img src="/assets/img/icons3d/referral.png" alt="">
      <div class="t"><small>KODE REFERRAL</small><b id="ref-code"><?= htmlspecialchars($user['referral_code']) ?></b></div>
      <button class="g-btn sm" onclick="copyRef()" type="button"><i class="ph-bold ph-copy"></i>Salin</button>
    </div>
  </section>

  <!-- Inventaris saldo -->
  <section data-rv style="--d:.05s">
    <div class="g-sec"><span class="g-ribbon gold"><img src="/assets/img/icons3d/barn.png" alt="">Lumbung Saldo</span></div>
    <div class="pf-inv">
      <a href="/withdraw" class="pf-slot">
        <span class="go">Tarik ›</span>
        <span class="g-slot sm"><img src="/assets/img/icons3d/moneybag.png" alt=""></span>
        <div><small>Saldo Tarik</small><b><?= format_rp((float)$user['balance_wd']) ?></b></div>
        <span class="meta"><?= number_format($total_withdrawals) ?>x penarikan</span>
      </a>
      <a href="/deposit" class="pf-slot">
        <span class="go">Top Up ›</span>
        <span class="g-slot sm"><img src="/assets/img/icons3d/piggy.png" alt=""></span>
        <div><small>Saldo Beli</small><b><?= format_rp((float)$user['balance_dep']) ?></b></div>
        <span class="meta"><?= number_format($total_deposits) ?>x top up</span>
      </a>
      <a href="/history" class="pf-slot wide">
        <span class="g-slot"><img src="/assets/img/icons3d/coins.png" alt=""></span>
        <div style="flex:1;min-width:0"><small>Total Pendapatan</small><b><?= format_rp((float)$user['total_earned']) ?></b><span class="meta">Sejak <?= $member_since ?></span></div>
        <i class="ph-bold ph-caret-right" style="color:#c9a560"></i>
      </a>
      <div class="pf-slot wide">
        <div class="pf-mini">
          <div><b><?= number_format($total_watches) ?></b><small>Video</small></div>
          <div><b><?= number_format($refs) ?></b><small>Teman</small></div>
          <div><b><?= number_format($active_investments) ?></b><small>Invest</small></div>
        </div>
      </div>
    </div>
  </section>

  <!-- Menu -->
  <section class="g-panel" data-rv style="--d:.1s">
    <div class="g-sec"><span class="g-ribbon"><img src="/assets/img/icons3d/compass.png" alt="">Menu</span></div>
    <div class="pf-menu">
      <?php foreach ($PF_MENU as [$href, $ic, $lbl]): ?>
        <a href="<?= $href ?>"><span class="g-slot"><img src="/assets/img/icons3d/<?= $ic ?>.png" alt=""></span><span class="l"><?= $lbl ?></span></a>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Pengaturan (bottom sheet) -->
  <section data-rv style="--d:.15s">
    <div class="g-sec"><span class="g-ribbon sky"><i class="ph-fill ph-gear-six"></i>Pengaturan</span></div>
    <div class="pf-set">
      <button type="button" class="pf-row" onclick="openCabinSheet('doc')">
        <span class="g-slot sm"><img src="/assets/img/icons3d/receipt.png" alt=""></span>
        <span class="t"><strong>Data Akun</strong><small>Akun &amp; rekening bank</small></span><i class="ph-bold ph-caret-right"></i>
      </button>
      <button type="button" class="pf-row" onclick="openCabinSheet('pass')">
        <span class="g-slot sm"><img src="/assets/img/icons3d/lock.png" alt=""></span>
        <span class="t"><strong>Kata Sandi</strong><small>Ganti password akun</small></span><i class="ph-bold ph-caret-right"></i>
      </button>
      <button type="button" class="pf-row" onclick="openCabinSheet('help')">
        <span class="g-slot sm"><img src="/assets/img/icons3d/chat.png" alt=""></span>
        <span class="t"><strong>Bantuan</strong><small>Kontak &amp; komunitas</small></span><i class="ph-bold ph-caret-right"></i>
      </button>
    </div>
  </section>

  <a href="/logout" class="g-btn red full" data-rv style="--d:.2s"><i class="ph-bold ph-sign-out"></i>Keluar</a>
</div>

<!-- ══════════ BOTTOM SHEETS ══════════ -->
<div class="sheet-backdrop" id="cabin-sheet-backdrop" onclick="closeCabinSheet()"></div>

<!-- Sheet: Data Akun -->
<div class="cabin-sheet" id="sheet-doc" role="dialog" aria-modal="true" aria-labelledby="sheet-doc-title">
  <div class="sheet-grab"></div>
  <div class="sheet-head">
    <div class="sheet-title" id="sheet-doc-title"><img src="/assets/img/icons3d/receipt.png" alt="">Data Akun</div>
    <button type="button" class="sheet-close" onclick="closeCabinSheet()" aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="doc-grid">
    <div class="doc-cell">
      <div class="doc-k"><i class="ph-bold ph-user"></i> Username</div>
      <div class="doc-v"><?= htmlspecialchars($user['username']) ?></div>
    </div>
    <div class="doc-cell">
      <div class="doc-k"><i class="ph-bold ph-phone"></i> WhatsApp</div>
      <div class="doc-v"><?= htmlspecialchars(mask_account($user['whatsapp'] ?? '')) ?: '-' ?></div>
    </div>
    <div class="doc-cell doc-cell--wide">
      <div class="doc-k"><i class="ph-bold ph-envelope"></i> Email</div>
      <div class="doc-v"><?= htmlspecialchars($user['email']) ?></div>
    </div>
    <div class="doc-cell doc-cell--wide">
      <div class="doc-k"><i class="ph-bold ph-bank"></i> Bank Tujuan</div>
      <div class="doc-v"><?= $user['bank_name'] ? htmlspecialchars($user['bank_name'] . ' - ' . mask_account($user['account_number'] ?? '')) : 'Belum Didaftarkan' ?></div>
    </div>
    <div class="doc-cell">
      <div class="doc-k"><i class="ph-bold ph-crown"></i> Membership</div>
      <div class="doc-v"><?= htmlspecialchars($membership_name) ?></div>
    </div>
    <div class="doc-cell">
      <div class="doc-k"><i class="ph-bold ph-calendar-blank"></i> Bergabung</div>
      <div class="doc-v"><?= $member_since ?></div>
    </div>
  </div>
</div>

<!-- Sheet: Kata Sandi -->
<div class="cabin-sheet" id="sheet-pass" role="dialog" aria-modal="true" aria-labelledby="sheet-pass-title">
  <div class="sheet-grab"></div>
  <div class="sheet-head">
    <div class="sheet-title" id="sheet-pass-title"><img src="/assets/img/icons3d/lock.png" alt="">Kata Sandi</div>
    <button type="button" class="sheet-close" onclick="closeCabinSheet()" aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <?php if ($flash): ?>
  <div class="cabin-flash <?= $flashType === 'error' ? 'error' : 'success' ?>">
    <i class="ph-bold ph-<?= $flashType === 'error' ? 'warning-circle' : 'check-circle' ?>" style="font-size:16px;"></i>
    <?= htmlspecialchars($flash) ?>
  </div>
  <?php endif; ?>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_password">
    <label class="input-lbl">Password Lama</label>
    <input class="prof-input-ctrl" type="password" name="old_password" placeholder="Masukkan password saat ini" required minlength="6">
    <label class="input-lbl">Password Baru (Minimal 6 Karakter)</label>
    <input class="prof-input-ctrl" type="password" name="new_password" placeholder="Masukkan password baru" required minlength="6">
    <button class="g-btn full" type="submit"><i class="ph-bold ph-key"></i>Perbarui Password</button>
  </form>
</div>

<!-- Sheet: Bantuan -->
<div class="cabin-sheet" id="sheet-help" role="dialog" aria-modal="true" aria-labelledby="sheet-help-title">
  <div class="sheet-grab"></div>
  <div class="sheet-head">
    <div class="sheet-title" id="sheet-help-title"><img src="/assets/img/icons3d/chat.png" alt="">Pusat Bantuan</div>
    <button type="button" class="sheet-close" onclick="closeCabinSheet()" aria-label="Tutup"><i class="ph-bold ph-x"></i></button>
  </div>
  <div class="help-list">
    <?php if (empty($_contact_btns)): ?>
      <div class="ch-empty">Belum ada kontak bantuan.</div>
    <?php endif; ?>
    <?php foreach ($_contact_btns as $cb): ?>
      <?php
        $t = strtolower((string)$cb['icon_value']);
        $svg = $_psvg[$t] ?? $_psvg['cs'];
        $cls = isset($_psvg[$t]) ? $t : 'cs';
      ?>
      <a href="<?= htmlspecialchars($cb['url']) ?>" class="help-row" target="_blank" rel="noopener">
        <span class="ch-btn ch-btn--<?= $cls ?>"><?= $svg ?></span>
        <span class="help-name"><?= htmlspecialchars($cb['label'] ?? 'Hubungi Kami') ?></span>
        <i class="ph-bold ph-arrow-up-right"></i>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<script>
let __cabinSheetOpen = null;
function openCabinSheet(name) {
  const sheet = document.getElementById('sheet-' + name);
  const backdrop = document.getElementById('cabin-sheet-backdrop');
  if (!sheet || !backdrop) return;
  if (__cabinSheetOpen) __cabinSheetOpen.classList.remove('open');
  sheet.classList.add('open');
  backdrop.classList.add('open');
  __cabinSheetOpen = sheet;
}

function closeCabinSheet() {
  const backdrop = document.getElementById('cabin-sheet-backdrop');
  if (__cabinSheetOpen) __cabinSheetOpen.classList.remove('open');
  if (backdrop) backdrop.classList.remove('open');
  __cabinSheetOpen = null;
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeCabinSheet();
});

<?php if ($flash || $active_section === 'password'): ?>
document.addEventListener('DOMContentLoaded', () => openCabinSheet('pass'));
<?php endif; ?>

function copyRef() {
  const codeEl = document.getElementById('ref-code');
  if (!codeEl) return;
  const txt = codeEl.innerText.trim();

  if (typeof nToast !== 'undefined' && nToast.copy) {
    nToast.copy(txt, 'Kode Referral');
  } else {
    navigator.clipboard.writeText(txt).then(() => {
      if (typeof nToast === 'function') {
        nToast('Kode Referral disalin: ' + txt, 'success');
      }
    });
  }
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
