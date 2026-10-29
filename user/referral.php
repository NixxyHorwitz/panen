<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$ref_bonus     = (float) setting($pdo, 'referral_bonus', '1000');
$ref_pct       = (float) setting($pdo, 'referral_commission_percent', '5');
$ref_hold_days = max(0, (int) setting($pdo, 'referral_hold_days', '3'));

$flash     = $_SESSION['ref_flash'] ?? '';
$flashType = $_SESSION['ref_flash_type'] ?? '';
unset($_SESSION['ref_flash'], $_SESSION['ref_flash_type']);

// Handle Claim POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'claim_commissions') {
    if (!csrf_verify()) {
        $_SESSION['ref_flash'] = 'Sesi keamanan tidak valid. Silakan muat ulang halaman.';
        $_SESSION['ref_flash_type'] = 'error';
    } else {
        $claimRes = claim_user_referral_commissions($pdo, (int)$user['id']);
        $_SESSION['ref_flash'] = $claimRes['message'];
        $_SESSION['ref_flash_type'] = $claimRes['success'] ? 'success' : 'error';
    }
    header('Location: ' . base_url('user/referral.php'));
    exit;
}

// Refresh user balance in case it changed
$uStmt = $pdo->prepare("SELECT balance_wd, total_earned FROM users WHERE id = ?");
$uStmt->execute([$user['id']]);
$freshUser = $uStmt->fetch();
if ($freshUser) {
    $user['balance_wd'] = $freshUser['balance_wd'];
    $user['total_earned'] = $freshUser['total_earned'];
}

// Referral stats
$s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE TRIM(UPPER(referred_by)) = TRIM(UPPER(?))");
$s->execute([$user['referral_code']]);
$ref_count = (int)$s->fetchColumn();

// Commission Breakdown Summary
$commSummary   = get_user_referral_commission_summary($pdo, (int)$user['id']);
$ref_locked    = $commSummary['locked_amount'];
$ref_claimable = $commSummary['claimable_amount'];
$ref_claimed   = $commSummary['claimed_amount'];
$ref_earned    = $commSummary['total_earned'];
$earliest_unlock = $commSummary['earliest_unlock'];

// Referral history (recent commissions with status & unlock date)
$hist = $pdo->prepare(
  "SELECT rc.id, rc.amount, rc.status, rc.unlock_at, rc.claimed_at, rc.created_at, u.username
   FROM referral_commissions rc
   JOIN users u ON u.id = rc.from_user_id
   WHERE rc.user_id = ?
   ORDER BY rc.created_at DESC LIMIT 30"
);
$hist->execute([$user['id']]);
$history = $hist->fetchAll();

// Referred users list
$refs = $pdo->prepare(
  "SELECT u.id, u.username, u.created_at, 
          COALESCE(m.name, 'Free') as membership_name,
          COALESCE((SELECT SUM(amount) FROM deposits WHERE user_id = u.id AND status = 'confirmed'), 0) as total_deposit,
          COALESCE((SELECT SUM(amount) FROM referral_commissions WHERE user_id = ? AND from_user_id = u.id), 0) as commission_earned
   FROM users u
   LEFT JOIN memberships m ON m.id = u.membership_id
   WHERE TRIM(UPPER(u.referred_by)) = TRIM(UPPER(?))
   ORDER BY u.created_at DESC"
);
$refs->execute([$user['id'], $user['referral_code']]);
$referreds = $refs->fetchAll();

$ref_url = base_url('register/' . $user['referral_code']);
$share_text = 'Gabung dan hasilkan cuan bersama! Daftar menggunakan tautan referralku: ' . $ref_url;

$pageTitle  = 'Program Referral';
$activePage = 'referral';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══ REFERRAL — Ajak Teman Bertani · Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.kru-flash { display: flex; align-items: center; gap: 9px; padding: 10px 12px; border-radius: 16px; font-size: 12px; font-weight: 800; }
.kru-flash i { font-size: 19px; flex-shrink: 0; }
.kru-flash.success { background: #dcfce7; color: #14532d; box-shadow: inset 0 0 0 2px #bbf7d0; }
.kru-flash.error { background: #ffe4e6; color: #9f1239; box-shadow: inset 0 0 0 2px #fecdd3; }

/* Hero: jumlah teman */
.kru-count { position: relative; flex-shrink: 0; width: 88px; height: 88px; border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center;
  background: radial-gradient(circle at 40% 35%, #fff 0, #fff6dc 65%, #ffe9a0 100%); box-shadow: 0 0 0 4px #fff, 0 0 0 7px #f6b70a, 0 10px 18px rgba(120, 70, 10, .25); }
.kru-count img { position: absolute; top: -14px; right: -10px; width: 42px; height: 42px; object-fit: contain; animation: gBob 3s ease-in-out infinite; filter: drop-shadow(0 4px 4px rgba(0, 0, 0, .2)); }
.kru-count b { font-family: var(--pc-font); font-weight: 700; line-height: 1; color: #15803d; }
.kru-count small { font-size: 10.5px; font-weight: 900; color: #8a5a00; margin-top: 2px; }

.kru-promo { display: flex; align-items: center; gap: 10px; padding: 9px 9px 9px 11px; border-radius: 18px; color: #fff; background: linear-gradient(135deg, #a78bfa, #7c3aed); box-shadow: 0 4px 0 #5b21b6, 0 10px 18px rgba(124, 58, 237, .25); }
.kru-promo b { flex: 1; font-family: var(--pc-font); font-size: 15px; font-weight: 600; }
.kru-promo .g-btn { min-height: 32px; padding: 0 11px; font-size: 12.5px; border-radius: 10px; }

/* Tiket kode */
.kru-ticket { display: flex; align-items: center; gap: 10px; }
.kru-ticket .tx { flex: 1; min-width: 0; }
.kru-ticket small { display: block; font-size: 11px; font-weight: 900; color: #8a5a00; }
.kru-code { margin-top: 2px; font-family: var(--pc-font); font-size: 24px; font-weight: 700; letter-spacing: 2px; color: #15803d; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kru-url { margin-top: 3px; padding: 4px 8px; border-radius: 9px; background: #f6f1e4; font-size: 11px; font-weight: 700; color: var(--pc-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kru-copy.g-btn { flex-shrink: 0; flex-direction: column; gap: 2px; min-height: 58px; width: 70px; padding: 0 4px; font-size: 12px; }
.kru-copy.g-btn i { font-size: 20px; }
.kru-share { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-top: 10px; }
.kru-share .g-btn { min-height: 38px; padding: 0 6px; font-size: 13px; }
.kru-share .g-btn i { font-size: 17px; }
.g-btn.wa { --c1: #4ade80; --c2: #16a34a; --lip: #15803d; --sh: rgba(22, 163, 74, .3); }

/* Alur komisi */
.kru-flow-steps { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
.kru-step { min-width: 0; padding: 9px 4px 8px; border-radius: 16px; text-align: center; background: #fff; box-shadow: inset 0 0 0 1.5px #f1dfb4; }
.kru-step img { width: 34px; height: 34px; object-fit: contain; filter: drop-shadow(0 3px 3px rgba(0, 0, 0, .18)); }
.kru-step b { display: block; margin-top: 2px; font-family: var(--pc-font); font-size: 14px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kru-step small { display: block; font-size: 10.5px; font-weight: 900; color: var(--pc-muted); }
.kru-step.locked b { color: #0369a1; } .kru-step.ready b { color: #b07800; } .kru-step.done b { color: #15803d; }
.kru-step.ready.on { background: linear-gradient(180deg, #fff7d1, #fffdf3); box-shadow: inset 0 0 0 2px #fcd34d; }
.kru-step.ready.on img { animation: gBob 1.6s ease-in-out infinite; }
.kru-flow-note { display: flex; gap: 7px; margin: 10px 0; padding: 8px 10px; border-radius: 13px; background: #f6f1e4; font-size: 11.5px; font-weight: 700; color: var(--pc-muted); line-height: 1.45; }
.kru-flow-note i { font-size: 15px; color: #15803d; flex-shrink: 0; margin-top: 1px; }
.kru-flow-note strong { color: var(--pc-ink); }

.kru-wallet { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.kru-wallet > div { display: flex; align-items: center; gap: 9px; min-width: 0; padding: 9px; border-radius: 18px; background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 0 0 2px #f6e2b0, 0 4px 0 #ecd59c; }
.kru-wallet .tx { min-width: 0; }
.kru-wallet b { display: block; font-family: var(--pc-font); font-size: 15px; font-weight: 700; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kru-wallet small { display: block; font-size: 10.5px; font-weight: 900; color: var(--pc-muted); }

/* Cara kerja */
.kru-how { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
.kru-how-row { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 9px 5px 8px; border-radius: 18px; text-align: center; background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 0 0 2px #f6e2b0, 0 4px 0 #ecd59c; min-width: 0; }
.kru-how-row img { width: 40px; height: 40px; object-fit: contain; filter: drop-shadow(0 3px 3px rgba(0, 0, 0, .18)); }
.kru-how-row b { font-size: 11.5px; font-weight: 900; line-height: 1.15; color: var(--pc-ink); }
.kru-how-row small { font-size: 10px; font-weight: 700; color: var(--pc-muted); line-height: 1.25; }

/* Tab */
.ref-tab-btn .ref-tab-pill { min-width: 20px; height: 18px; padding: 0 6px; border-radius: 999px; font-family: 'Nunito', sans-serif; font-size: 10.5px; font-weight: 900; display: inline-grid; place-items: center; background: rgba(0, 0, 0, .12); }
.ref-tab-btn.active .ref-tab-pill { background: #ffd54f; color: #4a3000; text-shadow: none; }
.ref-tab-panel { display: none; }
.ref-tab-panel.active { display: block; }

/* Daftar teman */
.kru-crew { display: flex; flex-direction: column; gap: 7px; }
.kru-mate { display: flex; align-items: center; gap: 10px; padding: 8px 10px 8px 8px; border-radius: 18px; background: #fff; box-shadow: 0 0 0 2px #efe6cf, 0 3px 0 #e6dbbd; }
.kru-ava { position: relative; width: 40px; height: 40px; flex-shrink: 0; border-radius: 50%; display: grid; place-items: center; font-family: var(--pc-font); font-size: 18px; font-weight: 700; color: #fff; box-shadow: 0 0 0 2.5px #fff, 0 3px 6px rgba(0, 0, 0, .15); }
.kru-ava.c0 { background: linear-gradient(145deg, #4ade80, #15803d); } .kru-ava.c1 { background: linear-gradient(145deg, #fb923c, #ea580c); }
.kru-ava.c2 { background: linear-gradient(145deg, #38bdf8, #0284c7); } .kru-ava.c3 { background: linear-gradient(145deg, #a78bfa, #7c3aed); }
.kru-ava.c4 { background: linear-gradient(145deg, #ffd54f, #f6b70a); color: #4a3000; }
.kru-ava .vip { position: absolute; right: -5px; bottom: -4px; width: 18px; height: 18px; object-fit: contain; }
.kru-mate-info { flex: 1; min-width: 0; }
.kru-mate-name { font-size: 13px; font-weight: 900; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kru-mate-meta { display: flex; align-items: center; gap: 5px; margin-top: 2px; flex-wrap: wrap; }
.kru-tag { padding: 1px 7px; border-radius: 999px; font-size: 10px; font-weight: 900; }
.kru-tag.free { background: #edf2e6; color: #3f5a33; } .kru-tag.vip { background: #fff3c4; color: #8a5a00; }
.kru-date { font-size: 10.5px; font-weight: 700; color: var(--pc-muted); }
.kru-mate-amt { flex-shrink: 0; text-align: right; }
.kru-mate-amt b { display: block; font-family: var(--pc-font); font-size: 14px; font-weight: 700; color: #15803d; }
.kru-mate-amt small { font-size: 10px; font-weight: 800; color: var(--pc-muted); }

.kru-pg { display: flex; align-items: center; justify-content: center; gap: 12px; margin-top: 10px; }
.kru-pg-btn.g-btn { width: 38px; min-height: 36px; padding: 0; }
.kru-pg-info { min-width: 64px; padding: 5px 12px; border-radius: 999px; text-align: center; font-size: 12px; font-weight: 900; color: #8a5a00; background: #fff3c4; }

/* Riwayat komisi */
.kru-tl { display: flex; align-items: center; gap: 10px; padding: 8px 10px 8px 8px; margin-bottom: 7px; border-radius: 18px; background: #fff; box-shadow: 0 0 0 2px #efe6cf, 0 3px 0 #e6dbbd; }
.kru-tl-body { flex: 1; min-width: 0; }
.kru-tl-title { font-size: 12.5px; font-weight: 900; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.kru-tl-meta { display: flex; align-items: center; gap: 6px; margin-top: 2px; flex-wrap: wrap; }
.kru-st { display: inline-flex; align-items: center; gap: 3px; padding: 1px 7px; border-radius: 999px; font-size: 10px; font-weight: 900; }
.kru-st.claimed { background: #dcfce7; color: #15803d; } .kru-st.ready { background: #fff3c4; color: #8a5a00; } .kru-st.locked { background: #e0f2fe; color: #0369a1; }
.kru-tl-amt { flex-shrink: 0; font-family: var(--pc-font); font-size: 14px; font-weight: 700; color: #15803d; }
@media (max-width: 350px) { .kru-count { width: 74px; height: 74px; } .kru-how-row small { display: none; } }
</style>

<?php
$code_initial = static fn(string $name): string => mb_strtoupper(mb_substr(trim($name) !== '' ? trim($name) : '?', 0, 1));
$code_color   = static fn(string $name): string => 'c' . (crc32($name) % 5);
$ic = static fn(string $n): string => '/assets/img/icons3d/' . $n . '.png';
?>
<div class="g-page">

  <?php if ($flash): ?>
    <div class="kru-flash <?= $flashType === 'error' ? 'error' : 'success' ?>">
      <i class="ph-fill ph-<?= $flashType === 'error' ? 'warning-circle' : 'check-circle' ?>"></i>
      <span><?= htmlspecialchars($flash) ?></span>
    </div>
  <?php endif; ?>

  <section class="g-hero" data-rv>
    <span class="g-sun"></span><span class="g-cloud c1"></span><span class="g-cloud c2"></span>
    <div class="g-hero-row">
      <div style="flex:1;min-width:0">
        <h1>Ajak Teman Bertani</h1>
        <p>Bonus <?= format_rp($ref_bonus) ?> tiap teman daftar + komisi <?= (float)$ref_pct ?>% dari setiap depositnya, seumur hidup.</p>
      </div>
      <?php $refCountTxt = number_format($ref_count, 0, ',', '.'); ?>
      <div class="kru-count" aria-label="Jumlah teman terdaftar">
        <img src="<?= $ic('referral') ?>" alt="">
        <b style="font-size:<?= [1 => 32, 2 => 30, 3 => 26, 4 => 21, 5 => 18][strlen($refCountTxt)] ?? 15 ?>px"><?= $refCountTxt ?></b>
        <small>teman</small>
      </div>
    </div>
  </section>

  <?php if ((int)($user['is_promotor'] ?? 0) === 1): ?>
  <div class="kru-promo" data-rv>
    <span class="g-slot sm"><img src="<?= $ic('megaphone') ?>" alt=""></span>
    <b>Status Promotor Aktif</b>
    <a href="/user/promotor.php" class="g-btn gold">Dashboard</a>
  </div>
  <?php endif; ?>

  <!-- Kode referral -->
  <div class="g-panel" data-rv style="--d:.05s">
    <div class="kru-ticket">
      <span class="g-slot"><img src="<?= $ic('redeem') ?>" alt=""></span>
      <div class="tx">
        <small>Kode referral kamu</small>
        <div class="kru-code" id="ref-code-val"><?= htmlspecialchars($user['referral_code']) ?></div>
        <div class="kru-url"><?= htmlspecialchars(preg_replace('~^https?://~', '', $ref_url)) ?></div>
      </div>
      <button type="button" onclick="copyRefLink()" class="g-btn gold kru-copy" id="btn-copy-code">
        <i class="ph-bold ph-copy" id="copy-ico"></i>
        <span id="copy-txt">Salin</span>
      </button>
    </div>
    <div class="kru-share">
      <a href="https://wa.me/?text=<?= urlencode($share_text) ?>" target="_blank" rel="noopener noreferrer" class="g-btn wa"><i class="ph-fill ph-whatsapp-logo"></i>WA</a>
      <a href="https://t.me/share/url?url=<?= urlencode($ref_url) ?>&text=<?= urlencode('Yuk gabung dan dapatkan saldo gratis!') ?>" target="_blank" rel="noopener noreferrer" class="g-btn sky"><i class="ph-fill ph-telegram-logo"></i>Telegram</a>
      <button type="button" onclick="shareNative()" class="g-btn soft"><i class="ph-bold ph-share-network"></i>Lainnya</button>
    </div>
  </div>

  <!-- Komisi -->
  <div class="g-sec"><span class="g-ribbon gold"><img src="<?= $ic('coins') ?>" alt="">Komisi</span><small>tahan <?= $ref_hold_days ?> hari</small></div>
  <section class="g-panel" data-rv>
    <div class="kru-flow-steps">
      <div class="kru-step locked"><img src="<?= $ic('lock') ?>" alt=""><b><?= format_rp($ref_locked) ?></b><small>Masa tahan</small></div>
      <div class="kru-step ready <?= $ref_claimable > 0 ? 'on' : '' ?>"><img src="<?= $ic('chest_open') ?>" alt=""><b><?= format_rp($ref_claimable) ?></b><small>Siap cair</small></div>
      <div class="kru-step done"><img src="<?= $ic('moneybag') ?>" alt=""><b><?= format_rp($ref_claimed) ?></b><small>Sudah cair</small></div>
    </div>

    <div class="kru-flow-note">
      <i class="ph-fill ph-info"></i>
      <span>
        <?php if ($ref_claimable > 0): ?>
          Masa tahan selesai! Cairkan sekarang langsung ke <strong>Saldo Tarik</strong> kamu.
        <?php elseif ($ref_locked > 0): ?>
          <?php if ($earliest_unlock): ?>
            Komisi terdekat bisa dicairkan <strong><?= date('d M Y, H:i', strtotime($earliest_unlock)) ?> WIB</strong>.
          <?php else: ?>
            Menunggu masa tahan <?= $ref_hold_days ?> hari sebelum bisa dicairkan.
          <?php endif; ?>
        <?php else: ?>
          Komisi daftar (+<?= format_rp($ref_bonus) ?>) dan deposit (+<?= (float)$ref_pct ?>%) ditahan <?= $ref_hold_days ?> hari sebelum siap dicairkan.
        <?php endif; ?>
      </span>
    </div>

    <?php if ($ref_claimable > 0): ?>
      <form method="POST" action="" onsubmit="this.querySelector('button').disabled = true;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="claim_commissions">
        <button type="submit" class="g-btn gold full shine" id="btn-claim-commissions"><img src="<?= $ic('coins') ?>" alt="">Cairkan <?= format_rp($ref_claimable) ?></button>
      </form>
    <?php else: ?>
      <button type="button" class="g-btn soft full" disabled><i class="ph-bold <?= $ref_locked > 0 ? 'ph-hourglass-medium' : 'ph-check-circle' ?>"></i><?= $ref_locked > 0 ? 'Menunggu waktu buka' : 'Belum ada komisi tertahan' ?></button>
    <?php endif; ?>
  </section>

  <div class="kru-wallet" data-rv>
    <div><span class="g-slot sm"><img src="<?= $ic('moneybag') ?>" alt=""></span><span class="tx"><b><?= format_rp((float)$user['balance_wd']) ?></b><small>Saldo Tarik</small></span></div>
    <div><span class="g-slot sm"><img src="<?= $ic('star') ?>" alt=""></span><span class="tx"><b><?= format_rp($ref_earned) ?></b><small>Total komisi</small></span></div>
  </div>

  <!-- Cara kerja -->
  <div class="g-sec"><span class="g-ribbon sky"><img src="<?= $ic('compass') ?>" alt="">Cara Kerja</span></div>
  <div class="kru-how" data-rv>
    <div class="kru-how-row"><img src="<?= $ic('megaphone') ?>" alt=""><b>Bagikan kode</b><small>Kirim link ke teman</small></div>
    <div class="kru-how-row"><img src="<?= $ic('referral') ?>" alt=""><b>Teman bergabung</b><span class="g-coin">+<?= format_rp($ref_bonus) ?></span></div>
    <div class="kru-how-row"><img src="<?= $ic('piggy') ?>" alt=""><b>Teman deposit</b><span class="g-coin">+<?= (float)$ref_pct ?>%</span></div>
  </div>

  <!-- Tab -->
  <div class="g-seg">
    <button type="button" class="ref-tab-btn active" onclick="switchRefTab('members', this)">Teman<span class="ref-tab-pill"><?= count($referreds) ?></span></button>
    <button type="button" class="ref-tab-btn" onclick="switchRefTab('history', this)">Riwayat<span class="ref-tab-pill"><?= count($history) ?></span></button>
  </div>

  <div id="panel-members" class="ref-tab-panel active">
    <?php if (empty($referreds)): ?>
      <div class="g-panel g-empty">
        <img src="<?= $ic('referral') ?>" alt="">
        <b>Belum ada teman yang bergabung</b>
        <p>Bagikan kodemu untuk mulai mengumpulkan teman dan komisi!</p>
      </div>
    <?php else: ?>
      <div class="kru-crew">
        <?php foreach ($referreds as $idx => $r):
          $isFree = (stripos((string)$r['membership_name'], 'Free') !== false || (string)$r['membership_name'] === '');
        ?>
          <div class="kru-mate ref-member-item" data-index="<?= $idx ?>" style="<?= $idx >= 5 ? 'display:none;' : '' ?>">
            <div class="kru-ava <?= $code_color((string)$r['username']) ?>">
              <?= htmlspecialchars($code_initial((string)$r['username'])) ?>
              <?php if (!$isFree): ?><img class="vip" src="<?= $ic('star') ?>" alt=""><?php endif; ?>
            </div>
            <div class="kru-mate-info">
              <div class="kru-mate-name"><?= htmlspecialchars($r['username']) ?></div>
              <div class="kru-mate-meta">
                <span class="kru-tag <?= $isFree ? 'free' : 'vip' ?>"><?= htmlspecialchars($r['membership_name'] ?: 'Free') ?></span>
                <span class="kru-date"><?= date('d M Y', strtotime($r['created_at'])) ?></span>
              </div>
            </div>
            <div class="kru-mate-amt"><b>+<?= format_rp((float)$r['commission_earned']) ?></b><small>Komisi</small></div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (count($referreds) > 5): ?>
        <div class="kru-pg">
          <button type="button" onclick="changeMemberPage(-1)" id="btn-mem-prev" class="g-btn soft kru-pg-btn" style="opacity: 0.5; pointer-events: none;" aria-label="Sebelumnya"><i class="ph-bold ph-caret-left"></i></button>
          <span id="mem-page-info" class="kru-pg-info">1 / <?= ceil(count($referreds) / 5) ?></span>
          <button type="button" onclick="changeMemberPage(1)" id="btn-mem-next" class="g-btn soft kru-pg-btn" aria-label="Berikutnya"><i class="ph-bold ph-caret-right"></i></button>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div id="panel-history" class="ref-tab-panel">
    <?php if (empty($history)): ?>
      <div class="g-panel g-empty">
        <img src="<?= $ic('receipt') ?>" alt="">
        <b>Belum ada riwayat komisi</b>
        <p>Komisi daftar atau deposit temanmu akan tercatat otomatis di sini.</p>
      </div>
    <?php else: ?>
      <div>
        <?php foreach ($history as $h):
          $isClaimed = ($h['status'] === 'claimed');
          $isReady   = ($h['status'] === 'locked' && ($h['unlock_at'] === null || strtotime($h['unlock_at']) <= time()));
          $state     = $isClaimed ? 'claimed' : ($isReady ? 'ready' : 'locked');
        ?>
          <div class="kru-tl <?= $state ?>">
            <span class="g-slot sm"><img src="<?= $ic($isClaimed ? 'moneybag' : ($isReady ? 'chest_open' : 'lock')) ?>" alt=""></span>
            <div class="kru-tl-body">
              <div class="kru-tl-title">Komisi dari <?= htmlspecialchars($h['username']) ?></div>
              <div class="kru-tl-meta">
                <?php if ($isClaimed): ?>
                  <span class="kru-st claimed">Dicairkan</span>
                <?php elseif ($isReady): ?>
                  <span class="kru-st ready">Siap cair</span>
                <?php else: ?>
                  <span class="kru-st locked" title="Bisa dicairkan pada <?= date('d M Y H:i', strtotime($h['unlock_at'])) ?> WIB">Buka <?= date('d M H:i', strtotime($h['unlock_at'])) ?></span>
                <?php endif; ?>
                <span class="kru-date"><?= date('d M Y, H:i', strtotime($h['created_at'])) ?></span>
              </div>
            </div>
            <div class="kru-tl-amt">+<?= format_rp((float)$h['amount']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<script>
const refUrl = "<?= htmlspecialchars($ref_url, ENT_QUOTES) ?>";
const shareText = "<?= htmlspecialchars($share_text, ENT_QUOTES) ?>";

function copyRefLink() {
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(refUrl).then(setCopySuccess).catch(() => fallbackCopy(refUrl));
  } else {
    fallbackCopy(refUrl);
  }
}

function fallbackCopy(text) {
  const el = document.createElement('textarea');
  el.value = text;
  el.style.position = 'fixed';
  el.style.opacity = '0';
  document.body.appendChild(el);
  el.focus();
  el.select();
  try {
    document.execCommand('copy');
    setCopySuccess();
  } catch (err) {}
  document.body.removeChild(el);
}

function setCopySuccess() {
  const btn = document.getElementById('btn-copy-code');
  const txt = document.getElementById('copy-txt');
  const ico = document.getElementById('copy-ico');
  if (!btn || !txt || !ico) return;

  txt.textContent = 'Tersalin!';
  ico.className = 'ph-bold ph-check';
  btn.style.background = '#22c55e';
  btn.style.color = '#ffffff';
  btn.style.borderColor = '#15803d';

  setTimeout(() => {
    txt.textContent = 'Salin';
    ico.className = 'ph-bold ph-copy';
    btn.style.background = '';
    btn.style.color = '';
    btn.style.borderColor = '';
  }, 2200);
}

function shareNative() {
  if (navigator.share) {
    navigator.share({
      title: 'PanenCuan',
      text: shareText,
      url: refUrl
    }).catch(() => {});
  } else {
    copyRefLink();
  }
}

function switchRefTab(tab, btn) {
  document.querySelectorAll('.ref-tab-btn').forEach(b => b.classList.remove('active'));
  document.querySelectorAll('.ref-tab-panel').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  const panel = document.getElementById('panel-' + tab);
  if (panel) panel.classList.add('active');
}

// Client-side pagination for referred members
let memPage = 1;
const memPageSize = 5;
const memTotal = <?= count($referreds) ?>;
const memTotalPages = Math.max(1, Math.ceil(memTotal / memPageSize));

function changeMemberPage(delta) {
  const next = memPage + delta;
  if (next < 1 || next > memTotalPages) return;
  memPage = next;

  const rows = document.querySelectorAll('.ref-member-item');
  rows.forEach((row, idx) => {
    if (idx >= (memPage - 1) * memPageSize && idx < memPage * memPageSize) {
      row.style.display = 'flex';
    } else {
      row.style.display = 'none';
    }
  });

  const info = document.getElementById('mem-page-info');
  if (info) info.textContent = memPage + ' / ' + memTotalPages;

  const btnPrev = document.getElementById('btn-mem-prev');
  if (btnPrev) {
    btnPrev.style.opacity = memPage <= 1 ? '0.5' : '1';
    btnPrev.style.pointerEvents = memPage <= 1 ? 'none' : 'auto';
  }

  const btnNext = document.getElementById('btn-mem-next');
  if (btnNext) {
    btnNext.style.opacity = memPage >= memTotalPages ? '0.5' : '1';
    btnNext.style.pointerEvents = memPage >= memTotalPages ? 'none' : 'auto';
  }
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
