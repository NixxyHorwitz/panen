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
/* ══ RIWAYAT — Buku Panen · Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.hx-bal { display: flex; align-items: center; gap: 10px; margin-top: 12px; padding: 10px 10px 10px 12px; border-radius: 18px; background: rgba(255, 255, 255, .78); box-shadow: 0 0 0 2px rgba(255, 255, 255, .9), 0 8px 18px rgba(120, 70, 10, .12); }
.hx-bal .t { flex: 1; min-width: 0; }
.hx-bal small { display: block; font-size: 11px; font-weight: 900; color: #8a5a00; }
.hx-bal b { display: block; font-family: var(--pc-font); font-size: 22px; font-weight: 700; line-height: 1.1; color: #15803d; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hx-mini { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; margin-top: 8px; }
.hx-mini > div { min-width: 0; padding: 6px 4px 5px; border-radius: 14px; text-align: center; background: rgba(255, 255, 255, .66); box-shadow: inset 0 0 0 1.5px rgba(255, 255, 255, .9); }
.hx-mini img { width: 26px; height: 26px; object-fit: contain; filter: drop-shadow(0 2px 2px rgba(0, 0, 0, .18)); }
.hx-mini b { display: block; font-size: 11px; font-weight: 900; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hx-mini small { display: block; font-size: 9.5px; font-weight: 800; color: #7a4f00; white-space: nowrap; }

.hx-flash { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 16px; font-size: 12px; font-weight: 800; }
.hx-flash i { font-size: 18px; flex-shrink: 0; }
.hx-flash.success { background: #dcfce7; color: #14532d; box-shadow: inset 0 0 0 2px #bbf7d0; }
.hx-flash.error { background: #ffe4e6; color: #9f1239; box-shadow: inset 0 0 0 2px #fecdd3; }

/* Tab gulir */
.hx-tabs { display: flex; gap: 6px; overflow-x: auto; margin: 0 -12px; padding: 2px 12px 6px; scrollbar-width: none; }
.hx-tabs::-webkit-scrollbar { display: none; }
.hx-tab { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 5px; min-height: 36px; padding: 0 11px 0 6px; border-radius: 12px; text-decoration: none;
  font-family: var(--pc-font); font-size: 13.5px; font-weight: 600; color: #8a6a3a; background: #f2e6c8; box-shadow: inset 0 -2px 0 rgba(120, 70, 10, .12); transition: transform .15s var(--pc-ease); }
.hx-tab:active { transform: scale(.94); }
.hx-tab img { width: 24px; height: 24px; object-fit: contain; }
.hx-tab em { font-style: normal; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px; font-size: 10.5px; font-weight: 900; display: inline-grid; place-items: center; background: rgba(0, 0, 0, .1); }
.hx-tab.on { color: #fff; background: linear-gradient(180deg, #22c55e, #15803d); box-shadow: 0 3px 0 #11622f; text-shadow: 0 1px 0 rgba(0, 0, 0, .2); }
.hx-tab.on em { background: #ffd54f; color: #4a3000; text-shadow: none; }

/* Grup tanggal */
.hx-day { display: flex; flex-direction: column; gap: 6px; }
.hx-day-lbl { display: flex; align-items: center; gap: 8px; margin: 2px 4px 0; font-size: 11.5px; font-weight: 900; color: #8a5a00; }
.hx-day-lbl::after { content: ''; flex: 1; height: 2px; border-radius: 2px; background: repeating-linear-gradient(90deg, #ecd59c 0 6px, transparent 6px 10px); }
.hx-card { padding: 4px 10px; border-radius: 20px; background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 0 0 2px #f6e2b0, 0 4px 0 #ecd59c, 0 10px 18px rgba(120, 70, 10, .06); }

.hx-row { padding: 8px 0; }
.hx-row + .hx-row { border-top: 1.5px dashed #f1dfb4; }
.hx-main { display: flex; align-items: center; gap: 10px; }
.hx-ico.logo { background: #fff; }
.hx-ico.logo img { width: 72%; height: 72%; filter: none; }
.hx-body { flex: 1; min-width: 0; }
.hx-title { font-size: 13px; font-weight: 900; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hx-meta { margin-top: 1px; font-size: 11px; font-weight: 700; color: var(--pc-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.hx-meta b { font-weight: 900; color: #8a5a00; }
.hx-right { flex-shrink: 0; text-align: right; }
.hx-amt { font-family: var(--pc-font); font-size: 14.5px; font-weight: 700; line-height: 1.15; white-space: nowrap; }
.hx-amt.in { color: #15803d; } .hx-amt.out { color: #e11d48; }
.hx-st { display: inline-flex; align-items: center; gap: 3px; margin-top: 2px; padding: 1px 7px; border-radius: 999px; font-size: 10px; font-weight: 900; }
.hx-st.succ { background: #dcfce7; color: #15803d; } .hx-st.warn { background: #fff3c4; color: #8a5a00; } .hx-st.err { background: #ffe4e6; color: #be123c; }
.hx-st.hold { background: #eef0f3; color: #475569; } .hx-st.info { background: #e0f2fe; color: #0369a1; }
.hx-note { display: flex; gap: 6px; margin: 6px 0 0 48px; padding: 6px 9px; border-radius: 11px; background: #fff1f2; font-size: 11px; font-weight: 700; color: #9f1239; line-height: 1.35; }
.hx-note i { font-size: 14px; flex-shrink: 0; margin-top: 1px; }
.hx-act { display: flex; justify-content: flex-end; margin-top: 6px; }
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
.hx-sheet .info i { font-size: 16px; flex-shrink: 0; }
.hx-sheet .row { display: flex; gap: 8px; }
.hx-sheet .row .g-btn { flex: 1; }
@media (max-width: 360px) { .hx-mini small { font-size: 8.5px; } .hx-mini b { font-size: 10px; } .hx-tab { font-size: 12.5px; } }
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
$hx_day = static function (string $time): string {
    $d = date('Y-m-d', strtotime($time));
    if ($d === date('Y-m-d')) return 'Hari ini';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Kemarin';
    return date('d M Y', strtotime($time));
};
// Ikon 3D per jenis transaksi
$hx_3d = static function (array $it): string {
    return match ($it['type']) {
        'withdraw' => 'moneybag',
        'deposit'  => 'piggy',
        'reward'   => 'watch',
        'upgrade'  => 'upgrade',
        'farm'     => 'basket',
        default    => match ($it['sub_type']) {
            'Survei Pengguna' => 'survey',
            'Komisi Referral' => 'referral',
            'Voucher Redeem'  => 'redeem',
            default           => 'missions',
        },
    };
};
$hx_tabs = [
    'all'      => ['Semua', 'receipt'],
    'farm'     => ['Jual Panen', 'basket'],
    'withdraw' => ['Withdraw', 'moneybag'],
    'deposit'  => ['Deposit', 'piggy'],
    'reward'   => ['Video', 'watch'],
    'bonus'    => ['Bonus', 'redeem'],
];
if (!isset($hx_tabs[$tab])) $tab = 'all';
$hx_items = $tab === 'all' ? $unified : array_values(array_filter($unified, fn($it) => $it['type'] === $tab));
$hx_empty = [
    'all'      => ['receipt', 'Belum ada transaksi', 'Tonton video, bertani, atau selesaikan quest untuk mengisi buku panenmu.', '/home', 'Mulai Cuan'],
    'farm'     => ['basket', 'Belum ada panen terjual', 'Tanam &amp; panen di Sawah, lalu jual di Pasar Pengepul jadi Saldo Tarik.', '/farm', 'Mulai Bertani'],
    'withdraw' => ['moneybag', 'Belum ada withdraw', 'Kumpulkan saldo lalu tarik ke e-wallet atau rekening bankmu.', '/withdraw', 'Withdraw'],
    'deposit'  => ['piggy', 'Belum ada deposit', 'Deposit saldo untuk level up atau beli bibit di Sawah.', '/deposit', 'Deposit'],
    'reward'   => ['watch', 'Belum ada riwayat nonton', 'Tonton video untuk dapat rupiah di setiap video.', '/videos', 'Nonton Video'],
    'bonus'    => ['redeem', 'Belum ada bonus', 'Selesaikan survei, quest, atau referral untuk dapat bonus.', '/missions', 'Lihat Quest'],
][$tab];
$HX_PAGE = 25;
$ic = static fn(string $n): string => '/assets/img/icons3d/' . $n . '.png';
?>
<div class="g-page">

  <?php if ($flash): ?>
    <div class="hx-flash <?= $flashType === 'error' ? 'error' : 'success' ?>">
      <i class="ph-fill <?= $flashType === 'error' ? 'ph-warning-circle' : 'ph-check-circle' ?>"></i>
      <span><?= htmlspecialchars($flash) ?></span>
    </div>
  <?php endif; ?>

  <section class="g-hero" data-rv>
    <span class="g-sun"></span><span class="g-cloud c1"></span><span class="g-cloud c2"></span>
    <div class="g-hero-row">
      <div style="flex:1;min-width:0">
        <h1>Buku Panen</h1>
        <p>Semua uang masuk &amp; keluar akunmu.</p>
      </div>
      <div class="g-hero-art" style="width:66px;height:66px"><img src="<?= $ic('receipt') ?>" alt=""></div>
    </div>
    <div class="hx-bal">
      <span class="g-slot sm"><img src="<?= $ic('moneybag') ?>" alt=""></span>
      <div class="t"><small>Saldo siap withdraw</small><b><?= format_rp($balance_wd) ?></b></div>
      <a href="/withdraw" class="g-btn gold sm shine">Withdraw</a>
    </div>
    <div class="hx-mini">
      <div><img src="<?= $ic('coins') ?>" alt=""><b><?= format_rp($total_earned) ?></b><small>Total cuan</small></div>
      <div><img src="<?= $ic('basket') ?>" alt=""><b><?= format_rp($farm_sales_total) ?></b><small>Jual panen</small></div>
      <div><img src="<?= $ic('piggy') ?>" alt=""><b><?= format_rp($total_dep) ?></b><small>Deposit</small></div>
      <div><img src="<?= $ic('moneybag') ?>" alt=""><b><?= format_rp($total_wd) ?></b><small>Ditarik</small></div>
    </div>
  </section>

  <nav class="hx-tabs" aria-label="Filter riwayat" data-rv style="--d:.05s">
    <?php foreach ($hx_tabs as $key => [$lbl, $tic]): ?>
      <a href="?tab=<?= $key ?>" class="hx-tab <?= $tab === $key ? 'on' : '' ?>"><img src="<?= $ic($tic) ?>" alt=""><?= htmlspecialchars($lbl) ?><em><?= (int)($counts[$key] ?? 0) ?></em></a>
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
    <div id="hx-list" style="display:flex;flex-direction:column;gap:12px">
      <?php
      $curDay = null; $open = false; $gi = 0;
      foreach ($hx_items as $i => $item):
        $day = $hx_day((string)$item['time']);
        [$stCls, $stTxt] = $hx_status($item);
        $stLower = strtolower((string)$item['status']);
        if ($day !== $curDay):
          if ($open) echo '</div></div>';
          $curDay = $day; $open = true;
      ?>
        <div class="hx-day" data-day data-rv style="--d:<?= number_format(min(0.3, 0.05 * $gi++), 2) ?>s">
          <div class="hx-day-lbl"><?= $day ?></div>
          <div class="hx-card">
      <?php endif; ?>
            <div class="hx-row" data-i="<?= $i ?>" <?= $i >= $HX_PAGE ? 'hidden' : '' ?>>
              <div class="hx-main">
                <?php if (!empty($item['logo'])): ?>
                  <span class="g-slot sm hx-ico logo"><img src="/assets/banks/<?= htmlspecialchars($item['logo']) ?>" alt="" loading="lazy"></span>
                <?php else: ?>
                  <span class="g-slot sm hx-ico"><img src="<?= $ic($hx_3d($item)) ?>" alt="" loading="lazy"></span>
                <?php endif; ?>
                <div class="hx-body">
                  <div class="hx-title"><?= htmlspecialchars((string)$item['title']) ?></div>
                  <div class="hx-meta"><b><?= htmlspecialchars((string)$item['sub_type']) ?></b> · <?= date('H:i', strtotime((string)$item['time'])) ?><?php if (!empty($item['sub'])): ?> · <?= htmlspecialchars((string)$item['sub']) ?><?php endif; ?></div>
                </div>
                <div class="hx-right">
                  <div class="hx-amt <?= $item['is_income'] ? 'in' : 'out' ?>"><?= ($item['is_income'] ? '+' : '-') . format_rp((float)$item['amount']) ?></div>
                  <span class="hx-st <?= $stCls ?>"><?= $stTxt ?></span>
                </div>
              </div>

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
            </div>
      <?php endforeach; if ($open) echo '</div></div>'; ?>
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
