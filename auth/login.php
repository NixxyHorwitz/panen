<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
if (auth_user($pdo)) redirect('/home');
csrf_enforce();

$error = '';

// Rate limit
$ip_key   = 'login_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
$attempts = (int)($_SESSION[$ip_key . '_att'] ?? 0);
$lock_until = (int)($_SESSION[$ip_key . '_lock'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (time() < $lock_until) {
        $wait  = ceil(($lock_until - time()) / 60);
        $error = "Akun terkunci sementara. Coba lagi dalam {$wait} menit.";
        goto end_login;
    }

    $login = trim($_POST['login'] ?? '');
    $pwd   = $_POST['password'] ?? '';
    $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
    $s     = $pdo->prepare("SELECT * FROM users WHERE {$field}=? AND is_active=1");
    $s->execute([$login]);
    $user  = $s->fetch();

    if ($user && password_verify($pwd, $user['password_hash'])) {
        unset($_SESSION[$ip_key . '_att'], $_SESSION[$ip_key . '_lock']);
        session_regenerate_id(true);
        set_auth_cookie((int)$user['id']);
        try {
            $pdo->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?")->execute([(int)$user['id']]);
        } catch (\Throwable) {}
        redirect('/home');
    }

    $new_att = $attempts + 1;
    $_SESSION[$ip_key . '_att'] = $new_att;
    if ($new_att >= 5) {
        $_SESSION[$ip_key . '_lock'] = time() + 600;
        $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam 10 menit.';
    } else {
        $left  = 5 - $new_att;
        $error = "Username/email atau kata sandi salah. Sisa percobaan: {$left}";
    }
}
end_login:

// Load SEO settings
$_seo_title  = setting($pdo, 'seo_title', 'PanenCuan — Nonton Video & Minigame Penghasil Saldo Cuan');
$_seo_desc   = setting($pdo, 'seo_description', 'Platform nonton video dan minigame penghasil saldo cuan resmi.');
$_favicon    = setting($pdo, 'favicon_path', '/assets/favicon.png');
$_site_name  = setting($pdo, 'site_name', 'PanenCuan');
$_site_logo  = site_logo($pdo);
$_seo_kw     = setting($pdo, 'seo_keywords', 'panencuan, panen cuan, nonton video dapat uang, aplikasi penghasil saldo dana');
$_seo_author = setting($pdo, 'seo_author', 'PanenCuan Official');
$_seo_twcard = setting($pdo, 'seo_twitter_card', 'summary_large_image');
$_seo_og_img = setting($pdo, 'seo_og_image', '/assets/seobanner.jpg');

$_og_disk = dirname(__DIR__) . '/' . ltrim(preg_replace('~\?.*$~', '', $_seo_og_img), '/');
if (!$_seo_og_img || !file_exists($_og_disk)) {
    $_seo_og_img = '/assets/seobanner.jpg';
}
$absolute_og = preg_match('~^https?://~', $_seo_og_img) ? $_seo_og_img : base_url(ltrim($_seo_og_img, '/'));
$fav_url     = $_favicon ? (preg_match('~^https?://~', $_favicon) ? $_favicon : base_url(ltrim($_favicon, '/'))) : base_url('assets/favicon.png');
$og_mime     = str_ends_with(strtolower($absolute_og), '.png') ? 'image/png' : 'image/jpeg';
$current_url = base_url('login');
$_page_title = 'Masuk Akun — ' . $_seo_title;
$_final_og_title = setting($pdo, 'seo_og_title', 'PanenCuan — Nonton Video & Main Game Panen Saldo Nyata Tiap Hari!');
$_final_og_desc  = setting($pdo, 'seo_og_description', $_seo_desc);
?>
<!DOCTYPE html>
<html lang="id" prefix="og: https://ogp.me/ns#">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#4fb6f0">
<title><?= htmlspecialchars($_page_title) ?></title>
<link rel="canonical" href="<?= htmlspecialchars($current_url) ?>">
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

<?php require dirname(__DIR__) . '/partials/head_icons.php'; ?>

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

<div class="lg-page">
<main class="au-card">
  <div class="au-top">
    <div class="au-banner">
      <img src="/assets/brand/panencuan-hero.jpg" alt="" width="1800" height="595">
      <span class="au-tag"><img src="<?= $ic3d('padi') ?>" alt="">Sawah menunggumu</span>
    </div>
  </div>

  <div class="au-id">
    <div class="au-logo"><img src="<?= htmlspecialchars($_site_logo) ?>" alt="<?= htmlspecialchars($_site_name) ?>"></div>
    <div>
      <h1>Halo lagi, <span>Petani!</span></h1>
      <p>Masuk &amp; lanjut panen cuan di <?= htmlspecialchars($_site_name) ?></p>
    </div>
  </div>

  <div class="au-in">
    <div class="au-perks">
      <span class="au-perk" style="--i:0"><img src="<?= $ic3d('watch') ?>" alt="">Nonton video</span>
      <span class="au-perk" style="--i:1"><img src="<?= $ic3d('padi') ?>" alt="">Tanam padi</span>
      <span class="au-perk" style="--i:2"><img src="<?= $ic3d('moneybag') ?>" alt="">Cair ke e-wallet</span>
    </div>

    <?php if ($error): ?>
      <div class="au-err" role="alert"><i class="ph-fill ph-warning-circle"></i><span><?= htmlspecialchars($error) ?></span></div>
    <?php endif; ?>

    <form method="POST" id="login-form">
      <?= csrf_field() ?>

      <div class="fld">
        <label for="login-input">Username / Email</label>
        <div class="inp-wrap">
          <i class="ph-bold ph-user inp-icon"></i>
          <input type="text" class="inp-field" id="login-input" name="login" value="<?= htmlspecialchars($_POST['login'] ?? '') ?>"
                 placeholder="nama kamu atau email" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
        </div>
      </div>

      <div class="fld">
        <label for="pwd-input">Kata Sandi</label>
        <div class="inp-wrap">
          <i class="ph-bold ph-lock-key inp-icon gold"></i>
          <input type="password" class="inp-field" id="pwd-input" name="password" placeholder="••••••••" autocomplete="current-password" required>
          <button type="button" class="eye-btn" onclick="togglePasswordVisibility()" title="Lihat kata sandi" aria-label="Lihat kata sandi">
            <i class="ph-bold ph-eye" id="eye-icon"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="au-btn" id="login-btn"><span>Masuk ke Sawah</span><img src="<?= $ic3d('padi') ?>" alt=""></button>
    </form>

    <div class="au-alt">Belum punya lahan?<a href="/register">Daftar gratis</a></div>
  </div>
</main>

<div class="au-trust">
  <small><i class="ph-fill ph-shield-check"></i>Terdaftar</small>
  <img src="/assets/ojkkk.png?v=3" alt="Otoritas Jasa Keuangan">
  <img src="/assets/bap.png?v=3" alt="Bappebti">
</div>
</div>

<script>
function togglePasswordVisibility() {
  const inp = document.getElementById('pwd-input');
  const ico = document.getElementById('eye-icon');
  if (!inp || !ico) return;
  const show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  ico.className = show ? 'ph-bold ph-eye-slash' : 'ph-bold ph-eye';
}
document.getElementById('login-form').addEventListener('submit', function () {
  const b = document.getElementById('login-btn');
  setTimeout(() => { b.disabled = true; b.innerHTML = '<i class="ph-bold ph-spinner" style="animation:spin 1s linear infinite"></i><span>Membuka gerbang...</span>'; }, 0);
});
</script>
</body>
</html>
