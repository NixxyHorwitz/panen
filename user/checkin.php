<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Inisialisasi token CSRF session di awal
$csrf_token   = csrf_token();
$checkin_min  = max(1, (float) setting($pdo, 'checkin_reward_min', '500'));
$checkin_max  = max($checkin_min, (float) setting($pdo, 'checkin_reward_max', '2000'));
$today        = date('Y-m-d');
$last_checkin = $user['last_checkin'] ?? null;
$already      = ($last_checkin === $today);

// Hitung streak check-in / aktivitas
$streak = 0;
if ($last_checkin) {
    $diff = (int)((strtotime($today) - strtotime($last_checkin)) / 86400);
    if ($diff <= 1) {
        $sq = $pdo->prepare("SELECT COUNT(DISTINCT DATE(watched_at)) FROM watch_history WHERE user_id=? AND watched_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
        $sq->execute([$user['id']]);
        $streak = max(1, (int)$sq->fetchColumn());
    }
}

$flash = $flashType = '';
$reward_given = 0;

// Ambil reward dari session jika ada (untuk display setelah form submit / redirect)
if (!empty($_SESSION['checkin_reward_display']) && $already) {
    $reward_given = (int)$_SESSION['checkin_reward_display'];
    unset($_SESSION['checkin_reward_display']);
    $flash = 'checkin_ok';
    $flashType = 'success';
}

// ── PROSES KLAIM CHECK-IN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkin') {
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
               || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
               || isset($_POST['ajax']);

    // Validasi token CSRF (menerima _csrf, csrf_token, atau HTTP_X_CSRF_TOKEN)
    $submitted_token = (string)($_POST['_csrf'] ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
    $is_valid_csrf = !empty($submitted_token) && hash_equals($csrf_token, $submitted_token);

    // Keamanan: Pastikan user login valid. Jika auth_user/guard valid, proses secara aman & idempotent
    if (!$is_valid_csrf && empty($user['id'])) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Sesi tidak valid. Silakan login kembali.']);
            exit;
        }
        $flash = 'Sesi tidak valid. Silakan coba lagi.';
        $flashType = 'error';
    } elseif ($already && $flash !== 'checkin_ok') {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Kamu sudah membuka kotak hadiah hari ini. Kembali besok!']);
            exit;
        }
        $flash = 'Kamu sudah check-in hari ini. Kembali besok!';
        $flashType = 'warn';
    } else {
        // Generate reward acak server-side
        $reward_given = rand((int)$checkin_min, (int)$checkin_max);
        try {
            $pdo->beginTransaction();
            // CRITICAL: Hadiah uang tunai langsung masuk ke Saldo Tarik (balance_wd) dan total_earned
            $stmt = $pdo->prepare(
                "UPDATE users SET balance_wd = balance_wd + ?, total_earned = total_earned + ?, last_checkin = CURDATE()
                 WHERE id = ? AND (last_checkin IS NULL OR last_checkin < CURDATE())"
            );
            $stmt->execute([$reward_given, $reward_given, $user['id']]);

            if ($stmt->rowCount() > 0) {
                $pdo->commit();

                // Refresh saldo lokal
                $user['balance_wd'] = (float)($user['balance_wd'] ?? 0) + $reward_given;
                $user['total_earned'] = (float)($user['total_earned'] ?? 0) + $reward_given;
                $user['last_checkin'] = $today;
                $already = true;
                $streak = max(1, $streak + 1);

                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'reward' => $reward_given,
                        'reward_formatted' => format_rp((float)$reward_given),
                        'new_balance_wd' => $user['balance_wd'],
                        'new_balance_formatted' => format_rp((float)$user['balance_wd']),
                        'streak' => $streak
                    ]);
                    exit;
                }

                $_SESSION['checkin_reward_display'] = $reward_given;
                header('Location: /checkin');
                exit;
            } else {
                $pdo->rollBack();
                if ($is_ajax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Kamu sudah membuka sarang hari ini!']);
                    exit;
                }
                $flash = 'Kamu sudah check-in hari ini!';
                $flashType = 'warn';
                $already = true;
            }
        } catch (\Throwable $e) {
            $pdo->rollBack();
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem.']);
                exit;
            }
            $flash = 'Terjadi kesalahan sistem.';
            $flashType = 'error';
        }
    }
}

$pageTitle  = 'Hadiah Check-in Harian';
$activePage = 'checkin';
require dirname(__DIR__) . '/partials/header.php';

$completed_days = $already ? ($streak % 7 ?: 7) : ($streak % 7);
if ($streak == 0 && $already) $completed_days = 1;
?>

<?php
// Enam karung panen yang bisa dipilih (ikon 3D tanaman)
$SACKS = [['padi', 'Padi'], ['jagung', 'Jagung'], ['cabai', 'Cabai'], ['semangka', 'Semangka'], ['basket', 'Keranjang'], ['seed', 'Benih']];
?>
<style>
/* ══ CHECK-IN — Lumbung Hadiah Harian · Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.ci-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }
.ci-stat { display: flex; align-items: center; gap: 8px; padding: 8px; border-radius: 16px; background: rgba(255, 255, 255, .75); box-shadow: 0 0 0 2px rgba(255, 255, 255, .85), 0 6px 14px rgba(120, 70, 10, .1); min-width: 0; }
.ci-stat .t { min-width: 0; }
.ci-stat small { display: block; font-size: 10.5px; font-weight: 900; color: #8a5a00; }
.ci-stat b { display: block; font-family: var(--pc-font); font-size: 15px; font-weight: 700; color: #4a2b00; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Jalur 7 hari */
.ci-track { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; margin-top: 10px; }
.ci-day { position: relative; display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 7px 0 6px; border-radius: 14px; background: #fff; box-shadow: inset 0 0 0 2px #efe3c4, 0 3px 0 #e6d6ad; }
.ci-day img { width: 28px; height: 28px; object-fit: contain; filter: grayscale(1) opacity(.45); }
.ci-day span { font-size: 10px; font-weight: 900; color: #a08a5c; }
.ci-day.done { background: linear-gradient(180deg, #dcfce7, #f0fdf4); box-shadow: inset 0 0 0 2px #86efac, 0 3px 0 #4ade80; }
.ci-day.done img { filter: none; }
.ci-day.done span { color: #15803d; }
.ci-day.done::after { content: '\2713'; position: absolute; top: -5px; right: -3px; width: 16px; height: 16px; border-radius: 50%; display: grid; place-items: center; font-size: 10px; font-weight: 900; color: #fff; background: #16a34a; box-shadow: 0 0 0 2px #fff; }
.ci-day.today { background: linear-gradient(180deg, #fff3c4, #fffbe8); box-shadow: inset 0 0 0 2px #fcd34d, 0 3px 0 #e9b949; animation: ciToday 1.6s var(--pc-ease) infinite; }
.ci-day.today img { filter: none; }
.ci-day.today span { color: #8a5a00; }
@keyframes ciToday { 50% { transform: translateY(-4px); } }

/* Lumbung: 6 karung */
.ci-field { position: relative; overflow: hidden; padding: 12px 10px 14px; border-radius: 24px;
  background: radial-gradient(120% 60% at 50% 0%, rgba(255, 255, 255, .5), transparent 60%), repeating-linear-gradient(90deg, #7cc95a 0 22px, #6fbd4e 22px 44px);
  box-shadow: inset 0 0 0 3px rgba(255, 255, 255, .5), 0 4px 0 #4f9f3a, 0 12px 24px rgba(40, 90, 20, .2); }
.ci-field-hd { text-align: center; margin-bottom: 10px; color: #fff; text-shadow: 0 2px 0 rgba(30, 70, 15, .45); }
.ci-field-hd b { display: block; font-family: var(--pc-font); font-size: 17px; font-weight: 700; }
.ci-field-hd span { font-size: 11.5px; font-weight: 800; opacity: .95; }
.ci-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.ci-sack { position: relative; display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 4px 8px; border: 0; border-radius: 18px; cursor: pointer; font: inherit;
  background: linear-gradient(180deg, #fffaf0, #f9ecc8); box-shadow: inset 0 0 0 2px #fff, 0 5px 0 #c9a560, 0 8px 12px rgba(60, 40, 0, .2); transition: transform .15s var(--pc-ease); }
.ci-sack img { width: 54px; height: 54px; object-fit: contain; filter: drop-shadow(0 4px 4px rgba(0, 0, 0, .25)); }
.ci-sack span { font-family: var(--pc-font); font-size: 12.5px; font-weight: 600; color: #6b4f1d; }
.ci-sack.can-pick { animation: ciIdle 2.6s ease-in-out infinite; animation-delay: calc(var(--i) * -.4s); }
.ci-sack.can-pick:active { transform: translateY(4px); box-shadow: inset 0 0 0 2px #fff, 0 1px 0 #c9a560; }
@keyframes ciIdle { 0%, 80%, 100% { transform: none; } 85% { transform: rotate(-4deg); } 90% { transform: rotate(4deg); } 95% { transform: rotate(-2deg); } }
.ci-sack.dimmed { filter: grayscale(.8); opacity: .55; cursor: default; animation: none; }
.ci-sack.picking { animation: ciPick .6s ease-in-out infinite; z-index: 2; }
@keyframes ciPick { 25% { transform: rotate(-10deg) scale(1.08); } 75% { transform: rotate(10deg) scale(1.08); } }
.ci-sack.opened { cursor: default; background: linear-gradient(180deg, #fff3c4, #ffe9a0); box-shadow: inset 0 0 0 2px #fff, 0 5px 0 #e9b949, 0 0 0 4px rgba(255, 213, 79, .6), 0 10px 22px rgba(246, 183, 10, .5); animation: ciOpen .6s var(--pc-ease) both; }
.ci-sack.opened span { color: #15803d; font-weight: 700; }
@keyframes ciOpen { from { transform: scale(.6); } }

.ci-info { display: flex; align-items: center; gap: 10px; }
.ci-info .t { flex: 1; min-width: 0; }
.ci-info b { display: block; font-family: var(--pc-font); font-size: 15px; font-weight: 700; color: var(--pc-ink); }
.ci-info p { margin: 2px 0 0; font-size: 11.5px; font-weight: 700; color: var(--pc-muted); line-height: 1.4; }
.ci-info p strong { color: #15803d; }
.ci-acts { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 10px; }

/* Modal hadiah */
.ci-modal { position: fixed; inset: 0; z-index: 10000; display: grid; place-items: center; padding: 20px; background: rgba(27, 42, 30, .55); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); opacity: 0; visibility: hidden; transition: opacity .25s, visibility .25s; }
.ci-modal.open { opacity: 1; visibility: visible; }
.ci-box { position: relative; overflow: hidden; width: 100%; max-width: 330px; padding: 22px 18px 18px; text-align: center; border-radius: 28px; background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 0 0 3px #f6e2b0, 0 6px 0 #e6c886, 0 24px 50px rgba(0, 0, 0, .35); transform: scale(.7); transition: transform .45s var(--pc-ease); }
.ci-modal.open .ci-box { transform: none; }
.ci-rays { position: absolute; left: 50%; top: 70px; width: 420px; height: 420px; margin: -210px 0 0 -210px; background: repeating-conic-gradient(rgba(255, 213, 79, .28) 0 10deg, transparent 10deg 20deg); border-radius: 50%; animation: ciRays 12s linear infinite; pointer-events: none; }
@keyframes ciRays { to { transform: rotate(360deg); } }
.ci-box > *:not(.ci-rays) { position: relative; }
.ci-box .art { display: block; width: 110px; height: 110px; margin: -6px auto 2px; object-fit: contain; filter: drop-shadow(0 10px 12px rgba(120, 70, 10, .35)); animation: gBob 2.4s ease-in-out infinite; }
.ci-box .g-ribbon { margin: 0 0 8px 8px; }
.ci-box h3 { margin: 0; font-family: var(--pc-font); font-size: 21px; font-weight: 700; color: var(--pc-ink); }
.ci-amt { margin: 4px 0 6px; font-family: var(--pc-font); font-size: 36px; font-weight: 700; line-height: 1.1; color: #15803d; text-shadow: 0 3px 0 #bbf7d0; animation: ciAmt .6s var(--pc-ease) .25s both; }
@keyframes ciAmt { from { transform: scale(.3); opacity: 0; } }
.ci-box .dest { display: inline-flex; align-items: center; gap: 4px; margin-bottom: 14px; font-size: 11.5px; font-weight: 800; color: var(--pc-muted); }
.ci-box .g-btn + .g-btn { margin-top: 10px; }
.ci-coin { position: fixed; z-index: 10002; width: 28px; height: 28px; pointer-events: none; animation: ciCoin 1.2s var(--pc-out) forwards; }
@keyframes ciCoin { to { transform: translate(var(--x), var(--y)) rotate(var(--r)) scale(.5); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .ci-day.today, .ci-sack.can-pick, .ci-rays, .ci-box .art { animation: none; } }
</style>

<div class="g-page">

  <section class="g-hero" data-rv>
    <span class="g-sun"></span><span class="g-cloud c1"></span><span class="g-cloud c2"></span>
    <div class="g-hero-row">
      <div style="flex:1;min-width:0">
        <h1>Lumbung Harian</h1>
        <p><?= $already ? 'Hadiah hari ini sudah dipanen. Datang lagi besok!' : 'Pilih satu karung, panen uang tunai gratis!' ?></p>
      </div>
      <div class="g-hero-art"><img src="/assets/img/icons3d/checkin.png" alt=""></div>
    </div>
    <div class="ci-stats">
      <div class="ci-stat"><span class="g-slot sm"><img src="/assets/img/icons3d/moneybag.png" alt=""></span><div class="t"><small>Saldo Tarik</small><b id="display-user-balance-wd"><?= format_rp((float)$user['balance_wd']) ?></b></div></div>
      <div class="ci-stat"><span class="g-slot sm"><img src="/assets/img/icons3d/flame.png" alt=""></span><div class="t"><small>Streak</small><b><span id="display-user-streak"><?= $streak ?></span> hari</b></div></div>
    </div>
  </section>

  <section class="g-panel" data-rv style="--d:.05s">
    <div class="g-sec"><span class="g-ribbon gold"><img src="/assets/img/icons3d/star.png" alt="">Jalur 7 Hari</span><small>Hari ke-<?= $completed_days ?>/7</small></div>
    <div class="ci-track">
      <?php for ($i = 1; $i <= 7; $i++):
        $is_done  = $i < $completed_days || ($i == $completed_days && $already);
        $is_today = !$already && $i == $completed_days + 1;
        $cls = $is_done ? 'done' : ($is_today ? 'today' : ''); ?>
      <div class="ci-day <?= $cls ?>" id="streak-bead-<?= $i ?>">
        <img src="/assets/img/icons3d/<?= $i === 7 ? 'chest' : 'coins' ?>.png" alt="">
        <span>H<?= $i ?></span>
      </div>
      <?php endfor; ?>
    </div>
  </section>

  <section class="ci-field" data-rv style="--d:.1s">
    <div class="ci-field-hd">
      <b><?= $already ? 'Karung hari ini sudah dibuka' : 'Pilih karung panenmu!' ?></b>
      <span><?= $already ? 'Karung baru siap besok jam 00:00' : 'Setiap karung berisi hadiah tunai acak' ?></span>
    </div>
    <div class="ci-grid">
      <?php foreach ($SACKS as $k => [$ic, $lbl]): $id = $k + 1;
        $state = !$already ? 'can-pick' : ($id === 1 && $reward_given > 0 ? 'opened' : 'dimmed'); ?>
      <button type="button" class="ci-sack <?= $state ?>" data-sack-id="<?= $id ?>" style="--i:<?= $k ?>" onclick="handleSackSelect(<?= $id ?>, this)">
        <img src="/assets/img/icons3d/<?= $ic ?>.png" alt="">
        <span class="ci-sack-lbl"><?= $state === 'opened' ? format_rp((float)$reward_given) : $lbl ?></span>
      </button>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="g-panel" data-rv style="--d:.15s">
    <div class="ci-info">
      <span class="g-slot"><img src="/assets/img/icons3d/<?= $already ? 'chest_open' : 'chest' ?>.png" alt=""><?php if ($already): ?><span class="g-ok"><i class="ph-bold ph-check"></i></span><?php endif; ?></span>
      <div class="t">
        <?php if (!$already): ?>
          <b>Hadiah siap dipanen</b>
          <p>Isi karung <strong><?= format_rp($checkin_min) ?></strong> – <strong><?= format_rp($checkin_max) ?></strong>, langsung ke Saldo Tarik.</p>
        <?php else: ?>
          <b>Hadiah tersimpan</b>
          <p>Sudah masuk Saldo Tarik. Lumbung terisi lagi besok jam 00:00 WIB.</p>
        <?php endif; ?>
      </div>
    </div>
    <div class="ci-acts">
      <a href="/withdraw" class="g-btn gold sm"><img src="/assets/img/icons3d/moneybag.png" alt="">Tarik Tunai</a>
      <a href="/farm" class="g-btn sm"><img src="/assets/img/icons3d/padi.png" alt="">Ke Sawah</a>
    </div>
  </section>

</div>

<!-- Form cadangan bila AJAX gagal -->
<form method="POST" id="checkin-fallback-form" style="display:none;">
  <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf_token) ?>">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
  <input type="hidden" name="action" value="checkin">
</form>

<!-- Modal hadiah -->
<div id="ci-clam-modal" class="ci-modal" onclick="if (event.target === this) closeClamModal()">
  <div class="ci-box">
    <div class="ci-rays"></div>
    <img class="art" src="/assets/img/icons3d/chest_open.png" alt="">
    <span class="g-ribbon gold">Karung terbuka!</span>
    <h3>Panen berhasil</h3>
    <div class="ci-amt" id="clam-modal-amt">+Rp 0</div>
    <div class="dest"><i class="ph-fill ph-check-circle" style="color:#16a34a"></i>Langsung masuk ke Saldo Tarik</div>
    <a href="/withdraw" class="g-btn gold full shine"><img src="/assets/img/icons3d/moneybag.png" alt="">Withdraw Sekarang</a>
    <button type="button" onclick="closeClamModal()" class="g-btn soft full">Simpan &amp; lanjut</button>
  </div>
</div>

<script>
const CSRF_TOKEN = '<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>';
let isProcessing = false;
let alreadyClaimed = <?= $already ? 'true' : 'false' ?>;

function handleSackSelect(id, sackEl) {
  if (alreadyClaimed || isProcessing) return;
  isProcessing = true;
  sackEl.classList.remove('can-pick');
  sackEl.classList.add('picking');
  document.querySelectorAll('.ci-sack').forEach((c) => { if (c !== sackEl) { c.classList.remove('can-pick'); c.classList.add('dimmed'); } });

  const formData = new FormData();
  formData.append('action', 'checkin');
  formData.append('_csrf', CSRF_TOKEN);
  formData.append('csrf_token', CSRF_TOKEN);
  formData.append('ajax', '1');

  const reset = () => {
    isProcessing = false;
    document.querySelectorAll('.ci-sack').forEach((c) => { c.classList.remove('picking', 'dimmed'); c.classList.add('can-pick'); });
  };

  fetch(window.location.pathname, {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF_TOKEN, 'Accept': 'application/json' },
    body: formData
  })
  .then((res) => res.json())
  .then((data) => {
    if (data.success) {
      alreadyClaimed = true;
      setTimeout(() => {
        sackEl.classList.remove('picking');
        sackEl.classList.add('opened');
        const lbl = sackEl.querySelector('.ci-sack-lbl');
        if (lbl) lbl.textContent = data.reward_formatted;
        const balEl = document.getElementById('display-user-balance-wd');
        if (balEl && data.new_balance_formatted) balEl.textContent = data.new_balance_formatted;
        const streakEl = document.getElementById('display-user-streak');
        if (streakEl && data.streak) streakEl.textContent = data.streak;
        const today = document.querySelector('.ci-day.today');
        if (today) { today.classList.remove('today'); today.classList.add('done'); }
        ciCoinBurst(sackEl);
        setTimeout(() => showClamModal(data.reward_formatted), 350);
      }, 700);
    } else {
      reset();
      if (typeof nToast === 'function') nToast(data.message || 'Gagal membuka karung.', 'error'); else alert(data.message || 'Gagal membuka karung.');
    }
  })
  .catch((err) => {
    console.warn('Checkin AJAX fallback ke form submit:', err);
    const fallbackForm = document.getElementById('checkin-fallback-form');
    if (fallbackForm) fallbackForm.submit(); else reset();
  });
}

function ciCoinBurst(el) {
  if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const r = el.getBoundingClientRect();
  for (let i = 0; i < 16; i++) {
    const c = document.createElement('img');
    c.src = '/assets/img/icons3d/coins.png'; c.className = 'ci-coin';
    c.style.left = (r.left + r.width / 2 - 14) + 'px'; c.style.top = (r.top + r.height / 2 - 14) + 'px';
    const a = Math.random() * Math.PI * 2, d = 60 + Math.random() * 90;
    c.style.setProperty('--x', Math.cos(a) * d + 'px'); c.style.setProperty('--y', (Math.sin(a) * d - 60) + 'px'); c.style.setProperty('--r', (Math.random() * 540) + 'deg');
    document.body.appendChild(c); setTimeout(() => c.remove(), 1250);
  }
}

function showClamModal(amtFormatted) {
  const modal = document.getElementById('ci-clam-modal');
  const amtEl = document.getElementById('clam-modal-amt');
  if (amtEl) amtEl.textContent = '+' + amtFormatted;
  if (modal) modal.classList.add('open');
}

function closeClamModal() {
  const modal = document.getElementById('ci-clam-modal');
  if (modal) modal.classList.remove('open');
}

<?php if ($flash === 'checkin_ok' && $reward_given > 0): ?>
document.addEventListener('DOMContentLoaded', () => showClamModal('<?= format_rp((float)$reward_given) ?>'));
<?php elseif ($flash !== ''): ?>
document.addEventListener('DOMContentLoaded', () => { if (typeof nToast === 'function') nToast(<?= json_encode($flash) ?>, <?= json_encode($flashType === 'warn' ? 'info' : $flashType) ?>); });
<?php endif; ?>
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
