<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

function get_active_price(array $membership, array $user): float {
    $price = (float)$membership['price'];
    if (!empty($membership['is_genjutsu'])) {
        $balance = (float)($user['balance_dep'] ?? 0);
        if ($balance >= $price) {
            $price = (float)$membership['price_genjutsu'];
        }
    }
    return $price;
}

// Hitung rata-rata reward video aktif untuk estimasi panen yang akurat
$v_stats = $pdo->query("SELECT AVG(reward_amount) AS avg_reward, COUNT(*) AS total_videos FROM videos WHERE is_active=1")->fetch();
$avg_video_reward = (float)($v_stats['avg_reward'] ?? 7200);
$total_active_videos = (int)($v_stats['total_videos'] ?? 0);

/**
 * Menghitung estimasi panen berdasarkan limit tonton harian dan rata-rata reward video aktif
 */
function get_harvest_calc(?array $m, float $avg_reward): ?array {
    if (!$m) return null;
    $watch_limit   = (int)($m['watch_limit'] ?? 0);
    $duration_days = (int)($m['duration_days'] ?? 30);
    $price         = (float)($m['price'] ?? 0);

    $daily_income  = $watch_limit * $avg_reward;
    $total_harvest = $daily_income * $duration_days;
    $break_even_days = ($daily_income > 0 && $price > 0) ? max(1, (int)ceil($price / $daily_income)) : 1;

    $rounded_total = round($total_harvest / 1000) * 1000;
    $rounded_daily = round($daily_income / 1000) * 1000;

    if ($duration_days == 60) {
        $period_label = ' / 2 bln';
    } elseif ($duration_days == 30) {
        $period_label = '/bln';
    } else {
        $period_label = " / {$duration_days} hari";
    }

    return [
        'daily_income'    => $daily_income,
        'total_harvest'   => $total_harvest,
        'break_even_days' => $break_even_days,
        'period_label'    => $period_label,
        'harvest_text'    => '~' . format_rp($rounded_total) . $period_label,
        'daily_text'      => '~' . format_rp($rounded_daily) . '/hari',
    ];
}

$all_memberships = $pdo->query("SELECT * FROM memberships WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();
$memberships = [];
foreach ($all_memberships as $ms) {
    if (!empty($ms['is_genjutsu_hilang'])) {
        $price = (float)$ms['price'];
        $balance = (float)($user['balance_dep'] ?? 0);
        if ($balance >= $price) {
            continue;
        }
    }
    $memberships[] = $ms;
}
$flash = $flashType = '';

// Active membership info
$active_membership = null;
$can_refund = false;
$user_settings = ['refund_cut_percent' => 20, 'is_refund_enabled' => 1];

if ($user['membership_id'] && $user['membership_expires_at'] && strtotime($user['membership_expires_at']) > time()) {
    $ms = $pdo->prepare("SELECT * FROM memberships WHERE id=?");
    $ms->execute([$user['membership_id']]);
    $active_membership = $ms->fetch();
    
    $uSet = $pdo->prepare("SELECT refund_cut_percent, is_refund_enabled FROM users WHERE id=?");
    $uSet->execute([$user['id']]);
    $user_settings = $uSet->fetch() ?: $user_settings;
    
    if ($user_settings['is_refund_enabled']) {
        $lastUp = $pdo->prepare("SELECT confirmed_at FROM upgrade_orders WHERE user_id=? AND membership_id=? AND status='confirmed' ORDER BY id DESC LIMIT 1");
        $lastUp->execute([$user['id'], $active_membership['id']]);
        $last_confirmed = $lastUp->fetchColumn();
        if ($last_confirmed && strtotime($last_confirmed) > time() - (12 * 3600)) {
            $can_refund = true;
        }
    }
}

// AJAX Check Voucher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_voucher') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['error' => 'Sesi kedaluwarsa, muat ulang halaman.']); exit; }
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $mid  = (int)($_POST['membership_id'] ?? 0);
    
    if (!$code) { echo json_encode(['error' => 'Masukkan kode voucher.']); exit; }
    if (!$mid) { echo json_encode(['error' => 'Pilih paket terlebih dahulu.']); exit; }
    
    $stmt = $pdo->prepare("SELECT * FROM discount_vouchers WHERE code = ?");
    $stmt->execute([$code]);
    $v = $stmt->fetch();
    
    if (!$v) { echo json_encode(['error' => 'Kode voucher tidak ditemukan atau tidak valid.']); exit; }
    if ($v['expires_at'] && strtotime($v['expires_at']) < time()) { echo json_encode(['error' => 'Voucher ini sudah kedaluwarsa.']); exit; }
    if ($v['max_claims'] > 0 && $v['claims_count'] >= $v['max_claims']) { echo json_encode(['error' => 'Kuota voucher ini sudah habis.']); exit; }
    
    $chk = $pdo->prepare("SELECT id FROM user_discount_claims WHERE user_id = ? AND voucher_id = ?");
    $chk->execute([$user['id'], $v['id']]);
    if ($chk->fetch()) { echo json_encode(['error' => 'Kamu sudah menggunakan voucher ini sebelumnya.']); exit; }
    
    $discounts = json_decode($v['discounts'], true) ?: [];
    
    if (isset($discounts['*'])) {
        $val = $discounts['*'];
    } elseif (isset($discounts[$mid])) {
        $val = $discounts[$mid];
    } else {
        echo json_encode(['error' => 'Voucher ini tidak dapat digunakan untuk paket pilihanmu.']); exit;
    }
    
    $ms = $pdo->prepare("SELECT * FROM memberships WHERE id=? AND is_active=1");
    $ms->execute([$mid]);
    $chosen_m = $ms->fetch();
    if (!$chosen_m || (!empty($chosen_m['is_genjutsu_hilang']) && (float)($user['balance_dep'] ?? 0) >= (float)$chosen_m['price'])) {
        echo json_encode(['error' => 'Paket tidak valid.']); exit;
    }
    $price = get_active_price($chosen_m, $user);
    if (!$price) { echo json_encode(['error' => 'Paket tidak valid.']); exit; }
    
    $is_rp = false;
    $pct = 0;
    if (is_string($val) && stripos($val, 'rp') !== false) {
        $discount_amount = (float)str_ireplace('rp', '', $val);
        $is_rp = true;
    } elseif (is_numeric($val) && $val > 100) {
        $discount_amount = (float)$val; // legacy format fallback
        $is_rp = true;
    } else {
        $pct = (float)$val;
        $discount_amount = ($price * $pct) / 100;
    }
    
    $final_price = $price - $discount_amount;
    if ($final_price < 0) $final_price = 0;
    
    $discount_text = $is_rp ? 'Rp ' . number_format($discount_amount, 0, ',', '.') : $pct . '%';
    
    echo json_encode([
        'ok' => true,
        'discount_text' => $discount_text,
        'discount_amount' => $discount_amount,
        'discount_amount_formatted' => format_rp($discount_amount),
        'final_price' => $final_price,
        'final_price_formatted' => format_rp($final_price)
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_verify()) {
        $flash = 'Sesi keamanan kedaluwarsa. Muat ulang halaman lalu coba lagi.'; $flashType = 'error';
        goto end_post;
    }
    
    if ($action === 'refund_level') {
        $stmtPending = $pdo->prepare("SELECT id FROM admin_requests WHERE user_id=? AND type='refund_level' AND status='pending'");
        $stmtPending->execute([$user['id']]);
        
        if ($stmtPending->fetchColumn()) {
            $flash = '❌ Permintaan pengembalian dana kamu sebelumnya masih dalam proses verifikasi otomatis.'; $flashType = 'error';
        } else {
            $s = $pdo->prepare("SELECT m.id as membership_id, m.name, m.price, u.refund_cut_percent, u.is_refund_enabled FROM users u LEFT JOIN memberships m ON u.membership_id = m.id WHERE u.id=?");
            $s->execute([$user['id']]);
            $uInfo = $s->fetch();
            $mName = $uInfo['name'] ?? null;
            
            if (!$mName) {
                $flash = '❌ Kamu tidak memiliki paket aktif.'; $flashType = 'error';
            } elseif (!$uInfo['is_refund_enabled']) {
                $flash = '❌ Akses refund kamu telah dinonaktifkan.'; $flashType = 'error';
            } else {
                $pdo->prepare("INSERT INTO admin_requests (user_id, type) VALUES (?, 'refund_level')")->execute([$user['id']]);
                $req_id = $pdo->lastInsertId();
                
                $oStmt = $pdo->prepare("SELECT amount FROM upgrade_orders WHERE user_id=? AND membership_id=? AND status='confirmed' ORDER BY id DESC LIMIT 1");
                $oStmt->execute([$user['id'], $uInfo['membership_id']]);
                $basePrice = (float)$oStmt->fetchColumn();
                if (!$basePrice) $basePrice = (float)$uInfo['price'];
                
                $pct = (float)$uInfo['refund_cut_percent'];
                $cutAmount = ($basePrice * $pct) / 100;
                $afterCut = $basePrice - $cutAmount;
                
                $msg  = "💰 <b>REQUEST REFUND LEVEL</b>\n\n";
                $msg .= "👤 User: <code>{$user['username']}</code>\n";
                $msg .= "🏆 Level: <b>{$mName}</b>\n";
                $msg .= "💵 Harga Awal: <b>" . format_rp($basePrice) . "</b>\n";
                $msg .= "✂️ Setelah Dipotong ({$pct}%): <b>" . format_rp($afterCut) . "</b>\n\n";
                $msg .= "⚠️ <i>Refund ini akan membatalkan level user dan mengembalikan saldo dengan potongan {$pct}% (jika di-Approve).</i>\n";
                $kb = [
                    [['text'=>'✅ Approve Refund', 'callback_data'=>'req_approve_'.$req_id], ['text'=>'❌ Reject', 'callback_data'=>'req_reject_'.$req_id]],
                    [['text'=>"⚙️ Ubah Potongan ({$pct}%)", 'callback_data'=>'edit_refcut_'.$user['id']], ['text'=>'➡️ Cabut Akses Refund', 'callback_data'=>'toggle_ref_'.$user['id']]]
                ];
                tg_store_msg_id($pdo, 'admin_requests', (int)$req_id, send_telegram_notif($pdo, $msg, $kb, 'permintaan'));
                
                $flash = '✅ Permintaan pengembalian dana kamu telah masuk dan sedang diverifikasi oleh sistem secara otomatis.';
            }
        }
        goto end_post;
    }

    $mid = (int)($_POST['membership_id'] ?? 0);
    $ms  = $pdo->prepare("SELECT * FROM memberships WHERE id=? AND is_active=1");
    $ms->execute([$mid]);
    $chosen = $ms->fetch();

    if (!$chosen) {
        $flash = 'Duh, paketnya gak ketemu nih.'; $flashType = 'error';
    } elseif (!empty($chosen['is_genjutsu_hilang']) && (float)($user['balance_dep'] ?? 0) >= (float)$chosen['price']) {
        $flash = 'Duh, paketnya gak ketemu nih.'; $flashType = 'error';
    } elseif ((float)$chosen['price'] == 0) {
        $flash = 'Paket Free gak usah diupgrade ya!'; $flashType = 'error';
    } else {
        $voucher_code = strtoupper(trim($_POST['voucher_code'] ?? ''));
        $price = get_active_price($chosen, $user);
        $final_price = $price;
        $v_data = null;
        
        if ($voucher_code !== '') {
            $v_stmt = $pdo->prepare("SELECT * FROM discount_vouchers WHERE code = ? FOR UPDATE");
            $v_stmt->execute([$voucher_code]);
            $v_data = $v_stmt->fetch();
            
            if (!$v_data) {
                $flash = 'Kode vouchermu gak valid nih.'; $flashType = 'error';
                goto end_post;
            }
            if ($v_data['expires_at'] && strtotime($v_data['expires_at']) < time()) {
                $flash = 'Wah, voucher diskon ini udah kedaluwarsa.'; $flashType = 'error';
                goto end_post;
            }
            if ($v_data['max_claims'] > 0 && $v_data['claims_count'] >= $v_data['max_claims']) {
                $flash = 'Kuota voucher diskon ini udah abis ya.'; $flashType = 'error';
                goto end_post;
            }
            
            $chk = $pdo->prepare("SELECT id FROM user_discount_claims WHERE user_id = ? AND voucher_id = ?");
            $chk->execute([$user['id'], $v_data['id']]);
            if ($chk->fetch()) {
                $flash = 'Kamu udah pernah pakai voucher diskon ini sebelumnya.'; $flashType = 'error';
                goto end_post;
            }
            
            $discounts = json_decode($v_data['discounts'], true) ?: [];
            
            if (isset($discounts['*'])) {
                $val = $discounts['*'];
            } elseif (isset($discounts[$mid])) {
                $val = $discounts[$mid];
            } else {
                $flash = 'Voucher diskon ini gak bisa dipakai buat paket pilihanmu ya.'; $flashType = 'error';
                goto end_post;
            }
            
            if (is_string($val) && stripos($val, 'rp') !== false) {
                $discount_amount = (float)str_ireplace('rp', '', $val);
            } elseif (is_numeric($val) && $val > 100) {
                $discount_amount = (float)$val; // legacy format fallback
            } else {
                $pct = (float)$val;
                $discount_amount = ($price * $pct) / 100;
            }
            
            $final_price = $price - $discount_amount;
            if ($final_price < 0) $final_price = 0;
        }
        
        if ((float)$user['balance_dep'] < $final_price) {
            $flash = 'Saldo Beli kamu kurang nih. Yuk deposit dulu!'; $flashType = 'error';
        } else {
            try {
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("UPDATE users SET balance_dep=balance_dep-? WHERE id=? AND balance_dep >= ?");
                $stmt->execute([$final_price, $user['id'], $final_price]);
                
                if ($stmt->rowCount() > 0) {
                    if ($v_data) {
                        $v_lock = $pdo->prepare("SELECT claims_count, max_claims FROM discount_vouchers WHERE id = ? FOR UPDATE");
                        $v_lock->execute([$v_data['id']]);
                        $v_current = $v_lock->fetch();
                        if ($v_current['max_claims'] > 0 && $v_current['claims_count'] >= $v_current['max_claims']) {
                            throw new \Exception("Kuota voucher diskon sudah habis.");
                        }
                        
                        $pdo->prepare("INSERT INTO user_discount_claims (user_id, voucher_id) VALUES (?, ?)")
                            ->execute([$user['id'], $v_data['id']]);
                        
                        $pdo->prepare("UPDATE discount_vouchers SET claims_count = claims_count + 1 WHERE id = ?")
                            ->execute([$v_data['id']]);
                    }
                    
                    $pdo->prepare("INSERT INTO upgrade_orders (user_id,membership_id,amount,status,confirmed_at) VALUES (?,?,?,'confirmed',NOW())")
                        ->execute([$user['id'], $mid, $final_price]);
                    
                    $new_expires = date('Y-m-d H:i:s', strtotime("+{$chosen['duration_days']} days"));
                    $pdo->prepare("UPDATE users SET membership_id=?, membership_expires_at=? WHERE id=?")
                        ->execute([$mid, $new_expires, $user['id']]);
                    
                    $pdo->commit();
                    
                    $us = $pdo->prepare("SELECT * FROM users WHERE id=?"); $us->execute([$user['id']]); $user = $us->fetch();
                    $flash = '🎉 Hore! Upgrade ke ' . htmlspecialchars($chosen['name']) . ' berhasil! Berlaku s/d ' . date('d M Y', strtotime($new_expires)) . ' ya.';
                    $active_membership = $chosen;
                    $can_refund = true; // just upgraded, well within 12 hours
                    
                    $msgNotif = "🎉 <b>MEMBER UPGRADE LEVEL</b>\n\n";
                    $msgNotif .= "👤 User: <code>{$user['username']}</code>\n";
                    $msgNotif .= "🏆 Level Baru: <b>{$chosen['name']}</b>\n";
                    $msgNotif .= "💰 Harga: " . format_rp((float)$final_price) . "\n";
                    $msgNotif .= "🕒 Waktu: " . date('d M Y H:i:s');
                    send_telegram_notif($pdo, $msgNotif, [], 'log');
                } else {
                    $pdo->rollBack();
                    $flash = 'Saldo Beli kamu kurang nih. Transaksi gagal ya.'; $flashType = 'error';
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flash = 'Terjadi kesalahan: ' . $e->getMessage(); $flashType = 'error';
            }
        }
    }
}
end_post:

// Migrasi nama pangkat lama (tema Amber, lalu tema nelayan) -> tema sawah PanenCuan (hanya jika masih bernama lama)
$rankRename = [
  'Raw Amber'         => ['Petani Pemula', 'ph-plant',  'Pangkat awal. Kuota nonton harian dasar.'],
  'Golden Amber'      => ['Juragan Sawah', 'ph-leaf',   'Kuota nonton lebih banyak & batas tarik lebih tinggi.'],
  'Royal Amber'       => ['Mandor Panen',  'ph-basket', 'Pilihan favorit: kuota besar & tarik lebih cepat.'],
  'Imperial Amber'    => ['Tuan Tanah',    'ph-crown',  'Pangkat tertinggi: kuota maksimal & pencairan prioritas.'],
  'Nelayan Pemula'    => ['Petani Pemula', 'ph-plant',  'Pangkat awal. Kuota nonton harian dasar.'],
  'Juragan Perahu'    => ['Juragan Sawah', 'ph-leaf',   'Kuota nonton lebih banyak & batas tarik lebih tinggi.'],
  'Kapten Kapal'      => ['Mandor Panen',  'ph-basket', 'Pilihan favorit: kuota besar & tarik lebih cepat.'],
  'Laksamana Samudra' => ['Tuan Tanah',    'ph-crown',  'Pangkat tertinggi: kuota maksimal & pencairan prioritas.'],
];
$renStmt = $pdo->prepare("UPDATE memberships SET name = ?, icon = ?, description = ? WHERE name = ?");
foreach ($rankRename as $old => [$new, $icon, $desc]) $renStmt->execute([$new, $icon, $desc, $old]);
// Reload after rename
$memberships = $pdo->query("SELECT * FROM memberships WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();
// Ambil ulang pangkat aktif agar nama/ikon sesuai data terbaru
if ($active_membership) {
    $ms = $pdo->prepare("SELECT * FROM memberships WHERE id=?");
    $ms->execute([$active_membership['id']]);
    $active_membership = $ms->fetch() ?: $active_membership;
}

$pageTitle  = 'Level Up';
$activePage = 'upgrade';
require dirname(__DIR__) . '/partials/header.php';
?>
<style>
/* ══ LEVEL UP — Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.pk-flash { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 16px; font-size: 12px; font-weight: 800; }
.pk-flash.success { background: #dcfce7; color: #14532d; box-shadow: inset 0 0 0 2px #bbf7d0; }
.pk-flash.error { background: #ffe4e6; color: #9f1239; box-shadow: inset 0 0 0 2px #fecdd3; }

/* Status di hero */
.pk-status { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-top: 12px; }
.pk-st { display: flex; align-items: center; gap: 7px; min-width: 0; padding: 7px 7px 7px 8px; border-radius: 16px; background: rgba(255, 255, 255, .8); box-shadow: 0 0 0 2px rgba(255, 255, 255, .9), 0 6px 14px rgba(120, 70, 10, .1); }
.pk-st .g-slot { width: 34px; height: 34px; border-radius: 11px; }
.pk-st .tx { flex: 1; min-width: 0; line-height: 1.2; }
.pk-st small { display: block; font-size: 10px; font-weight: 900; color: #8a5a00; }
.pk-st b { display: block; font-size: 12.5px; font-weight: 900; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pk-st em { display: block; font-style: normal; font-size: 10px; font-weight: 800; color: var(--pc-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pk-st .add { flex-shrink: 0; width: 28px; height: 28px; border-radius: 9px; display: grid; place-items: center; text-decoration: none; color: #fff; font-size: 15px; background: linear-gradient(135deg, #22c55e, #15803d); box-shadow: 0 2px 0 #11622f; }
.pk-refund { flex-shrink: 0; width: 28px; height: 28px; border: 0; border-radius: 9px; cursor: pointer; display: grid; place-items: center; color: #be123c; font-size: 15px; background: #ffe4e6; }

/* Pemilih pangkat */
.pk-tiers { grid-auto-columns: minmax(0, 1fr); }
.pk-tier.g-tier { position: relative; flex-direction: column; gap: 1px; min-height: 52px; padding: 5px 2px; line-height: 1.05; }
.pk-tier b { font-size: 12.5px; font-weight: 600; text-align: center; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding: 0 2px; }
.pk-tier small { font-family: 'Nunito', sans-serif; font-size: 10.5px; font-weight: 900; opacity: .85; }
.pk-tier .hot { position: absolute; top: -9px; left: 50%; transform: translateX(-50%); padding: 1px 7px; border-radius: 999px; font-family: 'Nunito', sans-serif; font-size: 9px; font-weight: 900; color: #fff; background: linear-gradient(180deg, #fb7185, #e11d48); box-shadow: 0 2px 0 #9f1239; white-space: nowrap; text-shadow: none; }
.pk-tier .cur { position: absolute; top: 3px; right: 4px; font-size: 13px; color: #16a34a; }
.pk-tier.on .cur { color: #ffd54f; }
.pk-tiers .pk-tier.on { color: #fff; background: linear-gradient(180deg, #22c55e, #15803d); box-shadow: 0 3px 0 #11622f; text-shadow: 0 1px 0 rgba(0, 0, 0, .2); }

/* Kartu pangkat */
.pk-card { display: none; overflow: hidden; border-radius: 24px; background: #fff; box-shadow: 0 0 0 2px #f6e2b0, 0 5px 0 #ecd59c, 0 14px 24px rgba(120, 70, 10, .1); }
.pk-card.on { display: block; animation: pkIn .4s var(--pc-out); }
@keyframes pkIn { from { opacity: 0; transform: translateY(10px) scale(.98); } }
.pk-top { position: relative; overflow: hidden; display: flex; align-items: center; gap: 11px; padding: 13px 14px; }
.pk-card.t0 .pk-top { color: #fff; background: radial-gradient(circle at 92% 120%, rgba(255, 230, 120, .5) 0, transparent 45%), linear-gradient(135deg, #15803d, #22c55e); }
.pk-card.t1 .pk-top { color: #4a3000; background: radial-gradient(circle at 92% 120%, rgba(255, 255, 255, .55) 0, transparent 45%), linear-gradient(135deg, #f6b70a, #ffd54f); }
.pk-card.t2 .pk-top { color: #fff; background: radial-gradient(circle at 92% 120%, rgba(255, 213, 79, .5) 0, transparent 45%), linear-gradient(135deg, #6d28d9, #a78bfa); }
.pk-emb { position: relative; flex-shrink: 0; width: 58px; height: 58px; border-radius: 18px; display: grid; place-items: center; background: rgba(255, 255, 255, .3); box-shadow: inset 0 0 0 2px rgba(255, 255, 255, .7); }
.pk-emb img { width: 46px; height: 46px; object-fit: contain; filter: drop-shadow(0 4px 4px rgba(0, 0, 0, .25)); animation: gBob 3s ease-in-out infinite; }
.pk-name { flex: 1; min-width: 0; }
.pk-name b { display: block; font-family: var(--pc-font); font-size: 19px; font-weight: 700; line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pk-name span { display: inline-flex; align-items: center; gap: 3px; margin-top: 4px; padding: 1px 8px; border-radius: 999px; font-size: 10.5px; font-weight: 900; background: rgba(255, 255, 255, .3); }
.pk-price { flex-shrink: 0; text-align: right; }
.pk-price s { display: block; font-size: 11px; font-weight: 800; opacity: .8; }
.pk-price b { display: block; font-family: var(--pc-font); font-size: 20px; font-weight: 700; line-height: 1.1; }
.pk-price em { display: inline-block; margin-top: 2px; padding: 0 7px; border-radius: 999px; font-style: normal; font-size: 10px; font-weight: 900; color: #fff; background: #e11d48; }
.pk-body { padding: 11px 12px 12px; }
.pk-nums { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.pk-num { display: flex; align-items: center; gap: 7px; min-width: 0; padding: 7px 8px; border-radius: 14px; background: #fffaf0; box-shadow: inset 0 0 0 1.5px #f1dfb4; }
.pk-num img { width: 28px; height: 28px; flex-shrink: 0; object-fit: contain; }
.pk-num > span { min-width: 0; }
.pk-num b { display: block; font-size: 12.5px; font-weight: 900; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pk-num small { display: block; font-size: 10px; font-weight: 800; color: var(--pc-muted); }
.pk-perks { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 8px; }
.pk-perks .g-chip { background: #dcfce7; color: #14532d; }
.pk-cta { margin-top: 10px; }
.pk-note { margin-top: 6px; text-align: center; font-size: 10.5px; font-weight: 700; color: var(--pc-muted); }

.pk-trust { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; }
.pk-trust span { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 8px 4px; border-radius: 16px; text-align: center; font-size: 11px; font-weight: 900; color: var(--pc-ink); background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 0 0 2px #f6e2b0, 0 3px 0 #ecd59c; }
.pk-trust img { width: 30px; height: 30px; object-fit: contain; }

.pk-guide { border-radius: 18px; background: #fff; box-shadow: 0 0 0 2px #efe6cf, 0 3px 0 #e6dbbd; overflow: hidden; }
.pk-guide summary { display: flex; align-items: center; gap: 7px; padding: 11px 12px; cursor: pointer; list-style: none; font-size: 12.5px; font-weight: 900; color: var(--pc-ink); }
.pk-guide summary::-webkit-details-marker { display: none; }
.pk-guide summary img { width: 22px; height: 22px; }
.pk-guide summary .chev { margin-left: auto; color: #15803d; transition: transform .25s var(--pc-ease); }
.pk-guide[open] summary .chev { transform: rotate(180deg); }
.pk-steps { list-style: none; margin: 0; padding: 0 12px 11px; display: flex; flex-direction: column; gap: 7px; }
.pk-steps li { display: flex; align-items: center; gap: 9px; font-size: 12px; font-weight: 700; color: var(--pc-muted); }
.pk-steps li > span { width: 24px; height: 24px; flex-shrink: 0; border-radius: 50%; display: grid; place-items: center; font-family: var(--pc-font); font-size: 13px; font-weight: 700; color: #4a3000; background: linear-gradient(180deg, #ffd54f, #f6b70a); box-shadow: 0 2px 0 #b07800; }
.pk-steps b { color: var(--pc-ink); }

/* Modal */
.cg-modal { display: none; position: fixed; inset: 0; z-index: 10000; align-items: flex-end; justify-content: center; background: rgba(40, 25, 5, .5); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); }
.cg-modal-card { width: 100%; max-width: 440px; padding: 10px 16px calc(16px + env(safe-area-inset-bottom)); border-radius: 26px 26px 0 0; background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 -10px 30px rgba(40, 25, 5, .25); animation: pkUp .35s var(--pc-out); }
@keyframes pkUp { from { transform: translateY(100%); } }
.cg-grab { width: 40px; height: 5px; margin: 0 auto 8px; border-radius: 5px; background: #ecd59c; }
.cg-art { display: block; width: 70px; height: 70px; margin: 0 auto 2px; object-fit: contain; animation: gBob 3s ease-in-out infinite; }
.cg-mc-hdr { text-align: center; font-family: var(--pc-font); font-size: 19px; font-weight: 700; color: #4a2b00; }
.cg-mc-box { margin: 10px 0; padding: 10px 12px; border-radius: 16px; background: #fff; box-shadow: inset 0 0 0 1.5px #f1dfb4; }
.cg-mc-val { margin-bottom: 6px; text-align: center; font-family: var(--pc-font); font-size: 17px; font-weight: 700; color: #15803d; }
.cg-line { display: flex; justify-content: space-between; padding: 3px 0; font-size: 12px; font-weight: 800; color: var(--pc-muted); }
.cg-line b, .cg-line span { font-weight: 900; color: var(--pc-ink); }
#discount-row, #discount-row span { color: #15803d; }
#final-price-row { margin-top: 3px; padding-top: 6px; border-top: 1.5px dashed #f1dfb4; font-size: 13px; }
#final-price-row span { color: #be123c; }
.cg-voucher-btn { display: inline-flex; align-items: center; gap: 4px; padding: 0; border: 0; background: none; cursor: pointer; font: 900 12px/1 'Nunito', sans-serif; color: #15803d; }
#voucher-box { margin-top: 6px; gap: 6px; }
#voucher-input { flex: 1; min-width: 0; padding: 9px 11px; border: 0; border-radius: 12px; outline: none; background: #fff; box-shadow: inset 0 0 0 2px #f1dfb4; font: 900 12.5px/1 'Nunito', sans-serif; text-transform: uppercase; color: var(--pc-ink); }
#voucher-input:focus { box-shadow: inset 0 0 0 2px #22c55e; }
.cg-voucher-apply.g-btn { min-height: 36px; padding: 0 13px; font-size: 13px; border-radius: 11px; }
#voucher-msg { display: none; margin-top: 5px; font-size: 11.5px; font-weight: 800; }
#modal-warn { display: none; margin: 8px 0; padding: 8px 10px; border-radius: 12px; background: #ffe4e6; font-size: 11.5px; font-weight: 800; color: #be123c; }
.cg-btn-row { display: flex; gap: 8px; margin-top: 12px; }
.cg-btn-row .g-btn { flex: 1; }
.cg-sub { margin-top: 4px; text-align: center; font-size: 12px; font-weight: 700; color: var(--pc-muted); line-height: 1.45; }
</style>

<?php
$paid = array_values(array_filter($memberships, fn($m) => (float)$m['price'] > 0));
usort($paid, fn($a, $b) => (float)$a['price'] <=> (float)$b['price']);
$free_rank  = current(array_filter($memberships, fn($m) => (float)$m['price'] == 0)) ?: null;
$cur_id     = $active_membership ? (int)$active_membership['id'] : 0;
$hot_idx    = count($paid) >= 3 ? 1 : -1;
// Pangkat terpilih awal: satu tingkat di atas pangkat aktif, atau yang populer
$sel_idx = $hot_idx >= 0 ? $hot_idx : 0;
foreach ($paid as $i => $m) { if ((int)$m['id'] === $cur_id) { $sel_idx = min($i + 1, count($paid) - 1); break; } }
$short = static fn(float $n): string => $n >= 1000000 ? rtrim(rtrim(number_format($n / 1000000, 1, ',', '.'), '0'), ',') . 'jt' : ($n >= 1000 ? number_format($n / 1000, 0, ',', '.') . 'rb' : number_format($n, 0, ',', '.'));
$ic = static fn(string $n): string => '/assets/img/icons3d/' . $n . '.png';
$tier_art = ['padi', 'basket', 'barn', 'chest_open'];
?>
<div class="g-page">

  <?php if ($flash): ?>
    <div class="pk-flash <?= $flashType === 'error' ? 'error' : 'success' ?>"><span><?= htmlspecialchars($flash) ?></span></div>
  <?php endif; ?>

  <section class="g-hero" data-rv>
    <span class="g-sun"></span><span class="g-cloud c1"></span><span class="g-cloud c2"></span>
    <div class="g-hero-row">
      <div style="flex:1;min-width:0">
        <h1>Level Up</h1>
        <p>Naik pangkat, kuota video &amp; batas withdraw ikut naik.</p>
      </div>
      <div class="g-hero-art"><img src="<?= $ic('upgrade') ?>" alt=""></div>
    </div>
    <div class="pk-status">
      <div class="pk-st">
        <span class="g-slot"><img src="<?= $ic('piggy') ?>" alt=""></span>
        <span class="tx"><small>Saldo Beli</small><b><?= format_rp((float)$user['balance_dep']) ?></b></span>
        <a href="/deposit" class="add" aria-label="Deposit Saldo Beli"><i class="ph-bold ph-plus"></i></a>
      </div>
      <div class="pk-st">
        <span class="g-slot"><img src="<?= $ic($active_membership ? 'upgrade' : 'seed') ?>" alt=""></span>
        <span class="tx">
          <small>Pangkatmu</small>
          <b><?= htmlspecialchars((string)($active_membership['name'] ?? ($free_rank['name'] ?? 'Petani Pemula'))) ?></b>
          <em><?= $active_membership ? 's/d ' . date('d M Y', strtotime($user['membership_expires_at'])) : 'Gratis' ?></em>
        </span>
        <?php if ($can_refund): ?>
          <button type="button" class="pk-refund" onclick="document.getElementById('refund-modal').style.display='flex'" aria-label="Ajukan refund" title="Ajukan refund"><i class="ph-bold ph-arrow-u-up-left"></i></button>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php if (!$paid): ?>
    <div class="g-panel g-empty"><img src="<?= $ic('upgrade') ?>" alt=""><b>Belum ada pangkat</b><p>Belum ada pangkat berbayar yang tersedia.</p></div>
  <?php else: ?>

  <div class="g-seg pk-tiers" role="tablist" data-rv style="--d:.05s">
    <?php foreach ($paid as $i => $m): ?>
      <button type="button" class="pk-tier g-tier <?= $i === $sel_idx ? 'on' : '' ?>" data-tier="<?= $i ?>" onclick="pickTier(<?= $i ?>)" role="tab">
        <?php if ($i === $hot_idx): ?><span class="hot">FAVORIT</span><?php endif; ?>
        <?php if ((int)$m['id'] === $cur_id): ?><i class="ph-fill ph-check-circle cur"></i><?php endif; ?>
        <b><?= htmlspecialchars($m['name']) ?></b>
        <small><?= $short(get_active_price($m, $user)) ?></small>
      </button>
    <?php endforeach; ?>
  </div>

  <form method="POST" id="upgrade-form">
    <?= csrf_field() ?>
    <input type="hidden" name="membership_id" id="chosen-id" value="">
    <input type="hidden" name="voucher_code" id="applied-voucher-code" value="">
  </form>

  <?php foreach ($paid as $i => $m):
    $active_price = get_active_price($m, $user);
    $can_afford   = (float)$user['balance_dep'] >= $active_price;
    $h            = get_harvest_calc($m, $avg_video_reward);
    $orig         = (float)$m['original_price'];
    $off          = $orig > $active_price && $orig > 0 ? (int)round((1 - $active_price / $orig) * 100) : 0;
    $is_cur       = (int)$m['id'] === $cur_id;
    $is_top       = $i === count($paid) - 1;
  ?>
    <div class="pk-card t<?= $i % 3 ?> <?= $i === $sel_idx ? 'on' : '' ?>" data-card="<?= $i ?>">
      <div class="pk-top">
        <div class="pk-emb"><img src="<?= $ic($tier_art[min($i, count($tier_art) - 1)]) ?>" alt=""></div>
        <div class="pk-name">
          <b><?= htmlspecialchars($m['name']) ?></b>
          <span><i class="ph-bold ph-hourglass"></i> <?= (int)$m['duration_days'] ?> hari</span>
        </div>
        <div class="pk-price">
          <?php if ($orig > $active_price): ?><s><?= format_rp($orig) ?></s><?php endif; ?>
          <b><?= format_rp($active_price) ?></b>
          <?php if ($off > 0): ?><em>-<?= $off ?>%</em><?php endif; ?>
        </div>
      </div>
      <div class="pk-body">
        <div class="pk-nums">
          <div class="pk-num"><img src="<?= $ic('watch') ?>" alt=""><span><b><?= (int)$m['watch_limit'] ?> video</b><small>Per hari</small></span></div>
          <div class="pk-num"><img src="<?= $ic('coins') ?>" alt=""><span><b><?= $h ? '±' . format_rp(round($h['daily_income'] / 1000) * 1000) : '-' ?></b><small>Estimasi/hari</small></span></div>
          <div class="pk-num"><img src="<?= $ic('piggy') ?>" alt=""><span><b><?= format_rp((float)$m['min_wd']) ?></b><small>Min withdraw</small></span></div>
          <div class="pk-num"><img src="<?= $ic('moneybag') ?>" alt=""><span><b><?= (float)$m['max_wd'] > 0 ? format_rp((float)$m['max_wd']) : 'Tanpa batas' ?></b><small>Maks withdraw</small></span></div>
        </div>
        <?php if (!empty($m['allow_edit_bank']) || $is_top || ($h && $h['break_even_days'] > 0)): ?>
        <div class="pk-perks">
          <?php if ($h && $h['break_even_days'] > 0): ?><span class="g-chip"><i class="ph-bold ph-lightning"></i>Balik modal ±<?= (int)$h['break_even_days'] ?> hari</span><?php endif; ?>
          <?php if (!empty($m['allow_edit_bank'])): ?><span class="g-chip"><i class="ph-bold ph-pencil-simple"></i>Bebas ganti rekening</span><?php endif; ?>
          <?php if ($is_top): ?><span class="g-chip"><i class="ph-bold ph-crown"></i>Pencairan prioritas</span><?php endif; ?>
        </div>
        <?php endif; ?>
        <button type="button" class="g-btn full pk-cta <?= $is_cur ? '' : ($can_afford ? 'gold shine' : 'soft') ?>"
          onclick="<?= !$can_afford && !$is_cur ? "location.href='/deposit'" : 'openConfirm(' . (int)$m['id'] . ", '" . htmlspecialchars($m['name'], ENT_QUOTES) . "', " . $active_price . ', ' . (int)$m['duration_days'] . ')' ?>">
          <?php if ($is_cur): ?><i class="ph-bold ph-arrows-clockwise"></i>Perpanjang pangkat
          <?php elseif ($can_afford): ?><img src="<?= $ic('upgrade') ?>" alt="">Naik ke <?= htmlspecialchars($m['name']) ?>
          <?php else: ?><img src="<?= $ic('piggy') ?>" alt="">Deposit · kurang <?= format_rp($active_price - (float)$user['balance_dep']) ?><?php endif; ?>
        </button>
        <?php if ($h): ?><div class="pk-note">Estimasi dari rata-rata <?= format_rp($avg_video_reward) ?>/video</div><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <div class="pk-trust" data-rv>
    <span><img src="<?= $ic('lock') ?>" alt="">Garansi 12 jam</span>
    <span><img src="<?= $ic('speed') ?>" alt="">Aktif instan</span>
    <span><img src="<?= $ic('moneybag') ?>" alt="">WD 1×24 jam</span>
  </div>

  <details class="pk-guide" data-rv>
    <summary><img src="<?= $ic('compass') ?>" alt="">Cara level up <i class="ph-bold ph-caret-down chev"></i></summary>
    <ol class="pk-steps">
      <li><span>1</span><span>Isi <b>Saldo Beli</b> lewat tombol +</span></li>
      <li><span>2</span><span>Pilih pangkat, tekan tombol, konfirmasi</span></li>
      <li><span>3</span><span>Pangkat aktif, kuota nonton &amp; bonus langsung berlaku</span></li>
    </ol>
  </details>
</div>

<!-- MODAL KONFIRMASI -->
<div id="upgrade-modal" class="cg-modal">
  <div class="cg-modal-card">
    <div class="cg-grab"></div>
    <img class="cg-art" src="<?= $ic('upgrade') ?>" alt="">
    <div class="cg-mc-hdr">Konfirmasi pangkat</div>
    <div class="cg-mc-box">
      <div class="cg-mc-val" id="modal-name">--</div>
      <div class="cg-line" id="price-row">Harga <span id="modal-price">--</span></div>
      <div class="cg-line" id="discount-row" style="display:none">Diskon (<span id="modal-pct">--</span>) <span>-<span id="modal-discount">--</span></span></div>
      <div class="cg-line" id="final-price-row" style="display:none">Total bayar <span id="modal-final-price">--</span></div>
      <div class="cg-line">Masa aktif <b><span id="modal-days">--</span> hari</b></div>
    </div>
    <button type="button" id="toggle-voucher-btn" class="cg-voucher-btn" onclick="toggleVoucher()"><i class="ph-bold ph-tag"></i> Punya voucher?</button>
    <div id="voucher-box" style="display:none">
      <input type="text" id="voucher-input" placeholder="KODE VOUCHER">
      <button type="button" class="g-btn cg-voucher-apply" onclick="applyVoucher()">Pakai</button>
    </div>
    <div id="voucher-msg"></div>
    <div id="modal-warn"></div>
    <div class="cg-btn-row">
      <button type="button" class="g-btn soft" onclick="closeConfirm()">Batal</button>
      <button type="button" id="modal-confirm-btn" class="g-btn gold shine" onclick="submitUpgrade()">YA, AKTIFKAN!</button>
    </div>
  </div>
</div>

<!-- MODAL REFUND -->
<div id="refund-modal" class="cg-modal">
  <div class="cg-modal-card">
    <div class="cg-grab"></div>
    <img class="cg-art" src="<?= $ic('piggy') ?>" alt="">
    <div class="cg-mc-hdr">Ajukan refund?</div>
    <div class="cg-sub">Saldo kembali ke <b>Saldo Beli</b> setelah dipotong biaya admin. Pangkat kembali ke <b><?= htmlspecialchars((string)($free_rank['name'] ?? 'pangkat awal')) ?></b>.</div>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="refund_level">
      <div class="cg-btn-row">
        <button type="button" class="g-btn soft" onclick="document.getElementById('refund-modal').style.display='none'">Batal</button>
        <button type="submit" class="g-btn red">Ajukan refund</button>
      </div>
    </form>
  </div>
</div>

<script>
function pickTier(i) {
  document.querySelectorAll('.pk-tier').forEach((b) => b.classList.toggle('on', +b.dataset.tier === i));
  document.querySelectorAll('.pk-card').forEach((c) => c.classList.toggle('on', +c.dataset.card === i));
}
</script>

<script>
const userBal = <?= (float)$user['balance_dep'] ?>;
let currentPrice = 0;

function checkAffordability(p) {
  const btn = document.getElementById('modal-confirm-btn');
  const warn = document.getElementById('modal-warn');
  if (userBal < p) {
    btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed';
    btn.innerText = 'Saldo Kurang';
    warn.style.display = 'block';
    warn.innerHTML = 'Saldo kurang <strong>Rp ' + (p - userBal).toLocaleString('id-ID') + '</strong>. Silakan deposit dulu.';
  } else {
    btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer';
    btn.innerText = 'YA, AKTIFKAN!';
    warn.style.display = 'none';
  }
}
function openConfirm(id, name, price, days) {
  currentPrice = price;
  document.getElementById('chosen-id').value = id;
  document.getElementById('modal-name').textContent = name;
  document.getElementById('modal-price').textContent = 'Rp ' + price.toLocaleString('id-ID');
  document.getElementById('modal-days').textContent = days;
  document.getElementById('applied-voucher-code').value = '';
  document.getElementById('discount-row').style.display = 'none';
  document.getElementById('final-price-row').style.display = 'none';
  const vi = document.getElementById('voucher-input'); if(vi) vi.value = '';
  const vm = document.getElementById('voucher-msg'); if(vm) vm.style.display = 'none';
  const vb = document.getElementById('voucher-box'); if(vb) vb.style.display = 'none';
  const tb = document.getElementById('toggle-voucher-btn'); if(tb){ tb.style.display='flex'; tb.innerHTML='<i class="ph-bold ph-tag"></i> Pakai Voucher Diskon?'; }
  checkAffordability(price);
  document.getElementById('upgrade-modal').style.display = 'flex';
  document.body.style.overflow = 'hidden';
}
function closeConfirm() {
  document.getElementById('upgrade-modal').style.display = 'none';
  document.body.style.overflow = '';
}
function toggleVoucher() {
  const vb = document.getElementById('voucher-box');
  const tb = document.getElementById('toggle-voucher-btn');
  if(vb.style.display === 'none') { vb.style.display='flex'; tb.innerHTML='<i class="ph-bold ph-x"></i> Tutup Voucher'; }
  else { vb.style.display='none'; tb.innerHTML='<i class="ph-bold ph-tag"></i> Pakai Voucher Diskon?'; }
}
function applyVoucher() {
  const code = document.getElementById('voucher-input').value.toUpperCase().trim();
  const mid = document.getElementById('chosen-id').value;
  const msg = document.getElementById('voucher-msg');
  if(!code){ msg.style.color='#ef4444'; msg.innerText='Masukkan kode voucher.'; msg.style.display='block'; return; }
  msg.style.color='#94a3b8'; msg.innerText='Mengecek...'; msg.style.display='block';
  fetch('', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'action=check_voucher&code='+encodeURIComponent(code)+'&membership_id='+encodeURIComponent(mid)+'&_csrf='+encodeURIComponent(document.querySelector('input[name="_csrf"]')?.value||'')
  })
  .then(r=>r.json())
  .then(res=>{
    if(res.error){ msg.style.color='#ef4444'; msg.innerText=res.error; msg.style.display='block';
      document.getElementById('applied-voucher-code').value='';
      document.getElementById('discount-row').style.display='none';
      document.getElementById('final-price-row').style.display='none';
      checkAffordability(currentPrice);
    } else {
      msg.style.color='#10b981'; msg.innerText='Diskon '+res.discount_text+' aktif!'; msg.style.display='block';
      document.getElementById('applied-voucher-code').value=code;
      document.getElementById('modal-discount').textContent=res.discount_amount_formatted;
      document.getElementById('modal-pct').textContent=res.discount_text;
      document.getElementById('modal-final-price').textContent=res.final_price_formatted;
      document.getElementById('discount-row').style.display='flex';
      document.getElementById('final-price-row').style.display='flex';
      document.getElementById('voucher-box').style.display='none';
      document.getElementById('toggle-voucher-btn').style.display='none';
      checkAffordability(res.final_price);
    }
  })
  .catch(()=>{ msg.style.color='#ef4444'; msg.innerText='Gagal cek voucher.'; msg.style.display='block'; });
}
function submitUpgrade() {
  const btn = document.getElementById('modal-confirm-btn');
  btn.disabled = true; btn.textContent = 'Memproses...';
  document.getElementById('upgrade-form').submit();
}
document.getElementById('upgrade-modal').addEventListener('click', function(e){ if(e.target===this) closeConfirm(); });
document.getElementById('refund-modal').addEventListener('click', function(e){ if(e.target===this) e.target.style.display='none'; });
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
