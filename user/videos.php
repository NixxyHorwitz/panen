<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$flash = '';
if (!empty($_SESSION['flash_videos_msg'])) {
    $flash = $_SESSION['flash_videos_msg'];
    $flash_type = $_SESSION['flash_videos_type'] ?? 'success';
    unset($_SESSION['flash_videos_msg'], $_SESSION['flash_videos_type']);
}

$watch_limit = user_watch_limit($pdo, $user);
$watch_today = user_watch_today($pdo, $user);

// Cek status survei berhadiah untuk user
$user_has_surveyed = false;
if (!empty($user['id'])) {
    try {
        $survCheck = $pdo->prepare("SELECT 1 FROM user_surveys WHERE user_id = ? LIMIT 1");
        $survCheck->execute([$user['id']]);
        $user_has_surveyed = (bool)$survCheck->fetchColumn();
    } catch (\Throwable) {}
}

// Determine Sort Order
$sort_mode = setting($pdo, 'video_sort_mode', 'default');
$order_by = 'v.sort_order ASC, v.id DESC';
if ($sort_mode === 'newest') $order_by = 'v.id DESC';
if ($sort_mode === 'oldest') $order_by = 'v.id ASC';
if ($sort_mode === 'reward_desc') $order_by = 'v.reward_amount DESC, v.id DESC';
if ($sort_mode === 'reward_asc') $order_by = 'v.reward_amount ASC, v.id DESC';
if ($sort_mode === 'duration_asc') $order_by = 'v.watch_duration ASC, v.id DESC';
if ($sort_mode === 'random') $order_by = 'RAND()';

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// All active videos with watch status and user liked status
$videos = $pdo->prepare(
    "SELECT v.*,
       (SELECT COUNT(*) FROM watch_history wh
        WHERE wh.user_id=? AND wh.video_id=v.id AND DATE(wh.watched_at)=CURDATE()) AS watched_today,
       (SELECT COUNT(*) FROM video_likes vl
        WHERE vl.user_id=? AND vl.video_id=v.id) AS user_liked
     FROM videos v
     WHERE v.is_active=1
     ORDER BY {$order_by}
     LIMIT {$limit} OFFSET {$offset}"
);
$videos->execute([$user['id'], $user['id']]);
$videos = $videos->fetchAll();

// AJAX: Hapus progres tontonan dari riwayat user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_watch_progress') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok'=>false,'msg'=>'Invalid CSRF token.']); exit; }
    $del_vid = (int)($_POST['video_id'] ?? 0);
    if ($del_vid > 0) {
        $pdo->prepare("DELETE FROM user_watch_progress WHERE user_id=? AND video_id=?")->execute([$user['id'], $del_vid]);
    }
    echo json_encode(['ok'=>true]);
    exit;
}

// Bersihkan progres tontonan untuk video yang sudah tuntas ditonton hari ini
$pdo->prepare(
    "DELETE uwp FROM user_watch_progress uwp
     JOIN watch_history wh ON wh.user_id = uwp.user_id AND wh.video_id = uwp.video_id AND DATE(wh.watched_at) = CURDATE()
     WHERE uwp.user_id = ?"
)->execute([$user['id']]);

// Ambil riwayat tontonan yang sedang berjalan (maksimal 3 per user)
$cw_stmt = $pdo->prepare(
    "SELECT uwp.seconds_watched, uwp.duration, uwp.last_position, uwp.updated_at,
            v.id, v.title, v.youtube_id, v.watch_duration, v.reward_amount, v.total_watches, v.total_likes
     FROM user_watch_progress uwp
     JOIN videos v ON v.id = uwp.video_id
     WHERE uwp.user_id = ? AND v.is_active = 1
     ORDER BY uwp.updated_at DESC
     LIMIT 3"
);
$cw_stmt->execute([$user['id']]);
$continue_watching = $cw_stmt->fetchAll();

$saved_prog_map = [];
foreach ($continue_watching as $cw) {
    $saved_prog_map[$cw['id']] = $cw;
}

// Satu baris kartu video (dipakai halaman & infinite scroll)
$render_vcard = static function (array $v) use ($watch_today, $watch_limit, $saved_prog_map): void {
    $done    = (bool)$v['watched_today'];
    $blocked = !$done && ($watch_today >= $watch_limit);
    $href    = ($done || $blocked) ? 'javascript:void(0)' : '/watch?id=' . $v['id'];
    $status_class = $done ? 'done' : ($blocked ? 'blocked' : 'ready');
    $resume  = (!$done && isset($saved_prog_map[$v['id']]) && (int)$v['watch_duration'] > 0)
        ? (int)round(($saved_prog_map[$v['id']]['seconds_watched'] / $v['watch_duration']) * 100) : null;
    ?>
    <a href="<?= $href ?>" class="vcard vcard--<?= $status_class ?>" data-status="<?= $done ? 'done' : 'ready' ?>" data-rv <?= ($done || $blocked) ? 'style="pointer-events:none"' : '' ?>>
      <div class="vcard__thumb">
        <img src="<?= yt_thumb($v['youtube_id']) ?>" alt="<?= htmlspecialchars($v['title']) ?>" loading="lazy" onerror="this.src='https://img.youtube.com/vi/<?= $v['youtube_id'] ?>/hqdefault.jpg'">
        <span class="vcard__dur"><i class="ph-bold ph-timer"></i><?= (int)$v['watch_duration'] ?>s</span>
        <?php if ($done): ?><span class="vcard__state ok"><span class="g-stamp">BERES</span></span>
        <?php elseif ($blocked): ?><span class="vcard__state lock"><img src="/assets/img/icons3d/lock.png" alt=""></span>
        <?php else: ?><span class="vcard__play"><i class="ph-fill ph-play"></i></span><?php endif; ?>
        <?php if ($resume !== null): ?><span class="vcard__resume"><i style="width:<?= max(4, min(100, $resume)) ?>%"></i></span><?php endif; ?>
      </div>
      <div class="vcard__body">
        <div class="vcard__title"><?= htmlspecialchars($v['title']) ?></div>
        <div class="vcard__stats">
          <span><i class="ph-fill ph-eye"></i><?= number_format((int)$v['total_watches']) ?></span>
          <span><i class="ph-fill ph-heart"></i><?= number_format((int)($v['total_likes'] ?? 0)) ?></span>
        </div>
        <div class="vcard__foot">
          <?php if ($done): ?><span class="vcard__rw done"><i class="ph-fill ph-check-circle"></i>Dipanen</span>
          <?php else: ?><span class="vcard__rw g-coin"><img src="/assets/img/icons3d/coins.png" alt="">+<?= format_rp((float)$v['reward_amount']) ?></span><?php endif; ?>
          <?php if (!$done && !$blocked): ?>
            <span class="vcard__go"><?= $resume !== null ? 'Lanjut ' . $resume . '%' : 'Tonton' ?></span>
          <?php endif; ?>
        </div>
      </div>
    </a>
    <?php
};

// AJAX Infinite Scroll Response
if (isset($_GET['ajax'])) {
    if (empty($videos)) {
        echo '';
        exit;
    }
    foreach ($videos as $v) $render_vcard($v);
    exit;
}

$pageTitle  = 'Video Cuan';
$activePage = 'videos';
require dirname(__DIR__) . '/partials/header.php';
$pct  = $watch_limit > 0 ? min(100, (int)round(($watch_today / $watch_limit) * 100)) : 0;
$left = max(0, $watch_limit - $watch_today);
?>

<style>
/* ══ VIDEOS — Bioskop Sawah · Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.vh-quota { display: flex; align-items: center; gap: 12px; margin-top: 12px; padding: 10px; border-radius: 20px; background: rgba(255, 255, 255, .72); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); box-shadow: 0 0 0 2px rgba(255, 255, 255, .8), 0 8px 18px rgba(120, 70, 10, .12); }
.vh-quota .t { flex: 1; min-width: 0; }
.vh-quota .t b { display: block; font-family: var(--pc-font); font-size: 15px; font-weight: 700; color: #4a2b00; }
.vh-quota .t span { display: block; margin: 2px 0 7px; font-size: 11.5px; font-weight: 800; color: #7a4f00; }
.vh-pips { display: flex; gap: 3px; flex-wrap: wrap; }
.vh-pips i { width: 12px; height: 12px; border-radius: 4px; background: #efe3c4; box-shadow: inset 0 -2px 0 rgba(0, 0, 0, .08); }
.vh-pips i.on { background: linear-gradient(180deg, #4ade80, #16a34a); box-shadow: inset 0 -2px 0 #11622f; animation: vhPip .5s var(--pc-ease) both; animation-delay: calc(var(--i) * .06s); }
@keyframes vhPip { from { transform: scale(0); } }

/* Survei */
.vh-survey { display: flex; align-items: center; gap: 10px; text-decoration: none; color: inherit; padding: 9px 10px; }
.vh-survey .t { flex: 1; min-width: 0; line-height: 1.25; }
.vh-survey .t b { display: block; font-family: var(--pc-font); font-size: 14.5px; font-weight: 700; color: var(--pc-ink); }
.vh-survey .t span { display: block; font-size: 11px; font-weight: 800; color: var(--pc-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.vh-survey.done { background: linear-gradient(180deg, #f0fdf4, #fff); box-shadow: 0 0 0 2px #bbf7d0, 0 10px 22px rgba(22, 163, 74, .1); }

/* Lanjutkan (carousel) */
.vhub-continue-list { display: flex; gap: 9px; overflow-x: auto; scroll-snap-type: x mandatory; padding: 4px 2px 8px; margin: 0 -2px; scrollbar-width: none; }
.vhub-continue-list::-webkit-scrollbar { display: none; }
.vhub-continue-item { position: relative; flex: 0 0 76%; scroll-snap-align: start; border-radius: 18px; background: #fff; overflow: hidden; box-shadow: 0 0 0 2px #cdeefd, 0 8px 16px rgba(2, 132, 199, .12); transition: opacity .3s, transform .3s; }
.vhub-continue-list .vhub-continue-item:only-child { flex-basis: 100%; }
.cw-thumb { position: relative; display: block; aspect-ratio: 16 / 7; background: #1b2a1e; }
.cw-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.cw-thumb .pl { position: absolute; left: 50%; top: 50%; width: 42px; height: 42px; margin: -21px 0 0 -21px; border-radius: 50%; display: grid; place-items: center; font-size: 18px; color: #4a3000;
  background: linear-gradient(180deg, #ffd54f, #f6b70a); box-shadow: 0 3px 0 #b07800, 0 6px 14px rgba(0, 0, 0, .3); animation: vhPulse 2s ease-in-out infinite; }
@keyframes vhPulse { 50% { transform: scale(1.1); } }
.cw-thumb .bar { position: absolute; left: 0; right: 0; bottom: 0; height: 6px; background: rgba(0, 0, 0, .4); }
.cw-thumb .bar i { display: block; height: 100%; background: linear-gradient(90deg, #ffd54f, #4ade80); }
.cw-del { position: absolute; top: 6px; right: 6px; z-index: 2; width: 28px; height: 28px; border-radius: 10px; border: 0; background: rgba(27, 42, 30, .6); color: #fff; font-size: 13px; cursor: pointer; display: grid; place-items: center; }
.cw-info { display: flex; align-items: center; gap: 8px; padding: 8px 10px; }
.cw-info a { flex: 1; min-width: 0; text-decoration: none; color: var(--pc-ink); font-size: 12px; font-weight: 900; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cw-info span { flex-shrink: 0; font-size: 11px; font-weight: 900; color: #0369a1; }

/* Daftar video: kartu quest */
.vgrid { display: flex; flex-direction: column; gap: 10px; }
.vcard { position: relative; display: flex; gap: 10px; padding: 7px; border-radius: 20px; text-decoration: none; color: inherit; background: linear-gradient(180deg, #fffaf0, #fff);
  box-shadow: 0 0 0 2px #f6e2b0, 0 4px 0 #ecd59c, 0 10px 18px rgba(120, 70, 10, .08); }
.vcard[data-rv].in { transition: opacity .5s var(--pc-out), transform .2s var(--pc-ease); }
.vcard:active { transform: translateY(3px); box-shadow: 0 0 0 2px #f6e2b0, 0 1px 0 #ecd59c; }
.vcard--done { background: #f6f8f2; box-shadow: 0 0 0 2px #dfe8d3, 0 4px 0 #d3dec5; }
.vcard--done .vcard__thumb img { filter: saturate(.4); }
.vcard--blocked { filter: grayscale(.6); opacity: .7; }
.vcard__thumb { position: relative; width: 132px; flex-shrink: 0; aspect-ratio: 16 / 10; border-radius: 14px; overflow: hidden; background: #1b2a1e; align-self: center; box-shadow: 0 0 0 2px #fff; }
.vcard__thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.vcard__dur { position: absolute; left: 5px; bottom: 5px; display: inline-flex; align-items: center; gap: 2px; padding: 2px 6px; border-radius: 7px; background: rgba(27, 42, 30, .8); color: #fff; font-size: 10px; font-weight: 900; }
.vcard__play { position: absolute; right: 6px; top: 6px; width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; font-size: 12px; color: #4a3000; background: linear-gradient(180deg, #ffd54f, #f6b70a); box-shadow: 0 2px 0 #b07800; }
.vcard__state { position: absolute; inset: 0; display: grid; place-items: center; }
.vcard__state.ok { background: rgba(22, 163, 74, .25); }
.vcard__state.ok .g-stamp { position: static; font-size: 12px; }
.vcard__state.lock { background: rgba(27, 42, 30, .5); }
.vcard__state.lock img { width: 40px; height: 40px; object-fit: contain; filter: drop-shadow(0 3px 4px rgba(0, 0, 0, .4)); }
.vcard__resume { position: absolute; left: 0; right: 0; bottom: 0; height: 5px; background: rgba(0, 0, 0, .45); }
.vcard__resume i { display: block; height: 100%; background: linear-gradient(90deg, #ffd54f, #f59e0b); }
.vcard__body { flex: 1; min-width: 0; display: flex; flex-direction: column; padding: 2px 2px 1px 0; }
.vcard__title { font-size: 12.5px; font-weight: 900; line-height: 1.3; color: var(--pc-ink); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.vcard__stats { display: flex; gap: 9px; margin-top: 3px; font-size: 10.5px; font-weight: 800; color: var(--pc-muted); }
.vcard__stats span { display: inline-flex; align-items: center; gap: 2px; }
.vcard__stats .ph-heart { color: #f43f5e; }
.vcard__foot { margin-top: auto; padding-top: 6px; display: flex; align-items: center; justify-content: space-between; gap: 6px; }
.vcard__rw.done { display: inline-flex; align-items: center; gap: 3px; font-size: 11.5px; font-weight: 900; color: #15803d; }
.vcard__go { display: inline-flex; align-items: center; padding: 5px 11px; border-radius: 10px; font-family: var(--pc-font); font-size: 12.5px; font-weight: 600; color: #fff; white-space: nowrap;
  background: linear-gradient(135deg, #22c55e, #15803d); box-shadow: 0 3px 0 #11622f, inset 0 2px 0 rgba(255, 255, 255, .4); text-shadow: 0 1px 0 rgba(0, 0, 0, .15); }

#loader { text-align: center; padding: 12px; display: none; }
#loader img { width: 40px; height: 40px; animation: vhSpin 1s var(--pc-ease) infinite; }
@keyframes vhSpin { 50% { transform: translateY(-8px) rotate(180deg); } 100% { transform: rotate(360deg); } }
</style>

<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs><linearGradient id="gRingGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ffd54f"/><stop offset="1" stop-color="#16a34a"/></linearGradient></defs></svg>

<div class="g-page">

  <!-- Hero kuota -->
  <section class="g-hero" data-rv>
    <span class="g-sun"></span><span class="g-cloud c1"></span><span class="g-cloud c2"></span>
    <div class="g-hero-row">
      <div style="flex:1;min-width:0">
        <h1>Bioskop Sawah</h1>
        <p>Tonton video, panen koin ke saldo kamu.</p>
      </div>
      <div class="g-hero-art"><img src="/assets/img/icons3d/watch.png" alt=""></div>
    </div>
    <div class="vh-quota">
      <div class="g-ring">
        <svg viewBox="0 0 80 80"><circle class="bg" cx="40" cy="40" r="32"/><circle class="fg" cx="40" cy="40" r="32" style="stroke-dashoffset:<?= round(201 * (1 - $pct / 100), 1) ?>"/></svg>
        <div><b><?= (int)$watch_today ?></b><small>/ <?= (int)$watch_limit ?></small></div>
      </div>
      <div class="t">
        <?php if ($watch_today >= $watch_limit): ?>
          <b>Kuota hari ini habis!</b>
          <span>Reset jam 00:00 · naikkan rank untuk kuota lebih</span>
          <a href="/upgrade" class="g-btn gold sm shine"><img src="/assets/img/icons3d/upgrade.png" alt="">Tambah kuota</a>
        <?php else: ?>
          <b>Sisa <?= $left ?> tontonan</b>
          <span>Kuota harian · reset 00:00</span>
          <div class="vh-pips"><?php for ($i = 0; $i < min(20, (int)$watch_limit); $i++): ?><i class="<?= $i < $watch_today ? 'on' : '' ?>" style="--i:<?= $i ?>"></i><?php endfor; ?></div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- Survei berhadiah (bisa ditutup admin) -->
  <?php if (setting($pdo, 'survey_enabled', '1') === '1'): ?>
  <a href="/survey" class="g-panel vh-survey <?= $user_has_surveyed ? 'done' : '' ?>" data-rv style="--d:.05s">
    <span class="g-slot"><img src="/assets/img/icons3d/survey.png" alt=""><?php if ($user_has_surveyed): ?><span class="g-ok"><i class="ph-bold ph-check"></i></span><?php endif; ?></span>
    <span class="t">
      <b><?= $user_has_surveyed ? 'Survei selesai' : 'Quest: Survei berhadiah' ?></b>
      <span><?= $user_has_surveyed ? 'Hadiah sudah masuk Saldo Tarik.' : 'Isi 2 menit, hadiah langsung ke Saldo Tarik' ?></span>
    </span>
    <?php if ($user_has_surveyed): ?><span class="g-chip">Lihat</span>
    <?php else: ?><span class="g-coin"><img src="/assets/img/icons3d/coins.png" alt="">+15rb</span><?php endif; ?>
  </a>
  <?php endif; ?>

  <?php if (!empty($continue_watching)): ?>
  <!-- Lanjutkan menonton -->
  <section id="continue-watching-box" data-rv style="--d:.08s">
    <div class="g-sec"><span class="g-ribbon sky"><i class="ph-fill ph-clock-counter-clockwise"></i>Lanjutkan</span><small id="continue-watching-count"><?= count($continue_watching) ?> / 3 Video</small></div>
    <div class="vhub-continue-list" id="continue-watching-list" style="margin-top:8px">
      <?php foreach ($continue_watching as $cw):
        $cw_pct = min(99, max(1, (int)round(($cw['seconds_watched'] / max(1, (int)$cw['watch_duration'])) * 100))); ?>
        <div class="vhub-continue-item" id="cw-item-<?= $cw['id'] ?>">
          <a href="/watch?id=<?= $cw['id'] ?>" class="cw-thumb">
            <img src="<?= yt_thumb($cw['youtube_id']) ?>" alt="<?= htmlspecialchars($cw['title']) ?>" onerror="this.src='https://img.youtube.com/vi/<?= $cw['youtube_id'] ?>/hqdefault.jpg'">
            <span class="pl"><i class="ph-fill ph-play"></i></span>
            <span class="bar"><i style="width:<?= $cw_pct ?>%"></i></span>
          </a>
          <button type="button" class="cw-del" onclick="dismissWatchProgress(<?= $cw['id'] ?>)" title="Hapus dari riwayat" aria-label="Hapus dari riwayat"><i class="ph-bold ph-x"></i></button>
          <div class="cw-info">
            <a href="/watch?id=<?= $cw['id'] ?>" title="<?= htmlspecialchars($cw['title']) ?>"><?= htmlspecialchars($cw['title']) ?></a>
            <span><?= $cw_pct ?>% · +<?= format_rp((float)$cw['reward_amount']) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- Daftar quest video -->
  <div class="g-sec" data-rv style="--d:.1s"><span class="g-ribbon"><img src="/assets/img/icons3d/missions.png" alt="">Quest Nonton</span></div>
  <div class="g-seg" data-rv style="--d:.12s">
    <button type="button" class="vhub-chip active" onclick="filterVideos('all', this)">Semua</button>
    <button type="button" class="vhub-chip" onclick="filterVideos('ready', this)">Belum</button>
    <button type="button" class="vhub-chip" onclick="filterVideos('done', this)">Selesai</button>
  </div>

  <?php if (empty($videos)): ?>
    <div class="g-panel g-empty" data-rv>
      <img src="/assets/img/icons3d/watch.png" alt="">
      <b>Belum ada video</b>
      <p>Video baru segera hadir. Cek lagi nanti ya.</p>
    </div>
  <?php else: ?>
    <div class="vgrid" id="vgrid">
      <?php foreach ($videos as $v) $render_vcard($v); ?>
    </div>
    <div id="loader"><img src="/assets/img/icons3d/coins.png" alt="Memuat"></div>
  <?php endif; ?>

</div>

<script>
// Filter Videos
function filterVideos(type, btn) {
  document.querySelectorAll('.vhub-chip').forEach(c => c.classList.remove('active'));
  btn.classList.add('active');
  const cards = document.querySelectorAll('#vgrid .vcard');
  cards.forEach(card => {
    const st = card.getAttribute('data-status');
    if (type === 'all') {
      card.style.display = '';
    } else if (type === st) {
      card.style.display = '';
    } else {
      card.style.display = 'none';
    }
  });
}

// Infinite Scroll
document.addEventListener('DOMContentLoaded', function() {
  let page = 1;
  let isLoading = false;
  let hasMore = <?= count($videos) === $limit ? 'true' : 'false' ?>;
  const grid = document.getElementById('vgrid');
  const loader = document.getElementById('loader');

  if (!grid || !hasMore) return;

  const observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && !isLoading && hasMore) {
      loadMore();
    }
  }, { rootMargin: '100px' });

  const sentinel = document.createElement('div');
  sentinel.style.height = '1px';
  grid.parentNode.insertBefore(sentinel, grid.nextSibling);
  observer.observe(sentinel);

  async function loadMore() {
    isLoading = true;
    if (loader) loader.style.display = 'block';
    page++;

    try {
      const res = await fetch(`?ajax=1&page=${page}`);
      const html = await res.text();

      if (html.trim() === '') {
        hasMore = false;
        observer.unobserve(sentinel);
      } else {
        grid.insertAdjacentHTML('beforeend', html);
        requestAnimationFrame(() => grid.querySelectorAll('.vcard[data-rv]:not(.in)').forEach((el) => el.classList.add('in')));
      }
    } catch (e) {
      console.error('Error loading more videos:', e);
      page--;
    } finally {
      isLoading = false;
      if (loader) loader.style.display = 'none';
    }
  }
});

// Dismiss Watch Progress
async function dismissWatchProgress(vidId) {
  const item = document.getElementById('cw-item-' + vidId);
  if (item) {
    item.style.opacity = '0.4';
    item.style.pointerEvents = 'none';
  }

  const fd = new FormData();
  fd.append('action', 'delete_watch_progress');
  fd.append('_csrf', '<?= csrf_token() ?>');
  fd.append('video_id', vidId);

  try {
    const res = await fetch('/videos', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      if (item) {
        item.style.transition = 'all 0.3s ease';
        item.style.transform = 'scale(0.9)';
        item.style.opacity = '0';
        setTimeout(() => {
          item.remove();
          const list = document.getElementById('continue-watching-list');
          const remaining = list ? list.querySelectorAll('.vhub-continue-item').length : 0;
          const countBadge = document.getElementById('continue-watching-count');
          if (countBadge) countBadge.textContent = remaining + ' / 3 Video';
          if (remaining === 0) {
            const box = document.getElementById('continue-watching-box');
            if (box) box.remove();
          }
        }, 300);
      }
      if (typeof nToast === 'function') nToast('Riwayat tontonan dihapus.', 'info');
    }
  } catch(e) {
    if (item) {
      item.style.opacity = '';
      item.style.pointerEvents = '';
    }
  }
}
</script>

<?php if (!empty($flash)): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    if (typeof nToast !== 'undefined') {
        nToast('<?= addslashes($flash) ?>', '<?= $flash_type ?>');
    }
});
</script>
<?php endif; ?>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
