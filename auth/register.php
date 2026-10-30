<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
if (auth_user($pdo)) redirect('/home');
csrf_enforce();

$error = '';
$error_fields = [];

// Client IP resolver (Handles Cloudflare & Reverse Proxy)
$client_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] 
    ?? $_SERVER['HTTP_X_FORWARDED_FOR'] 
    ?? $_SERVER['REMOTE_ADDR'] 
    ?? '127.0.0.1';
if (str_contains($client_ip, ',')) {
    $client_ip = trim(explode(',', $client_ip)[0]);
}

// Rate limiting by session
$ip_key = 'reg_' . md5($client_ip);
$attempts = (int)($_SESSION[$ip_key . '_attempts'] ?? 0);
$lock_until = (int)($_SESSION[$ip_key . '_lock'] ?? 0);

// ── Captcha "Sawah Bersih": pilih semua ikan / semua sampah dari 6 kotak gambar ──
// Jawaban disimpan di session (tidak pernah dikirim ke browser), gambar diacak ulang tiap permintaan.
const REG_CAP_FISH = ['biasa', 'bagus', 'langka', 'super'];
// Sampah yang jelas bukan ikan (kantong plastik dibuang karena mirip ikan/bawang)
const REG_CAP_JUNK = ['junk-boot', 'junk-bottle', 'junk-can', 'junk-cup', 'junk-trashbag'];
const REG_CAP_TTL  = 900;

/** Soal selalu sama: 1 ikan di antara 5 sampah, cukup ketuk ikannya. */
function generate_captcha_challenge(): array {
    $fish = REG_CAP_FISH; shuffle($fish);
    $junk = REG_CAP_JUNK; shuffle($junk);
    $tiles = array_merge([$fish[0]], $junk);
    shuffle($tiles);

    $ans = [];
    foreach ($tiles as $i => $t) {
        if (!str_starts_with($t, 'junk-')) $ans[] = $i;
    }
    $_SESSION['reg_cap'] = ['tiles' => $tiles, 'ans' => $ans, 'ts' => time(), 'v' => bin2hex(random_bytes(4))];

    return [
        'prompt' => 'Ketuk ikan di sawah',
        'icon'   => 'ph-fish-simple',
        'v'      => $_SESSION['reg_cap']['v'],
    ];
}

/** Gambar satu kotak captcha: rotasi, skala, posisi & gelembung acak agar tidak bisa dicocokkan per-byte */
function render_captcha_tile(int $i): void {
    $cap = $_SESSION['reg_cap'] ?? null;
    $name = $cap['tiles'][$i] ?? null;
    header('Cache-Control: no-store, max-age=0');
    $src = $name ? @imagecreatefrompng(dirname(__DIR__) . '/assets/img/fish/' . $name . '.png') : false;
    if (!$src) { http_response_code(404); exit; }

    $S = 96;
    $im = imagecreatetruecolor($S, $S);
    imagealphablending($im, true);
    imagesavealpha($im, true);
    $bgs = [[190, 232, 160], [168, 222, 140], [184, 228, 244], [255, 228, 168], [206, 234, 182], [192, 228, 214]];
    [$r, $g, $b] = $bgs[random_int(0, count($bgs) - 1)];
    imagefill($im, 0, 0, imagecolorallocate($im, $r + random_int(-6, 0), $g + random_int(-6, 0), $b + random_int(-6, 0)));
    for ($k = random_int(3, 6); $k > 0; $k--) {
        $d = random_int(4, 14);
        imagefilledellipse($im, random_int(0, $S), random_int(0, $S), $d, $d, imagecolorallocatealpha($im, 255, 255, 255, random_int(40, 90)));
    }
    if (random_int(0, 1)) imageflip($src, IMG_FLIP_HORIZONTAL);
    $rot = imagerotate($src, random_int(-28, 28), imagecolorallocatealpha($src, 0, 0, 0, 127));
    imagesavealpha($rot, true);
    $sz = random_int(62, 78);
    $rw = imagesx($rot); $rh = imagesy($rot);
    $dw = (int)round($sz * $rw / 128); $dh = (int)round($sz * $rh / 128);
    imagecopyresampled($im, $rot, (int)(($S - $dw) / 2) + random_int(-6, 6), (int)(($S - $dh) / 2) + random_int(-6, 6), 0, 0, $dw, $dh, $rw, $rh);
    header('Content-Type: image/png');
    imagepng($im, null, 6);
    exit;
}

// Gambar kotak captcha
if (isset($_GET['cap_tile'])) {
    render_captcha_tile(max(0, min(5, (int)$_GET['cap_tile'])));
}

// AJAX Refresh Captcha Endpoint
if (isset($_GET['refresh_captcha'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true] + generate_captcha_challenge());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (time() < $lock_until) {
        $wait = ceil(($lock_until - time()) / 60);
        $error = "Terlalu banyak percobaan pendaftaran gagal. Silakan tunggu {$wait} menit lagi.";
        goto end_reg;
    }

    // ── 1. HONEYPOT ANTI-BOT TRAP (DENGAN TOLERANSI BROWSER AUTOFILL) ──
    $hp_val     = trim((string)($_POST['website_hp_check'] ?? ''));
    $user_email = trim((string)($_POST['email'] ?? ''));
    $user_uname = trim((string)($_POST['username'] ?? ''));

    // Deteksi jika terisi karena false-positive autofill browser (email / username pengguna)
    $is_autofill_accidental = ($hp_val !== '' && (
        ($user_email !== '' && strcasecmp($hp_val, $user_email) === 0) ||
        ($user_uname !== '' && strcasecmp($hp_val, $user_uname) === 0)
    ));

    if (!empty($hp_val) && !$is_autofill_accidental) {
        $msg_bot = "🚨 <b>PERINGATAN ABUSE: BOT SPAMMER TERPERANGKAP HONEYPOT!</b>\n\n"
            . "🌐 <b>IP Address:</b> <code>{$client_ip}</code>\n"
            . "👤 <b>Username:</b> <code>" . htmlspecialchars($_POST['username'] ?? '') . "</code>\n"
            . "📝 <b>Input Perangkap:</b> " . htmlspecialchars($hp_val) . "\n"
            . "🛑 <b>Status:</b> Pendaftaran otomatis digagalkan sistem.\n"
            . "🕐 <b>Waktu:</b> " . date('d M Y H:i:s');
        send_telegram_notif($pdo, $msg_bot, [], 'abuse');
        
        $error = 'Permintaan tidak dapat diproses. Silakan refresh halaman dan coba lagi.';
        goto end_reg;
    }

    // ── 2. SUBMISSION SPEED CHECK ──
    $form_time = (int)($_POST['form_time_sig'] ?? 0);
    if ($form_time > 0 && (time() - $form_time) < 2) {
        $error = 'Pengisian formulir terlalu cepat (terindikasi bot otomatis).';
        goto end_reg;
    }

    // ── 3. CAPTCHA VALIDATION (sekali pakai) ──
    $cap = $_SESSION['reg_cap'] ?? null;
    unset($_SESSION['reg_cap']);
    $cap_pick = array_values(array_unique(array_map('intval', array_filter(explode(',', (string)($_POST['captcha_answer'] ?? '')), 'is_numeric'))));
    sort($cap_pick);

    if (!$cap || !isset($cap['ans'], $cap['ts'])) {
        $error = 'Sesi verifikasi kedaluwarsa. Silakan ulangi verifikasi gambar.';
        $error_fields[] = 'f_captcha';
        goto end_reg;
    }

    if ((time() - (int)$cap['ts']) > REG_CAP_TTL) {
        $error = 'Waktu verifikasi habis. Silakan ulangi verifikasi gambar.';
        $error_fields[] = 'f_captcha';
        goto end_reg;
    }

    if (!$cap_pick || $cap_pick !== $cap['ans']) {
        $error = 'Verifikasi gambar belum tepat. Coba lagi ya!';
        $error_fields[] = 'f_captcha';
        goto end_reg;
    }

    // ── 4. FORM DATA SANITIZATION ──
    $username  = trim($_POST['username']  ?? '');
    $email     = strtolower(trim($_POST['email'] ?? ''));
    $whatsapp  = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
    $password  = $_POST['password']  ?? '';
    $ref_input = strtoupper(trim($_POST['referral'] ?? ''));
    if (empty($ref_input)) {
        $ref_input = strtoupper(trim($_COOKIE['tonton_ref'] ?? $_COOKIE['ref_code'] ?? ''));
    }
    
    $bank_name           = trim($_POST['bank_name'] ?? '');
    $account_number      = trim($_POST['account_number'] ?? '');
    $account_name        = trim($_POST['account_name'] ?? '');
    $acc_num_input_type  = ($_POST['acc_num_input_type'] ?? 'typed') === 'pasted' ? 'pasted' : 'typed';
    $acc_name_input_type = ($_POST['acc_name_input_type'] ?? 'typed') === 'pasted' ? 'pasted' : 'typed';
    $acc_num_record      = trim($_POST['acc_num_record'] ?? '[]');
    $acc_name_record     = trim($_POST['acc_name_record'] ?? '[]');

    // ── 5. BASIC VALIDATIONS ──
    if (!$username || !$email || !$whatsapp || !$password || !$bank_name || !$account_number || !$account_name) {
        $error = 'Semua kolom wajib diisi lengkap.';
        if (!$username) $error_fields[] = 'f_username';
        if (!$email) $error_fields[] = 'f_email';
        if (!$whatsapp) $error_fields[] = 'f_wa';
        if (!$password) $error_fields[] = 'f_pwd';
        if (!$bank_name) $error_fields[] = 'f_bank_name';
        if (!$account_number) $error_fields[] = 'f_account_number';
        if (!$account_name) $error_fields[] = 'f_account_name';
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = 'Username harus 3–30 karakter (huruf, angka, underscore).';
        $error_fields[] = 'f_username';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format alamat email tidak valid.';
        $error_fields[] = 'f_email';
    } elseif (strlen($whatsapp) < 9 || strlen($whatsapp) > 16) {
        $error = 'Nomor WhatsApp tidak valid (minimal 9 digit).';
        $error_fields[] = 'f_wa';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
        $error_fields[] = 'f_pwd';
    } else {
        // ── 6. ANTI-ABUSE: IP REGISTRATION RATE LIMIT (Max 3 accounts / 24h) ──
        if ($client_ip !== '127.0.0.1') {
            $ip_cnt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE registration_ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            $ip_cnt->execute([$client_ip]);
            $reg_24h = (int)$ip_cnt->fetchColumn();
            if ($reg_24h >= 3) {
                $msg_abuse = "🚨 <b>PERINGATAN ABUSE: BATAS REGISTRASI IP TERLAMPAUI!</b>\n\n"
                    . "⚠️ <b>Pelanggaran:</b> Terdeteksi {$reg_24h} akun dibuat dari IP yang sama dalam 24 jam terakhir.\n"
                    . "👤 <b>Calon User:</b> <code>{$username}</code>\n"
                    . "🌐 <b>IP Address:</b> <code>{$client_ip}</code>\n"
                    . "🔗 <b>Kode Referral:</b> " . ($ref_input ?: "Tanpa Referral") . "\n"
                    . "🛑 <b>Status:</b> Pendaftaran ditolak otomatis.\n"
                    . "🕐 <b>Waktu:</b> " . date('d M Y H:i:s');
                send_telegram_notif($pdo, $msg_abuse, [], 'abuse');
                
                $error = 'Batas pendaftaran harian untuk jaringan ini telah tercapai (maksimal 3 akun per 24 jam).';
                goto end_reg;
            }
        }

        // ── 7. DUPLICATE USERNAME OR EMAIL CHECK ──
        $chk_user = $pdo->prepare("SELECT id, username, email FROM users WHERE username=? OR email=?");
        $chk_user->execute([$username, $email]);
        $existing_user = $chk_user->fetch();
        if ($existing_user) {
            if ($existing_user['username'] === $username) {
                $error = 'Username sudah digunakan. Pilih username lain.';
                $error_fields[] = 'f_username';
            } else {
                $error = 'Alamat email sudah terdaftar. Silakan login.';
                $error_fields[] = 'f_email';
            }
            $_SESSION[$ip_key . '_attempts'] = $attempts + 1;
            if ($attempts + 1 >= 5) {
                $_SESSION[$ip_key . '_lock'] = time() + 900;
            }
            goto end_reg;
        }

        // ── 8. ANTI-ABUSE: DUPLICATE WHATSAPP CHECK ──
        $chk_wa = $pdo->prepare("SELECT id, username FROM users WHERE whatsapp = ? LIMIT 1");
        $chk_wa->execute([$whatsapp]);
        $existing_wa = $chk_wa->fetch();
        if ($existing_wa) {
            $error = 'Nomor WhatsApp sudah digunakan oleh akun lain.';
            $error_fields[] = 'f_wa';
            goto end_reg;
        }

        // ── 9. ANTI-ABUSE: DUPLICATE BANK / E-WALLET CHECK ──
        $chk_bank = $pdo->prepare("SELECT id, username, referral_code, registration_ip FROM users WHERE account_number = ? LIMIT 1");
        $chk_bank->execute([$account_number]);
        $existing_bank = $chk_bank->fetch();
        if ($existing_bank) {
            if ($ref_input && $existing_bank['referral_code'] === $ref_input) {
                $msg_abuse = "🚨 <b>PERINGATAN ABUSE: SELF-REFERRAL REKENING IDENTIK!</b>\n\n"
                    . "⚠️ <b>Pelanggaran:</b> User baru mendaftar menggunakan nomor rekening yang PERSIS SAMA dengan pemilik referralnya!\n"
                    . "👤 <b>Calon User:</b> <code>{$username}</code>\n"
                    . "🔗 <b>Kode Referral:</b> <code>{$ref_input}</code> (@{$existing_bank['username']})\n"
                    . "🏦 <b>Rekening:</b> {$bank_name} - <code>{$account_number}</code> (a.n. {$account_name})\n"
                    . "🌐 <b>IP Pendaftar:</b> <code>{$client_ip}</code>\n"
                    . "🛑 <b>Status:</b> Pendaftaran ditolak otomatis.\n"
                    . "🕐 <b>Waktu:</b> " . date('d M Y H:i:s');
                send_telegram_notif($pdo, $msg_abuse, [], 'abuse');
                
                $error = 'Nomor rekening/e-wallet ini sudah terdaftar sebagai pemilik kode referral tersebut.';
                $error_fields[] = 'f_account_number';
                goto end_reg;
            }

            $msg_abuse = "⚠️ <b>PERINGATAN ABUSE: DUPLIKASI REKENING BANK!</b>\n\n"
                . "⚠️ <b>Pelanggaran:</b> Mencoba mendaftar dengan nomor rekening yang sudah dimiliki user lain.\n"
                . "👤 <b>Calon User:</b> <code>{$username}</code>\n"
                . "👤 <b>Pemilik Asli:</b> @{$existing_bank['username']}\n"
                . "🏦 <b>Rekening:</b> {$bank_name} - <code>{$account_number}</code> (a.n. {$account_name})\n"
                . "🌐 <b>IP Pendaftar:</b> <code>{$client_ip}</code>\n"
                . "🛑 <b>Status:</b> Pendaftaran ditolak.\n"
                . "🕐 <b>Waktu:</b> " . date('d M Y H:i:s');
            send_telegram_notif($pdo, $msg_abuse, [], 'abuse');
            
            $error = 'Nomor rekening/e-wallet ini sudah terdaftar di akun lain. Satu rekening untuk satu akun.';
            $error_fields[] = 'f_account_number';
            goto end_reg;
        }

        // ── 10. REFERRAL VERIFICATION & ANTI-ABUSE ENGINE ──
        $ref_by = null;
        $ref_username = null;
        $ref_abuse = false;
        $ref_abuse_note = '';

        if ($ref_input) {
            $rs = $pdo->prepare("SELECT id, username, referral_code, is_promotor, is_referral_active, registration_ip FROM users WHERE referral_code = ?");
            $rs->execute([$ref_input]);
            $referrer = $rs->fetch();
            
            if (!$referrer) {
                $error = 'Kode referral tidak valid atau tidak ditemukan.';
                $error_fields[] = 'f_referral';
                goto end_reg;
            }

            if (!empty($referrer['is_promotor']) && empty($referrer['is_referral_active'])) {
                $ref_by = null;
            } else {
                $ref_by = $ref_input;
                $ref_username = $referrer['username'];

                // Check A: Same IP as Referrer
                if ($client_ip !== '127.0.0.1' && !empty($referrer['registration_ip']) && $client_ip === $referrer['registration_ip']) {
                    $ref_abuse = true;
                    $ref_abuse_note = "Self-referral: IP pendaftar sama dengan IP pengundang (@{$referrer['username']}) [{$client_ip}]";
                }

                // Check B: Same device via cookie
                if (!empty($_COOKIE['self_ref_owner']) && $_COOKIE['self_ref_owner'] === $ref_input) {
                    $ref_abuse = true;
                    $ref_abuse_note = "Self-referral: Perangkat sama dengan pemilik referral (@{$referrer['username']})";
                }

                // Check C: Referral Velocity (Bypass jika pemilik referral adalah promotor resmi)
                if (empty($referrer['is_promotor'])) {
                    $vel_check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE referred_by = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
                    $vel_check->execute([$ref_input]);
                    $vel_cnt = (int)$vel_check->fetchColumn();
                    if ($vel_cnt >= 5) {
                        $ref_abuse = true;
                        $ref_abuse_note = "Referral Burst: Kode @{$referrer['username']} menerima {$vel_cnt} pendaftaran dalam 15 menit";
                    }
                }
            }
        }

        // ── 11. INSERT NEW USER ──
        $code = generate_referral_code($pdo);
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $is_flagged_abuse = $ref_abuse ? 1 : 0;
        $abuse_reason     = $ref_abuse ? $ref_abuse_note : null;

        $pdo->prepare("INSERT INTO users (username,email,whatsapp,password_hash,referral_code,referred_by,bank_name,account_number,account_name,acc_num_input_type,acc_name_input_type,acc_num_record,acc_name_record,can_withdraw,registration_ip,is_flagged_abuse,abuse_reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?)")
            ->execute([$username, $email, $whatsapp, $hash, $code, $ref_by, $bank_name, $account_number, $account_name, $acc_num_input_type, $acc_name_input_type, $acc_num_record, $acc_name_record, $client_ip, $is_flagged_abuse, $abuse_reason]);
        $new_id = (int)$pdo->lastInsertId();

        setcookie('self_ref_owner', $code, time() + (86400 * 90), '/', '', false, true);

        // ── 12. REFERRAL BONUS LOGIC WITH ABUSE PROTECTION ──
        if ($ref_by) {
            if ($ref_abuse) {
                $msg_abuse = "🚨 <b>DETEKSI ABUSE: BONUS REFERRAL DITAHAN!</b>\n\n"
                    . "⚠️ <b>Alasan:</b> {$ref_abuse_note}\n"
                    . "👤 <b>User Baru:</b> <code>{$username}</code> (ID: #{$new_id})\n"
                    . "🔗 <b>Kode Ref:</b> <code>{$ref_by}</code> (@{$ref_username})\n"
                    . "🌐 <b>IP Terdeteksi:</b> <code>{$client_ip}</code>\n"
                    . "🏦 <b>Rekening:</b> {$bank_name} - {$account_number} (a.n. {$account_name})\n\n"
                    . "🛡️ <b>Tindakan Sistem:</b>\n"
                    . "• Saldo Tarik (Bonus Ref) <b>TIDAK DIKREDITKAN</b> ke akun @{$ref_username}\n"
                    . "• Akun baru ditandai [ABUSE FLAG] di panel admin.\n"
                    . "🕐 <b>Waktu:</b> " . date('d M Y H:i:s');
                $site_url = rtrim(setting($pdo, 'lc_site_url', ''), '/');
                $kb_abuse = $site_url ? [[['text' => '🔍 Cek Akun Baru', 'url' => "{$site_url}/console/user_detail.php?id={$new_id}"]]] : [];
                send_telegram_notif($pdo, $msg_abuse, $kb_abuse, 'abuse');
            } else {
                credit_registration_referral_bonus($pdo, $new_id, $ref_by);
            }
        }

        // ── 13. TELEGRAM NEW USER NOTIFICATION ──
        $msg = "<b>🆕 USER BARU DAFTAR</b>\n"
             . "👤 Username: <b>{$username}</b>\n"
             . "📧 Email: {$email}\n"
             . "📱 WhatsApp: {$whatsapp}\n"
             . "🏦 Bank: {$bank_name} · {$account_number} (a.n. {$account_name})\n"
             . "🔗 Referral: " . ($ref_by ? "dari kode <b>{$ref_by}</b> (@{$ref_username})" . ($ref_abuse ? " [⚠️ ABUSE DETECTED - BONUS WITHHELD]" : "") : "Langsung (tanpa referral)") . "\n"
             . "🎫 Kode Ref-nya: <code>{$code}</code>\n"
             . "🌐 IP: <code>{$client_ip}</code>\n"
             . "🕐 Waktu: " . date('d M Y H:i:s');
        $site_url = rtrim(setting($pdo, 'lc_site_url', ''), '/');
        $kb_reg = $site_url ? [[['text' => '👤 Lihat Detail User', 'url' => "{$site_url}/console/user_detail.php?id={$new_id}"]]] : [];
        send_telegram_notif($pdo, $msg, $kb_reg, 'user_baru');

        // Clear attempts & Log user in
        unset($_SESSION[$ip_key . '_attempts'], $_SESSION[$ip_key . '_lock']);
        session_regenerate_id(true);
        set_auth_cookie((int)$new_id);
        redirect('/home');
    }
}
end_reg:

// Generate initial captcha challenge for page display
$captcha = generate_captcha_challenge();

$ref_from_url = strtoupper(trim($_GET['ref'] ?? $_COOKIE['tonton_ref'] ?? $_COOKIE['ref_code'] ?? ''));

$_pay_channels = $pdo->query("SELECT name, type FROM payment_channels WHERE is_active=1 ORDER BY type ASC, sort_order ASC, name ASC")->fetchAll();
$_banks    = array_filter($_pay_channels, fn($c) => $c['type'] === 'bank');
$_ewallets = array_filter($_pay_channels, fn($c) => $c['type'] === 'ewallet');

// Dynamic account labels & placeholders based on selected payment channel
$_post_bank = $_POST['bank_name'] ?? '';
$_selected_type = '';
if (!empty($_post_bank)) {
    foreach ($_pay_channels as $_pc) {
        if ($_pc['name'] === $_post_bank) {
            $_selected_type = $_pc['type'];
            break;
        }
    }
}

$_lbl_acc_num  = 'No. Rekening / HP';
$_ph_acc_num   = 'Nomor Rekening';
$_lbl_acc_name = 'Nama Pemilik Rekening';
$_ph_acc_name  = 'Sesuai Buku Tabungan / KTP';

if ($_selected_type === 'bank') {
    $_lbl_acc_num  = 'Nomor Rekening ' . (!empty($_post_bank) ? htmlspecialchars($_post_bank) : 'Bank');
    $_ph_acc_num   = 'Nomor Rekening ' . (!empty($_post_bank) ? htmlspecialchars($_post_bank) : 'Bank');
    $_lbl_acc_name = 'Nama Pemilik Rekening ' . (!empty($_post_bank) ? htmlspecialchars($_post_bank) : 'Bank');
    $_ph_acc_name  = 'Sesuai Buku Tabungan / KTP';
} elseif ($_selected_type === 'ewallet') {
    $_lbl_acc_num  = 'Nomor HP ' . (!empty($_post_bank) ? htmlspecialchars($_post_bank) : 'E-Wallet');
    $_ph_acc_num   = 'Contoh: 08xxxxxxxxxx';
    $_lbl_acc_name = 'Nama Pemilik Akun ' . (!empty($_post_bank) ? htmlspecialchars($_post_bank) : 'E-Wallet');
    $_ph_acc_name  = 'Nama Terdaftar di ' . (!empty($_post_bank) ? htmlspecialchars($_post_bank) : 'Akun E-Wallet');
}

$_seo_title  = setting($pdo, 'seo_title', 'PanenCuan — Nonton Video & Minigame Penghasil Saldo Cuan');
$_seo_desc   = setting($pdo, 'seo_description', 'Platform nonton video dan minigame penghasil saldo cuan resmi.');
$_favicon    = setting($pdo, 'favicon_path', '/assets/favicon.png');
$_site_name  = setting($pdo, 'site_name', 'PanenCuan');
$_seo_kw     = setting($pdo, 'seo_keywords', 'panencuan, panen cuan, nonton video dapat uang, aplikasi penghasil saldo dana, game cuan terpercaya, withdraw instan');
$_seo_author = setting($pdo, 'seo_author', 'PanenCuan Official');
$_seo_twcard = setting($pdo, 'seo_twitter_card', 'summary_large_image');
$_seo_og_img = setting($pdo, 'seo_og_image', '/assets/seobanner.jpg');

// Ensure OG banner exists on disk, fallback to /assets/seobanner.jpg
$_og_disk = dirname(__DIR__) . '/' . ltrim(preg_replace('~\?.*$~', '', $_seo_og_img), '/');
if (!$_seo_og_img || !file_exists($_og_disk)) {
    $_seo_og_img = '/assets/seobanner.jpg';
}
$absolute_og = preg_match('~^https?://~', $_seo_og_img) ? $_seo_og_img : base_url(ltrim($_seo_og_img, '/'));
$fav_url     = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : base_url(ltrim($_favicon, '/'))) : base_url('assets/favicon.png');
$og_mime     = str_ends_with(strtolower($absolute_og), '.png') ? 'image/png' : 'image/jpeg';

// Dynamic Referral Inviter Meta Logic
$ref_inviter_name = '';
if (!empty($ref_from_url)) {
    try {
        $uStmt = $pdo->prepare("SELECT username FROM users WHERE referral_code = ? LIMIT 1");
        $uStmt->execute([$ref_from_url]);
        $ref_inviter_name = (string)($uStmt->fetchColumn() ?: '');
    } catch (\Throwable) {}
}

if (!empty($ref_inviter_name)) {
    $_page_title     = "Undangan dari @{$ref_inviter_name} — Daftar Akun Baru {$_site_name}";
    $_final_og_title = "Undangan Khusus dari @{$ref_inviter_name} — Gabung {$_site_name} & Dapatkan Bonus!";
    $_final_og_desc  = "Kamu diundang oleh @{$ref_inviter_name} untuk bergabung di {$_site_name}! Nonton video harian dapat saldo tunai, main minigame, dan cairkan saldo instan ke DANA, OVO, GoPay, atau Bank!";
} else {
    $_page_title     = 'Daftar Akun Baru — ' . $_seo_title;
    $_final_og_title = setting($pdo, 'seo_og_title', 'PanenCuan — Nonton Video & Main Game Panen Saldo Nyata Tiap Hari!');
    $_final_og_desc  = setting($pdo, 'seo_og_description', $_seo_desc);
}

$current_url = base_url('register' . (!empty($ref_from_url) ? '/' . urlencode($ref_from_url) : ''));
?>
<!DOCTYPE html>
<html lang="id" prefix="og: https://ogp.me/ns#">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#4fb6f0">
<title><?= htmlspecialchars($_page_title) ?></title>
<link rel="canonical" href="<?= htmlspecialchars(base_url('register')) ?>">
<meta name="description" content="<?= htmlspecialchars($_final_og_desc) ?>">
<?php if ($_seo_kw): ?><meta name="keywords" content="<?= htmlspecialchars($_seo_kw) ?>"><?php endif; ?>
<?php if ($_seo_author): ?><meta name="author" content="<?= htmlspecialchars($_seo_author) ?>"><?php endif; ?>
<meta name="robots" content="index, follow">

<!-- Open Graph / Facebook / WhatsApp / Telegram Meta Tags -->
<meta property="og:locale" content="id_ID">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= htmlspecialchars($_site_name) ?>">
<meta property="og:url" content="<?= htmlspecialchars($current_url) ?>">
<meta property="og:title" content="<?= htmlspecialchars($_final_og_title) ?>">
<meta property="og:description" content="<?= htmlspecialchars($_final_og_desc) ?>">
<meta property="og:image" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:secure_url" content="<?= htmlspecialchars($absolute_og) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:type" content="<?= $og_mime ?>">
<meta property="og:image:alt" content="<?= htmlspecialchars($_final_og_title) ?>">

<!-- Twitter Card Meta Tags -->
<meta name="twitter:card" content="<?= htmlspecialchars($_seo_twcard) ?>">
<meta name="twitter:site" content="@PanenCuan">
<meta name="twitter:creator" content="@PanenCuan">
<meta name="twitter:url" content="<?= htmlspecialchars($current_url) ?>">
<meta name="twitter:title" content="<?= htmlspecialchars($_final_og_title) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($_final_og_desc) ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($absolute_og) ?>">
<meta name="twitter:image:alt" content="<?= htmlspecialchars($_final_og_title) ?>">

<!-- Favicon -->
<?php require dirname(__DIR__) . '/partials/head_icons.php'; ?>

<!-- Schema.org JSON-LD Structured Data for Google Rich Snippets -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebPage",
  "name": <?= json_encode($_final_og_title, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
  "description": <?= json_encode($_final_og_desc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
  "url": <?= json_encode($current_url, JSON_UNESCAPED_SLASHES) ?>,
  "image": <?= json_encode($absolute_og, JSON_UNESCAPED_SLASHES) ?>
}
</script>

<!-- VERSION_CHECK_PROMOTOR_BYPASS_20261002 -->
<!-- Google Fonts & Phosphor Icons -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@700;800;900&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web@2.1.1"></script>
<link rel="stylesheet" href="/assets/css/pc-auth.css?v=<?= @filemtime(dirname(__DIR__) . '/assets/css/pc-auth.css') ?: 1 ?>">
<link rel="preload" as="image" href="/assets/brand/panencuan-hero.jpg">
</head>
<body>

<?php require dirname(__DIR__) . '/partials/auth_scene.php'; ?>
<?php $ic3d = static fn(string $n): string => '/assets/img/icons3d/' . $n . '.png'; ?>

<div class="wrap">

  <nav class="topbar">
    <a href="/" class="tb-btn sq" title="Kembali ke Beranda" aria-label="Kembali"><i class="ph-bold ph-caret-left"></i></a>
    <a href="/login" class="tb-btn"><i class="ph-bold ph-sign-in"></i>Masuk</a>
  </nav>

  <main class="au-card">
    <div class="au-top">
      <div class="au-banner">
        <img src="/assets/brand/panencuan-hero.jpg" alt="" width="1800" height="595">
        <?php if (!empty($ref_inviter_name)): ?>
          <span class="au-tag green"><img src="<?= $ic3d('referral') ?>" alt="">Diundang @<?= htmlspecialchars($ref_inviter_name) ?></span>
        <?php else: ?>
          <span class="au-tag"><img src="<?= $ic3d('seed') ?>" alt="">Gratis, tanpa modal</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="au-id">
      <div class="au-logo"><img src="<?= htmlspecialchars(site_logo($pdo)) ?>" alt="<?= htmlspecialchars($_site_name) ?>"></div>
      <div>
        <h1>Buka <span>lahan</span> barumu</h1>
        <p>3 langkah singkat, langsung panen cuan di <?= htmlspecialchars($_site_name) ?></p>
      </div>
    </div>

    <div class="au-in">
    <div class="grow" id="grow">
      <span class="grow-art"><img id="grow-img" src="<?= $ic3d('crop1') ?>" alt=""></span>
      <span class="grow-tx">
        <b><span id="grow-lbl">Benih ditanam</span><em id="grow-pct">0%</em></b>
        <span class="grow-bar"><i id="grow-bar"></i></span>
      </span>
    </div>

<main class="card">
    <?php if ($error): ?>
      <div class="note" role="alert"><i class="ph-fill ph-warning-circle"></i><span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>

    <form method="POST" id="reg-form" novalidate onsubmit="return validateReg(event)">
      <?= csrf_field() ?>

      <input type="hidden" name="form_time_sig" value="<?= time() ?>">
      <input type="hidden" name="captcha_answer" id="f_captcha_answer" value="">
      <input type="hidden" name="acc_num_input_type" id="f_acc_num_input_type" value="typed">
      <input type="hidden" name="acc_name_input_type" id="f_acc_name_input_type" value="typed">
      <input type="hidden" name="acc_num_record" id="f_acc_num_record" value="<?= htmlspecialchars($_POST['acc_num_record'] ?? '[]') ?>">
      <input type="hidden" name="acc_name_record" id="f_acc_name_record" value="<?= htmlspecialchars($_POST['acc_name_record'] ?? '[]') ?>">

      <section class="sec" data-sec="1">
        <div class="sec-h"><span class="sec-ic"><img src="<?= $ic3d('seed') ?>" alt=""></span><h2>Data Petani</h2><span class="sec-n">1/3</span></div>
        <div class="grid">
          <div class="fld">
            <label class="sr" for="f_username">Username</label>
            <div class="inp-wrap"><i class="ph-bold ph-user inp-icon"></i>
              <input type="text" class="inp-field" id="f_username" name="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" placeholder="Username" autocomplete="username" autocapitalize="none" spellcheck="false" required></div>
          </div>
          <div class="fld">
            <label class="sr" for="f_wa">WhatsApp</label>
            <div class="inp-wrap"><i class="ph-bold ph-whatsapp-logo inp-icon"></i>
              <input type="tel" class="inp-field" id="f_wa" name="whatsapp" value="<?= htmlspecialchars($_POST['whatsapp'] ?? '') ?>" placeholder="No. WhatsApp" autocomplete="tel" inputmode="numeric" required></div>
          </div>
          <div class="fld span-2">
            <label class="sr" for="f_email">Email</label>
            <div class="inp-wrap"><i class="ph-bold ph-envelope-simple inp-icon"></i>
              <input type="email" class="inp-field" id="f_email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="Email" autocomplete="email" autocapitalize="none" required></div>
          </div>
          <div class="fld span-2">
            <label class="sr" for="f_pwd">Kata Sandi</label>
            <div class="inp-wrap"><i class="ph-bold ph-lock-key inp-icon gold"></i>
              <input type="password" class="inp-field" id="f_pwd" name="password" placeholder="Kata sandi (min. 6)" autocomplete="new-password" required>
              <button type="button" class="eye-btn" onclick="togglePasswordVisibility()" title="Lihat kata sandi" aria-label="Lihat kata sandi"><i class="ph-bold ph-eye" id="eye-icon"></i></button></div>
          </div>
        </div>
      </section>

      <section class="sec" data-sec="2">
        <div class="sec-h"><span class="sec-ic"><img src="<?= $ic3d('moneybag') ?>" alt=""></span><h2>Rekening Pencairan</h2><span class="sec-n">2/3</span></div>
        <div class="grid">
          <div class="fld">
            <label class="sr" for="f_bank_name">Bank / E-Wallet</label>
            <div class="inp-wrap">
              <i class="ph-bold <?= $_selected_type === 'ewallet' ? 'ph-wallet' : 'ph-bank' ?> inp-icon gold" id="ico_bank"></i>
              <select class="inp-field" id="f_bank_name" name="bank_name" required onchange="handleBankTypeChange()">
                <option value="">Bank/E-Wallet</option>
                <?php if (!empty($_ewallets)): ?>
                <optgroup label="E-Wallet">
                  <?php foreach ($_ewallets as $_ch): ?>
                  <option value="<?= htmlspecialchars($_ch['name']) ?>" data-type="ewallet" <?= ($_POST['bank_name'] ?? '') === $_ch['name'] ? 'selected' : '' ?>><?= htmlspecialchars($_ch['name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
                <?php if (!empty($_banks)): ?>
                <optgroup label="Bank Nasional">
                  <?php foreach ($_banks as $_ch): ?>
                  <option value="<?= htmlspecialchars($_ch['name']) ?>" data-type="bank" <?= ($_POST['bank_name'] ?? '') === $_ch['name'] ? 'selected' : '' ?>><?= htmlspecialchars($_ch['name']) ?></option>
                  <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
              </select>
              <i class="ph-bold ph-caret-down caret"></i>
            </div>
          </div>
          <div class="fld">
            <label class="sr" for="f_account_number" id="lbl_account_number"><?= $_lbl_acc_num ?></label>
            <div class="inp-wrap"><i class="ph-bold <?= $_selected_type === 'ewallet' ? 'ph-device-mobile' : 'ph-credit-card' ?> inp-icon" id="ico_account_number"></i>
              <input type="text" class="inp-field" id="f_account_number" name="account_number" value="<?= htmlspecialchars($_POST['account_number'] ?? '') ?>" placeholder="<?= $_ph_acc_num ?>" inputmode="numeric" required></div>
          </div>
          <div class="fld span-2">
            <label class="sr" for="f_account_name" id="lbl_account_name"><?= $_lbl_acc_name ?></label>
            <div class="inp-wrap"><i class="ph-bold <?= $_selected_type === 'ewallet' ? 'ph-user-check' : 'ph-identification-card' ?> inp-icon" id="ico_account_name"></i>
              <input type="text" class="inp-field" id="f_account_name" name="account_name" value="<?= htmlspecialchars($_POST['account_name'] ?? '') ?>" placeholder="<?= $_ph_acc_name ?>" required></div>
          </div>
        </div>

        <?php $ref_open = $ref_from_url || !empty($_POST['referral']) || in_array('f_referral', $error_fields, true); ?>
        <button type="button" class="ref-toggle" id="ref-toggle" onclick="openRef()" <?= $ref_open ? 'hidden' : '' ?>><img src="<?= $ic3d('referral') ?>" alt="">Punya kode undangan?</button>
        <div class="ref-box" id="ref-box" <?= $ref_open ? '' : 'hidden' ?>>
          <label class="sr" for="f_referral">Kode Referral</label>
          <div class="inp-wrap <?= $ref_from_url ? 'inp-wrap--ref' : '' ?>"><i class="ph-bold ph-gift inp-icon"></i>
            <input type="text" class="inp-field" id="f_referral" name="referral" value="<?= htmlspecialchars($_POST['referral'] ?? $ref_from_url) ?>" placeholder="Kode referral (opsional)" style="text-transform:uppercase;letter-spacing:1px;<?= $ref_from_url ? 'color:#047857;font-weight:900;' : '' ?>" <?= $ref_from_url ? 'readonly' : '' ?>>
            <?php if ($ref_from_url): ?><i class="ph-fill ph-seal-check" style="color:#10b981;font-size:18px" title="Terverifikasi"></i><?php endif; ?></div>
        </div>
      </section>

      <section class="sec" data-sec="3">
        <div class="sec-h"><span class="sec-ic"><img src="<?= $ic3d('star') ?>" alt=""></span><h2>Sawah Bersih</h2><span class="sec-n">3/3</span></div>
        <div class="cap" id="f_captcha" tabindex="-1">
          <div class="cap-top">
            <div class="cap-q"><i class="ph-fill <?= $captcha['icon'] ?>" id="cap-icon"></i><span id="cap-prompt"><?= htmlspecialchars($captcha['prompt']) ?></span></div>
            <button type="button" class="cap-re" id="cap-re" onclick="refreshCaptcha()" title="Ganti gambar" aria-label="Ganti gambar"><i class="ph-bold ph-arrows-clockwise"></i></button>
          </div>
          <div class="cap-grid" id="cap-grid">
            <?php for ($i = 0; $i < 6; $i++): ?>
              <button type="button" class="cap-t" data-i="<?= $i ?>" onclick="capPick(this)" aria-pressed="false" aria-label="Gambar <?= $i + 1 ?>">
                <img src="/register?cap_tile=<?= $i ?>&v=<?= $captcha['v'] ?>" alt="" draggable="false"><span class="ok"><i class="ph-bold ph-check"></i></span>
              </button>
            <?php endfor; ?>
          </div>
          <div class="cap-hint" id="cap-hint">Ada 1 ikan nyasar di antara sampah sawah. Ketuk ikannya.</div>
        </div>
      </section>

      <button type="submit" id="btn-submit-reg" class="au-btn gold"><span>Daftar &amp; Mulai Bertani</span><img src="<?= $ic3d('padi') ?>" alt=""></button>

      <!-- Honeypot Anti-Bot Trap (hidden safely from browser autofill) -->
      <div style="display:none !important;" aria-hidden="true">
        <input type="text" name="website_hp_check" value="" tabindex="-1" autocomplete="new-password">
      </div>
    </form>
    </div>
  </main>

  <div class="foot">Sudah punya lahan?<a href="/login">Masuk di sini</a></div>
  <div class="au-trust">
    <small><i class="ph-fill ph-shield-check"></i>Terdaftar</small>
    <img src="/assets/ojkkk.png?v=3" alt="OJK">
    <img src="/assets/bap.png?v=3" alt="Bappebti">
  </div>
</div>

<div class="toast" id="toast" role="status"><i class="ph-fill ph-warning-circle"></i><span id="toast-txt"></span></div>

<script>
function togglePasswordVisibility() {
  const inp = document.getElementById('f_pwd');
  const ico = document.getElementById('eye-icon');
  if (!inp || !ico) return;
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  ico.className = show ? 'ph-bold ph-eye-slash' : 'ph-bold ph-eye';
}

function openRef() {
  document.getElementById('ref-toggle').hidden = true;
  document.getElementById('ref-box').hidden = false;
  document.getElementById('f_referral').focus();
}

let toastT;
function regToast(msg) {
  const t = document.getElementById('toast');
  document.getElementById('toast-txt').textContent = msg;
  t.classList.add('show');
  clearTimeout(toastT);
  toastT = setTimeout(() => t.classList.remove('show'), 2800);
}
function flagField(id) {
  const el = document.getElementById(id);
  if (!el) return;
  const box = el.closest('.inp-wrap') || el;
  box.classList.add('bad');
  if (el.focus) el.focus({ preventScroll: true });
  box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  const clear = () => box.classList.remove('bad');
  el.addEventListener('focus', clear, { once: true });
  el.addEventListener('click', clear, { once: true });
}

/* ── Captcha gambar ── */
function capSync() {
  const picked = [...document.querySelectorAll('.cap-t.on')].map(b => b.dataset.i);
  document.getElementById('f_captcha_answer').value = picked.join(',');
}
function capPick(btn) {
  // Pilih satu saja: ketuk gambar lain = pindah pilihan
  document.querySelectorAll('.cap-t').forEach(b => {
    const on = b === btn;
    b.classList.toggle('on', on);
    b.setAttribute('aria-pressed', on ? 'true' : 'false');
  });
  document.getElementById('f_captcha').classList.remove('bad');
  const hint = document.getElementById('cap-hint');
  if (hint) { hint.textContent = 'Sip! Gambar terpilih. Salah pilih? Ketuk gambar lain.'; hint.classList.add('ok'); }
  capSync();
}
async function refreshCaptcha() {
  const re = document.getElementById('cap-re');
  re.style.transform = 'rotate(180deg)';
  try {
    const res = await fetch('/register?refresh_captcha=1', { credentials: 'same-origin' });
    const data = await res.json();
    if (data.ok) {
      document.getElementById('cap-prompt').textContent = data.prompt;
      document.getElementById('cap-icon').className = 'ph-fill ' + data.icon;
      document.querySelectorAll('.cap-t').forEach(b => {
        b.classList.remove('on'); b.setAttribute('aria-pressed', 'false');
        b.querySelector('img').src = '/register?cap_tile=' + b.dataset.i + '&v=' + data.v;
      });
      const hint = document.getElementById('cap-hint');
      if (hint) { hint.textContent = 'Ada 1 ikan nyasar di antara sampah sawah. Ketuk ikannya.'; hint.classList.remove('ok'); }
      capSync();
    }
  } catch (err) {
    regToast('Gagal memuat gambar, coba lagi.');
  } finally {
    setTimeout(() => re.style.transform = '', 300);
  }
}

function handleBankTypeChange() {
  const sel     = document.getElementById('f_bank_name');
  const icoBank = document.getElementById('ico_bank');
  const lblNum  = document.getElementById('lbl_account_number');
  const inpNum  = document.getElementById('f_account_number');
  const icoNum  = document.getElementById('ico_account_number');
  const lblName = document.getElementById('lbl_account_name');
  const inpName = document.getElementById('f_account_name');
  const icoName = document.getElementById('ico_account_name');

  if (!sel) return;

  const opt  = sel.options[sel.selectedIndex];
  const type = opt ? opt.getAttribute('data-type') : '';
  const val  = sel.value.trim();

  if (type === 'ewallet') {
    if (icoBank) icoBank.className = 'ph-bold ph-wallet inp-icon';
    if (lblNum)  lblNum.textContent = 'Nomor HP ' + val;
    if (inpNum)  inpNum.placeholder = 'No. HP ' + val;
    if (icoNum)  icoNum.className = 'ph-bold ph-device-mobile inp-icon';
    if (lblName) lblName.textContent = 'Nama Pemilik Akun ' + val;
    if (inpName) inpName.placeholder = 'Nama pemilik akun ' + val;
    if (icoName) icoName.className = 'ph-bold ph-user-check inp-icon';
  } else if (type === 'bank') {
    if (icoBank) icoBank.className = 'ph-bold ph-bank inp-icon';
    if (lblNum)  lblNum.textContent = 'Nomor Rekening ' + val;
    if (inpNum)  inpNum.placeholder = 'No. rek ' + val;
    if (icoNum)  icoNum.className = 'ph-bold ph-credit-card inp-icon';
    if (lblName) lblName.textContent = 'Nama Pemilik Rekening ' + val;
    if (inpName) inpName.placeholder = 'Nama sesuai buku tabungan / KTP';
    if (icoName) icoName.className = 'ph-bold ph-identification-card inp-icon';
  } else {
    if (icoBank) icoBank.className = 'ph-bold ph-bank inp-icon';
    if (lblNum)  lblNum.textContent = 'No. Rekening / HP';
    if (inpNum)  inpNum.placeholder = 'No. rek / HP';
    if (icoNum)  icoNum.className = 'ph-bold ph-credit-card inp-icon';
    if (lblName) lblName.textContent = 'Nama Pemilik Rekening';
    if (inpName) inpName.placeholder = 'Nama pemilik rekening';
    if (icoName) icoName.className = 'ph-bold ph-identification-card inp-icon';
  }
}

document.addEventListener('DOMContentLoaded', handleBankTypeChange);

/* ── Meter tumbuh: padi makin tinggi seiring isian lengkap ── */
const GROW_STEPS = [
  [0,   'crop1', 'Benih ditanam'],
  [30,  'crop2', 'Tunas muncul'],
  [60,  'crop3', 'Padi tumbuh'],
  [100, 'crop4', 'Siap panen!'],
];
const GROW_SECS = {
  1: ['f_username', 'f_wa', 'f_email', 'f_pwd'],
  2: ['f_bank_name', 'f_account_number', 'f_account_name'],
  3: ['f_captcha_answer'],
};
let growStage = '';
function growUpdate() {
  const okVal = id => {
    const el = document.getElementById(id);
    if (!el) return false;
    const v = el.value.trim();
    if (id === 'f_username') return /^[a-zA-Z0-9_]{3,30}$/.test(v);
    if (id === 'f_wa') return v.replace(/\D/g, '').length >= 9;
    if (id === 'f_email') return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
    if (id === 'f_pwd') return v.length >= 6;
    return v !== '';
  };
  let done = 0, total = 0;
  Object.entries(GROW_SECS).forEach(([n, ids]) => {
    const ok = ids.filter(okVal).length;
    done += ok; total += ids.length;
    const tag = document.querySelector('.sec[data-sec="' + n + '"] .sec-n');
    if (tag) { const full = ok === ids.length; tag.classList.toggle('done', full); tag.textContent = full ? 'Beres' : n + '/3'; }
  });
  const pct = Math.round(done / total * 100);
  const step = GROW_STEPS.filter(s => pct >= s[0]).pop();
  document.getElementById('grow-bar').style.width = pct + '%';
  document.getElementById('grow-pct').textContent = pct + '%';
  document.getElementById('grow-lbl').textContent = step[2];
  if (step[1] !== growStage) {
    const img = document.getElementById('grow-img');
    img.src = '/assets/img/icons3d/' + step[1] + '.png';
    if (growStage) { img.classList.remove('pop'); void img.offsetWidth; img.classList.add('pop'); }
    growStage = step[1];
  }
}
document.getElementById('reg-form').addEventListener('input', growUpdate);
document.getElementById('reg-form').addEventListener('change', growUpdate);
document.getElementById('cap-grid').addEventListener('click', () => setTimeout(growUpdate, 0));
document.addEventListener('DOMContentLoaded', growUpdate);

function validateReg(e) {
  const u   = document.getElementById('f_username').value.trim();
  const em  = document.getElementById('f_email').value.trim();
  const wa  = document.getElementById('f_wa').value.replace(/\D/g, '');
  const pwd = document.getElementById('f_pwd').value;
  const b   = document.getElementById('f_bank_name').value.trim();
  const acc = document.getElementById('f_account_number').value.trim();
  const nam = document.getElementById('f_account_name').value.trim();
  const cap = document.getElementById('f_captcha_answer').value.trim();
  const fail = (id, msg) => { regToast(msg); flagField(id); return false; };

  if (!u || u.length < 3 || !/^[a-zA-Z0-9_]+$/.test(u)) return fail('f_username', 'Username minimal 3 karakter (huruf, angka, underscore).');
  if (wa.length < 9) return fail('f_wa', 'Nomor WhatsApp minimal 9 digit.');
  if (!em || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) return fail('f_email', 'Format email belum benar.');
  if (pwd.length < 6) return fail('f_pwd', 'Kata sandi minimal 6 karakter.');
  if (!b) return fail('f_bank_name', 'Pilih bank atau e-wallet dulu.');

  const selBank = document.getElementById('f_bank_name');
  const optBank = selBank ? selBank.options[selBank.selectedIndex] : null;
  const isEwallet = optBank && optBank.getAttribute('data-type') === 'ewallet';

  if (!acc) return fail('f_account_number', isEwallet ? ('Nomor HP ' + b + ' wajib diisi.') : ('Nomor rekening ' + b + ' wajib diisi.'));
  if (!nam) return fail('f_account_name', isEwallet ? ('Nama pemilik akun ' + b + ' wajib diisi.') : 'Nama pemilik rekening wajib diisi sesuai buku tabungan/KTP.');
  if (cap === '') {
    document.getElementById('f_captcha').classList.add('bad');
    document.getElementById('f_captcha').scrollIntoView({ behavior: 'smooth', block: 'center' });
    regToast(document.getElementById('cap-prompt').textContent + ' dulu ya.');
    return false;
  }

  const btn = document.getElementById('btn-submit-reg');
  btn.disabled = true;
  btn.innerHTML = '<i class="ph-bold ph-spinner" style="animation:spin 1s linear infinite;"></i> Menyiapkan lahan...';
  return true;
}

// Keystroke Telemetry Tracking
let nr = JSON.parse(document.getElementById('f_acc_num_record').value || '[]');
let ar = JSON.parse(document.getElementById('f_acc_name_record').value || '[]');
function trk(id, rec, hid, tid, ref) {
  const el = document.getElementById(id);
  if (!el) return;
  const r = (p) => {
    if (ref.v === 0) ref.v = Date.now();
    rec.push({ t: Date.now() - ref.v, v: el.value, p: p ? 1 : 0 });
    document.getElementById(hid).value = JSON.stringify(rec);
  };
  el.addEventListener('input', () => r(false));
  el.addEventListener('paste', () => {
    document.getElementById(tid).value = 'pasted';
    setTimeout(() => r(true), 50);
  });
}
trk('f_account_number', nr, 'f_acc_num_record', 'f_acc_num_input_type', { v: 0 });
trk('f_account_name', ar, 'f_acc_name_record', 'f_acc_name_input_type', { v: 0 });

<?php if (!empty($error_fields)): ?>
  <?= json_encode($error_fields) ?>.forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    const box = id === 'f_captcha' ? el : el.closest('.inp-wrap');
    if (!box) return;
    box.classList.add('bad');
    el.addEventListener(id === 'f_captcha' ? 'click' : 'focus', () => box.classList.remove('bad'), { once: true });
  });
<?php endif; ?>
</script>
</body>
</html>
