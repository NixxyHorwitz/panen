<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = $flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refund_wd_hold' && !csrf_verify()) {
    $flash = 'Sesi keamanan kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    $flashType = 'error';
    $_GET['tab'] = 'withdraw';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'refund_wd_hold') {
    $wd_id = (int)($_POST['wd_id'] ?? 0);
    $stmtPending = $pdo->prepare("SELECT id FROM admin_requests WHERE user_id=? AND type='refund_wd_hold' AND status='pending' AND payload LIKE ?");
    $stmtPending->execute([$user['id'], '%"withdrawal_id":'.$wd_id.'%']);
    if ($stmtPending->fetchColumn()) {
        $flash = 'Permintaan pengembalian untuk penarikan ini sudah diajukan.';
        $flashType = 'error';
    } else {
        $w = $pdo->prepare("SELECT * FROM withdrawals WHERE id=? AND user_id=? AND status='hold'");
        $w->execute([$wd_id, $user['id']]);
        $wData = $w->fetch();
        if (!$wData) {
            $flash = 'Withdraw tidak ditemukan atau bukan berstatus Hold.';
            $flashType = 'error';
        } else {
            $payload = json_encode(['withdrawal_id' => $wd_id]);
            $pdo->prepare("INSERT INTO admin_requests (user_id, type, payload) VALUES (?, 'refund_wd_hold', ?)")
                ->execute([$user['id'], $payload]);
            $req_id = $pdo->lastInsertId();
            
            $msg  = "💰 <b>REQUEST PENGEMBALIAN WD HOLD</b>\n\n";
            $msg .= "👤 User: <code>{$user['username']}</code>\n";
            $msg .= "💸 Jumlah WD: <b>" . format_rp((float)$wData['amount']) . "</b>\n";
            $msg .= "🏦 Tujuan Awal: {$wData['bank_name']} - {$wData['account_number']}\n\n";
            $msg .= "⚠️ <i>Refund ini akan mengembalikan saldo WD yang ditahan (Hold) ke Saldo Tarik user secara utuh.</i>\n";
            $kb = [
                [['text'=>'✅ Approve Refund', 'callback_data'=>'req_approve_'.$req_id], ['text'=>'❌ Reject', 'callback_data'=>'req_reject_'.$req_id]]
            ];
            tg_store_msg_id($pdo, 'admin_requests', (int)$req_id, send_telegram_notif($pdo, $msg, $kb, 'permintaan'));
            
            $flash = 'Permintaan refund telah dikirim ke admin untuk diverifikasi.';
            $flashType = 'success';
        }
    }
    $_GET['tab'] = 'withdraw';
}

$tab = $_GET['tab'] ?? 'all';

// Payment Channels Logos
$channels = $pdo->query("SELECT name, logo FROM payment_channels WHERE logo IS NOT NULL AND logo != ''")->fetchAll();
$channel_logos = [];
foreach ($channels as $c) {
    $channel_logos[strtolower($c['name'])] = $c['logo'];
}

// ── 1. Withdrawals ──
$wds = $pdo->prepare("SELECT * FROM withdrawals WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
$wds->execute([$user['id']]);
$wds = $wds->fetchAll(PDO::FETCH_ASSOC);

// Pending Refund Requests for Hold WDs
$pending_refunds = $pdo->prepare("SELECT payload FROM admin_requests WHERE user_id=? AND type='refund_wd_hold' AND status='pending'");
$pending_refunds->execute([$user['id']]);
$requested_wds = [];
foreach ($pending_refunds->fetchAll() as $pr) {
    $p = json_decode((string)$pr['payload'], true);
    if (isset($p['withdrawal_id'])) {
        $requested_wds[] = (int)$p['withdrawal_id'];
    }
}

// ── 2. Deposits ──
$deposits = $pdo->prepare("SELECT * FROM deposits WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
$deposits->execute([$user['id']]);
$deposits = $deposits->fetchAll(PDO::FETCH_ASSOC);

// ── 3. Watch Video Rewards ──
$rewards = $pdo->prepare("
    SELECT wh.*, v.title as video_title 
    FROM watch_history wh 
    LEFT JOIN videos v ON v.id = wh.video_id 
    WHERE wh.user_id = ? 
    ORDER BY wh.watched_at DESC LIMIT 50
");
$rewards->execute([$user['id']]);
$rewards = $rewards->fetchAll(PDO::FETCH_ASSOC);

// ── 5. User Surveys ──
$surveys = $pdo->prepare("SELECT * FROM user_surveys WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
$surveys->execute([$user['id']]);
$surveys = $surveys->fetchAll(PDO::FETCH_ASSOC);

// ── 6. User Missions Claimed ──
$missions = $pdo->prepare("
    SELECT um.*, m.title as mission_title, m.reward_amount, m.icon as mission_icon 
    FROM user_missions um 
    JOIN missions m ON m.slug = um.mission_slug 
    WHERE um.user_id=? AND um.claimed_at IS NOT NULL 
    ORDER BY um.claimed_at DESC LIMIT 50
");
$missions->execute([$user['id']]);
$missions = $missions->fetchAll(PDO::FETCH_ASSOC);

// ── 7. Referral Commissions ──
$commissions = $pdo->prepare("
    SELECT rc.*, u.username as from_username 
    FROM referral_commissions rc 
    LEFT JOIN users u ON u.id = rc.from_user_id 
    WHERE rc.user_id=? 
    ORDER BY rc.created_at DESC LIMIT 50
");
$commissions->execute([$user['id']]);
$commissions = $commissions->fetchAll(PDO::FETCH_ASSOC);

// ── 8. User Redeems ──
$redeems = $pdo->prepare("
    SELECT ur.*, rc.code, rc.reward_wd, rc.reward_dep 
    FROM user_redeems ur 
    JOIN redeem_codes rc ON rc.id = ur.code_id 
    WHERE ur.user_id=? 
    ORDER BY ur.claimed_at DESC LIMIT 20
");
$redeems->execute([$user['id']]);
$redeems = $redeems->fetchAll(PDO::FETCH_ASSOC);

// ── 9. Upgrade Orders ──
$upgrades = $pdo->prepare("
    SELECT uo.*, m.name as membership_name 
    FROM upgrade_orders uo 
    LEFT JOIN memberships m ON m.id = uo.membership_id 
    WHERE uo.user_id=? 
    ORDER BY uo.created_at DESC LIMIT 20
");
$upgrades->execute([$user['id']]);
$upgrades = $upgrades->fetchAll(PDO::FETCH_ASSOC);

// ── 10. Penjualan Hasil Panen (Pasar Pengepul, Sawah Panen Cuan) ──
$farm_sales = [];
$farm_sales_total = 0.0;
try {
    $fs = $pdo->prepare("SELECT id, qty, amount, created_at FROM farm_logs WHERE user_id = ? AND action = 'sell_all' ORDER BY id DESC LIMIT 50");
    $fs->execute([$user['id']]);
    $farm_sales = $fs->fetchAll(PDO::FETCH_ASSOC);
    $fst = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM farm_logs WHERE user_id = ? AND action = 'sell_all'");
    $fst->execute([$user['id']]);
    $farm_sales_total = (float)$fst->fetchColumn();
} catch (\Throwable $e) {
    $farm_sales = [];
}

// ── Summary Totals ──
$total_earned = (float)($user['total_earned'] ?? 0);
$balance_wd   = (float)($user['balance_wd'] ?? 0);

$stmtDepSum = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM deposits WHERE user_id=? AND status='confirmed'");
$stmtDepSum->execute([$user['id']]);
$total_dep = (float)$stmtDepSum->fetchColumn();

$stmtWdSum = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawals WHERE user_id=? AND status='approved'");
$stmtWdSum->execute([$user['id']]);
$total_wd = (float)$stmtWdSum->fetchColumn();

// ── Generate Unified "Semua" Timeline ──
$unified = [];

foreach ($wds as $w) {
    $unified[] = [
        'type'        => 'withdraw',
        'sub_type'    => 'Penarikan Dana',
        'title'       => 'Tarik ke ' . $w['bank_name'],
        'sub'         => $w['account_number'] . ' · a.n. ' . $w['account_name'],
        'amount'      => (float)$w['amount'],
        'is_income'   => false,
        'status'      => $w['status'],
        'admin_note'  => $w['admin_note'],
        'time'        => $w['created_at'],
        'icon'        => 'ph-arrow-up-right',
        'logo'        => $channel_logos[strtolower((string)$w['bank_name'])] ?? null,
        'raw'         => $w
    ];
}

foreach ($deposits as $d) {
    $unified[] = [
        'type'        => 'deposit',
        'sub_type'    => 'Deposit Saldo',
        'title'       => 'Deposit ' . strtoupper((string)$d['method']),
        'sub'         => 'Metode ' . strtoupper((string)$d['method']),
        'amount'      => (float)$d['amount'],
        'is_income'   => true,
        'status'      => $d['status'],
        'admin_note'  => $d['status'] === 'confirmed' ? null : $d['admin_note'], // deposit sukses tanpa keterangan
        'time'        => $d['created_at'],
        'icon'        => strtolower((string)$d['method']) === 'qris' ? 'ph-qr-code' : 'ph-wallet',
        'logo'        => $channel_logos[strtolower((string)$d['method'])] ?? null,
        'raw'         => $d
    ];
}

foreach ($rewards as $r) {
    $unified[] = [
        'type'        => 'reward',
        'sub_type'    => 'Nonton Video',
        'title'       => $r['video_title'] ?: ('Video #' . $r['video_id']),
        'sub'         => 'Reward Menonton Video',
        'amount'      => (float)$r['reward_given'],
        'is_income'   => true,
        'status'      => 'approved',
        'admin_note'  => null,
        'time'        => $r['watched_at'],
        'icon'        => 'ph-play-circle',
        'logo'        => null,
        'raw'         => $r
    ];
}

foreach ($surveys as $sv) {
    $unified[] = [
        'type'        => 'bonus',
        'sub_type'    => 'Survei Pengguna',
        'title'       => 'Hadiah Survei PanenCuan',
        'sub'         => 'Rating: ' . $sv['satisfaction_rating'] . '/5 · Sumber: ' . $sv['source_info'],
        'amount'      => (float)$sv['reward_amount'],
        'is_income'   => $sv['status'] !== 'revoked',
        'status'      => $sv['status'],
        'admin_note'  => $sv['revoke_reason'],
        'time'        => $sv['created_at'],
        'icon'        => 'ph-clipboard-text',
        'logo'        => null,
        'raw'         => $sv
    ];
}

foreach ($missions as $m) {
    $unified[] = [
        'type'        => 'bonus',
        'sub_type'    => 'Quest Harian',
        'title'       => $m['mission_title'],
        'sub'         => 'Klaim Hadiah Misi Selesai',
        'amount'      => (float)$m['reward_amount'],
        'is_income'   => true,
        'status'      => 'approved',
        'admin_note'  => null,
        'time'        => $m['claimed_at'],
        'icon'        => $m['mission_icon'] ?: 'ph-target',
        'logo'        => null,
        'raw'         => $m
    ];
}

foreach ($commissions as $cm) {
    $unified[] = [
        'type'        => 'bonus',
        'sub_type'    => 'Komisi Referral',
        'title'       => 'Komisi dari ' . ($cm['from_username'] ?: 'Teman'),
        'sub'         => 'Bonus Afiliasi Akun',
        'amount'      => (float)$cm['amount'],
        'is_income'   => true,
        'status'      => $cm['status'] ?? 'approved',
        'admin_note'  => null,
        'time'        => $cm['claimed_at'] ?: $cm['created_at'],
        'icon'        => 'ph-users-three',
        'logo'        => null,
        'raw'         => $cm
    ];
}

foreach ($redeems as $rd) {
    $rdAmt = (float)$rd['reward_wd'] > 0 ? (float)$rd['reward_wd'] : (float)$rd['reward_dep'];
    $unified[] = [
        'type'        => 'bonus',
        'sub_type'    => 'Voucher Redeem',
        'title'       => 'Kode Voucher: ' . $rd['code'],
        'sub'         => 'Klaim Kode Promo',
        'amount'      => $rdAmt,
        'is_income'   => true,
        'status'      => 'approved',
        'admin_note'  => null,
        'time'        => $rd['claimed_at'],
        'icon'        => 'ph-ticket',
        'logo'        => null,
        'raw'         => $rd
    ];
}

foreach ($upgrades as $upg) {
    $unified[] = [
        'type'        => 'upgrade',
        'sub_type'    => 'Upgrade Membership',
        'title'       => 'Upgrade Level ' . ($upg['membership_name'] ?: 'VIP'),
        'sub'         => 'Pembelian Paket Level Akun',
        'amount'      => (float)$upg['amount'],
        'is_income'   => false,
        'status'      => $upg['status'],
        'admin_note'  => $upg['admin_note'],
        'time'        => $upg['created_at'],
        'icon'        => 'ph-crown',
        'logo'        => null,
        'raw'         => $upg
    ];
}

foreach ($farm_sales as $sale) {
    $unified[] = [
        'type'        => 'farm',
        'sub_type'    => 'Jual Panen',
        'title'       => 'Jual ' . (int)$sale['qty'] . ' hasil panen di Pasar Pengepul',
        'sub'         => null,
        'amount'      => (float)$sale['amount'],
        'is_income'   => true,
        'status'      => 'approved',
        'admin_note'  => null,
        'time'        => $sale['created_at'],
        'icon'        => 'ph-basket',
        'logo'        => null,
        'raw'         => $sale
    ];
}

// Sort unified by time descending
usort($unified, function(array $a, array $b): int {
    return strtotime((string)$b['time']) <=> strtotime((string)$a['time']);
});

// Group counts for tabs
$counts = [
    'all'      => count($unified),
    'farm'     => count($farm_sales),
    'withdraw' => count($wds),
    'deposit'  => count($deposits),
    'reward'   => count($rewards),
    'bonus'    => count($surveys) + count($missions) + count($commissions) + count($redeems),
];

$pageTitle  = 'Riwayat Transaksi';
$activePage = 'history';
require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══ RIWAYAT — Jurnal Panen: grafik arus + linimasa · Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.hx-flash { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 16px; font-size: 12px; font-weight: 800; }
.hx-flash i { font-size: 18px; flex-shrink: 0; }
.hx-flash.success { background: #dcfce7; color: #14532d; box-shadow: inset 0 0 0 2px #bbf7d0; }
.hx-flash.error { background: #ffe4e6; color: #9f1239; box-shadow: inset 0 0 0 2px #fecdd3; }

/* Kepala ringkas */
.hx-top { display: flex; align-items: center; gap: 10px; }
.hx-top .t { flex: 1; min-width: 0; }
.hx-top h1 { margin: 0; font-family: var(--pc-font); font-size: 22px; font-weight: 700; line-height: 1.1; color: #4a2b00; }
.hx-top p { margin: 1px 0 0; font-size: 11.5px; font-weight: 800; color: var(--pc-muted); }
.hx-top .art { width: 50px; height: 50px; object-fit: contain; animation: gBob 3s ease-in-out infinite; filter: drop-shadow(0 5px 5px rgba(120, 70, 10, .25)); }

/* Kartu arus cuan */
.hx-flow { position: relative; overflow: hidden; padding: 12px 12px 10px; border-radius: 24px; color: #fff;
  background: radial-gradient(120% 80% at 100% 0%, rgba(255, 213, 79, .35) 0, transparent 55%), linear-gradient(160deg, #1f7a3a 0%, #15803d 45%, #0f5a2a 100%);
  box-shadow: 0 5px 0 #0b4320, 0 14px 26px rgba(15, 90, 42, .3); }
.hx-flow::before { content: ''; position: absolute; inset: 0; background: repeating-linear-gradient(0deg, rgba(255, 255, 255, .05) 0 1px, transparent 1px 22px); pointer-events: none; }
.hx-flow > * { position: relative; }
.hx-flow-h { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.hx-flow-h small { display: block; font-size: 11px; font-weight: 900; color: #bbf7d0; }
.hx-flow-h b { display: block; font-family: var(--pc-font); font-size: 26px; font-weight: 700; line-height: 1.1; color: #fff; text-shadow: 0 2px 0 rgba(0, 0, 0, .2); }
.hx-flow-h .g-btn { flex-shrink: 0; }
.hx-legend { display: flex; gap: 10px; margin-top: 8px; font-size: 11px; font-weight: 800; color: #dcfce7; }
.hx-legend span { display: inline-flex; align-items: center; gap: 4px; }
.hx-legend i { width: 9px; height: 9px; border-radius: 3px; }
.hx-legend b { color: #fff; }
.hx-chart { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; align-items: end; height: 92px; margin-top: 8px; padding: 0 2px; }
.hx-col { display: flex; flex-direction: column; align-items: center; justify-content: flex-end; gap: 2px; height: 100%; }
.hx-bars { display: flex; align-items: flex-end; gap: 2px; height: 72px; }
.hx-bars i { width: 9px; min-height: 3px; border-radius: 4px 4px 2px 2px; transform-origin: bottom; animation: hxGrow .8s var(--pc-ease) both; animation-delay: var(--d, 0s); }
.hx-bars .in { background: linear-gradient(180deg, #ffe58a, #f6b70a); box-shadow: 0 0 8px rgba(255, 213, 79, .5); }
.hx-bars .out { background: linear-gradient(180deg, #fda4af, #e11d48); }
@keyframes hxGrow { from { transform: scaleY(0); } }
.hx-col small { font-size: 9.5px; font-weight: 900; color: #bbf7d0; }
.hx-col.today small { color: #ffd54f; }

/* Filter petak */
.hx-filter { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
.hx-f { position: relative; display: flex; align-items: center; gap: 6px; min-width: 0; padding: 7px 8px; border-radius: 15px; text-decoration: none; background: #fff; box-shadow: inset 0 0 0 2px #f1dfb4, 0 3px 0 #ecd59c; transition: transform .15s var(--pc-ease); }
.hx-f:active { transform: translateY(2px); }
.hx-f img { width: 26px; height: 26px; flex-shrink: 0; object-fit: contain; }
.hx-f span { min-width: 0; line-height: 1.1; }
.hx-f b { display: block; font-family: var(--pc-font); font-size: 13px; font-weight: 600; color: #4a2b00; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hx-f small { display: block; font-size: 10px; font-weight: 900; color: var(--pc-muted); }
.hx-f.on { background: linear-gradient(180deg, #22c55e, #15803d); box-shadow: 0 3px 0 #11622f, 0 6px 12px rgba(22, 163, 74, .25); }
.hx-f.on b { color: #fff; } .hx-f.on small { color: #dcfce7; }

/* Ringkasan filter */
.hx-sum { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.hx-sum > div { display: flex; align-items: center; gap: 7px; padding: 7px 10px; border-radius: 14px; background: #fffaf0; box-shadow: inset 0 0 0 1.5px #f1dfb4; min-width: 0; }
.hx-sum i { font-size: 18px; }
.hx-sum small { display: block; font-size: 10px; font-weight: 900; color: var(--pc-muted); }
.hx-sum b { display: block; font-family: var(--pc-font); font-size: 15px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hx-sum .in i, .hx-sum .in b { color: #15803d; } .hx-sum .out i, .hx-sum .out b { color: #e11d48; }

/* Linimasa */
.hx-tl { position: relative; display: flex; flex-direction: column; gap: 6px; padding-left: 22px; }
.hx-tl::before { content: ''; position: absolute; left: 8px; top: 6px; bottom: 6px; width: 3px; border-radius: 3px; background: repeating-linear-gradient(180deg, #ecd59c 0 7px, transparent 7px 12px); }
.hx-day { display: flex; flex-direction: column; gap: 6px; }
.hx-day-lbl { position: relative; align-self: flex-start; margin: 4px 0 0 -22px; padding: 3px 11px 3px 24px; border-radius: 999px; font-size: 11.5px; font-weight: 900; color: #4a3000; background: linear-gradient(180deg, #ffd54f, #f6b70a); box-shadow: 0 2px 0 #b07800; }
.hx-day-lbl::before { content: ''; position: absolute; left: 5px; top: 50%; width: 9px; height: 9px; margin-top: -4.5px; border-radius: 50%; background: #fff; box-shadow: 0 0 0 2px #b07800; }
.hx-row { position: relative; border-radius: 16px; background: #fff; box-shadow: 0 0 0 2px #efe6cf, 0 3px 0 #e6dbbd; transition: box-shadow .2s; }
.hx-row::before { content: ''; position: absolute; left: -18px; top: 19px; width: 9px; height: 9px; border-radius: 50%; background: var(--tc, #16a34a); box-shadow: 0 0 0 2px #fff; }
.hx-row.k-withdraw { --tc: #e11d48; } .hx-row.k-deposit { --tc: #0284c7; } .hx-row.k-reward { --tc: #f6b70a; } .hx-row.k-bonus { --tc: #7c3aed; } .hx-row.k-upgrade { --tc: #b07800; } .hx-row.k-farm { --tc: #16a34a; }
.hx-row.open { box-shadow: 0 0 0 2px var(--tc), 0 3px 0 #e6dbbd; }
.hx-main { display: flex; align-items: center; gap: 9px; width: 100%; padding: 7px 10px 7px 7px; border: 0; background: none; cursor: pointer; text-align: left; font: inherit; color: inherit; }
.hx-main .g-slot { width: 36px; height: 36px; border-radius: 11px; }
.hx-ico.logo img { width: 72%; height: 72%; filter: none; }
.hx-body { flex: 1; min-width: 0; }
.hx-title { font-size: 12.5px; font-weight: 900; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hx-meta { margin-top: 1px; display: flex; align-items: center; gap: 5px; font-size: 10.5px; font-weight: 800; color: var(--pc-muted); white-space: nowrap; overflow: hidden; }
.hx-st { flex-shrink: 0; padding: 0 6px; border-radius: 999px; font-size: 9.5px; font-weight: 900; }
.hx-st.succ { background: #dcfce7; color: #15803d; } .hx-st.warn { background: #fff3c4; color: #8a5a00; } .hx-st.err { background: #ffe4e6; color: #be123c; }
.hx-st.hold { background: #eef0f3; color: #475569; } .hx-st.info { background: #e0f2fe; color: #0369a1; }
.hx-amt { flex-shrink: 0; font-family: var(--pc-font); font-size: 14.5px; font-weight: 700; white-space: nowrap; }
.hx-amt.in { color: #15803d; } .hx-amt.out { color: #e11d48; }
.hx-amt.void { color: #94a3b8; text-decoration: line-through; }
.hx-chev { flex-shrink: 0; font-size: 12px; color: #c9a85a; transition: transform .25s var(--pc-ease); }
.hx-row.open .hx-chev { transform: rotate(180deg); }
.hx-det { display: none; padding: 0 10px 10px 52px; }
.hx-row.open .hx-det { display: block; animation: hxIn .3s var(--pc-out); }
@keyframes hxIn { from { opacity: 0; transform: translateY(-4px); } }
.hx-det dl { display: grid; grid-template-columns: auto 1fr; gap: 3px 10px; margin: 0; padding: 8px 10px; border-radius: 12px; background: #fffaf0; font-size: 11.5px; }
.hx-det dt { font-weight: 800; color: var(--pc-muted); }
.hx-det dd { margin: 0; font-weight: 900; color: var(--pc-ink); text-align: right; word-break: break-word; }
.hx-note { display: flex; gap: 6px; margin: 0 10px 8px 52px; padding: 6px 9px; border-radius: 11px; background: #fff1f2; font-size: 11px; font-weight: 700; color: #9f1239; line-height: 1.35; }
.hx-note i { font-size: 14px; flex-shrink: 0; margin-top: 1px; }
.hx-act { display: flex; justify-content: flex-end; padding: 0 10px 9px; }
.hx-act .g-btn { min-height: 32px; padding: 0 12px; font-size: 12.5px; border-radius: 11px; }
.hx-done { display: inline-flex; align-items: center; gap: 4px; padding: 5px 10px; border-radius: 10px; font-size: 11.5px; font-weight: 900; color: #64748b; background: #f1f5f9; }
.hx-more[hidden], .hx-row[hidden], .hx-day[hidden] { display: none !important; }

/* Modal refund */
.hx-modal { display: none; position: fixed; inset: 0; z-index: 10000; align-items: flex-end; justify-content: center; background: rgba(40, 25, 5, .5); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); }
.hx-sheet { width: 100%; max-width: 440px; padding: 10px 16px calc(16px + env(safe-area-inset-bottom)); border-radius: 26px 26px 0 0; text-align: center; background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 -10px 30px rgba(40, 25, 5, .25); animation: hxUp .35s var(--pc-out); }
@keyframes hxUp { from { transform: translateY(100%); } }
.hx-grab { width: 40px; height: 5px; margin: 0 auto 10px; border-radius: 5px; background: #ecd59c; }
.hx-sheet .art { display: block; width: 74px; height: 74px; margin: 0 auto 4px; object-fit: contain; animation: gBob 3s ease-in-out infinite; }
.hx-sheet h3 { margin: 0; font-family: var(--pc-font); font-size: 19px; font-weight: 700; color: #4a2b00; }
.hx-sheet p { margin: 4px 0 0; font-size: 12px; font-weight: 700; color: var(--pc-muted); line-height: 1.45; }
.hx-sheet .info { display: flex; gap: 7px; margin: 12px 0; padding: 9px 11px; border-radius: 14px; background: #dcfce7; font-size: 11.5px; font-weight: 800; color: #14532d; text-align: left; line-height: 1.4; }
.hx-sheet .row { display: flex; gap: 8px; }
.hx-sheet .row .g-btn { flex: 1; }
@media (max-width: 350px) { .hx-f img { display: none; } .hx-bars i { width: 7px; } .hx-det { padding-left: 10px; } .hx-note { margin-left: 10px; } }
@media (prefers-reduced-motion: reduce) { .hx-bars i { animation: none; } }
</style>

<?php
/* ── Helper tampilan ── */
$hx_status = static function (array $it): array {
    $st = strtolower((string)$it['status']);
    return match (true) {
        $st === 'pending'  => ['warn', $it['type'] === 'deposit' ? 'Menunggu' : 'Diproses'],
        $st === 'rejected' => ['err', 'Ditolak'],
        $st === 'hold'     => ['hold', 'Ditahan'],
        $st === 'refunded' => ['info', 'Dikembalikan'],
        $st === 'revoked'  => ['err', 'Dibatalkan'],
        $st === 'locked'   => ['info', 'Beku'],
        $st === 'claimed'  => ['succ', 'Dicairkan'],
        default            => ['succ', $it['type'] === 'farm' ? 'Terjual' : 'Sukses'],
    };
};
$hx_void = static fn(array $it): bool => in_array(strtolower((string)$it['status']), ['rejected', 'revoked', 'refunded'], true);
$hx_day = static function (string $time): string {
    $d = date('Y-m-d', strtotime($time));
    if ($d === date('Y-m-d')) return 'Hari ini';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Kemarin';
    return date('d M Y', strtotime($time));
};
$hx_3d = static function (array $it): string {
    return match ($it['type']) {
        'withdraw' => 'moneybag', 'deposit' => 'piggy', 'reward' => 'watch', 'upgrade' => 'upgrade', 'farm' => 'basket',
        default    => match ($it['sub_type']) { 'Survei Pengguna' => 'survey', 'Komisi Referral' => 'referral', 'Voucher Redeem' => 'redeem', default => 'missions' },
    };
};
$hx_tabs = [
    'all'      => ['Semua', 'receipt'],
    'farm'     => ['Panen', 'basket'],
    'reward'   => ['Video', 'watch'],
    'bonus'    => ['Bonus', 'redeem'],
    'deposit'  => ['Deposit', 'piggy'],
    'withdraw' => ['Withdraw', 'moneybag'],
];
if (!isset($hx_tabs[$tab])) $tab = 'all';
$hx_items = $tab === 'all' ? $unified : array_values(array_filter($unified, fn($it) => $it['type'] === $tab));
$hx_empty = [
    'all'      => ['receipt', 'Belum ada transaksi', 'Tonton video, bertani, atau selesaikan quest untuk mengisi jurnalmu.', '/home', 'Mulai Cuan'],
    'farm'     => ['basket', 'Belum ada panen terjual', 'Tanam &amp; panen di Sawah, lalu jual di Pasar Pengepul.', '/farm', 'Mulai Bertani'],
    'withdraw' => ['moneybag', 'Belum ada withdraw', 'Kumpulkan saldo lalu tarik ke e-wallet atau rekening bankmu.', '/withdraw', 'Withdraw'],
    'deposit'  => ['piggy', 'Belum ada deposit', 'Deposit saldo untuk level up atau beli bibit di Sawah.', '/deposit', 'Deposit'],
    'reward'   => ['watch', 'Belum ada riwayat nonton', 'Tonton video untuk dapat rupiah di setiap video.', '/videos', 'Nonton Video'],
    'bonus'    => ['redeem', 'Belum ada bonus', 'Selesaikan survei, quest, atau referral untuk dapat bonus.', '/missions', 'Lihat Quest'],
][$tab];
$HX_PAGE = 25;
$ic = static fn(string $n): string => '/assets/img/icons3d/' . $n . '.png';

// Grafik arus 7 hari (masuk vs keluar, transaksi batal tidak dihitung)
$hx_days = [];
for ($d = 6; $d >= 0; $d--) $hx_days[date('Y-m-d', strtotime("-{$d} day"))] = ['in' => 0.0, 'out' => 0.0];
$wk_in = $wk_out = 0.0;
foreach ($unified as $it) {
    if ($hx_void($it) || strtolower((string)$it['status']) === 'pending' && $it['type'] === 'deposit') continue;
    $k = date('Y-m-d', strtotime((string)$it['time']));
    if (!isset($hx_days[$k])) continue;
    $hx_days[$k][$it['is_income'] ? 'in' : 'out'] += (float)$it['amount'];
    if ($it['is_income']) $wk_in += (float)$it['amount']; else $wk_out += (float)$it['amount'];
}
$hx_peak = max(1.0, ...array_values(array_map(fn($v) => max($v["in"], $v["out"]), $hx_days)));
$hx_dn = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
// Ringkasan untuk filter aktif
$f_in = $f_out = 0.0;
foreach ($hx_items as $it) { if ($hx_void($it)) continue; if ($it['is_income']) $f_in += (float)$it['amount']; else $f_out += (float)$it['amount']; }
?>
<div class="g-page">

  <?php if ($flash): ?>
    <div class="hx-flash <?= $flashType === 'error' ? 'error' : 'success' ?>">
      <i class="ph-fill <?= $flashType === 'error' ? 'ph-warning-circle' : 'ph-check-circle' ?>"></i>
      <span><?= htmlspecialchars($flash) ?></span>
    </div>
  <?php endif; ?>

  <div class="hx-top" data-rv>
    <div class="t"><h1>Jurnal Panen</h1><p>Semua uang masuk &amp; keluar akunmu</p></div>
    <img class="art" src="<?= $ic('receipt') ?>" alt="">
  </div>

  <section class="hx-flow" data-rv style="--d:.05s">
    <div class="hx-flow-h">
      <div style="min-width:0"><small>Saldo siap withdraw</small><b><?= format_rp($balance_wd) ?></b></div>
      <a href="/withdraw" class="g-btn gold sm shine">Withdraw</a>
    </div>
    <div class="hx-legend">
      <span><i style="background:#f6b70a"></i>Masuk <b><?= format_rp($wk_in) ?></b></span>
      <span><i style="background:#e11d48"></i>Keluar <b><?= format_rp($wk_out) ?></b></span>
    </div>
    <div class="hx-chart" aria-label="Grafik arus 7 hari">
      <?php $di = 0; foreach ($hx_days as $day => $v): ?>
        <div class="hx-col <?= $day === date('Y-m-d') ? 'today' : '' ?>" title="<?= date('d M', strtotime($day)) ?>: masuk <?= format_rp($v['in']) ?>, keluar <?= format_rp($v['out']) ?>">
          <div class="hx-bars">
            <i class="in" style="height:<?= round($v['in'] / $hx_peak * 100) ?>%;--d:<?= 0.06 * $di ?>s"></i>
            <i class="out" style="height:<?= round($v['out'] / $hx_peak * 100) ?>%;--d:<?= 0.06 * $di + .03 ?>s"></i>
          </div>
          <small><?= $day === date('Y-m-d') ? 'Ini' : $hx_dn[(int)date('w', strtotime($day))] ?></small>
        </div>
      <?php $di++; endforeach; ?>
    </div>
  </section>

  <nav class="hx-filter" aria-label="Filter riwayat" data-rv style="--d:.1s">
    <?php foreach ($hx_tabs as $key => [$lbl, $tic]): ?>
      <a href="?tab=<?= $key ?>" class="hx-f <?= $tab === $key ? 'on' : '' ?>"><img src="<?= $ic($tic) ?>" alt=""><span><b><?= htmlspecialchars($lbl) ?></b><small><?= (int)($counts[$key] ?? 0) ?> catatan</small></span></a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$hx_items): ?>
    <div class="g-panel g-empty" data-rv>
      <img src="<?= $ic($hx_empty[0]) ?>" alt="">
      <b><?= $hx_empty[1] ?></b>
      <p><?= $hx_empty[2] ?></p>
      <a href="<?= $hx_empty[3] ?>" class="g-btn sm" style="margin-top:12px"><?= $hx_empty[4] ?></a>
    </div>
  <?php else: ?>
    <div class="hx-sum" data-rv>
      <div class="in"><i class="ph-bold ph-arrow-down-left"></i><span style="min-width:0"><small>Masuk · <?= htmlspecialchars($hx_tabs[$tab][0]) ?></small><b>+<?= format_rp($f_in) ?></b></span></div>
      <div class="out"><i class="ph-bold ph-arrow-up-right"></i><span style="min-width:0"><small>Keluar · <?= htmlspecialchars($hx_tabs[$tab][0]) ?></small><b>-<?= format_rp($f_out) ?></b></span></div>
    </div>

    <div class="hx-tl" id="hx-list">
      <?php
      $curDay = null; $open = false;
      foreach ($hx_items as $i => $item):
        $day = $hx_day((string)$item['time']);
        [$stCls, $stTxt] = $hx_status($item);
        $stLower = strtolower((string)$item['status']);
        $void = $hx_void($item);
        if ($day !== $curDay):
          if ($open) echo '</div>';
          $curDay = $day; $open = true;
      ?>
        <div class="hx-day" data-day>
          <div class="hx-day-lbl"><?= $day ?></div>
      <?php endif; ?>
          <div class="hx-row k-<?= htmlspecialchars($item['type']) ?>" data-i="<?= $i ?>" <?= $i >= $HX_PAGE ? 'hidden' : '' ?>>
            <button type="button" class="hx-main" onclick="this.parentElement.classList.toggle('open')" aria-label="Detail transaksi">
              <?php if (!empty($item['logo'])): ?>
                <span class="g-slot hx-ico logo"><img src="/assets/banks/<?= htmlspecialchars($item['logo']) ?>" alt="" loading="lazy"></span>
              <?php else: ?>
                <span class="g-slot hx-ico"><img src="<?= $ic($hx_3d($item)) ?>" alt="" loading="lazy"></span>
              <?php endif; ?>
              <span class="hx-body">
                <span class="hx-title" style="display:block"><?= htmlspecialchars((string)$item['title']) ?></span>
                <span class="hx-meta"><?= date('H:i', strtotime((string)$item['time'])) ?> · <span class="hx-st <?= $stCls ?>"><?= $stTxt ?></span></span>
              </span>
              <span class="hx-amt <?= $void ? 'void' : ($item['is_income'] ? 'in' : 'out') ?>"><?= ($item['is_income'] ? '+' : '-') . format_rp((float)$item['amount']) ?></span>
              <i class="ph-bold ph-caret-down hx-chev"></i>
            </button>

            <?php if (!empty($item['admin_note'])): ?>
              <div class="hx-note"><i class="ph-fill ph-warning-circle"></i><span><b><?= $stLower === 'rejected' ? 'Alasan ditolak:' : ($stLower === 'revoked' ? 'Alasan dibatalkan:' : ($stLower === 'hold' ? 'Catatan hold:' : 'Catatan admin:')) ?></b> <?= htmlspecialchars((string)$item['admin_note']) ?></span></div>
            <?php endif; ?>

            <?php if ($item['type'] === 'withdraw' && $stLower === 'hold'): ?>
              <div class="hx-act">
                <?php if (in_array((int)$item['raw']['id'], $requested_wds, true)): ?>
                  <span class="hx-done"><i class="ph-bold ph-hourglass-medium"></i> Refund diajukan</span>
                <?php else: ?>
                  <button type="button" class="g-btn sky" onclick="openRefundModal(<?= (int)$item['raw']['id'] ?>)"><i class="ph-bold ph-arrow-u-up-left"></i> Ajukan refund</button>
                <?php endif; ?>
              </div>
            <?php elseif ($item['type'] === 'deposit' && $stLower === 'pending' && strtolower((string)$item['raw']['method']) === 'qris'): ?>
              <div class="hx-act"><a href="/pay?id=<?= (int)$item['raw']['id'] ?>" class="g-btn gold shine"><i class="ph-bold ph-qr-code"></i> Bayar QRIS</a></div>
            <?php endif; ?>

            <div class="hx-det">
              <dl>
                <dt>Transaksi</dt><dd><?= htmlspecialchars((string)$item['title']) ?></dd>
                <dt>Jenis</dt><dd><?= htmlspecialchars((string)$item['sub_type']) ?></dd>
                <?php if (!empty($item['sub'])): ?><dt>Detail</dt><dd><?= htmlspecialchars((string)$item['sub']) ?></dd><?php endif; ?>
                <dt>Waktu</dt><dd><?= date('d M Y, H:i', strtotime((string)$item['time'])) ?></dd>
                <?php if (!empty($item['raw']['id']) && in_array($item['type'], ['withdraw', 'deposit', 'upgrade'], true)): ?><dt>No.</dt><dd>#<?= (int)$item['raw']['id'] ?></dd><?php endif; ?>
                <dt>Status</dt><dd><?= $stTxt ?></dd>
              </dl>
            </div>
          </div>
      <?php endforeach; if ($open) echo '</div>'; ?>
    </div>
    <?php if (count($hx_items) > $HX_PAGE): ?>
      <button type="button" class="g-btn soft full hx-more" id="hx-more" onclick="hxMore()"><i class="ph-bold ph-caret-down"></i> Muat lagi <span id="hx-more-n"></span></button>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- MODAL PENGAJUAN REFUND WD HOLD -->
<div id="refund-modal" class="hx-modal">
  <div class="hx-sheet">
    <div class="hx-grab"></div>
    <img class="art" src="<?= $ic('moneybag') ?>" alt="">
    <h3>Ajukan refund saldo?</h3>
    <p>Saldo dari withdraw yang ditahan ini akan dikembalikan.</p>
    <div class="info"><i class="ph-fill ph-shield-check"></i><span>Saldo kembali utuh ke <b>Saldo Tarik</b> setelah dicek admin.</span></div>
    <form method="POST" id="refund-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="refund_wd_hold">
      <input type="hidden" name="wd_id" id="refund-wd-id" value="">
      <div class="row">
        <button type="button" class="g-btn soft" onclick="closeRefundModal()">Batal</button>
        <button type="submit" class="g-btn sky" onclick="this.disabled=true;this.textContent='Memproses...';document.getElementById('refund-form').submit();">Ajukan refund</button>
      </div>
    </form>
  </div>
</div>

<script>
function openRefundModal(wdId) {
  document.getElementById('refund-wd-id').value = wdId;
  document.getElementById('refund-modal').style.display = 'flex';
}
function closeRefundModal() {
  document.getElementById('refund-modal').style.display = 'none';
}
document.getElementById('refund-modal').addEventListener('click', function (e) {
  if (e.target === this) closeRefundModal();
});
/* Muat lagi: tampilkan 25 baris berikutnya, sembunyikan grup tanggal yang kosong */
const HX_PAGE = <?= (int)$HX_PAGE ?>;
let hxShown = HX_PAGE;
function hxSync() {
  const rows = document.querySelectorAll('#hx-list .hx-row');
  document.querySelectorAll('#hx-list [data-day]').forEach((g) => { g.hidden = !g.querySelector('.hx-row:not([hidden])'); });
  const left = rows.length - hxShown, btn = document.getElementById('hx-more');
  if (btn) { btn.hidden = left <= 0; const n = document.getElementById('hx-more-n'); if (n) n.textContent = '(' + Math.max(0, left) + ')'; }
}
function hxMore() {
  hxShown += HX_PAGE;
  document.querySelectorAll('#hx-list .hx-row').forEach((r) => { if (+r.dataset.i < hxShown) r.hidden = false; });
  hxSync();
}
if (document.getElementById('hx-list')) hxSync();
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
