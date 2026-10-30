<?php
declare(strict_types=1);
// Tidak memakai auth/guard.php agar polling tidak tercatat sebagai pageview analitik.
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/global_chat.php';
$user = require_auth($pdo);
csrf_enforce();
if (is_maintenance($pdo) && !auth_admin()) {
    $maintenance_msg = setting($pdo, 'maintenance_message', 'Sistem sedang dalam perbaikan.');
    require __DIR__ . '/maintenance.php';
    exit;
}
gc_ensure_schema($pdo);
$cfg  = gc_config($pdo);
$uid  = (int)$user['id'];
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

/** Bentuk pesan untuk klien (tanpa info shadow/flag). */
function gc_public(array $m, int $me): array {
    return [
        'id' => (int)$m['id'], 'mine' => (int)$m['user_id'] === $me && !(int)$m['is_admin'], 'admin' => (bool)(int)$m['is_admin'],
        'name' => (string)$m['username'], 'tier' => (string)$m['tier_name'], 'text' => (string)($m['message'] ?? ''),
        'image' => $m['image'] ? '/' . ltrim((string)$m['image'], '/') : null, 'time' => date('H:i', strtotime((string)$m['created_at'])),
    ];
}
function gc_perm_public(array $p): array {
    return ['send' => $p['send'], 'image' => $p['image'], 'reason' => $p['reason'], 'msg_left' => $p['msg_left'], 'img_left' => $p['img_left'],
            'limit_msg' => $p['limit_msg'], 'limit_img' => $p['limit_img'], 'next_reset' => $p['next_reset']];
}

// ── AJAX: ambil pesan baru ──
if ($action === 'poll') {
    header('Content-Type: application/json');
    $perm = gc_permissions($pdo, $user, $cfg);
    if (!$perm['view']) { echo json_encode(['ok' => false, 'msg' => $perm['reason']]); exit; }
    $after = max(0, (int)($_GET['after'] ?? 0));
    $msgs = array_map(fn($m) => gc_public($m, $uid), gc_fetch($pdo, $uid, $after, $after ? 100 : (int)$cfg['history_limit']));
    $del = $pdo->query("SELECT id FROM gc_messages WHERE deleted_at > NOW() - INTERVAL 15 MINUTE")->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode(['ok' => true, 'messages' => $msgs, 'deleted' => array_map('intval', $del), 'perm' => gc_perm_public($perm), 'open' => (bool)$cfg['enabled']]);
    exit;
}

// ── AJAX: kirim pesan ──
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $fail = static function (string $msg, array $perm = null) { echo json_encode(['ok' => false, 'msg' => $msg] + ($perm ? ['perm' => gc_perm_public($perm)] : [])); exit; };
    $perm = gc_permissions($pdo, $user, $cfg);
    if (!$perm['send']) $fail($perm['reason'] ?: 'Kamu belum bisa mengirim pesan.', $perm);

    $text = trim(preg_replace("/\r\n?/", "\n", (string)($_POST['message'] ?? '')));
    $text = preg_replace("/\n{3,}/", "\n\n", $text);
    $file = $_FILES['image'] ?? null;
    $hasImg = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($text === '' && !$hasImg) $fail('Pesan masih kosong.');
    if (mb_strlen($text) > (int)$cfg['max_len']) $fail('Pesan maksimal ' . (int)$cfg['max_len'] . ' karakter.');

    $slow = max(0, (int)$cfg['slow_mode']);
    if ($slow && $perm['last_sent_at'] && time() - strtotime((string)$perm['last_sent_at']) < $slow) {
        $fail('Pelan-pelan ya, tunggu ' . ($slow - (time() - strtotime((string)$perm['last_sent_at']))) . ' detik lagi.', $perm);
    }
    if ($perm['msg_left'] <= 0) $fail('Kuota pesan hari ini habis. Reset ' . $perm['next_reset'] . '.', $perm);

    $imgPath = null;
    if ($hasImg) {
        if (!$perm['image']) $fail('Pangkatmu belum bisa mengirim gambar.', $perm);
        if ($perm['img_left'] <= 0) $fail('Kuota gambar hari ini habis. Reset ' . $perm['next_reset'] . '.', $perm);
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) $fail('Gambar gagal diunggah.');
        if ((int)$file['size'] > 3 * 1024 * 1024) $fail('Ukuran gambar maksimal 3MB.');
        $info = @getimagesize((string)$file['tmp_name']);
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
        if (!$info || !$ext) $fail('Format gambar harus JPG, PNG, GIF, atau WEBP.');
        $dir = 'uploads/gchat/' . date('Y/m');
        if (!is_dir(dirname(__DIR__) . '/' . $dir)) @mkdir(dirname(__DIR__) . '/' . $dir, 0755, true);
        $name = $dir . '/' . bin2hex(random_bytes(10)) . '.' . $ext;
        if (!move_uploaded_file((string)$file['tmp_name'], dirname(__DIR__) . '/' . $name)) $fail('Gambar gagal disimpan.');
        $imgPath = $name;
    }

    $check = gc_check_message($text, $cfg);
    $tier  = $perm['tier'];
    $pdo->prepare("INSERT INTO gc_messages (user_id, username, tier_name, message, image, is_shadow, flag_reason) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$uid, (string)$user['username'], $tier['name'], $text, $imgPath, $check['shadow'] ? 1 : 0, $check['reason'] ?: null]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO gc_daily_usage (user_id, period_key, msg_count, img_count, last_sent_at) VALUES (?, ?, 1, ?, NOW())
                   ON DUPLICATE KEY UPDATE msg_count = msg_count + 1, img_count = img_count + VALUES(img_count), last_sent_at = NOW()")
        ->execute([$uid, $perm['period'], $imgPath ? 1 : 0]);

    $row = $pdo->prepare("SELECT * FROM gc_messages WHERE id = ?");
    $row->execute([$id]);
    $row = $row->fetch(PDO::FETCH_ASSOC);
    if ($check['notify'] && !empty($cfg['notify_tg'])) gc_notify_abuse($pdo, $row, $check, $user, $tier['name']);

    // Pengirim selalu melihat pesannya "terkirim", termasuk pesan shadow.
    echo json_encode(['ok' => true, 'message' => gc_public($row, $uid), 'perm' => gc_perm_public(gc_permissions($pdo, $user, $cfg))]);
    exit;
}

track_pageview($pdo, '/global-chat');
$perm   = gc_permissions($pdo, $user, $cfg);
$_logo  = site_logo($pdo);
$_fav   = setting($pdo, 'favicon_path', '/assets/favicon.png');
$_title = setting($pdo, 'site_name', 'PanenCuan');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover,interactive-widget=resizes-content">
<meta name="theme-color" content="#ffb46b">
<title>Komunitas — <?= htmlspecialchars($_title) ?></title>
<?php require dirname(__DIR__) . '/partials/head_icons.php'; ?>
<meta name="robots" content="noindex">
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Nunito:wght@600;700;800;900&display=swap" rel="stylesheet">
<script src="https://unpkg.com/@phosphor-icons/web"></script>
<style>
/* ══ KOMUNITAS — Golden Hour game UI (tema sama dengan assets/css/pc-game.css) ══ */
:root { --ink: #1b2a1e; --muted: #5c6b5f; --pc-font: 'Fredoka', 'Nunito', sans-serif; --ease: cubic-bezier(.34, 1.56, .64, 1); }
* { box-sizing: border-box; }
html, body { height: 100%; margin: 0; }
body { overflow: hidden; color: var(--ink); font-family: 'Nunito', -apple-system, sans-serif;
  background: radial-gradient(120% 50% at 50% 0%, #ffd59a 0%, transparent 60%), linear-gradient(180deg, #fff0cf 0%, #fff8e8 45%, #eef7e2 100%); }
.gc-top { position: fixed; top: 0; left: 0; right: 0; height: 60px; z-index: 20; display: flex; align-items: center; gap: 9px; padding: 0 12px;
  background: linear-gradient(180deg, #ffb46b, #ffd08a); border-radius: 0 0 20px 20px; box-shadow: 0 4px 0 #e59a4a, 0 10px 22px rgba(120, 70, 10, .18); }
.gc-back { width: 36px; height: 36px; flex-shrink: 0; display: grid; place-items: center; border-radius: 12px; color: #4a2b00; text-decoration: none; background: rgba(255, 255, 255, .85); box-shadow: 0 3px 0 rgba(120, 70, 10, .25); font-size: 18px; }
.gc-back:active { transform: translateY(2px); box-shadow: none; }
.gc-ava { width: 40px; height: 40px; flex-shrink: 0; display: grid; place-items: center; }
.gc-ava img { width: 40px; height: 40px; object-fit: contain; filter: drop-shadow(0 3px 3px rgba(120, 70, 10, .3)); animation: gcBob 3s ease-in-out infinite; }
@keyframes gcBob { 50% { transform: translateY(-3px) rotate(-5deg); } }
.gc-ti { flex: 1; min-width: 0; line-height: 1.15; }
.gc-ti b { display: block; font-family: var(--pc-font); font-size: 18px; font-weight: 700; color: #4a2b00; }
.gc-ti small { display: block; font-size: 11px; font-weight: 800; color: #7a4f00; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gc-quota { flex-shrink: 0; display: flex; gap: 4px; }
.gc-quota span { display: inline-flex; align-items: center; gap: 3px; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 900; color: #14532d; background: rgba(255, 255, 255, .85); }
.gc-quota span.img { color: #6d28d9; }
#gc-root { position: fixed; top: 60px; left: 0; right: 0; bottom: 0; display: flex; flex-direction: column; }
.gc-page { display: flex; flex-direction: column; width: 100%; max-width: 560px; height: 100%; min-height: 0; margin: 0 auto; }
[hidden] { display: none !important; }
.gc-notice { display: flex; align-items: center; gap: 6px; margin: 10px 12px 0; padding: 8px 11px; border-radius: 14px; font-size: 12px; font-weight: 800; color: #7a4f00; background: #fff3c4; box-shadow: inset 0 0 0 2px #fde68a; }
.gc-msgs { flex: 1 1 0; min-height: 0; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; padding: 12px 12px 10px; -webkit-overflow-scrolling: touch; scrollbar-width: thin; scrollbar-color: #ecd59c transparent; }
.gc-day { align-self: center; padding: 3px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 900; color: #8a5a00; background: #fff3c4; }
.gc-row { display: flex; align-items: flex-end; gap: 6px; max-width: 86%; animation: gcPop .3s var(--ease); }
@keyframes gcPop { from { opacity: 0; transform: translateY(8px) scale(.96); } }
.gc-row.mine { align-self: flex-end; flex-direction: row-reverse; }
.gc-av { width: 30px; height: 30px; flex-shrink: 0; border-radius: 50%; display: grid; place-items: center; font-family: var(--pc-font); font-size: 14px; font-weight: 700; color: #fff; box-shadow: 0 0 0 2px #fff, 0 2px 4px rgba(0, 0, 0, .15); }
.gc-av.c0 { background: #16a34a; } .gc-av.c1 { background: #ea580c; } .gc-av.c2 { background: #0284c7; } .gc-av.c3 { background: #7c3aed; } .gc-av.c4 { background: #db2777; }
.gc-av.adm { background: linear-gradient(145deg, #ffd54f, #f6b70a); color: #4a3000; }
.gc-row.mine .gc-av, .gc-row.cont .gc-av { visibility: hidden; }
.gc-bub { min-width: 0; padding: 7px 11px 5px; border-radius: 16px 16px 16px 5px; background: #fff; box-shadow: 0 0 0 2px #efe6cf, 0 3px 0 #e6dbbd; }
.gc-row.mine .gc-bub { border-radius: 16px 16px 5px 16px; color: #fff; background: linear-gradient(135deg, #22c55e, #15803d); box-shadow: 0 3px 0 #11622f, 0 6px 12px rgba(22, 163, 74, .2); }
.gc-row.adm .gc-bub { background: #fff7d1; box-shadow: 0 0 0 2px #fcd34d, 0 3px 0 #e9b949; }
.gc-name { display: flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 900; color: #15803d; white-space: nowrap; }
.gc-name em { padding: 0 6px; border-radius: 999px; font-style: normal; font-size: 9.5px; color: #8a5a00; background: #fff3c4; }
.gc-row.adm .gc-name { color: #b07800; } .gc-row.adm .gc-name em { background: #ffd54f; color: #4a3000; }
.gc-row.mine .gc-name, .gc-row.cont .gc-name { display: none; }
.gc-txt { font-size: 13.5px; font-weight: 700; line-height: 1.4; white-space: pre-wrap; word-break: break-word; }
.gc-img { display: block; max-width: 200px; max-height: 220px; margin: 3px 0 2px; border-radius: 12px; object-fit: cover; cursor: zoom-in; }
.gc-time { display: block; margin-top: 1px; text-align: right; font-size: 9.5px; font-weight: 800; opacity: .65; }
.gc-empty { margin: auto; text-align: center; font-size: 12.5px; font-weight: 800; color: var(--muted); }
.gc-empty img { display: block; width: 76px; height: 76px; margin: 0 auto 6px; object-fit: contain; animation: gcBob 3s ease-in-out infinite; }
.gc-input { display: flex; align-items: flex-end; gap: 6px; flex-shrink: 0; margin: 0 10px calc(10px + env(safe-area-inset-bottom)); padding: 6px; border-radius: 22px; background: #fff; box-shadow: 0 0 0 2px #f6e2b0, 0 4px 0 #ecd59c, 0 10px 22px rgba(120, 70, 10, .12); }
.gc-input textarea { flex: 1; min-height: 38px; max-height: 110px; padding: 9px 6px; border: 0; outline: 0; resize: none; background: transparent; font-family: inherit; font-size: 16px; font-weight: 700; line-height: 1.3; color: var(--ink); }
.gc-ib { width: 40px; height: 40px; flex-shrink: 0; display: grid; place-items: center; border: 0; border-radius: 13px; cursor: pointer; font-size: 18px; transition: transform .12s; }
.gc-ib:active { transform: translateY(2px); }
.gc-ib.attach { color: #8a6a3a; background: #f2e6c8; }
.gc-ib.send { color: #fff; background: linear-gradient(135deg, #22c55e, #15803d); box-shadow: 0 3px 0 #11622f; }
.gc-ib:disabled { opacity: .45; cursor: not-allowed; }
.gc-prev { display: none; align-items: center; gap: 6px; padding: 3px 4px; }
.gc-prev img { width: 38px; height: 38px; border-radius: 9px; object-fit: cover; }
.gc-prev span { flex: 1; min-width: 0; font-size: 11px; font-weight: 800; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.gc-prev button { border: 0; background: none; color: #e11d48; font-size: 17px; cursor: pointer; }
.gc-count { align-self: center; flex-shrink: 0; padding-right: 2px; font-size: 9.5px; font-weight: 800; color: var(--muted); }
.gc-lock { display: flex; align-items: center; gap: 8px; margin: 0 10px calc(10px + env(safe-area-inset-bottom)); padding: 10px 12px; border-radius: 18px; font-size: 12px; font-weight: 800; color: #7a4f00; background: #fff3c4; box-shadow: inset 0 0 0 2px #fde68a; }
.gc-lock a { flex-shrink: 0; margin-left: auto; padding: 6px 12px; border-radius: 11px; text-decoration: none; font-family: var(--pc-font); font-size: 13px; font-weight: 600; color: #4a3000; background: linear-gradient(135deg, #ffd54f, #f6b70a); box-shadow: 0 3px 0 #b07800; }
.gc-zoom { position: fixed; inset: 0; z-index: 99; display: none; align-items: center; justify-content: center; padding: 16px; background: rgba(40, 25, 5, .85); }
.gc-zoom img { max-width: 100%; max-height: 100%; border-radius: 14px; }
.gc-blocked { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
.gc-blocked > div { max-width: 320px; padding: 20px 18px; text-align: center; border-radius: 24px; background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 0 0 2px #f6e2b0, 0 5px 0 #ecd59c, 0 20px 40px rgba(120, 70, 10, .15); }
.gc-blocked img { width: 84px; height: 84px; object-fit: contain; animation: gcBob 3s ease-in-out infinite; }
.gc-blocked h2 { margin: 6px 0 4px; font-family: var(--pc-font); font-size: 20px; font-weight: 700; color: #4a2b00; }
.gc-blocked p { margin: 0 0 14px; font-size: 12px; font-weight: 700; color: var(--muted); }
.gc-blocked a { display: inline-flex; align-items: center; gap: 6px; min-height: 42px; padding: 0 18px; border-radius: 14px; text-decoration: none; font-family: var(--pc-font); font-size: 15px; font-weight: 600; color: #4a3000; background: linear-gradient(135deg, #ffd54f, #f6b70a); box-shadow: 0 4px 0 #b07800; }
@media (max-width: 360px) { .gc-quota span { padding: 3px 6px; } .gc-ti small { display: none; } }
@media (prefers-reduced-motion: reduce) { .gc-row, .gc-ava img, .gc-empty img, .gc-blocked img { animation: none; } }
</style>
</head>
<body>
<?php if (!$perm['view']): ?>
<div class="gc-blocked"><div>
  <img src="/assets/img/icons3d/lock.png" alt="">
  <h2>Komunitas terkunci</h2>
  <p><?= htmlspecialchars($perm['reason']) ?></p>
  <a href="/upgrade"><i class="ph-bold ph-crown"></i> Lihat Pangkat</a>
</div></div>
<?php else: ?>
<header class="gc-top">
  <a href="/home" class="gc-back" aria-label="Kembali"><i class="ph-bold ph-caret-left"></i></a>
  <span class="gc-ava"><img src="/assets/img/icons3d/chat.png" alt=""></span>
  <div class="gc-ti"><b>Komunitas</b><small id="gc-sub">Pangkatmu: <?= htmlspecialchars($perm['tier']['name']) ?> · reset <?= htmlspecialchars($perm['next_reset']) ?></small></div>
  <div class="gc-quota">
    <span title="Sisa pesan hari ini"><i class="ph-bold ph-chat-text"></i><b id="gc-q-msg"><?= $perm['msg_left'] ?></b></span>
    <span class="img" id="gc-q-img-wrap" title="Sisa gambar hari ini" <?= $perm['image'] ? '' : 'hidden' ?>><i class="ph-bold ph-image"></i><b id="gc-q-img"><?= $perm['img_left'] ?></b></span>
  </div>
</header>

<div id="gc-root"><div class="gc-page">
  <div class="gc-notice" id="gc-notice" <?= $cfg['enabled'] ? 'hidden' : '' ?>><i class="ph-fill ph-lock-simple"></i><span id="gc-notice-txt"><?= htmlspecialchars((string)$cfg['closed_message']) ?></span></div>
  <div class="gc-msgs" id="gc-msgs"><div class="gc-empty" id="gc-empty"><img src="/assets/img/icons3d/chat.png" alt="">Memuat obrolan...</div></div>
  <div class="gc-lock" id="gc-lock" hidden><i class="ph-fill ph-lock-key"></i><span id="gc-lock-txt"></span><a href="/upgrade" id="gc-lock-up" hidden>Level Up</a></div>
  <form class="gc-input" id="gc-form" autocomplete="off" onsubmit="gcSend(event)">
    <button type="button" class="gc-ib attach" id="gc-attach" title="Kirim gambar" onclick="document.getElementById('gc-file').click()" <?= $perm['image'] ? '' : 'hidden' ?>><i class="ph-bold ph-image"></i></button>
    <input type="file" id="gc-file" accept="image/jpeg,image/png,image/gif,image/webp" hidden onchange="gcPreview(this)">
    <div style="flex:1;min-width:0;display:flex;flex-direction:column">
      <div class="gc-prev" id="gc-prev"><img id="gc-prev-img" alt=""><span id="gc-prev-name"></span><button type="button" onclick="gcClearFile()" aria-label="Batal"><i class="ph-bold ph-x-circle"></i></button></div>
      <textarea id="gc-text" rows="1" maxlength="<?= (int)$cfg['max_len'] ?>" placeholder="Tulis pesan..." oninput="gcAutosize(this)" onkeydown="gcKey(event)"></textarea>
    </div>
    <span class="gc-count" id="gc-count">0/<?= (int)$cfg['max_len'] ?></span>
    <button type="submit" class="gc-ib send" id="gc-send" aria-label="Kirim"><i class="ph-fill ph-paper-plane-right"></i></button>
  </form>
</div></div>
<div class="gc-zoom" id="gc-zoom" onclick="this.style.display='none'"><img id="gc-zoom-img" alt=""></div>

<script>
const GC = { csrf: <?= json_encode(csrf_token()) ?>, last: 0, busy: false, perm: <?= json_encode(gc_perm_public($perm)) ?>, maxLen: <?= (int)$cfg['max_len'] ?>, prevName: null };
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const colorOf = (n) => { let h = 0; for (const ch of n) h = (h * 31 + ch.charCodeAt(0)) | 0; return 'c' + (Math.abs(h) % 5); };

function gcApplyPerm(p, open) {
  GC.perm = p;
  $('gc-q-msg').textContent = p.msg_left;
  $('gc-q-img').textContent = p.img_left;
  $('gc-q-img-wrap').hidden = !p.image;
  $('gc-attach').hidden = !p.image;
  if (typeof open === 'boolean') $('gc-notice').hidden = open;
  const blocked = !p.send;
  $('gc-form').hidden = blocked;
  $('gc-lock').hidden = !blocked;
  if (blocked) { $('gc-lock-txt').textContent = p.reason || 'Kamu belum bisa mengirim pesan.'; $('gc-lock-up').hidden = !/pangkat/i.test(p.reason || ''); }
}
function gcRender(m) {
  if (document.querySelector('[data-mid="' + m.id + '"]')) return;
  const box = $('gc-msgs'), empty = $('gc-empty');
  if (empty) empty.remove();
  const prev = box.lastElementChild;
  const cont = prev && prev.dataset.name === m.name && !m.mine && prev.dataset.mine !== '1';
  const row = document.createElement('div');
  row.className = 'gc-row' + (m.mine ? ' mine' : '') + (m.admin ? ' adm' : '') + (cont ? ' cont' : '');
  row.dataset.mid = m.id; row.dataset.name = m.name; row.dataset.mine = m.mine ? '1' : '0';
  row.innerHTML = '<span class="gc-av ' + (m.admin ? 'adm' : colorOf(m.name)) + '">' + (m.admin ? '<i class="ph-fill ph-shield-star"></i>' : esc(m.name.charAt(0).toUpperCase())) + '</span>'
    + '<div class="gc-bub"><span class="gc-name">' + esc(m.name) + (m.tier ? '<em>' + esc(m.tier) + '</em>' : '') + '</span>'
    + (m.image ? '<img class="gc-img" src="' + esc(m.image) + '" alt="" loading="lazy" onclick="gcZoom(this.src)">' : '')
    + (m.text ? '<div class="gc-txt">' + esc(m.text) + '</div>' : '') + '<span class="gc-time">' + esc(m.time) + '</span></div>';
  box.appendChild(row);
  GC.last = Math.max(GC.last, m.id);
}
function gcNearBottom() { const b = $('gc-msgs'); return b.scrollHeight - b.scrollTop - b.clientHeight < 120; }
function gcScroll() { const b = $('gc-msgs'); b.scrollTop = b.scrollHeight; }

async function gcPoll(first) {
  if (GC.busy) return;
  GC.busy = true;
  try {
    const r = await fetch('/global-chat?action=poll&after=' + GC.last, { credentials: 'same-origin' });
    const d = await r.json();
    if (!d.ok) { if (d.msg) location.reload(); return; }
    const stick = first || gcNearBottom();
    d.messages.forEach(gcRender);
    (d.deleted || []).forEach((id) => { const el = document.querySelector('[data-mid="' + id + '"]'); if (el) el.remove(); });
    if (first && !d.messages.length) $('gc-msgs').innerHTML = '<div class="gc-empty" id="gc-empty"><img src="/assets/img/icons3d/chat.png" alt="">Belum ada obrolan. Sapa yang lain duluan!</div>';
    gcApplyPerm(d.perm, d.open);
    if (stick) gcScroll();
  } catch (e) {} finally { GC.busy = false; }
}
async function gcSend(e) {
  e.preventDefault();
  const ta = $('gc-text'), file = $('gc-file').files[0], btn = $('gc-send');
  const text = ta.value.trim();
  if (!text && !file) return;
  btn.disabled = true;
  const fd = new FormData();
  fd.append('_csrf', GC.csrf); fd.append('action', 'send'); fd.append('message', text);
  if (file) fd.append('image', file);
  try {
    const r = await fetch('/global-chat?action=send', { method: 'POST', body: fd, credentials: 'same-origin' });
    const d = await r.json();
    if (d.perm) gcApplyPerm(d.perm);
    if (!d.ok) { nToast(d.msg, 'warn'); return; }
    ta.value = ''; gcAutosize(ta); gcClearFile();
    gcRender(d.message); gcScroll();
  } catch (e2) { nToast('Koneksi terputus, coba lagi.', 'error'); }
  finally { btn.disabled = false; ta.focus(); }
}
function gcPreview(inp) {
  const f = inp.files[0]; if (!f) return;
  if (f.size > 3 * 1024 * 1024) { nToast('Ukuran gambar maksimal 3MB.', 'warn'); gcClearFile(); return; }
  $('gc-prev-img').src = URL.createObjectURL(f); $('gc-prev-name').textContent = f.name; $('gc-prev').style.display = 'flex';
}
function gcClearFile() { $('gc-file').value = ''; $('gc-prev').style.display = 'none'; }
function gcAutosize(el) { el.style.height = ''; el.style.height = Math.min(el.scrollHeight, 110) + 'px'; $('gc-count').textContent = el.value.length + '/' + GC.maxLen; }
function gcKey(e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); $('gc-form').requestSubmit(); } }
function gcZoom(src) { $('gc-zoom-img').src = src; $('gc-zoom').style.display = 'flex'; }

gcApplyPerm(GC.perm);
gcPoll(true);
let gcTimer = setInterval(() => gcPoll(false), 3000);
document.addEventListener('visibilitychange', () => {
  clearInterval(gcTimer);
  if (!document.hidden) { gcPoll(false); gcTimer = setInterval(() => gcPoll(false), 3000); }
});
// Keyboard HP: sesuaikan tinggi area chat
if (window.visualViewport) {
  const root = $('gc-root');
  const fit = () => { root.style.bottom = Math.max(0, window.innerHeight - visualViewport.height - visualViewport.offsetTop) + 'px'; };
  visualViewport.addEventListener('resize', fit); visualViewport.addEventListener('scroll', fit);
}
</script>
<script src="/assets/js/toast.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/toast.js') ?>"></script>
<?php endif; ?>
</body>
</html>
