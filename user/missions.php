<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Aturan: hadiah uang misi tidak boleh receh, minimal Rp 1.500 (berlaku juga bila nilai di DB lebih kecil)
const MISSION_MIN_REWARD = 1500;

// Misi fitur mancing (sudah dihapus) disembunyikan walau masih aktif di database
const FISHING_MISSION_SLUGS = ['daily_cast_5', 'daily_sell_fish', 'weekly_catch_30', 'weekly_rare_3', 'lifetime_100_fish', 'lifetime_jackpot'];

// ── Daftar misi dari database (tabel `missions`, satu sumber untuk halaman ini, riwayat & console) ──
$ALL_MISSIONS = [];
foreach ($pdo->query("SELECT * FROM missions WHERE is_active = 1 ORDER BY FIELD(category, 'daily', 'weekly', 'lifetime'), sort_order, id") as $r) {
    if (in_array((string)$r['slug'], FISHING_MISSION_SLUGS, true)) continue;
    $ALL_MISSIONS[] = [
        'slug'     => (string)$r['slug'],
        'category' => (string)$r['category'],
        'title'    => (string)$r['title'],
        'desc'     => (string)$r['description'],
        'target'   => max(1, (int)$r['target_value']),
        'reward'   => max(MISSION_MIN_REWARD, (float)$r['reward_amount']),
        'icon'     => (string)($r['icon'] ?: 'ph-target'),
    ];
}

/** Jenis misi → warna ikon & tujuan tombol "Mulai" */
function mission_kind(string $slug): array {
    return match (true) {
        str_contains($slug, 'ref')                                   => ['ref', '/referral'],
        str_contains($slug, '_wd')                                   => ['wd', '/withdraw'],
        str_contains($slug, 'upgrade')                               => ['up', '/upgrade'],
        str_contains($slug, 'active')                                => ['mix', '/home'],
        (bool)preg_match('/plant|harvest/', $slug)                    => ['fish', '/farm'],
        default                                                      => ['video', '/videos'],
    };
}

// ── Progress real-time ─────────────────────────────────────────────────
function get_progress(PDO $pdo, array $user, array $mission): int {
    $uid = (int)$user['id'];
    $q = static function (string $sql, array $p) use ($pdo): int {
        try { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); } catch (\Throwable) { return 0; }
    };
    $week  = "YEARWEEK(%s, 1) = YEARWEEK(CURDATE(), 1)";
    switch ($mission['slug']) {
        // Video
        case 'daily_watch_3':
        case 'daily_watch_5':
        case 'daily_watch_6':
            return $q("SELECT COUNT(*) FROM watch_history WHERE user_id = ? AND DATE(watched_at) = CURDATE()", [$uid]);
        case 'weekly_watch_20':
            return $q("SELECT COUNT(*) FROM watch_history WHERE user_id = ? AND " . sprintf($week, 'watched_at'), [$uid]);
        case 'lifetime_100_videos':
            return $q("SELECT COUNT(*) FROM watch_history WHERE user_id = ?", [$uid]);
        // Sawah Panen Cuan (farm_logs)
        case 'daily_plant_3':
            return $q("SELECT COUNT(*) FROM farm_logs WHERE user_id = ? AND action = 'plant' AND DATE(created_at) = CURDATE()", [$uid]);
        case 'daily_sell_harvest':
            return $q("SELECT COUNT(*) FROM farm_logs WHERE user_id = ? AND action = 'sell_all' AND DATE(created_at) = CURDATE()", [$uid]);
        case 'weekly_harvest_20':
            return $q("SELECT COUNT(*) FROM farm_logs WHERE user_id = ? AND action = 'harvest' AND " . sprintf($week, 'created_at'), [$uid]);
        case 'lifetime_harvest_100':
            return $q("SELECT COUNT(*) FROM farm_logs WHERE user_id = ? AND action = 'harvest'", [$uid]);
        // Gabungan: hari berbeda dengan aktivitas nonton ATAU bertani
        case 'weekly_active_5':
            return $q("SELECT COUNT(*) FROM (
                         SELECT DATE(watched_at) d FROM watch_history WHERE user_id = ? AND " . sprintf($week, 'watched_at') . "
                         UNION SELECT DATE(created_at) FROM farm_logs WHERE user_id = ? AND " . sprintf($week, 'created_at') . "
                       ) x", [$uid, $uid]);
        // Lainnya
        case 'lifetime_first_ref':
        case 'lifetime_5_refs':
            return $q("SELECT COUNT(*) FROM users WHERE TRIM(UPPER(referred_by)) = TRIM(UPPER(?))", [(string)$user['referral_code']]);
        case 'lifetime_first_wd':
            return $q("SELECT COUNT(*) FROM withdrawals WHERE user_id = ?", [$uid]);
        case 'lifetime_upgrade':
            return !empty($user['membership_id']) ? 1 : 0;
    }
    return 0;
}

function get_period_key(string $category): ?string {
    if ($category === 'daily')  return date('Y-m-d');
    if ($category === 'weekly') return date('o-\WW');
    return null; // lifetime
}

function is_claimed(PDO $pdo, int $user_id, string $slug, ?string $period_key): bool {
    $s = $pdo->prepare("SELECT 1 FROM user_missions WHERE user_id = ? AND mission_slug = ? AND period_key <=> ? AND claimed_at IS NOT NULL LIMIT 1");
    $s->execute([$user_id, $slug, $period_key]);
    return (bool)$s->fetchColumn();
}

// ── Klaim hadiah ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'claim_mission') {
    header('Content-Type: application/json');
    if (!csrf_verify()) { echo json_encode(['ok' => false, 'msg' => 'Sesi kedaluwarsa, muat ulang halaman.']); exit; }

    $slug = trim((string)($_POST['slug'] ?? ''));
    $mission = null;
    foreach ($ALL_MISSIONS as $m) if ($m['slug'] === $slug) { $mission = $m; break; }
    if (!$mission) { echo json_encode(['ok' => false, 'msg' => 'Misi tidak ditemukan.']); exit; }

    $period   = get_period_key($mission['category']);
    $progress = get_progress($pdo, $user, $mission);
    if ($progress < $mission['target']) {
        echo json_encode(['ok' => false, 'msg' => "Belum selesai ({$progress}/{$mission['target']})."]); exit;
    }

    try {
        $pdo->beginTransaction();
        // Kunci baris user agar klik ganda tidak membayar dua kali
        $pdo->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE")->execute([$user['id']]);
        if (is_claimed($pdo, (int)$user['id'], $slug, $period)) {
            $pdo->rollBack();
            echo json_encode(['ok' => false, 'msg' => 'Misi ini sudah diklaim.']); exit;
        }
        $pdo->prepare("INSERT INTO user_missions (user_id, mission_slug, progress, completed_at, claimed_at, period_key) VALUES (?, ?, ?, NOW(), NOW(), ?)")
            ->execute([$user['id'], $slug, $progress, $period]);
        $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ? WHERE id = ?")
            ->execute([$mission['reward'], $user['id']]);
        $pdo->commit();

        $tg  = "🎯 <b>MEMBER KLAIM MISI!</b>\n";
        $tg .= "Username: <code>" . htmlspecialchars((string)$user['username']) . "</code>\n";
        $tg .= "Misi: <b>" . htmlspecialchars($mission['title']) . "</b>\n";
        $tg .= "Reward: " . format_rp($mission['reward']);
        send_telegram_notif($pdo, $tg, [], 'misi');

        $msg = $mission['reward'] > 0 ? 'Hadiah +' . format_rp($mission['reward']) . ' masuk ke Saldo Tarik' : 'Misi selesai';
        echo json_encode(['ok' => true, 'msg' => $msg . '!', 'reward' => $mission['reward']]);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Gagal mengklaim, coba lagi.']);
    }
    exit;
}

// ── Data tampilan ──────────────────────────────────────────────────────
$groups = ['daily' => [], 'weekly' => [], 'lifetime' => []];
$ready_total = 0.0; $ready_count = 0;
foreach ($ALL_MISSIONS as $m) {
    $period   = get_period_key($m['category']);
    $progress = get_progress($pdo, $user, $m);
    $claimed  = is_claimed($pdo, (int)$user['id'], $m['slug'], $period);
    $done     = $progress >= $m['target'];
    [$kind, $go] = mission_kind($m['slug']);
    if ($done && !$claimed) { $ready_total += $m['reward']; $ready_count++; }
    $groups[$m['category']][] = $m + [
        'progress' => min($progress, $m['target']), 'claimed' => $claimed, 'done' => $done, 'kind' => $kind, 'go' => $go,
    ];
}
$TABS = ['daily' => ['Harian', 'ph-sun', 'Reset tiap 00:00'], 'weekly' => ['Mingguan', 'ph-calendar-blank', 'Reset tiap Senin'], 'lifetime' => ['Pencapaian', 'ph-trophy', 'Sekali seumur akun']];
$active_tab = 'daily';
foreach ($groups as $cat => $list) { foreach ($list as $m) if ($m['done'] && !$m['claimed']) { $active_tab = $cat; break 2; } }

$pageTitle  = 'Misi Harian';
$activePage = 'missions';
require dirname(__DIR__) . '/partials/header.php';
?>
<?php
$KIND_ICON = ['video' => 'watch', 'fish' => 'padi', 'mix' => 'flame', 'ref' => 'referral', 'wd' => 'moneybag', 'up' => 'upgrade'];
$TAB_ICON  = ['daily' => 'checkin', 'weekly' => 'missions', 'lifetime' => 'star'];
?>
<style>
/* ══ MISI — Papan Quest · Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.ms-chest { position: relative; flex-shrink: 0; width: 84px; height: 84px; }
.ms-chest img { width: 100%; height: 100%; object-fit: contain; filter: drop-shadow(0 8px 8px rgba(120, 70, 10, .3)); }
.ms-chest.ready img { animation: msShake 2.4s ease-in-out infinite; }
.ms-chest.ready::before { content: ''; position: absolute; inset: -6px; border-radius: 50%; background: radial-gradient(circle, rgba(255, 230, 120, .85), transparent 65%); animation: gGlow 2s ease-in-out infinite; }
@keyframes msShake { 0%, 70%, 100% { transform: none; } 74% { transform: rotate(-8deg) scale(1.05); } 78% { transform: rotate(8deg) scale(1.05); } 82% { transform: rotate(-5deg); } 86% { transform: rotate(4deg); } }
.ms-sum { display: flex; align-items: center; gap: 10px; margin-top: 12px; padding: 10px 12px; border-radius: 18px; background: rgba(255, 255, 255, .75); box-shadow: 0 0 0 2px rgba(255, 255, 255, .85), 0 8px 18px rgba(120, 70, 10, .12); }
.ms-sum .t { flex: 1; min-width: 0; }
.ms-sum small { display: block; font-size: 11px; font-weight: 900; color: #8a5a00; }
.ms-sum b { display: block; font-family: var(--pc-font); font-size: 22px; font-weight: 700; line-height: 1.1; color: #15803d; }
.ms-sum .g-chip { background: #fff3c4; color: #8a5a00; }

.ms-tab { position: relative; min-width: 0; overflow: hidden; }
@media (max-width: 350px) { .ms-tab img { display: none; } }
.ms-tab img { width: 22px; height: 22px; object-fit: contain; margin: -4px 0; }
.ms-tab .dot { position: absolute; top: 3px; right: 6px; width: 10px; height: 10px; border-radius: 50%; background: #f43f5e; box-shadow: 0 0 0 2px #fff; animation: gGlow 1.4s ease-in-out infinite; }
.ms-panel { display: none; }
.ms-panel.on { display: flex; flex-direction: column; gap: 9px; animation: msIn .4s var(--pc-out) both; }
@keyframes msIn { from { opacity: 0; transform: translateY(10px); } }
.ms-note { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin: 0 4px; font-size: 11.5px; font-weight: 800; color: var(--pc-muted); }
.ms-note span { display: inline-flex; align-items: center; gap: 4px; }

.ms-row { position: relative; display: flex; align-items: center; gap: 10px; padding: 10px; border-radius: 20px; background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 0 0 2px #f6e2b0, 0 4px 0 #ecd59c, 0 10px 18px rgba(120, 70, 10, .07); animation: msIn .45s var(--pc-out) both; animation-delay: calc(var(--i) * .05s); }
.ms-row.is-ready { background: linear-gradient(180deg, #fff7d1, #fffdf3); box-shadow: 0 0 0 2px #fcd34d, 0 4px 0 #e9b949, 0 10px 22px rgba(246, 183, 10, .2); }
.ms-row.is-claimed { background: #f6f8f2; box-shadow: 0 0 0 2px #dfe8d3, 0 4px 0 #d3dec5; }
.ms-row.is-claimed .g-slot img, .ms-row.is-claimed .ms-body { opacity: .55; }
.ms-body { flex: 1; min-width: 0; }
.ms-title { font-size: 13px; font-weight: 900; line-height: 1.25; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ms-desc { margin-top: 1px; font-size: 11px; font-weight: 700; color: var(--pc-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ms-prog { display: flex; align-items: center; gap: 7px; margin-top: 6px; }
.ms-prog .g-bar { flex: 1; height: 9px; }
.ms-prog b { flex-shrink: 0; font-size: 11px; font-weight: 900; color: #15803d; }
.ms-side { flex-shrink: 0; display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
.ms-btn.g-btn { min-height: 32px; padding: 0 11px; font-size: 13px; border-radius: 11px; }
.ms-btn.claim { animation: msBounce 1.4s var(--pc-ease) infinite; }
@keyframes msBounce { 0%, 60%, 100% { transform: none; } 70% { transform: translateY(-4px) scale(1.04); } 80% { transform: none; } }
.ms-btn.done { display: inline-flex; align-items: center; gap: 3px; font-family: var(--pc-font); font-size: 13px; font-weight: 700; color: #15803d; }
@keyframes msSpin { to { transform: rotate(360deg); } }

/* Hujan koin saat klaim */
.ms-coin { position: fixed; z-index: 10002; width: 26px; height: 26px; pointer-events: none; animation: msCoin .9s var(--pc-out) forwards; }
@keyframes msCoin { to { transform: translate(var(--x), var(--y)) rotate(var(--r)) scale(.6); opacity: 0; } }
@media (prefers-reduced-motion: reduce) { .ms-btn.claim, .ms-chest.ready img, .ms-row, .ms-panel.on { animation: none; } }
</style>

<div class="g-page">

  <section class="g-hero" data-rv>
    <span class="g-sun"></span><span class="g-cloud c1"></span><span class="g-cloud c2"></span>
    <div class="g-hero-row">
      <div style="flex:1;min-width:0">
        <h1>Papan Quest</h1>
        <p>Selesaikan quest, klaim peti hadiah ke Saldo Tarik.</p>
      </div>
      <div class="ms-chest <?= $ready_count > 0 ? 'ready' : '' ?>" id="ms-chest"><img src="/assets/img/icons3d/<?= $ready_count > 0 ? 'chest_open' : 'chest' ?>.png" alt=""></div>
    </div>
    <div class="ms-sum">
      <span class="g-slot sm"><img src="/assets/img/icons3d/coins.png" alt=""></span>
      <div class="t"><small>Hadiah siap diklaim</small><b id="ms-ready-amt"><?= format_rp($ready_total) ?></b></div>
      <span class="g-chip" id="ms-ready-n"><?= $ready_count ?> misi</span>
    </div>
  </section>

  <div class="g-seg" role="tablist" data-rv style="--d:.05s">
    <?php foreach ($TABS as $cat => [$lbl]):
      $hasReady = (bool)array_filter($groups[$cat], fn($m) => $m['done'] && !$m['claimed']); ?>
      <button type="button" class="ms-tab <?= $cat === $active_tab ? 'on' : '' ?>" data-tab="<?= $cat ?>" onclick="msTab('<?= $cat ?>')">
        <img src="/assets/img/icons3d/<?= $TAB_ICON[$cat] ?>.png" alt=""><?= $lbl ?><?php if ($hasReady): ?><span class="dot"></span><?php endif; ?>
      </button>
    <?php endforeach; ?>
  </div>

  <?php foreach ($TABS as $cat => [$lbl, $ic, $note]):
    $n_claimed = count(array_filter($groups[$cat], fn($m) => $m['claimed'])); ?>
  <div class="ms-panel <?= $cat === $active_tab ? 'on' : '' ?>" id="ms-<?= $cat ?>">
    <div class="ms-note"><span><i class="ph-bold ph-clock-counter-clockwise"></i><?= $note ?></span><span class="g-chip"><?= $n_claimed ?>/<?= count($groups[$cat]) ?> beres</span></div>
    <?php if (empty($groups[$cat])): ?>
      <div class="g-panel g-empty"><img src="/assets/img/icons3d/missions.png" alt=""><b>Belum ada quest</b><p>Quest baru segera hadir.</p></div>
    <?php endif; ?>
    <?php foreach ($groups[$cat] as $i => $m): $pct = (int)round($m['progress'] / $m['target'] * 100);
      $state = $m['claimed'] ? 'is-claimed' : ($m['done'] ? 'is-ready' : ''); ?>
    <div class="ms-row <?= $state ?>" id="mr-<?= htmlspecialchars($m['slug']) ?>" data-reward="<?= (float)$m['reward'] ?>" style="--i:<?= $i ?>">
      <span class="g-slot"><img src="/assets/img/icons3d/<?= $KIND_ICON[$m['kind']] ?? 'missions' ?>.png" alt=""><?php if ($m['claimed']): ?><span class="g-ok"><i class="ph-bold ph-check"></i></span><?php endif; ?></span>
      <div class="ms-body">
        <div class="ms-title"><?= htmlspecialchars($m['title']) ?></div>
        <div class="ms-desc"><?= htmlspecialchars($m['desc']) ?></div>
        <div class="ms-prog"><div class="g-bar <?= $m['done'] && !$m['claimed'] ? 'gold' : '' ?>"><i style="width:<?= $pct ?>%"></i></div><b><?= $m['progress'] ?>/<?= $m['target'] ?></b></div>
      </div>
      <div class="ms-side">
        <span class="g-coin"><img src="/assets/img/icons3d/coins.png" alt="">+<?= format_rp($m['reward']) ?></span>
        <?php if ($m['claimed']): ?>
          <span class="ms-btn done"><i class="ph-fill ph-check-circle"></i>Beres</span>
        <?php elseif ($m['done']): ?>
          <button type="button" class="ms-btn claim g-btn gold shine" onclick="claimMission('<?= htmlspecialchars($m['slug'], ENT_QUOTES) ?>', this)">Klaim</button>
        <?php else: ?>
          <a href="<?= $m['go'] ?>" class="ms-btn go g-btn">Mulai</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div>

<script>
const _csrf = '<?= csrf_token() ?>';
function msTab(cat) {
  document.querySelectorAll('.ms-tab').forEach((t) => t.classList.toggle('on', t.dataset.tab === cat));
  document.querySelectorAll('.ms-panel').forEach((p) => p.classList.toggle('on', p.id === 'ms-' + cat));
}
function msRefreshSummary() {
  let amt = 0, n = 0;
  document.querySelectorAll('.ms-btn.claim').forEach((b) => { n++; amt += +b.closest('.ms-row').dataset.reward || 0; });
  document.getElementById('ms-ready-amt').textContent = 'Rp ' + Math.round(amt).toLocaleString('id-ID');
  document.getElementById('ms-ready-n').textContent = n + ' misi';
  document.querySelectorAll('.ms-tab').forEach((t) => {
    const has = document.querySelector('#ms-' + t.dataset.tab + ' .ms-btn.claim');
    const dot = t.querySelector('.dot'); if (!has && dot) dot.remove();
  });
  if (!n) {
    const chest = document.getElementById('ms-chest');
    if (chest) { chest.classList.remove('ready'); chest.querySelector('img').src = '/assets/img/icons3d/chest.png'; }
  }
}
function msCoinBurst(el) {
  if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const r = el.getBoundingClientRect();
  for (let i = 0; i < 12; i++) {
    const c = document.createElement('img');
    c.src = '/assets/img/icons3d/coins.png'; c.className = 'ms-coin';
    c.style.left = (r.left + r.width / 2 - 13) + 'px'; c.style.top = (r.top + r.height / 2 - 13) + 'px';
    const a = Math.random() * Math.PI * 2, d = 50 + Math.random() * 70;
    c.style.setProperty('--x', Math.cos(a) * d + 'px'); c.style.setProperty('--y', (Math.sin(a) * d - 40) + 'px'); c.style.setProperty('--r', (Math.random() * 360) + 'deg');
    document.body.appendChild(c); setTimeout(() => c.remove(), 950);
  }
}
function claimMission(slug, btn) {
  btn.disabled = true;
  btn.innerHTML = '<i class="ph-bold ph-spinner-gap" style="animation:msSpin .8s linear infinite"></i>';
  const fd = new FormData();
  fd.append('action', 'claim_mission'); fd.append('slug', slug); fd.append('_csrf', _csrf);
  fetch(location.href, { method: 'POST', body: fd })
    .then((r) => r.json())
    .then((data) => {
      if (data.ok) {
        const row = document.getElementById('mr-' + slug);
        if (row) {
          row.classList.remove('is-ready'); row.classList.add('is-claimed');
          row.querySelector('.g-bar')?.classList.remove('gold');
          row.querySelector('.g-slot')?.insertAdjacentHTML('beforeend', '<span class="g-ok"><i class="ph-bold ph-check"></i></span>');
        }
        msCoinBurst(btn);
        const done = document.createElement('span');
        done.className = 'ms-btn done'; done.innerHTML = '<i class="ph-fill ph-check-circle"></i>Beres';
        btn.replaceWith(done);
        msRefreshSummary();
        if (typeof nToast !== 'undefined') nToast(data.msg, 'success');
      } else {
        btn.disabled = false; btn.textContent = 'Klaim';
        if (typeof nToast !== 'undefined') nToast(data.msg, 'error');
      }
    })
    .catch(() => {
      btn.disabled = false; btn.textContent = 'Klaim';
      if (typeof nToast !== 'undefined') nToast('Koneksi terputus.', 'error');
    });
}
</script>
<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
