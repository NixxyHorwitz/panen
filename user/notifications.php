<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Inisialisasi token CSRF session
$csrf_token = csrf_token();

// ── Ambil notifikasi untuk user ini ──────────────────────────────────────────
$notifications = [];
try {
    $stmt = $pdo->prepare(
        "SELECT n.*, IF(nr.id IS NOT NULL, 1, 0) as is_read
         FROM notifications n
         LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
         WHERE (n.target_type='all' OR (n.target_user_ids IS NOT NULL AND JSON_CONTAINS(n.target_user_ids, JSON_QUOTE(?))))
           AND (n.expires_at IS NULL OR n.expires_at > NOW())
         ORDER BY n.created_at DESC
         LIMIT 50"
    );
    $stmt->execute([$user['id'], (string)$user['id']]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // Fallback: hanya ambil notif 'all' jika JSON_CONTAINS tidak didukung
    try {
        $stmt = $pdo->prepare(
            "SELECT n.*, IF(nr.id IS NOT NULL, 1, 0) as is_read
             FROM notifications n
             LEFT JOIN notification_reads nr ON nr.notification_id=n.id AND nr.user_id=?
             WHERE n.target_type='all'
               AND (n.expires_at IS NULL OR n.expires_at > NOW())
             ORDER BY n.created_at DESC LIMIT 50"
        );
        $stmt->execute([$user['id']]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $ex) {}
}

$unread_count = 0;
foreach ($notifications as $n) {
    if (empty($n['is_read'])) $unread_count++;
}

$pageTitle  = 'Notifikasi & Pengumuman';
$activePage = 'notifications';
require dirname(__DIR__) . '/partials/header.php';

/// Tipe notifikasi → label, warna & ikon 3D
$type_cfg = [
    'info'     => ['label' => 'Info',          'color' => '#0369a1', 'badge' => '#e0f2fe', 'icon3d' => 'bell'],
    'success'  => ['label' => 'Cuan Masuk',    'color' => '#15803d', 'badge' => '#dcfce7', 'icon3d' => 'coins'],
    'warning'  => ['label' => 'Perhatian',     'color' => '#8a5a00', 'badge' => '#fff3c4', 'icon3d' => 'flame'],
    'alert'    => ['label' => 'Penting',       'color' => '#be123c', 'badge' => '#ffe4e6', 'icon3d' => 'megaphone'],
    'congrats' => ['label' => 'Hadiah',        'color' => '#6d28d9', 'badge' => '#ede9fe', 'icon3d' => 'chest_open'],
];
?>

<style>
/* ══ NOTIFIKASI — Papan Kabar · Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.ntf-hero-act { display: flex; align-items: center; gap: 8px; margin-top: 12px; padding: 8px 8px 8px 12px; border-radius: 18px; background: rgba(255, 255, 255, .78); box-shadow: 0 0 0 2px rgba(255, 255, 255, .9), 0 8px 18px rgba(120, 70, 10, .12); }
.ntf-hero-act .t { flex: 1; min-width: 0; font-size: 12px; font-weight: 800; color: #7a4f00; line-height: 1.3; }
.ntf-hero-act .t strong { font-family: var(--pc-font); font-size: 18px; font-weight: 700; color: #be123c; }
.ntf-unread-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 900; color: #4a3000; background: linear-gradient(180deg, #ffd54f, #f6b70a); box-shadow: 0 2px 0 #b07800; }
.btn-mark-all-read.g-btn { min-height: 34px; padding: 0 11px; font-size: 13px; border-radius: 11px; flex-shrink: 0; }

.ntf-tabs-deck button { gap: 6px; }
.ntf-tab-badge { min-width: 20px; height: 18px; padding: 0 6px; border-radius: 999px; font-size: 10.5px; font-weight: 900; display: inline-grid; place-items: center; background: rgba(0, 0, 0, .12); font-family: 'Nunito', sans-serif; }
.ntf-tab-item.active .ntf-tab-badge { background: #ffd54f; color: #4a3000; text-shadow: none; }

.ntf-list { display: flex; flex-direction: column; gap: 8px; }
.ntf-item { position: relative; display: flex; align-items: flex-start; gap: 10px; padding: 10px; border-radius: 20px; background: #fff; box-shadow: 0 0 0 2px #efe6cf, 0 3px 0 #e6dbbd; transition: opacity .2s, transform .15s; }
.ntf-item.unread { background: linear-gradient(180deg, #fff7d1, #fffdf3); box-shadow: 0 0 0 2px #fcd34d, 0 4px 0 #e9b949, 0 10px 18px rgba(246, 183, 10, .14); }
.ntf-item.read { opacity: .85; }
.ntf-item.read .g-slot img { filter: grayscale(.5); opacity: .75; }
.ntf-emoji { font-size: 24px; line-height: 1; }
.ntf-content { flex: 1; min-width: 0; }
.ntf-header-meta { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 3px; }
.ntf-badge-group { display: flex; align-items: center; gap: 5px; min-width: 0; }
.ntf-type-tag { padding: 1px 8px; border-radius: 999px; font-size: 10px; font-weight: 900; white-space: nowrap; }
.ntf-unread-dot { width: 8px; height: 8px; flex-shrink: 0; border-radius: 50%; background: #f43f5e; box-shadow: 0 0 0 2px #fff; animation: gGlow 1.4s ease-in-out infinite; }
.ntf-time-stamp { flex-shrink: 0; font-size: 10.5px; font-weight: 800; color: var(--pc-muted); white-space: nowrap; }
.ntf-title { font-size: 13px; font-weight: 900; line-height: 1.3; color: var(--pc-ink); }
.ntf-message { margin-top: 2px; font-size: 12px; font-weight: 700; line-height: 1.45; color: var(--pc-muted); word-break: break-word; }
.ntf-action-link.g-btn { min-height: 30px; margin-top: 8px; padding: 0 11px; font-size: 12.5px; border-radius: 10px; }
.btn-mark-single { width: 30px; height: 30px; flex-shrink: 0; border: 0; border-radius: 10px; cursor: pointer; display: grid; place-items: center; font-size: 15px; color: #15803d; background: #dcfce7; box-shadow: 0 2px 0 #bbf7d0; transition: transform .12s; }
.btn-mark-single:active { transform: scale(.9); }
</style>

<div class="g-page">

  <section class="g-hero" data-rv>
    <span class="g-sun"></span><span class="g-cloud c1"></span><span class="g-cloud c2"></span>
    <div class="g-hero-row">
      <div style="flex:1;min-width:0">
        <h1>Papan Kabar</h1>
        <p>Pengumuman &amp; kabar terbaru untukmu.</p>
        <div style="margin-top:8px"><span class="ntf-unread-pill"><i class="ph-bold ph-envelope-simple-open"></i><span id="unread-pill-txt"><?= $unread_count ?> Baru</span></span></div>
      </div>
      <div class="g-hero-art"><img src="/assets/img/icons3d/bell.png" alt=""></div>
    </div>
    <div class="ntf-hero-act">
      <div class="t" id="banner-sub-text">
        <?php if ($unread_count > 0): ?>
          Ada <strong id="unread-count-banner"><?= $unread_count ?></strong> kabar belum dibaca
        <?php else: ?>
          Semua kabar sudah dibaca
        <?php endif; ?>
      </div>
      <?php if ($unread_count > 0): ?>
        <button type="button" class="g-btn btn-mark-all-read" id="btn-mark-all" onclick="markAllRead()" title="Tandai semua sudah dibaca"><i class="ph-bold ph-checks"></i>Baca semua</button>
      <?php endif; ?>
    </div>
  </section>

  <div class="g-seg ntf-tabs-deck" data-rv style="--d:.05s">
    <button type="button" class="ntf-tab-item active" id="tab-all" onclick="filterNotifs('all')">Semua<span class="ntf-tab-badge" id="badge-all-count"><?= count($notifications) ?></span></button>
    <button type="button" class="ntf-tab-item" id="tab-unread" onclick="filterNotifs('unread')">Belum dibaca<span class="ntf-tab-badge" id="badge-unread-count"><?= $unread_count ?></span></button>
  </div>

  <?php if (empty($notifications)): ?>
    <div class="g-panel g-empty" data-rv>
      <img src="/assets/img/icons3d/bell.png" alt="">
      <b>Papan kabar masih kosong</b>
      <p>Belum ada pengumuman untuk akunmu saat ini.</p>
      <a href="/home" class="g-btn sm" style="margin-top:12px"><i class="ph-bold ph-house"></i>Kembali ke Home</a>
    </div>
  <?php else: ?>
    <div class="ntf-list" id="notif-list-container">
      <?php foreach ($notifications as $ni => $n):
        $cfg = $type_cfg[$n['type']] ?? $type_cfg['info'];
        $is_read = !empty($n['is_read']);
      ?>
        <div class="ntf-item <?= $is_read ? 'read' : 'unread' ?>" data-id="<?= (int)$n['id'] ?>" data-read="<?= $is_read ? '1' : '0' ?>" data-rv style="--d:<?= number_format(min(0.3, 0.04 * $ni), 2) ?>s">
          <span class="g-slot sm">
            <?php if (!empty($n['icon'])): ?><span class="ntf-emoji"><?= htmlspecialchars($n['icon']) ?></span>
            <?php else: ?><img src="/assets/img/icons3d/<?= $cfg['icon3d'] ?>.png" alt=""><?php endif; ?>
          </span>

          <div class="ntf-content">
            <div class="ntf-header-meta">
              <div class="ntf-badge-group">
                <?php if (!$is_read): ?><span class="ntf-unread-dot" title="Belum dibaca"></span><?php endif; ?>
                <span class="ntf-type-tag" style="color:<?= $cfg['color'] ?>;background:<?= $cfg['badge'] ?>"><?= htmlspecialchars($cfg['label']) ?></span>
              </div>
              <span class="ntf-time-stamp"><?= date('d M, H:i', strtotime($n['created_at'])) ?></span>
            </div>
            <div class="ntf-title"><?= htmlspecialchars($n['title']) ?></div>
            <div class="ntf-message"><?= nl2br(htmlspecialchars($n['message'])) ?></div>
            <?php if (!empty($n['action_url']) && !empty($n['action_text'])): ?>
              <a href="<?= htmlspecialchars($n['action_url']) ?>" class="g-btn ntf-action-link"><?= htmlspecialchars($n['action_text']) ?><i class="ph-bold ph-arrow-right"></i></a>
            <?php endif; ?>
          </div>

          <?php if (!$is_read): ?>
            <button type="button" class="btn-mark-single" onclick="markRead(<?= (int)$n['id'] ?>, this)" title="Tandai sudah dibaca"><i class="ph-bold ph-check"></i></button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div id="filter-empty-message" class="g-panel g-empty" style="display:none;">
      <img src="/assets/img/icons3d/star.png" alt="">
      <b>Semua kabar sudah dibaca!</b>
      <p>Kamu selalu up to date dengan kabar PanenCuan.</p>
      <button type="button" onclick="filterNotifs('all')" class="g-btn sm" style="margin-top:12px">Tampilkan semua</button>
    </div>
  <?php endif; ?>

</div>

<script>
const CSRF = '<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>';
let currentFilter = 'all';

function filterNotifs(filterType) {
  currentFilter = filterType;
  const tabAll = document.getElementById('tab-all');
  const tabUnread = document.getElementById('tab-unread');
  const items = document.querySelectorAll('.ntf-item');
  const emptyMsg = document.getElementById('filter-empty-message');

  if (filterType === 'unread') {
    tabUnread.classList.add('active');
    tabAll.classList.remove('active');
  } else {
    tabAll.classList.add('active');
    tabUnread.classList.remove('active');
  }

  let visibleCount = 0;
  items.forEach(item => {
    const isRead = item.getAttribute('data-read') === '1';
    if (filterType === 'unread' && isRead) {
      item.style.display = 'none';
    } else {
      item.style.display = 'flex';
      visibleCount++;
    }
  });

  if (emptyMsg) {
    emptyMsg.style.display = (visibleCount === 0) ? 'block' : 'none';
  }
}

async function markRead(id, btn) {
  const item = btn.closest('.ntf-item');
  const fd = new FormData();
  fd.append('action', 'mark_read');
  fd.append('id', id);
  fd.append('_csrf', CSRF);
  fd.append('csrf_token', CSRF);

  try {
    const res = await fetch('/notif_action', {
      method: 'POST',
      headers: { 'X-CSRF-Token': CSRF },
      body: fd
    });
    const data = await res.json();
    if (data.ok) {
      item.classList.remove('unread');
      item.classList.add('read');
      item.setAttribute('data-read', '1');

      // Hilangkan unread dot
      const dot = item.querySelector('.ntf-unread-dot');
      if (dot) dot.remove();

      // Hilangkan tombol check
      btn.remove();

      // Kurangi counter
      updateUnreadCounter(data.count ?? null);

      // Jika saat ini sedang di tab unread, sembunyikan item
      if (currentFilter === 'unread') {
        item.style.display = 'none';
        const remainingUnread = document.querySelectorAll('.ntf-item[data-read="0"]');
        if (remainingUnread.length === 0) {
          const emptyMsg = document.getElementById('filter-empty-message');
          if (emptyMsg) emptyMsg.style.display = 'block';
        }
      }
    }
  } catch (err) {
    console.error('Error markRead:', err);
  }
}

async function markAllRead() {
  const fd = new FormData();
  fd.append('action', 'mark_all');
  fd.append('_csrf', CSRF);
  fd.append('csrf_token', CSRF);

  try {
    const res = await fetch('/notif_action', {
      method: 'POST',
      headers: { 'X-CSRF-Token': CSRF },
      body: fd
    });
    const data = await res.json();
    if (data.ok) {
      // Ubah semua item menjadi read di UI
      document.querySelectorAll('.ntf-item').forEach(item => {
        item.classList.remove('unread');
        item.classList.add('read');
        item.setAttribute('data-read', '1');
        const dot = item.querySelector('.ntf-unread-dot');
        if (dot) dot.remove();
        const btn = item.querySelector('.btn-mark-single');
        if (btn) btn.remove();
      });

      updateUnreadCounter(0);

      // Sembunyikan tombol Tandai Semua Dibaca
      const btnAll = document.getElementById('btn-mark-all');
      if (btnAll) btnAll.remove();

      if (currentFilter === 'unread') {
        filterNotifs('unread');
      }
    }
  } catch (err) {
    console.error('Error markAllRead:', err);
  }
}

function updateUnreadCounter(explicitCount) {
  let count = explicitCount;
  if (count === null || count === undefined) {
    count = document.querySelectorAll('.ntf-item[data-read="0"]').length;
  }

  const badgeUnread = document.getElementById('badge-unread-count');
  if (badgeUnread) badgeUnread.innerText = count;

  const countBanner = document.getElementById('unread-count-banner');
  if (countBanner) countBanner.innerText = count;

  const pillTxt = document.getElementById('unread-pill-txt');
  if (pillTxt) pillTxt.innerText = count + ' Baru';

  const subText = document.getElementById('banner-sub-text');
  if (subText && count <= 0) {
    subText.innerHTML = 'Seluruh pesan telah dibaca';
    const btnAll = document.getElementById('btn-mark-all');
    if (btnAll) btnAll.remove();
  }
}
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
