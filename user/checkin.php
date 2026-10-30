<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';
require_once dirname(__DIR__) . '/lib/checkin.php';

$csrf_token = csrf_token();
$rewards    = checkin_rewards($pdo);
$ci         = checkin_state($user);

$is_ajax = !empty($_POST['ajax']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
$reply = static function (array $data) use ($is_ajax): void {
    if (!$is_ajax) {
        $_SESSION['checkin_flash'] = $data;
        header('Location: /checkin');
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
};

// ── KLAIM: hadiah ditentukan server dari hari streak, bukan pilihan user ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkin') {
    if ($ci['claimed']) {
        $reply(['success' => false, 'message' => 'Hadiah hari ini sudah dipanen. Datang lagi besok!']);
    }
    try {
        $pdo->beginTransaction();
        $q = $pdo->prepare("SELECT last_checkin, checkin_streak FROM users WHERE id = ? FOR UPDATE");
        $q->execute([$user['id']]);
        $row = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $st  = checkin_state($row);
        if ($st['claimed']) {
            $pdo->rollBack();
            $reply(['success' => false, 'message' => 'Hadiah hari ini sudah dipanen. Datang lagi besok!']);
        }
        $streak = $st['streak'] + 1;
        $day    = $st['day'];
        $reward = $rewards[$day];
        $up = $pdo->prepare(
            "UPDATE users SET balance_wd = balance_wd + ?, total_earned = total_earned + ?, last_checkin = CURDATE(), checkin_streak = ?
             WHERE id = ? AND (last_checkin IS NULL OR last_checkin < CURDATE())"
        );
        $up->execute([$reward, $reward, $streak, $user['id']]);
        if ($up->rowCount() < 1) {
            $pdo->rollBack();
            $reply(['success' => false, 'message' => 'Hadiah hari ini sudah dipanen. Datang lagi besok!']);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $reply(['success' => false, 'message' => 'Terjadi kesalahan sistem. Coba lagi sebentar.']);
    }
    $bal = (float) $user['balance_wd'] + $reward;
    $tomorrow = $rewards[$day % CHECKIN_CYCLE + 1];
    $reply([
        'success'            => true,
        'day'                => $day,
        'streak'             => $streak,
        'reward'             => $reward,
        'reward_formatted'   => format_rp((float) $reward),
        'balance_formatted'  => format_rp($bal),
        'tomorrow_formatted' => format_rp((float) $tomorrow),
    ]);
}

// Hasil klaim tanpa JS (form biasa)
$flash = $_SESSION['checkin_flash'] ?? null;
unset($_SESSION['checkin_flash']);

$pageTitle  = 'Kalender Panen Harian';
$activePage = 'checkin';
require dirname(__DIR__) . '/partials/header.php';

$DAY_ART  = [1 => 'seed', 2 => 'padi', 3 => 'jagung', 4 => 'cabai', 5 => 'semangka', 6 => 'basket', 7 => 'chest'];
$today_rw = $rewards[$ci['day']];
$next_rw  = $rewards[$ci['claimed'] ? $ci['day'] % CHECKIN_CYCLE + 1 : $ci['day']];
?>
<style>
/* ══ CHECK-IN — Kalender Panen 7 Hari · Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.ci-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }
.ci-stat { display: flex; align-items: center; gap: 8px; padding: 8px; border-radius: 16px; background: rgba(255, 255, 255, .75); box-shadow: 0 0 0 2px rgba(255, 255, 255, .85), 0 6px 14px rgba(120, 70, 10, .1); min-width: 0; }
.ci-stat .t { min-width: 0; }
.ci-stat small { display: block; font-size: 10.5px; font-weight: 900; color: #8a5a00; }
.ci-stat b { display: block; font-family: var(--pc-font); font-size: 15px; font-weight: 700; color: #4a2b00; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Kalender: Hari 1–6 kotak, Hari 7 peti besar */
.ci-field { position: relative; overflow: hidden; padding: 12px 10px 14px; border-radius: 24px;
  background: radial-gradient(120% 60% at 50% 0%, rgba(255, 255, 255, .5), transparent 60%), repeating-linear-gradient(90deg, #7cc95a 0 22px, #6fbd4e 22px 44px);
  box-shadow: inset 0 0 0 3px rgba(255, 255, 255, .5), 0 4px 0 #4f9f3a, 0 12px 24px rgba(40, 90, 20, .2); }
.ci-field-hd { text-align: center; margin-bottom: 10px; color: #fff; text-shadow: 0 2px 0 rgba(30, 70, 15, .45); }
.ci-field-hd b { display: block; font-family: var(--pc-font); font-size: 17px; font-weight: 700; }
.ci-field-hd span { font-size: 11.5px; font-weight: 800; opacity: .95; }
.ci-cal { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.ci-d { position: relative; display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 8px 2px 7px; border-radius: 16px;
  background: linear-gradient(180deg, #fffaf0, #f4e6c0); box-shadow: inset 0 0 0 2px #fff, 0 4px 0 #c9a560, 0 7px 10px rgba(60, 40, 0, .18); }
.ci-d small { font-size: 10px; font-weight: 900; color: #a08a5c; letter-spacing: .02em; }
.ci-d img { width: 40px; height: 40px; object-fit: contain; filter: drop-shadow(0 3px 3px rgba(0, 0, 0, .22)); transition: filter .3s; }
.ci-d b { font-family: var(--pc-font); font-size: 12.5px; font-weight: 700; color: #6b4f1d; white-space: nowrap; }
.ci-d.big { grid-column: span 2; flex-direction: row; justify-content: center; gap: 8px; padding: 8px 10px;
  background: linear-gradient(180deg, #fff3c4, #ffe08a); box-shadow: inset 0 0 0 2px #fff, 0 4px 0 #d39a1c, 0 7px 14px rgba(160, 100, 0, .25); }
.ci-d.big img { width: 54px; height: 54px; }
.ci-d.big .t { display: flex; flex-direction: column; align-items: flex-start; }
.ci-d.big b { font-size: 16px; color: #7a4a00; }
.ci-d.big em { font-style: normal; font-size: 10px; font-weight: 900; color: #b45309; }
.ci-d.lock img { filter: grayscale(.85) opacity(.5) drop-shadow(0 3px 3px rgba(0, 0, 0, .15)); }
.ci-d.lock b { color: #a08a5c; }
.ci-d.done { background: linear-gradient(180deg, #dcfce7, #f0fdf4); box-shadow: inset 0 0 0 2px #fff, 0 4px 0 #4ade80, 0 7px 10px rgba(20, 90, 40, .15); }
.ci-d.done b { color: #15803d; }
.ci-d.done::after { content: '\2713'; position: absolute; top: -6px; right: -4px; width: 20px; height: 20px; border-radius: 50%; display: grid; place-items: center; font-size: 12px; font-weight: 900; color: #fff; background: #16a34a; box-shadow: 0 0 0 2px #fff, 0 2px 4px rgba(0, 0, 0, .2); }
.ci-d.done.pop::after { animation: ciTick .5s var(--pc-ease) both; }
@keyframes ciTick { from { transform: scale(0) rotate(-90deg); } }
.ci-d.now { z-index: 1; background: linear-gradient(180deg, #fff, #fff6d6); box-shadow: inset 0 0 0 2px #fcd34d, 0 4px 0 #e9b949, 0 0 0 4px rgba(255, 213, 79, .55), 0 10px 22px rgba(246, 183, 10, .45); animation: ciNow 1.8s var(--pc-ease) infinite; }
.ci-d.now small { color: #b45309; }
.ci-d.now::before { content: 'Hari ini'; position: absolute; top: -9px; left: 50%; transform: translateX(-50%); padding: 1px 7px; border-radius: 99px; font-size: 9px; font-weight: 900; color: #fff; white-space: nowrap; background: linear-gradient(180deg, #f97316, #ea580c); box-shadow: 0 2px 0 #9a3412; }
.ci-d.now.big::before { content: 'Hari ini · Peti emas'; }
@keyframes ciNow { 50% { transform: translateY(-3px); } }

/* Tombol tahan-untuk-panen */
.ci-claim { margin-top: 12px; }
.ci-hold { position: relative; overflow: hidden; width: 100%; display: flex; align-items: center; justify-content: center; gap: 8px; padding: 15px 16px; border: 0; border-radius: 18px; cursor: pointer; font: inherit; touch-action: none; user-select: none; -webkit-user-select: none; -webkit-touch-callout: none;
  font-family: var(--pc-font); font-size: 17px; font-weight: 700; color: #4a3000; text-shadow: 0 1px 0 rgba(255, 255, 255, .5);
  background: linear-gradient(180deg, #ffd54f, #f6b70a); box-shadow: 0 5px 0 #b07800, 0 9px 18px rgba(246, 183, 10, .45), inset 0 2px 0 rgba(255, 255, 255, .5); transition: transform .12s, box-shadow .12s; }
.ci-hold img { position: relative; width: 28px; height: 28px; object-fit: contain; }
.ci-hold span { position: relative; }
.ci-hold i.fill { position: absolute; inset: 0; transform-origin: left; transform: scaleX(var(--p, 0)); background: linear-gradient(90deg, #4ade80, #22c55e); }
.ci-hold.held { transform: translateY(4px); box-shadow: 0 1px 0 #b07800, 0 3px 8px rgba(246, 183, 10, .35), inset 0 2px 0 rgba(255, 255, 255, .5); }
.ci-hold.held img { animation: ciShake .25s linear infinite; }
@keyframes ciShake { 25% { transform: rotate(-12deg); } 75% { transform: rotate(12deg); } }
.ci-hold:disabled { cursor: default; filter: grayscale(.4); opacity: .8; }
.ci-hold.done { color: #fff; text-shadow: 0 1px 0 rgba(0, 0, 0, .2); background: linear-gradient(180deg, #4ade80, #16a34a); box-shadow: 0 5px 0 #15803d, 0 9px 18px rgba(22, 163, 74, .35); filter: none; opacity: 1; }
.ci-tip { margin-top: 8px; text-align: center; font-size: 11px; font-weight: 800; color: #fff; text-shadow: 0 1px 0 rgba(30, 70, 15, .5); }

.ci-info { display: flex; align-items: center; gap: 10px; }
.ci-info .t { flex: 1; min-width: 0; }
.ci-info b { display: block; font-family: var(--pc-font); font-size: 15px; font-weight: 700; color: var(--pc-ink); }
.ci-info p { margin: 2px 0 0; font-size: 11.5px; font-weight: 700; color: var(--pc-muted); line-height: 1.4; }
.ci-info p strong { color: #15803d; }
.ci-warn { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; padding: 8px 10px; border-radius: 14px; font-size: 11.5px; font-weight: 800; color: #9a3412; background: #fff1e6; box-shadow: inset 0 0 0 2px #fed7aa; }
.ci-warn img { width: 26px; height: 26px; object-fit: contain; }
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
.ci-box .dest { display: block; margin-bottom: 14px; font-size: 11.5px; font-weight: 800; color: var(--pc-muted); }
.ci-box .dest strong { color: #b45309; }
.ci-box .g-btn + .g-btn { margin-top: 10px; }
.ci-coin { position: fixed; z-index: 10002; width: 28px; height: 28px; pointer-events: none; animation: ciCoin 1.2s var(--pc-out) forwards; }
@keyframes ciCoin { to { transform: translate(var(--x), var(--y)) rotate(var(--r)) scale(.5); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .ci-d.now, .ci-rays, .ci-box .art, .ci-hold.held img { animation: none; } }
</style>

<div class="g-page">

  <section class="g-hero" data-rv>
    <span class="g-sun"></span><span class="g-cloud c1"></span><span class="g-cloud c2"></span>
    <div class="g-hero-row">
      <div style="flex:1;min-width:0">
        <h1>Kalender Panen</h1>
        <p><?= $ci['claimed'] ? 'Hadiah hari ini sudah dipanen. Datang lagi besok!' : 'Datang tiap hari, hadiahnya naik terus sampai peti emas Hari 7.' ?></p>
      </div>
      <div class="g-hero-art"><img src="/assets/img/icons3d/checkin.png" alt=""></div>
    </div>
    <div class="ci-stats">
      <div class="ci-stat"><span class="g-slot sm"><img src="/assets/img/icons3d/moneybag.png" alt=""></span><div class="t"><small>Saldo Tarik</small><b id="ci-bal"><?= format_rp((float) $user['balance_wd']) ?></b></div></div>
      <div class="ci-stat"><span class="g-slot sm"><img src="/assets/img/icons3d/flame.png" alt=""></span><div class="t"><small>Streak beruntun</small><b><span id="ci-streak"><?= $ci['streak'] ?></span> hari</b></div></div>
    </div>
  </section>

  <section class="ci-field" data-rv style="--d:.05s">
    <div class="ci-field-hd">
      <b id="ci-hd"><?= $ci['claimed'] ? 'Hari ke-' . $ci['day'] . ' sudah dipanen' : 'Hari ke-' . $ci['day'] . ': ' . format_rp((float) $today_rw) ?></b>
      <span>Semua hadiah sudah kelihatan, tinggal datang tiap hari</span>
    </div>

    <div class="ci-cal">
      <?php for ($d = 1; $d <= CHECKIN_CYCLE; $d++):
        $done = $d < $ci['day'] || ($ci['claimed'] && $d === $ci['day']);
        $now  = !$ci['claimed'] && $d === $ci['day'];
        $cls  = ($done ? 'done' : ($now ? 'now' : 'lock')) . ($d === CHECKIN_CYCLE ? ' big' : ''); ?>
      <div class="ci-d <?= $cls ?>" data-day="<?= $d ?>">
        <?php if ($d === CHECKIN_CYCLE): ?>
          <img src="/assets/img/icons3d/<?= $done ? 'chest_open' : 'chest' ?>.png" alt="">
          <span class="t"><small>HARI 7</small><b><?= format_rp((float) $rewards[$d]) ?></b><em>Peti emas</em></span>
        <?php else: ?>
          <small>HARI <?= $d ?></small>
          <img src="/assets/img/icons3d/<?= $DAY_ART[$d] ?>.png" alt="">
          <b><?= format_rp((float) $rewards[$d]) ?></b>
        <?php endif; ?>
      </div>
      <?php endfor; ?>
    </div>

    <form method="POST" class="ci-claim" id="ci-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="checkin">
      <?php if ($ci['claimed']): ?>
        <button type="button" class="ci-hold done" disabled><img src="/assets/img/icons3d/basket.png" alt=""><span>Sudah dipanen hari ini</span></button>
        <div class="ci-tip">Besok: <?= format_rp((float) $next_rw) ?> menunggu di Hari <?= $ci['day'] % CHECKIN_CYCLE + 1 ?></div>
      <?php else: ?>
        <button type="submit" class="ci-hold" id="ci-hold" aria-label="Tahan untuk panen hadiah hari ini">
          <i class="fill"></i><img src="/assets/img/icons3d/basket.png" alt=""><span id="ci-hold-tx">Tahan untuk panen</span>
        </button>
        <div class="ci-tip" id="ci-tip">Tekan dan tahan tombolnya sampai penuh</div>
      <?php endif; ?>
    </form>
  </section>

  <section class="g-panel" data-rv style="--d:.1s">
    <?php if ($ci['broken'] && !$ci['claimed']): ?>
      <div class="ci-warn"><img src="/assets/img/icons3d/seed.png" alt="">Kemarin terlewat, jadi kalender mulai lagi dari Hari 1. Yuk jaga streak-nya!</div>
    <?php endif; ?>
    <div class="ci-info">
      <span class="g-slot"><img src="/assets/img/icons3d/<?= $ci['claimed'] ? 'chest_open' : 'chest' ?>.png" alt=""><?php if ($ci['claimed']): ?><span class="g-ok"><i class="ph-bold ph-check"></i></span><?php endif; ?></span>
      <div class="t">
        <b>Aturan kalender</b>
        <p>Hadiah naik dari <strong><?= format_rp((float) $rewards[1]) ?></strong> sampai <strong><?= format_rp((float) $rewards[CHECKIN_CYCLE]) ?></strong>, langsung ke Saldo Tarik. Lewat sehari, kembali ke Hari 1. Reset tiap 00:00 WIB.</p>
      </div>
    </div>
    <div class="ci-acts">
      <a href="/withdraw" class="g-btn gold sm"><img src="/assets/img/icons3d/moneybag.png" alt="">Tarik Tunai</a>
      <a href="/farm" class="g-btn sm"><img src="/assets/img/icons3d/padi.png" alt="">Ke Sawah</a>
    </div>
  </section>

</div>

<!-- Modal hadiah -->
<div id="ci-modal" class="ci-modal" onclick="if (event.target === this) ciClose()">
  <div class="ci-box">
    <div class="ci-rays"></div>
    <img class="art" id="ci-modal-art" src="/assets/img/icons3d/basket.png" alt="">
    <span class="g-ribbon gold" id="ci-modal-day">Hari 1</span>
    <h3>Panen berhasil</h3>
    <div class="ci-amt" id="ci-modal-amt">+Rp 0</div>
    <span class="dest">Masuk ke Saldo Tarik. Besok: <strong id="ci-modal-next">Rp 0</strong></span>
    <a href="/withdraw" class="g-btn gold full shine"><img src="/assets/img/icons3d/moneybag.png" alt="">Withdraw Sekarang</a>
    <button type="button" onclick="ciClose()" class="g-btn soft full">Simpan &amp; lanjut</button>
  </div>
</div>

<script>
(() => {
  const btn = document.getElementById('ci-hold');
  if (!btn) return;
  const form = document.getElementById('ci-form');
  const txt = document.getElementById('ci-hold-tx');
  const HOLD_MS = 900;
  let t0 = 0, raf = 0, busy = false;

  const setP = p => btn.style.setProperty('--p', p);
  function tick(now) {
    const p = Math.min(1, (now - t0) / HOLD_MS);
    setP(p);
    if (p >= 1) { stop(false); claim(); return; }
    raf = requestAnimationFrame(tick);
  }
  function start(e) {
    if (busy || (e.button !== undefined && e.button > 0)) return;
    e.preventDefault();
    if (e.pointerId !== undefined) btn.setPointerCapture(e.pointerId);
    btn.classList.add('held');
    txt.textContent = 'Terus tahan...';
    t0 = performance.now();
    raf = requestAnimationFrame(tick);
  }
  function stop(cancel = true) {
    cancelAnimationFrame(raf);
    btn.classList.remove('held');
    if (cancel && !busy) { setP(0); txt.textContent = 'Tahan untuk panen'; }
  }

  btn.addEventListener('pointerdown', start);
  ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(ev => btn.addEventListener(ev, () => stop()));
  btn.addEventListener('contextmenu', e => e.preventDefault());
  btn.addEventListener('click', e => e.preventDefault());
  btn.addEventListener('keydown', e => { if ((e.key === 'Enter' || e.key === ' ') && !e.repeat && !btn.classList.contains('held')) start(e); });
  btn.addEventListener('keyup', e => { if (e.key === 'Enter' || e.key === ' ') stop(); });

  function claim() {
    busy = true;
    btn.disabled = true;
    txt.textContent = 'Memanen...';
    const fd = new FormData(form);
    fd.append('ajax', '1');
    fetch(location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, body: fd })
      .then(r => r.json())
      .then(d => {
        if (!d.success) throw new Error(d.message || 'Gagal memanen hadiah.');
        const cell = document.querySelector('.ci-d[data-day="' + d.day + '"]');
        if (cell) {
          cell.classList.remove('now');
          cell.classList.add('done', 'pop');
          if (d.day === 7) cell.querySelector('img').src = '/assets/img/icons3d/chest_open.png';
          ciBurst(cell);
        }
        document.getElementById('ci-bal').textContent = d.balance_formatted;
        document.getElementById('ci-streak').textContent = d.streak;
        document.getElementById('ci-hd').textContent = 'Hari ke-' + d.day + ' sudah dipanen';
        btn.classList.add('done');
        setP(0);
        txt.textContent = 'Sudah dipanen hari ini';
        document.getElementById('ci-tip').textContent = 'Besok: ' + d.tomorrow_formatted + ' menunggu di Hari ' + (d.day % 7 + 1);
        setTimeout(() => ciShow(d), 450);
      })
      .catch(err => {
        busy = false;
        btn.disabled = false;
        stop();
        const msg = err instanceof SyntaxError ? 'Sesi habis. Muat ulang halaman lalu coba lagi.' : err.message;
        if (typeof nToast === 'function') nToast(msg, 'error'); else alert(msg);
      });
  }
})();

function ciBurst(el) {
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

function ciShow(d) {
  document.getElementById('ci-modal-art').src = '/assets/img/icons3d/' + (d.day === 7 ? 'chest_open' : 'basket') + '.png';
  document.getElementById('ci-modal-day').textContent = d.day === 7 ? 'Peti emas Hari 7!' : 'Hari ' + d.day + ' dari 7';
  document.getElementById('ci-modal-amt').textContent = '+' + d.reward_formatted;
  document.getElementById('ci-modal-next').textContent = d.tomorrow_formatted;
  document.getElementById('ci-modal').classList.add('open');
}
function ciClose() { document.getElementById('ci-modal').classList.remove('open'); }

<?php if ($flash): ?>
document.addEventListener('DOMContentLoaded', () => {
  <?php if (!empty($flash['success'])): ?>ciShow(<?= json_encode($flash) ?>);
  <?php else: ?>if (typeof nToast === 'function') nToast(<?= json_encode($flash['message'] ?? '') ?>, 'info');<?php endif; ?>
});
<?php endif; ?>
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
