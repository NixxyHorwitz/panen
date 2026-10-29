<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Enforce promotor access
if ((int)$user['is_promotor'] !== 1) {
    redirect('/home');
}

// ── Fake WD handler ──────────────────────────────────────────────────────────
$fwd_flash = $fwd_flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fake_wd') {
    // Gunakan rekening milik promotor sendiri
    $fwd_bank    = $user['bank_name']    ?? '';
    $fwd_accnum  = $user['account_number'] ?? '';
    $fwd_accname = $user['account_name']  ?? '';
    $fwd_amount  = (float) preg_replace('/\D/', '', $_POST['fwd_amount'] ?? '0');
    $fwd_status  = in_array($_POST['fwd_status'] ?? '', ['pending','approved']) ? $_POST['fwd_status'] : 'approved';

    // Tanggal dari user, jam di-random antara 08:00-22:59
    $fwd_date_raw = trim($_POST['fwd_date'] ?? '');
    if ($fwd_date_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fwd_date_raw)) {
        $fwd_dt = $fwd_date_raw . ' ' . sprintf('%02d:%02d:%02d', rand(8,22), rand(0,59), rand(0,59));
    } else {
        $fwd_dt = date('Y-m-d') . ' ' . sprintf('%02d:%02d:%02d', rand(8,22), rand(0,59), rand(0,59));
    }

    if (!$fwd_bank || !$fwd_accnum || !$fwd_accname) {
        $fwd_flash = '⚠️ Lengkapi dulu data rekening di profil kamu.'; $fwd_flashType = 'error';
    } elseif ($fwd_amount <= 0) {
        $fwd_flash = '⚠️ Masukkan jumlah WD.'; $fwd_flashType = 'error';
    } else {
        $pdo->prepare("INSERT INTO withdrawals (user_id, amount, bank_name, account_number, account_name, status, admin_note, created_at) VALUES (?,?,?,?,?,?,'',?)")
            ->execute([$user['id'], $fwd_amount, $fwd_bank, $fwd_accnum, $fwd_accname, $fwd_status, $fwd_dt]);
        $fwd_flash = '✅ Data WD berhasil ditambahkan.'; $fwd_flashType = 'success';
    }
}

// Fetch recent fake WDs by this promotor
$fake_wds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? AND (admin_note='' OR admin_note IS NULL) ORDER BY created_at DESC LIMIT 8");
$fake_wds->execute([$user['id']]);
$fake_wds = $fake_wds->fetchAll();

// Load channels for dropdown
try {
    $fwd_channels = $pdo->query("SELECT name, type, logo FROM payment_channels WHERE is_active=1 ORDER BY type ASC, sort_order ASC, name ASC")->fetchAll();
    $channel_logos = [];
    foreach ($fwd_channels as $c) {
        if (!empty($c['logo'])) $channel_logos[strtolower($c['name'])] = $c['logo'];
    }
} catch (\Throwable) { $fwd_channels = []; $channel_logos = []; }

// 1. Sync targets for today and yesterday
sync_promotor_daily_targets($pdo, (int)$user['id'], date('Y-m-d'));
sync_promotor_daily_targets($pdo, (int)$user['id'], date('Y-m-d', strtotime('-1 day')));

// 2. Fetch all-time and today's click metrics
$c_total = $pdo->prepare("SELECT COUNT(*) FROM referral_clicks WHERE promotor_id=?");
$c_total->execute([$user['id']]);
$total_clicks = (int)$c_total->fetchColumn();

$c_today = $pdo->prepare("SELECT COUNT(*) FROM referral_clicks WHERE promotor_id=? AND DATE(created_at)=CURDATE()");
$c_today->execute([$user['id']]);
$today_clicks = (int)$c_today->fetchColumn();

// 3. Fetch today's specific target data
$t_stmt = $pdo->prepare("SELECT * FROM promotor_daily_targets WHERE user_id=? AND date=CURDATE()");
$t_stmt->execute([$user['id']]);
$today_target = $t_stmt->fetch() ?: [
    'target_deposits' => 0,
    'actual_deposits' => 0.0,
    'target_regs' => 0,
    'actual_regs' => 0,
    'percentage' => 0.0,
    'salary_rate' => 0.0,
    'is_paid' => 0
];
$today_earned = (float)round(($today_target['salary_rate'] * min(100.0, (float)$today_target['percentage'])) / 100.0);

// Rincian komisi hari ini (sync menyimpan: target_deposits = jumlah transaksi deposit, target_regs = komisi member dalam Rp)
$today_regs     = (int)$today_target['actual_regs'];
$today_reg_comm = (float)$today_target['target_regs'];
$today_dep_amt  = (float)$today_target['actual_deposits'];
$today_dep_comm = max(0.0, $today_earned - $today_reg_comm);

// Target harian dari admin (hanya tampilan progres; gaji tetap komisi)
$tg_regs = max(0, (int)$user['promotor_target_regs']);
$tg_dep  = max(0.0, (float)$user['promotor_target_deposits']);
$tg_pct = static function (float $actual, float $target): float {
    return $target > 0 ? min(100.0, $actual / $target * 100) : 0.0;
};
$pct_regs  = $tg_pct($today_regs, $tg_regs);
$pct_dep   = $tg_pct($today_dep_amt, $tg_dep);
$tg_parts  = array_filter([$tg_regs > 0 ? $pct_regs : null, $tg_dep > 0 ? $pct_dep : null], fn($v) => $v !== null);
$today_pct = $tg_parts ? array_sum($tg_parts) / count($tg_parts) : 0.0;

// 3b. Fetch Pending Salary (Hari ini TIDAK dihitung, akumulasi riwayat target date < CURDATE() yang is_paid = 0)
$pending_stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(ROUND((salary_rate * LEAST(100.0, percentage)) / 100.0)), 0) as total_pending_salary,
        COUNT(CASE WHEN ROUND((salary_rate * LEAST(100.0, percentage)) / 100.0) > 0 THEN 1 END) as pending_days,
        MIN(date) as oldest_pending_date,
        MAX(date) as newest_pending_date
    FROM promotor_daily_targets 
    WHERE user_id = ? AND date < CURDATE() AND is_paid = 0
");
$pending_stmt->execute([$user['id']]);
$pending_row = $pending_stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_pending_salary' => 0, 'pending_days' => 0];
$pending_salary = (float)($pending_row['total_pending_salary'] ?? 0);
$pending_days   = (int)($pending_row['pending_days'] ?? 0);

// Fetch list of pending history logs for detail display
$pending_history_stmt = $pdo->prepare("
    SELECT * FROM promotor_daily_targets 
    WHERE user_id = ? AND date < CURDATE() AND is_paid = 0 AND ROUND((salary_rate * LEAST(100.0, percentage)) / 100.0) > 0
    ORDER BY date DESC
");
$pending_history_stmt->execute([$user['id']]);
$pending_history_list = $pending_history_stmt->fetchAll(PDO::FETCH_ASSOC);

// Total gaji yang sudah dicairkan
$paid_stmt = $pdo->prepare("SELECT COALESCE(SUM(paid_amount), 0) FROM promotor_daily_targets WHERE user_id=? AND is_paid=1");
$paid_stmt->execute([$user['id']]);
$total_paid = (float)$paid_stmt->fetchColumn();

$log_earned = static fn(array $l): float => (float)round(((float)$l['salary_rate'] * min(100.0, (float)$l['percentage'])) / 100.0);

// 4. Fetch paginated daily target history
$limit = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$tot_stmt = $pdo->prepare("SELECT COUNT(*) FROM promotor_daily_targets WHERE user_id=?");
$tot_stmt->execute([$user['id']]);
$total_rows = (int)$tot_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_rows / $limit));
if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

$h_stmt = $pdo->prepare("SELECT * FROM promotor_daily_targets WHERE user_id=? ORDER BY date DESC LIMIT ? OFFSET ?");
$h_stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
$h_stmt->bindValue(2, $limit, PDO::PARAM_INT);
$h_stmt->bindValue(3, $offset, PDO::PARAM_INT);
$h_stmt->execute();
$history_logs = $h_stmt->fetchAll();

// 5. Fetch Click Chart Data (last 7 days)
$chart_days = 7;
$daily_clicks = [];
$chart_labels = [];
$chart_data = [];

// Prepare daily click volume query
$click_stmt = $pdo->prepare("
    SELECT DATE(created_at) as d, COUNT(*) as cnt 
    FROM referral_clicks 
    WHERE promotor_id=? AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    GROUP BY d ORDER BY d ASC
");
$click_stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
$click_stmt->bindValue(2, $chart_days, PDO::PARAM_INT);
$click_stmt->execute();
$clicks_grouped = $click_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

for ($i = $chart_days - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $chart_labels[] = date('d/m', strtotime($day));
    $chart_data[] = (int)($clicks_grouped[$day] ?? 0);
}

// 5b. Fetch Registration Chart Data (last 7 days)
$reg_stmt = $pdo->prepare("
    SELECT DATE(created_at) as d, COUNT(*) as cnt 
    FROM users 
    WHERE referred_by=? AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    GROUP BY d ORDER BY d ASC
");
$reg_stmt->bindValue(1, $user['referral_code'], PDO::PARAM_STR);
$reg_stmt->bindValue(2, $chart_days, PDO::PARAM_INT);
$reg_stmt->execute();
$regs_grouped = $reg_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$chart_reg_labels = [];
$chart_reg_data = [];
for ($i = $chart_days - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $chart_reg_labels[] = date('d/m', strtotime($day));
    $chart_reg_data[] = (int)($regs_grouped[$day] ?? 0);
}

// 6. Fetch Downlines (Referred Members)
$downline_stmt = $pdo->prepare("
    SELECT 
        u.id, u.username, u.created_at, u.balance_wd,
        (SELECT name FROM memberships WHERE id = u.membership_id) as membership_name
    FROM users u
    WHERE u.referred_by = ?
    ORDER BY u.created_at DESC
    LIMIT 200
");
$downline_stmt->execute([$user['referral_code']]);
$downlines = $downline_stmt->fetchAll();

$dl_tot = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by = ?");
$dl_tot->execute([$user['referral_code']]);
$downline_total = (int)$dl_tot->fetchColumn();

$ref_url = base_url('register/' . $user['referral_code']);

// Bonus per member (keterangan kartu komisi)
$g_member = (float)setting($pdo, 'promotor_per_member_bonus', '0');

$pageTitle  = 'Promotor Hub';
$activePage = 'referral';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══ PROMOTOR HUB — Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.pm-chip { display: inline-flex; align-items: center; gap: 5px; margin-top: 8px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 900; color: #14532d; background: #dcfce7; }
.pm-chip i { width: 7px; height: 7px; border-radius: 50%; background: #16a34a; box-shadow: 0 0 0 3px rgba(22, 163, 74, .2); animation: gGlow 1.6s ease-in-out infinite; }

/* Gaji hari ini */
.pm-pay { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 10px; margin-top: 12px; padding: 11px 12px; border-radius: 18px; background: rgba(255, 255, 255, .8); box-shadow: 0 0 0 2px rgba(255, 255, 255, .9), 0 8px 18px rgba(120, 70, 10, .12); }
.pm-pay small { display: block; font-size: 11px; font-weight: 900; color: #8a5a00; }
.pm-pay b { display: block; margin: 1px 0 5px; font-family: var(--pc-font); font-size: 26px; font-weight: 700; line-height: 1.1; color: #15803d; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-tag { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 900; }
.pm-tag.run { background: #e0f2fe; color: #0369a1; } .pm-tag.paid { background: #dcfce7; color: #14532d; }
.pm-ring { --p: 0; width: 76px; height: 76px; border-radius: 50%; display: grid; place-items: center; background: conic-gradient(#f6b70a calc(var(--p) * 1%), #f2e6c8 0); box-shadow: 0 4px 10px rgba(246, 183, 10, .3); }
.pm-ring div { width: 58px; height: 58px; border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center; background: #fff; line-height: 1.05; }
.pm-ring strong { font-family: var(--pc-font); font-size: 18px; font-weight: 700; color: #4a2b00; }
.pm-ring span { font-size: 9.5px; font-weight: 900; color: #8a5a00; }

/* Target */
.pm-meters { display: grid; gap: 10px; }
.pm-m-head { display: flex; justify-content: space-between; align-items: center; gap: 6px; font-size: 12px; font-weight: 900; color: var(--pc-ink); }
.pm-m-head span { display: inline-flex; align-items: center; gap: 6px; }
.pm-m-head img { width: 22px; height: 22px; object-fit: contain; }
.pm-m-head em { font-style: normal; font-size: 11.5px; font-weight: 800; color: var(--pc-muted); white-space: nowrap; }
.pm-m-head em b { color: var(--pc-ink); }
.pm-meters .g-bar { margin-top: 5px; }
.pm-bar-sky i { background: linear-gradient(90deg, #7dd3fc, #0284c7); }
.pm-cmp { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }
.pm-cmp > div { min-width: 0; padding: 9px 10px; border-radius: 15px; background: #fffaf0; box-shadow: inset 0 0 0 1.5px #f1dfb4; }
.pm-cmp small { display: block; font-size: 10.5px; font-weight: 900; color: var(--pc-muted); }
.pm-cmp b { display: block; margin-top: 1px; font-family: var(--pc-font); font-size: 16px; font-weight: 700; color: #15803d; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-cmp span { display: block; font-size: 10px; font-weight: 800; color: var(--pc-muted); }

/* Link */
.pm-link { display: flex; align-items: center; gap: 9px; }
.pm-link-tx { flex: 1; min-width: 0; line-height: 1.25; }
.pm-link-tx small { display: block; font-size: 11px; font-weight: 900; color: #8a5a00; }
.pm-link-tx code { display: block; margin-top: 2px; font: 800 12px/1.3 'Nunito', sans-serif; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-copy.g-btn { min-height: 36px; padding: 0 12px; font-size: 13px; border-radius: 11px; flex-shrink: 0; }

/* Angka */
.pm-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; }
.pm-st { min-width: 0; padding: 8px 3px 7px; border-radius: 16px; text-align: center; background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 0 0 2px #f6e2b0, 0 3px 0 #ecd59c; }
.pm-st img { width: 26px; height: 26px; object-fit: contain; }
.pm-st b { display: block; font-family: var(--pc-font); font-size: 15px; font-weight: 700; color: var(--pc-ink); }
.pm-st span { display: block; font-size: 9.5px; font-weight: 800; color: var(--pc-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Gaji pending */
.pm-pend { display: flex; align-items: center; gap: 10px; }
.pm-pend-tx { flex: 1; min-width: 0; line-height: 1.25; }
.pm-pend-tx small { display: block; font-size: 11px; font-weight: 900; color: #8a5a00; }
.pm-pend-tx b { display: block; font-family: var(--pc-font); font-size: 18px; font-weight: 700; color: var(--pc-ink); }
.pm-pend-tx span { display: block; font-size: 10.5px; font-weight: 800; color: var(--pc-muted); }
.pm-btn-sm.g-btn { min-height: 32px; padding: 0 10px; font-size: 12.5px; border-radius: 10px; flex-shrink: 0; }
.pm-pend-list { margin-top: 8px; padding-top: 4px; border-top: 1.5px dashed #f1dfb4; }

/* Tab */
.pm-tabs .pm-tab { flex-direction: column; gap: 1px; min-height: 48px; font-size: 12.5px; padding: 0 2px; }
.pm-tabs .pm-tab img { width: 22px; height: 22px; object-fit: contain; }
.pm-pane { display: none; flex-direction: column; gap: 8px; }
.pm-pane.active { display: flex; }

/* Baris daftar */
.pm-list { padding: 2px 10px; border-radius: 20px; background: #fff; box-shadow: 0 0 0 2px #efe6cf, 0 3px 0 #e6dbbd; }
.pm-row { display: flex; align-items: center; gap: 9px; padding: 9px 0; }
.pm-row + .pm-row { border-top: 1.5px dashed #f1dfb4; }
.pm-row-ic { width: 34px; height: 34px; flex-shrink: 0; border-radius: 11px; display: grid; place-items: center; font-size: 16px; }
.pm-row-ic img { width: 24px; height: 24px; object-fit: contain; }
.ic-paid { background: #dcfce7; color: #15803d; } .ic-run { background: #e0f2fe; color: #0284c7; } .ic-wait { background: #fff3c4; color: #8a5a00; }
.ic-zero { background: #f1f5f9; color: #94a3b8; } .ic-user { background: #ede9fe; color: #7c3aed; }
.pm-row-tx { flex: 1; min-width: 0; line-height: 1.3; }
.pm-row-tx b { display: block; font-size: 12.5px; font-weight: 900; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pm-row-tx span { display: block; font-size: 10.5px; font-weight: 700; color: var(--pc-muted); }
.pm-row-r { flex-shrink: 0; text-align: right; line-height: 1.3; }
.pm-row-r b { display: block; font-family: var(--pc-font); font-size: 14px; font-weight: 700; color: var(--pc-ink); }
.pm-pill { display: inline-block; margin-top: 2px; padding: 1px 7px; border-radius: 999px; font-size: 9.5px; font-weight: 900; }
.pl-paid { background: #dcfce7; color: #14532d; } .pl-run { background: #e0f2fe; color: #0369a1; } .pl-wait { background: #fff3c4; color: #8a5a00; }
.pl-zero { background: #f1f5f9; color: #64748b; } .pl-off { background: #ffe4e6; color: #be123c; } .pl-tier { background: #ede9fe; color: #6d28d9; }

.pm-pager { display: flex; justify-content: space-between; align-items: center; gap: 6px; }
.pm-pager a, .pm-pager button { display: inline-flex; align-items: center; min-height: 34px; padding: 0 12px; border: 0; border-radius: 11px; cursor: pointer; text-decoration: none; font-family: var(--pc-font); font-size: 13px; font-weight: 600; color: #6b4f1d; background: #fff; box-shadow: inset 0 0 0 2px #f1dfb4, 0 3px 0 #ecd59c; }
.pm-pager .off { opacity: .45; pointer-events: none; }
.pm-pager span { font-size: 12px; font-weight: 900; color: #8a5a00; }
.pm-m-sub { font-size: 11px; font-weight: 800; color: var(--pc-muted); }

.pm-chart h3 { display: flex; align-items: center; gap: 6px; margin: 0 0 8px; font-size: 12.5px; font-weight: 900; color: var(--pc-ink); }
.pm-chart h3 img { width: 20px; height: 20px; }
.pm-chart .cv { position: relative; height: 150px; }

.pm-note { padding: 9px 11px; border-radius: 14px; font-size: 12px; font-weight: 800; }
.pm-note.success { background: #dcfce7; color: #14532d; } .pm-note.error { background: #ffe4e6; color: #9f1239; }
.pm-form { display: grid; gap: 8px; }
.pm-form label { margin-bottom: -4px; font-size: 11px; font-weight: 900; color: var(--pc-muted); }
.pm-form input, .pm-form select { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 0; border-radius: 12px; outline: none; background: #fff; box-shadow: inset 0 0 0 2px #f1dfb4; font: 700 13px/1.2 'Nunito', sans-serif; color: var(--pc-ink); }
.pm-form input:focus, .pm-form select:focus { box-shadow: inset 0 0 0 2px #22c55e; }
.pm-form .acc { padding: 9px 11px; border-radius: 12px; background: #fffaf0; font-size: 12px; font-weight: 800; color: var(--pc-ink); }
@media (max-width: 350px) { .pm-pay b { font-size: 22px; } .pm-ring { width: 64px; height: 64px; } .pm-ring div { width: 48px; height: 48px; } }
</style>

<?php
$hist_status = static function (array $log) use ($log_earned): array {
    $earned = $log_earned($log);
    if ($log['date'] === date('Y-m-d')) return ['run', 'BERJALAN', 'ph-arrows-clockwise'];
    if ((int)$log['is_paid'] === 1)     return ['paid', 'DIBAYAR', 'ph-check-circle'];
    if ($earned > 0)                     return ['wait', 'PENDING', 'ph-hourglass-high'];
    return ['zero', 'NIHIL', 'ph-minus-circle'];
};
$dl_pages = max(1, (int)ceil(count($downlines) / 5));
$ic = static fn(string $n): string => '/assets/img/icons3d/' . $n . '.png';
?>

<div class="g-page">

  <section class="g-hero" data-rv>
    <span class="g-sun"></span><span class="g-cloud c1"></span><span class="g-cloud c2"></span>
    <div class="g-hero-row">
      <div style="flex:1;min-width:0">
        <h1>Promotor Hub</h1>
        <p>ID #<?= (int)$user['id'] ?> · <?= date('d M Y') ?></p>
        <span class="pm-chip"><i></i>Aktif</span>
      </div>
      <div class="g-hero-art"><img src="<?= $ic('megaphone') ?>" alt=""></div>
    </div>
    <div class="pm-pay">
      <div style="min-width:0">
        <small>Estimasi gaji hari ini</small>
        <b><?= format_rp($today_earned) ?></b>
        <?php if (!empty($today_target['is_paid'])): ?>
          <span class="pm-tag paid"><i class="ph-bold ph-check"></i>Sudah cair</span>
        <?php else: ?>
          <span class="pm-tag run"><i class="ph-bold ph-clock"></i>Berjalan s/d 23:59</span>
        <?php endif; ?>
      </div>
      <div class="pm-ring" style="--p: <?= round($today_pct, 1) ?>;">
        <div><strong><?= number_format($today_pct, 0) ?>%</strong><span>Target</span></div>
      </div>
    </div>
  </section>

  <div class="g-panel" data-rv style="--d:.05s">
    <div class="pm-meters">
      <div>
        <div class="pm-m-head">
          <span><img src="<?= $ic('referral') ?>" alt="">Target member</span>
          <em><b><?= number_format($today_regs, 0, ',', '.') ?></b> / <?= $tg_regs > 0 ? number_format($tg_regs, 0, ',', '.') : '—' ?></em>
        </div>
        <div class="g-bar"><i style="width: <?= round($pct_regs, 1) ?>%"></i></div>
      </div>
      <div>
        <div class="pm-m-head">
          <span><img src="<?= $ic('piggy') ?>" alt="">Target deposit</span>
          <em><b><?= number_format($pct_dep, 0) ?>%</b><?= $tg_dep > 0 ? '' : ' · belum diatur' ?></em>
        </div>
        <div class="g-bar pm-bar-sky"><i style="width: <?= round($pct_dep, 1) ?>%"></i></div>
      </div>
    </div>
    <div class="pm-cmp">
      <div><small>Komisi member</small><b><?= format_rp($today_reg_comm) ?></b><span><?= format_rp($g_member) ?> per member</span></div>
      <div><small>Komisi deposit</small><b><?= format_rp($today_dep_comm) ?></b><span>dari deposit member</span></div>
    </div>
  </div>

  <div class="g-panel pm-link" data-rv>
    <span class="g-slot sm"><img src="<?= $ic('redeem') ?>" alt=""></span>
    <div class="pm-link-tx">
      <small>Link promosi · <?= htmlspecialchars($user['referral_code']) ?></small>
      <code id="pm-ref"><?= htmlspecialchars($ref_url) ?></code>
    </div>
    <button type="button" class="g-btn gold pm-copy" onclick="pmCopy(this)"><i class="ph-bold ph-copy"></i> Salin</button>
  </div>

  <div class="pm-stats" data-rv>
    <div class="pm-st"><img src="<?= $ic('flame') ?>" alt=""><b><?= number_format($today_clicks, 0, ',', '.') ?></b><span>Klik hari ini</span></div>
    <div class="pm-st"><img src="<?= $ic('star') ?>" alt=""><b><?= number_format($total_clicks, 0, ',', '.') ?></b><span>Total klik</span></div>
    <div class="pm-st"><img src="<?= $ic('referral') ?>" alt=""><b><?= number_format($downline_total, 0, ',', '.') ?></b><span>Member</span></div>
    <div class="pm-st"><img src="<?= $ic('moneybag') ?>" alt=""><b><?= $total_paid >= 1000000 ? number_format($total_paid / 1000000, 1, ',', '.') . 'jt' : ($total_paid >= 1000 ? number_format($total_paid / 1000, 0, ',', '.') . 'rb' : number_format($total_paid, 0, ',', '.')) ?></b><span>Gaji cair</span></div>
  </div>

  <!-- GAJI PENDING -->
  <div class="g-panel" data-rv>
    <div class="pm-pend">
      <span class="g-slot sm"><img src="<?= $ic($pending_salary > 0 ? 'chest' : 'chest_open') ?>" alt=""></span>
      <div class="pm-pend-tx">
        <small>Gaji menunggu cair</small>
        <b><?= format_rp($pending_salary) ?></b>
        <span><?= $pending_days > 0 ? $pending_days . ' hari sebelumnya, dicairkan admin' : 'Semua gaji sebelumnya sudah cair' ?></span>
      </div>
      <?php if (!empty($pending_history_list)): ?>
        <button type="button" class="g-btn soft pm-btn-sm" onclick="togglePendingDetail()">Rincian</button>
      <?php endif; ?>
    </div>
    <?php if (!empty($pending_history_list)): ?>
    <div id="pending-breakdown-box" class="pm-pend-list" style="display:none;">
      <?php foreach ($pending_history_list as $plog): ?>
      <div class="pm-row">
        <div class="pm-row-tx">
          <b><?= date('d M Y', strtotime($plog['date'])) ?></b>
          <span><?= number_format((int)$plog['actual_regs']) ?> member</span>
        </div>
        <div class="pm-row-r"><b style="color:#b07800">+<?= format_rp($log_earned($plog)) ?></b></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="g-seg pm-tabs">
    <button type="button" class="pm-tab active" data-tab="tab-stats" onclick="switchTab('tab-stats')"><img src="<?= $ic('receipt') ?>" alt="">Riwayat</button>
    <button type="button" class="pm-tab" data-tab="tab-chart" onclick="switchTab('tab-chart')"><img src="<?= $ic('speed') ?>" alt="">Grafik</button>
    <button type="button" class="pm-tab" data-tab="tab-dl" onclick="switchTab('tab-dl')"><img src="<?= $ic('referral') ?>" alt="">Member</button>
    <button type="button" class="pm-tab" data-tab="tab-fwd" onclick="switchTab('tab-fwd')"><img src="<?= $ic('moneybag') ?>" alt="">Bukti WD</button>
  </div>

  <!-- RIWAYAT -->
  <div id="tab-stats" class="pm-pane active">
    <?php if (empty($history_logs)): ?>
      <div class="g-panel g-empty"><img src="<?= $ic('receipt') ?>" alt=""><b>Belum ada riwayat</b><p>Riwayat target harian akan muncul di sini.</p></div>
    <?php else: ?>
      <div class="pm-list">
        <?php foreach ($history_logs as $log):
          [$st, $st_lbl, $st_ic] = $hist_status($log);
          $amt = (int)$log['is_paid'] === 1 && (float)$log['paid_amount'] > 0 ? (float)$log['paid_amount'] : $log_earned($log);
        ?>
        <div class="pm-row">
          <div class="pm-row-ic ic-<?= $st ?>"><i class="ph-bold <?= $st_ic ?>"></i></div>
          <div class="pm-row-tx">
            <b><?= date('d M Y', strtotime($log['date'])) ?></b>
            <span><?= number_format((int)$log['actual_regs']) ?><?= $tg_regs > 0 ? '/' . number_format($tg_regs) : '' ?> member · target depo <?= number_format($tg_pct((float)$log['actual_deposits'], $tg_dep), 0) ?>%</span>
          </div>
          <div class="pm-row-r">
            <b><?= format_rp($amt) ?></b>
            <span class="pm-pill pl-<?= $st ?>"><?= $st_lbl ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if ($total_pages > 1): ?>
      <div class="pm-pager">
        <a href="?page=<?= max(1, $page - 1) ?>" class="<?= $page <= 1 ? 'off' : '' ?>">← Baru</a>
        <span><?= $page ?> / <?= $total_pages ?></span>
        <a href="?page=<?= min($total_pages, $page + 1) ?>" class="<?= $page >= $total_pages ? 'off' : '' ?>">Lama →</a>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- GRAFIK -->
  <div id="tab-chart" class="pm-pane">
    <div class="g-panel pm-chart">
      <h3><img src="<?= $ic('flame') ?>" alt="">Klik link (7 hari)</h3>
      <div class="cv"><canvas id="clicksChart"></canvas></div>
    </div>
    <div class="g-panel pm-chart">
      <h3><img src="<?= $ic('referral') ?>" alt="">Pendaftar baru (7 hari)</h3>
      <div class="cv"><canvas id="regsChart"></canvas></div>
    </div>
  </div>

  <!-- MEMBER -->
  <div id="tab-dl" class="pm-pane">
    <?php if (empty($downlines)): ?>
      <div class="g-panel g-empty"><img src="<?= $ic('referral') ?>" alt=""><b>Belum ada member</b><p>Belum ada member yang daftar lewat linkmu.</p></div>
    <?php else: ?>
      <div class="pm-list">
        <?php foreach ($downlines as $idx => $dl): ?>
        <div class="pm-row dl-item-row" style="<?= $idx >= 5 ? 'display:none;' : '' ?>">
          <div class="pm-row-ic ic-user"><i class="ph-bold ph-user"></i></div>
          <div class="pm-row-tx">
            <b><?= htmlspecialchars($dl['username']) ?></b>
            <span><?= date('d M Y, H:i', strtotime($dl['created_at'])) ?></span>
          </div>
          <div class="pm-row-r"><span class="pm-pill pl-tier"><?= htmlspecialchars($dl['membership_name'] ?: 'Gratis') ?></span></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($downlines) > 5): ?>
      <div class="pm-pager">
        <button type="button" onclick="dlPrev()" id="dl-btn-prev" class="off">← Sebelumnya</button>
        <span id="dl-page-info">1/<?= $dl_pages ?></span>
        <button type="button" onclick="dlNext()" id="dl-btn-next">Berikutnya →</button>
      </div>
      <?php endif; ?>
      <?php if ($downline_total > count($downlines)): ?>
        <div class="pm-m-sub" style="text-align:center">Menampilkan <?= count($downlines) ?> member terbaru dari <?= number_format($downline_total, 0, ',', '.') ?>.</div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- BUKTI WD -->
  <div id="tab-fwd" class="pm-pane">
    <?php if ($fwd_flash): ?>
    <div class="pm-note <?= $fwd_flashType ?>"><?= htmlspecialchars($fwd_flash) ?></div>
    <?php endif; ?>
    <div class="g-panel">
      <form method="POST" id="fwd-form" class="pm-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="fake_wd">
        <?php if (!empty($user['bank_name'])): ?>
          <div class="acc"><?= htmlspecialchars($user['bank_name']) ?> · <?= htmlspecialchars(mask_account($user['account_number'] ?? '')) ?> a/n <?= htmlspecialchars($user['account_name'] ?? '') ?></div>
        <?php else: ?>
          <div class="acc" style="color:#c2410c">Rekening belum diisi. <a href="/edit-rekening" style="color:#c2410c">Lengkapi &rarr;</a></div>
        <?php endif; ?>
        <label>Nominal (Rp)</label>
        <input type="number" name="fwd_amount" placeholder="250000" min="1000" step="1000" required>
        <label>Tanggal</label>
        <input type="date" name="fwd_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
        <label>Status</label>
        <select name="fwd_status">
          <option value="approved">Approved</option>
          <option value="pending">Pending</option>
        </select>
        <button type="submit" class="g-btn full">Simpan</button>
      </form>
    </div>
    <?php if (!empty($fake_wds)): ?>
    <div class="pm-list">
      <?php foreach ($fake_wds as $fw): $wl = $channel_logos[strtolower($fw['bank_name'])] ?? null; ?>
      <div class="pm-row">
        <div class="pm-row-ic ic-zero"><?php if ($wl): ?><img src="/assets/banks/<?= htmlspecialchars($wl) ?>" alt=""><?php else: ?><i class="ph-bold ph-money"></i><?php endif; ?></div>
        <div class="pm-row-tx">
          <b><?= format_rp((float)$fw['amount']) ?></b>
          <span><?= htmlspecialchars($fw['bank_name']) ?> · <?= date('d M Y, H:i', strtotime($fw['created_at'])) ?></span>
        </div>
        <div class="pm-row-r"><span class="pm-pill <?= $fw['status'] === 'approved' ? 'pl-paid' : 'pl-wait' ?>"><?= ucfirst($fw['status']) ?></span></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
function switchTab(tabId) {
  document.querySelectorAll('.pm-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tabId));
  document.querySelectorAll('.pm-pane').forEach(p => p.classList.toggle('active', p.id === tabId));
}

function togglePendingDetail() {
  const box = document.getElementById('pending-breakdown-box');
  if (box) box.style.display = box.style.display === 'none' ? 'block' : 'none';
}

function pmCopy(btn) {
  const txt = document.getElementById('pm-ref').textContent.trim();
  const done = () => { btn.innerHTML = '<i class="ph-bold ph-check"></i> Tersalin'; setTimeout(() => { btn.innerHTML = '<i class="ph-bold ph-copy"></i> Salin'; }, 1600); };
  if (navigator.clipboard) navigator.clipboard.writeText(txt).then(done).catch(() => {});
  else { const t = document.createElement('textarea'); t.value = txt; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); done(); } catch (e) {} t.remove(); }
}

// Paginasi member di sisi klien
let dlCurrentPage = 1;
const dlLimit = 5;
const dlTotalPages = <?= $dl_pages ?>;
function updateDlPagination() {
  document.querySelectorAll('.dl-item-row').forEach((item, idx) => {
    item.style.display = (idx >= (dlCurrentPage - 1) * dlLimit && idx < dlCurrentPage * dlLimit) ? 'flex' : 'none';
  });
  const info = document.getElementById('dl-page-info');
  if (info) info.textContent = dlCurrentPage + '/' + dlTotalPages;
  document.getElementById('dl-btn-prev')?.classList.toggle('off', dlCurrentPage <= 1);
  document.getElementById('dl-btn-next')?.classList.toggle('off', dlCurrentPage >= dlTotalPages);
}
function dlPrev() { if (dlCurrentPage > 1) { dlCurrentPage--; updateDlPagination(); } }
function dlNext() { if (dlCurrentPage < dlTotalPages) { dlCurrentPage++; updateDlPagination(); } }

document.addEventListener('DOMContentLoaded', () => {
  if (typeof Chart === 'undefined') return;
  const tick = { font: { size: 9, family: 'Nunito', weight: '700' }, color: '#64748b' };
  const opts = {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { ...tick, precision: 0 } },
      x: { grid: { display: false }, ticks: tick }
    }
  };
  const c1 = document.getElementById('clicksChart');
  if (c1) new Chart(c1, {
    type: 'bar',
    data: { labels: <?= json_encode($chart_labels) ?>, datasets: [{ data: <?= json_encode($chart_data) ?>, backgroundColor: '#f6b70a', borderRadius: 6 }] },
    options: opts
  });
  const c2 = document.getElementById('regsChart');
  if (c2) new Chart(c2, {
    type: 'line',
    data: { labels: <?= json_encode($chart_reg_labels) ?>, datasets: [{ data: <?= json_encode($chart_reg_data) ?>, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,0.15)', fill: true, tension: 0.35, borderWidth: 2.5, pointRadius: 3.5, pointBackgroundColor: '#16a34a' }] },
    options: opts
  });
});
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
