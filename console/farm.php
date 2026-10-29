<?php
declare(strict_types=1);
/**
 * console/farm.php — Console › Sawah Panen Cuan
 *
 * Satu halaman untuk mengelola ekonomi farm: pengaturan (tabel settings),
 * katalog bibit (farm_crops), barang toko (farm_items), ringkasan arus uang,
 * pemain teratas, log aksi pemain (farm_logs) & audit admin (farm_admin_logs).
 */
require_once __DIR__ . '/auth.php';
staff_require('farm');
require_once dirname(__DIR__) . '/lib/farm.php';
farm_ensure_schema($pdo);

/* ───────────────────────── AKSI (POST → redirect) ───────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_enforce();
    $action = (string)($_POST['action'] ?? '');
    $flash = ''; $err = '';

    if ($action === 'settings') {
        $new = [
            'farm_enabled'        => isset($_POST['farm_enabled']) ? '1' : '0',
            'farm_free_plots'     => (string)max(1, min(FARM_TOTAL_PLOTS, (int)($_POST['farm_free_plots'] ?? 4))),
            'farm_plot_cost_base' => (string)max(0, round((float)($_POST['farm_plot_cost_base'] ?? 10000))),
            'farm_plot_cost_step' => (string)max(0, round((float)($_POST['farm_plot_cost_step'] ?? 5000))),
            'farm_pest_chance'    => (string)max(0, min(100, (int)($_POST['farm_pest_chance'] ?? 12))),
            'farm_barn_capacity'  => (string)max(5, min(10000, (int)($_POST['farm_barn_capacity'] ?? 30))),
        ];
        $old = farm_config($pdo);
        foreach ($new as $k => $v) setting_set($pdo, $k, $v);
        farm_admin_log($pdo, 'settings.save', ['from' => $old, 'to' => $new]);
        $flash = 'Pengaturan disimpan. Petak gratis & kapasitas lumbung hanya berlaku untuk petani baru.';

    } elseif ($action === 'crops') {
        $rows = [];
        foreach ((array)($_POST['crop'] ?? []) as $id => $in) {
            $in = (array)$in;
            $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 80);
            if ($id === 'new' && $name === '') continue; // baris tambah dibiarkan kosong
            $r = [
                'name'        => $name,
                'model'       => array_key_exists((string)($in['model'] ?? ''), FARM_MODELS) ? (string)$in['model'] : 'rice',
                'color'       => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($in['color'] ?? '')) ? strtolower((string)$in['color']) : '#facc15',
                'growth_secs' => max(5, min(604800, (int)($in['growth_secs'] ?? 60))),
                'seed_price'  => max(0, round((float)($in['seed_price'] ?? 0))),
                'yield_min'   => max(1, min(1000, (int)($in['yield_min'] ?? 1))),
                'yield_max'   => max(1, min(1000, (int)($in['yield_max'] ?? 1))),
                'unit_price'  => max(0, round((float)($in['unit_price'] ?? 0))),
                'xp'          => max(0, min(100000, (int)($in['xp'] ?? 0))),
                'min_level'   => max(1, min(999, (int)($in['min_level'] ?? 1))),
                'sort_order'  => (int)($in['sort_order'] ?? 0),
                'is_active'   => isset($in['is_active']) ? 1 : 0,
            ];
            if ($name === '') { $err = 'Nama bibit wajib diisi.'; break; }
            if ($r['yield_max'] < $r['yield_min']) { $err = "Hasil maksimum $name harus ≥ hasil minimum."; break; }
            $rows[$id] = $r;
        }
        if (!$err) {
            $cols = array_keys(reset($rows) ?: ['name' => '']);
            $upd = $pdo->prepare("UPDATE farm_crops SET " . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . " WHERE id = ?");
            $pdo->beginTransaction();
            try {
                foreach ($rows as $id => $r) {
                    if ($id === 'new') {
                        $code = trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower($r['name'])), '_') ?: 'bibit';
                        $base = substr($code, 0, 24); $n = 1;
                        $chk = $pdo->prepare("SELECT 1 FROM farm_crops WHERE code = ?");
                        while (true) { $chk->execute([$code]); if (!$chk->fetchColumn()) break; $code = $base . '_' . (++$n); }
                        $pdo->prepare("INSERT INTO farm_crops (code, " . implode(', ', $cols) . ") VALUES (?" . str_repeat(', ?', count($cols)) . ")")
                            ->execute([$code, ...array_values($r)]);
                    } else {
                        $upd->execute([...array_values($r), (int)$id]);
                    }
                }
                $pdo->commit();
                farm_admin_log($pdo, 'crops.save', $rows);
                $flash = 'Katalog bibit disimpan. Berlaku untuk tanam & penjualan berikutnya.';
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $err = 'Gagal menyimpan katalog bibit.';
            }
        }

    } elseif ($action === 'items') {
        $upd = $pdo->prepare("UPDATE farm_items SET name = ?, price = ?, effect_value = ?, description = ?, is_active = ?, sort_order = ? WHERE id = ?");
        $saved = [];
        foreach ((array)($_POST['item'] ?? []) as $id => $in) {
            $in = (array)$in;
            $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 80);
            if ($name === '') { $err = 'Nama barang wajib diisi.'; break; }
            $r = [$name, max(0, round((float)($in['price'] ?? 0))), max(0, min(10000, (int)($in['effect_value'] ?? 0))),
                  mb_substr(trim((string)($in['description'] ?? '')), 0, 190), isset($in['is_active']) ? 1 : 0, (int)($in['sort_order'] ?? 0)];
            $saved[(int)$id] = $r;
        }
        if (!$err) {
            foreach ($saved as $id => $r) $upd->execute([...$r, $id]);
            farm_admin_log($pdo, 'items.save', $saved);
            $flash = 'Barang Toko Tani disimpan.';
        }
    }

    $_SESSION['farm_console_flash'] = $err ? ['error', $err] : ['success', $flash];
    redirect('/console/farm.php' . (isset($_POST['tab']) ? '#' . preg_replace('/[^a-z]/', '', (string)$_POST['tab']) : ''));
}

[$flashType, $flash] = $_SESSION['farm_console_flash'] ?? ['', ''];
unset($_SESSION['farm_console_flash']);

/* ───────────────────────── DATA ───────────────────────── */
$cfg   = farm_config($pdo);
$crops = $pdo->query("SELECT * FROM farm_crops ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
$items = $pdo->query("SELECT * FROM farm_items ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);

$sum = fn(string $sql) => (float)$pdo->query($sql)->fetchColumn();
$stats = [
    'players'   => (int)$sum("SELECT COUNT(*) FROM farm_users"),
    'active7'   => (int)$sum("SELECT COUNT(DISTINCT user_id) FROM farm_logs WHERE created_at >= NOW() - INTERVAL 7 DAY"),
    'growing'   => (int)$sum("SELECT COUNT(*) FROM farm_plots WHERE status = 'growing'"),
    'barn_qty'  => (int)$sum("SELECT COALESCE(SUM(qty), 0) FROM farm_inventory WHERE kind = 'harvest'"),
    'barn_val'  => $sum("SELECT COALESCE(SUM(i.qty * c.unit_price), 0) FROM farm_inventory i JOIN farm_crops c ON c.id = i.ref_id WHERE i.kind = 'harvest'"),
    'in_all'    => $sum("SELECT COALESCE(SUM(amount), 0) FROM farm_logs WHERE wallet = 'dep'"),
    'out_all'   => $sum("SELECT COALESCE(SUM(amount), 0) FROM farm_logs WHERE wallet = 'wd'"),
    'in_today'  => $sum("SELECT COALESCE(SUM(amount), 0) FROM farm_logs WHERE wallet = 'dep' AND created_at >= CURDATE()"),
    'out_today' => $sum("SELECT COALESCE(SUM(amount), 0) FROM farm_logs WHERE wallet = 'wd' AND created_at >= CURDATE()"),
];
// Liabilitas = yang sudah dibayar + isi lumbung yang belum dijual
$margin = $stats['in_all'] - $stats['out_all'] - $stats['barn_val'];

// Pemain teratas
$players = $pdo->query("
    SELECT f.user_id, u.username, f.xp, f.unlocked_plots, f.barn_capacity,
           (SELECT COALESCE(SUM(qty), 0) FROM farm_inventory i WHERE i.user_id = f.user_id AND i.kind = 'harvest') AS barn_used,
           (SELECT COALESCE(SUM(amount), 0) FROM farm_logs l WHERE l.user_id = f.user_id AND l.wallet = 'dep') AS spent,
           (SELECT COALESCE(SUM(amount), 0) FROM farm_logs l WHERE l.user_id = f.user_id AND l.wallet = 'wd') AS earned
    FROM farm_users f LEFT JOIN users u ON u.id = f.user_id
    ORDER BY f.xp DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);

// Log aksi pemain (filter user & aksi)
$fq = trim((string)($_GET['u'] ?? ''));
$fa = trim((string)($_GET['a'] ?? ''));
$where = []; $args = [];
if ($fq !== '') {
    if (ctype_digit($fq)) { $where[] = 'l.user_id = ?'; $args[] = (int)$fq; }
    else { $where[] = 'u.username = ?'; $args[] = $fq; }
}
if ($fa !== '') { $where[] = 'l.action = ?'; $args[] = $fa; }
$s = $pdo->prepare("SELECT l.*, u.username FROM farm_logs l LEFT JOIN users u ON u.id = l.user_id"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY l.id DESC LIMIT 300");
$s->execute($args);
$logs = $s->fetchAll(PDO::FETCH_ASSOC);
$logActions = $pdo->query("SELECT DISTINCT action FROM farm_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$audit = $pdo->query("SELECT * FROM farm_admin_logs ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

$cropName = array_column($crops, 'name', 'id');
$itemName = array_column($items, 'name', 'id');
$ACTION_LABEL = ['plant' => 'Tanam', 'harvest' => 'Panen', 'speed_up' => 'Pupuk Kilat', 'cure' => 'Pestisida', 'sell_all' => 'Jual Semua',
                 'unlock_plot' => 'Buka Petak', 'buy_seed' => 'Beli Bibit', 'buy_item' => 'Beli Barang'];

/** Ringkasan ekonomi satu bibit untuk kolom analisis. */
function farm_console_econ(array $c): array {
    $avgYield = ((int)$c['yield_min'] + (int)$c['yield_max']) / 2;
    $profit = $avgYield * (float)$c['unit_price'] - (float)$c['seed_price'];
    $roi = (float)$c['seed_price'] > 0 ? $profit / (float)$c['seed_price'] * 100 : null;
    $perHour = $profit / max(5, (int)$c['growth_secs']) * 3600;
    return [$profit, $roi, $perHour];
}
function farm_console_dur(int $s): string {
    if ($s < 60) return $s . ' dtk';
    if ($s < 3600) return round($s / 60, 1) . ' mnt';
    return round($s / 3600, 1) . ' jam';
}

$pageTitle  = 'Sawah Panen Cuan';
$activePage = 'farm';
require __DIR__ . '/partials/header.php';
?>
<style>
.fm-muted { color: #64748b; font-size: 12px; }
.fm-num { font-variant-numeric: tabular-nums; }
.fm-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 18px; }
.fm-tab { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 10px; border: 1px solid var(--border-color, #1f2235); background: #121524; color: #94a3b8; font-size: 12.5px; font-weight: 600; text-decoration: none; }
.fm-tab:hover { color: #f8fafc; border-color: rgba(52, 211, 153, .4); }
.fm-pill { display: inline-block; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; border: 1px solid; white-space: nowrap; }
.fm-table input.c-form-control, .fm-table select.c-form-control { padding: 5px 7px; font-size: 12.5px; }
.fm-table td { vertical-align: middle; }
.fm-swatch { width: 38px; height: 30px; padding: 2px; border-radius: 8px; border: 1px solid #1f2235; background: #0f1117; }
.fm-good { color: #34d399; } .fm-warn { color: #fbbf24; } .fm-bad { color: #fb7185; }
.fm-new td { background: rgba(52, 211, 153, .05); }
section[id] { scroll-margin-top: 80px; }
</style>

<div class="mb-3">
  <h4 class="mb-1 text-white" style="font-weight:800">🌾 Sawah Panen Cuan</h4>
  <div class="fm-muted">Atur ekonomi farm: bibit, harga jual, barang Toko Tani, biaya petak & peluang hama. Pemain bermain di <a href="/farm" target="_blank" style="color:#6ee7b7">/farm</a>.</div>
</div>

<div class="fm-tabs">
  <a class="fm-tab" href="#ringkasan"><i class="ph-bold ph-chart-line-up"></i> Ringkasan</a>
  <a class="fm-tab" href="#pengaturan"><i class="ph-bold ph-gear-six"></i> Pengaturan</a>
  <a class="fm-tab" href="#bibit"><i class="ph-bold ph-plant"></i> Bibit &amp; Harga</a>
  <a class="fm-tab" href="#barang"><i class="ph-bold ph-storefront"></i> Toko Tani</a>
  <a class="fm-tab" href="#pemain"><i class="ph-bold ph-users-three"></i> Pemain</a>
  <a class="fm-tab" href="#log"><i class="ph-bold ph-list-bullets"></i> Log &amp; Audit</a>
</div>

<?php if ($flash !== ''): ?>
<div class="alert alert-<?= $flashType === 'error' ? 'danger' : 'success' ?> py-2 px-3" style="font-size:13px;border-radius:10px"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>
<?php if (!$cfg['enabled']): ?>
<div class="alert alert-warning py-2 px-3" style="font-size:13px;border-radius:10px">Farm sedang <b>nonaktif</b>: pemain dialihkan ke Beranda dan API menolak semua aksi.</div>
<?php endif; ?>

<!-- ── RINGKASAN ── -->
<section id="ringkasan" class="row g-3 mb-3">
  <?php
  $tiles = [
      ['Petani', number_format($stats['players'], 0, ',', '.'), 'ph-users-three', '#22d3ee', number_format($stats['active7'], 0, ',', '.') . ' aktif 7 hari · ' . $stats['growing'] . ' petak tumbuh'],
      ['Masuk (Saldo Beli)', format_rp($stats['in_all']), 'ph-arrow-circle-down', '#34d399', 'Hari ini ' . format_rp($stats['in_today'])],
      ['Keluar (Saldo Tarik)', format_rp($stats['out_all']), 'ph-arrow-circle-up', '#fb7185', 'Hari ini ' . format_rp($stats['out_today'])],
      ['Isi Lumbung (belum dijual)', format_rp($stats['barn_val']), 'ph-warehouse', '#fbbf24', number_format($stats['barn_qty'], 0, ',', '.') . ' hasil panen · kewajiban bayar'],
  ];
  foreach ($tiles as [$lbl, $val, $ic, $col, $sub]): ?>
  <div class="col-6 col-lg-3">
    <div class="c-stat h-100" style="border-top:3px solid <?= $col ?>">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div class="c-stat__lbl"><?= htmlspecialchars($lbl) ?></div>
        <div class="c-stat__icon" style="background:<?= $col ?>22;color:<?= $col ?>;font-size:18px"><i class="ph-bold <?= $ic ?>"></i></div>
      </div>
      <div class="c-stat__val fm-num" style="color:<?= $col ?>"><?= $val ?></div>
      <div class="fm-muted mt-1"><?= htmlspecialchars($sub) ?></div>
    </div>
  </div>
  <?php endforeach; ?>
  <div class="col-12">
    <div class="c-card"><div class="c-card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
      <div><div class="fm-muted">Selisih platform (masuk − keluar − isi lumbung)</div>
        <div class="fm-num <?= $margin >= 0 ? 'fm-good' : 'fm-bad' ?>" style="font-size:22px;font-weight:800"><?= format_rp($margin) ?></div></div>
      <div class="fm-muted" style="max-width:560px">Positif = pemain secara total membayar lebih banyak daripada yang mereka terima. Negatif berarti harga jual / hasil panen terlalu murah hati dibanding harga bibit &amp; barang. Perhatikan kolom <b>ROI</b> di tabel bibit.</div>
    </div></div>
  </div>
</section>

<!-- ── PENGATURAN ── -->
<section id="pengaturan" class="c-card mb-3">
  <div class="c-card-header"><b>Pengaturan Farm</b></div>
  <div class="c-card-body">
    <form method="post" class="row g-3">
      <?= csrf_field() ?><input type="hidden" name="action" value="settings"><input type="hidden" name="tab" value="pengaturan">
      <div class="col-12">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="fm-en" name="farm_enabled" <?= $cfg['enabled'] ? 'checked' : '' ?>>
          <label class="form-check-label text-white" for="fm-en" style="font-weight:700">Sawah Panen Cuan aktif untuk pengguna</label>
        </div>
      </div>
      <div class="col-6 col-lg-2"><label class="c-label">Petak gratis awal</label>
        <input type="number" min="1" max="<?= FARM_TOTAL_PLOTS ?>" class="c-form-control" name="farm_free_plots" value="<?= $cfg['free_plots'] ?>">
        <div class="fm-muted mt-1">Dari <?= FARM_TOTAL_PLOTS ?> petak. Untuk petani baru.</div></div>
      <div class="col-6 col-lg-2"><label class="c-label">Harga petak pertama (Rp)</label>
        <input type="number" min="0" step="500" class="c-form-control" name="farm_plot_cost_base" value="<?= (int)$cfg['plot_cost_base'] ?>" oninput="fmPlot()" id="fm-pb"></div>
      <div class="col-6 col-lg-2"><label class="c-label">Kenaikan per petak (Rp)</label>
        <input type="number" min="0" step="500" class="c-form-control" name="farm_plot_cost_step" value="<?= (int)$cfg['plot_cost_step'] ?>" oninput="fmPlot()" id="fm-ps"></div>
      <div class="col-6 col-lg-2"><label class="c-label">Peluang hama (%)</label>
        <input type="number" min="0" max="100" class="c-form-control" name="farm_pest_chance" value="<?= $cfg['pest_chance'] ?>">
        <div class="fm-muted mt-1">Per tanam. Tanpa Pestisida hasil panen ½.</div></div>
      <div class="col-6 col-lg-2"><label class="c-label">Kapasitas lumbung awal</label>
        <input type="number" min="5" max="10000" class="c-form-control" name="farm_barn_capacity" value="<?= $cfg['barn_capacity'] ?>">
        <div class="fm-muted mt-1">Untuk petani baru.</div></div>
      <div class="col-12 col-lg-2 d-flex align-items-end"><button class="btn btn-primary w-100">Simpan</button></div>
      <div class="col-12 fm-muted" id="fm-plot-preview"></div>
    </form>
  </div>
</section>

<!-- ── BIBIT ── -->
<section id="bibit" class="c-card mb-3">
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="crops"><input type="hidden" name="tab" value="bibit">
    <div class="c-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <b>Katalog Bibit &amp; Harga</b>
      <span class="fm-muted">Bibit dibayar Saldo Beli · hasil panen dijual ke Saldo Tarik · bibit tidak dihapus, cukup nonaktifkan</span>
    </div>
    <div class="c-card-body p-0"><div class="table-responsive">
      <table class="c-table no-dt w-100 fm-table">
        <thead><tr>
          <th>Aktif</th><th>Nama</th><th>Model 3D</th><th>Warna</th><th>Tumbuh (detik)</th><th>Harga Bibit</th>
          <th>Hasil (min–maks)</th><th>Harga Jual / hasil</th><th>XP</th><th>Lv</th><th>Urut</th><th>Analisis rata-rata</th>
        </tr></thead>
        <tbody>
        <?php foreach ([...$crops, ['id' => 'new', 'name' => '', 'model' => 'rice', 'color' => '#facc15', 'growth_secs' => 120, 'seed_price' => 1000, 'yield_min' => 2, 'yield_max' => 4, 'unit_price' => 300, 'xp' => 10, 'min_level' => 1, 'sort_order' => count($crops) + 1, 'is_active' => 1]] as $c):
            $id = $c['id']; $n = "crop[$id]"; $isNew = $id === 'new';
            [$profit, $roi, $perHour] = farm_console_econ($c);
            $cls = $roi === null ? 'fm-warn' : ($roi < 0 ? 'fm-bad' : ($roi > 100 ? 'fm-warn' : 'fm-good')); ?>
          <tr class="<?= $isNew ? 'fm-new' : '' ?>" data-crop>
            <td><input class="form-check-input" type="checkbox" name="<?= $n ?>[is_active]" <?= (int)$c['is_active'] ? 'checked' : '' ?>></td>
            <td style="min-width:150px"><input class="c-form-control" name="<?= $n ?>[name]" value="<?= htmlspecialchars((string)$c['name']) ?>" maxlength="80" placeholder="<?= $isNew ? '+ Bibit baru…' : '' ?>" <?= $isNew ? '' : 'required' ?>>
              <?php if (!$isNew): ?><div class="fm-muted mt-1"><code><?= htmlspecialchars((string)$c['code']) ?></code> · #<?= (int)$id ?></div><?php endif; ?></td>
            <td><select class="c-form-control" name="<?= $n ?>[model]"><?php foreach (FARM_MODELS as $k => $lbl): ?><option value="<?= $k ?>" <?= $c['model'] === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?></select></td>
            <td><input type="color" class="fm-swatch" name="<?= $n ?>[color]" value="<?= htmlspecialchars((string)$c['color']) ?>"></td>
            <td style="min-width:100px"><input type="number" min="5" class="c-form-control fm-num" name="<?= $n ?>[growth_secs]" value="<?= (int)$c['growth_secs'] ?>" data-f="g" oninput="fmCalc(this)">
              <div class="fm-muted mt-1" data-dur><?= farm_console_dur((int)$c['growth_secs']) ?></div></td>
            <td style="min-width:100px"><input type="number" min="0" step="100" class="c-form-control fm-num" name="<?= $n ?>[seed_price]" value="<?= (int)$c['seed_price'] ?>" data-f="s" oninput="fmCalc(this)"></td>
            <td style="min-width:130px"><div class="d-flex gap-1 align-items-center">
              <input type="number" min="1" class="c-form-control fm-num" name="<?= $n ?>[yield_min]" value="<?= (int)$c['yield_min'] ?>" style="width:58px" data-f="y0" oninput="fmCalc(this)"><span class="fm-muted">–</span>
              <input type="number" min="1" class="c-form-control fm-num" name="<?= $n ?>[yield_max]" value="<?= (int)$c['yield_max'] ?>" style="width:58px" data-f="y1" oninput="fmCalc(this)"></div></td>
            <td style="min-width:100px"><input type="number" min="0" step="10" class="c-form-control fm-num" name="<?= $n ?>[unit_price]" value="<?= (int)$c['unit_price'] ?>" data-f="u" oninput="fmCalc(this)"></td>
            <td><input type="number" min="0" class="c-form-control fm-num" name="<?= $n ?>[xp]" value="<?= (int)$c['xp'] ?>" style="width:64px"></td>
            <td><input type="number" min="1" class="c-form-control fm-num" name="<?= $n ?>[min_level]" value="<?= (int)$c['min_level'] ?>" style="width:56px"></td>
            <td><input type="number" class="c-form-control fm-num" name="<?= $n ?>[sort_order]" value="<?= (int)$c['sort_order'] ?>" style="width:56px"></td>
            <td class="fm-num" style="white-space:nowrap;font-size:12px" data-econ>
              <span class="<?= $cls ?>" style="font-weight:700"><?= ($profit >= 0 ? '+' : '') . format_rp($profit) ?></span> / panen<br>
              <span class="fm-muted">ROI <?= $roi === null ? '∞' : number_format($roi, 0) . '%' ?> · <?= format_rp($perHour) ?>/jam</span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
    <div class="c-card-body d-flex justify-content-between align-items-center flex-wrap gap-2" style="border-top:1px solid var(--border-color,#1f2235)">
      <span class="fm-muted">ROI = (rata-rata hasil × harga jual − harga bibit) ÷ harga bibit, sebelum hama &amp; Pupuk Kilat. <span class="fm-good">Hijau</span> 0–100%, <span class="fm-warn">kuning</span> &gt;100%, <span class="fm-bad">merah</span> rugi.</span>
      <button class="btn btn-primary">Simpan Katalog Bibit</button>
    </div>
  </form>
</section>

<!-- ── BARANG TOKO ── -->
<section id="barang" class="c-card mb-3">
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="items"><input type="hidden" name="tab" value="barang">
    <div class="c-card-header"><b>Barang Toko Tani</b></div>
    <div class="c-card-body p-0"><div class="table-responsive">
      <table class="c-table no-dt w-100 fm-table">
        <thead><tr><th>Aktif</th><th>Nama</th><th>Efek</th><th>Harga (Saldo Beli)</th><th>Nilai Efek</th><th>Deskripsi</th><th>Urut</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): $n = 'item[' . (int)$it['id'] . ']'; ?>
          <tr>
            <td><input class="form-check-input" type="checkbox" name="<?= $n ?>[is_active]" <?= (int)$it['is_active'] ? 'checked' : '' ?>></td>
            <td style="min-width:150px"><input class="c-form-control" name="<?= $n ?>[name]" value="<?= htmlspecialchars((string)$it['name']) ?>" maxlength="80" required></td>
            <td><span class="fm-pill" style="color:#6ee7b7;border-color:#6ee7b755"><?= htmlspecialchars(FARM_EFFECTS[$it['effect']] ?? $it['effect']) ?></span></td>
            <td style="min-width:110px"><input type="number" min="0" step="100" class="c-form-control fm-num" name="<?= $n ?>[price]" value="<?= (int)$it['price'] ?>"></td>
            <td style="min-width:90px"><?php if ($it['effect'] === 'barn'): ?>
              <input type="number" min="1" class="c-form-control fm-num" name="<?= $n ?>[effect_value]" value="<?= (int)$it['effect_value'] ?>"><div class="fm-muted mt-1">+kapasitas</div>
              <?php else: ?><input type="hidden" name="<?= $n ?>[effect_value]" value="<?= (int)$it['effect_value'] ?>"><span class="fm-muted">-</span><?php endif; ?></td>
            <td style="min-width:220px"><input class="c-form-control" name="<?= $n ?>[description]" value="<?= htmlspecialchars((string)$it['description']) ?>" maxlength="190"></td>
            <td><input type="number" class="c-form-control fm-num" name="<?= $n ?>[sort_order]" value="<?= (int)$it['sort_order'] ?>" style="width:56px"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
    <div class="c-card-body d-flex justify-content-between align-items-center flex-wrap gap-2" style="border-top:1px solid var(--border-color,#1f2235)">
      <span class="fm-muted">Menonaktifkan Pupuk Kilat / Pestisida menyembunyikan tombolnya di kartu petak. Stok yang sudah dimiliki pemain tetap tersimpan.</span>
      <button class="btn btn-primary">Simpan Barang</button>
    </div>
  </form>
</section>

<!-- ── PEMAIN ── -->
<section id="pemain" class="c-card mb-3">
  <div class="c-card-header"><b>Petani Teratas (XP)</b></div>
  <div class="c-card-body p-0"><div class="table-responsive">
    <table class="c-table w-100">
      <thead><tr><th>Pemain</th><th>Level</th><th>XP</th><th>Petak</th><th>Lumbung</th><th>Belanja</th><th>Diterima</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($players as $p): $lv = farm_level((int)$p['xp']); ?>
        <tr>
          <td><a href="/console/user_detail.php?id=<?= (int)$p['user_id'] ?>" style="color:#e2e8f0;font-weight:700;text-decoration:none"><?= htmlspecialchars((string)($p['username'] ?? '#' . $p['user_id'])) ?></a> <span class="fm-muted">#<?= (int)$p['user_id'] ?></span></td>
          <td><span class="fm-pill" style="color:#34d399;border-color:#34d39955">Lv <?= $lv['level'] ?></span></td>
          <td class="fm-num"><?= number_format((int)$p['xp'], 0, ',', '.') ?></td>
          <td class="fm-num"><?= (int)$p['unlocked_plots'] ?>/<?= FARM_TOTAL_PLOTS ?></td>
          <td class="fm-num"><?= (int)$p['barn_used'] ?>/<?= (int)$p['barn_capacity'] ?></td>
          <td class="fm-num"><?= format_rp((float)$p['spent']) ?></td>
          <td class="fm-num fm-good"><?= format_rp((float)$p['earned']) ?></td>
          <td><a href="?u=<?= (int)$p['user_id'] ?>#log" class="fm-muted" style="text-decoration:none">Log →</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</section>

<!-- ── LOG & AUDIT ── -->
<section id="log" class="c-card mb-3">
  <div class="c-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <b>Log Aksi Pemain</b>
    <form method="get" action="#log" class="d-flex gap-2 flex-wrap">
      <input class="c-form-control" name="u" value="<?= htmlspecialchars($fq) ?>" placeholder="ID / username" style="width:160px">
      <select class="c-form-control" name="a" style="min-width:150px">
        <option value="">Semua aksi</option>
        <?php foreach ($logActions as $a): ?><option value="<?= htmlspecialchars($a) ?>" <?= $fa === $a ? 'selected' : '' ?>><?= htmlspecialchars($ACTION_LABEL[$a] ?? $a) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-outline-info">Filter</button>
      <?php if ($fq !== '' || $fa !== ''): ?><a href="/console/farm.php#log" class="btn btn-sm btn-outline-secondary">Reset</a><?php endif; ?>
    </form>
  </div>
  <div class="c-card-body p-0"><div class="table-responsive">
    <table class="c-table w-100">
      <thead><tr><th>#</th><th>Waktu</th><th>Pemain</th><th>Aksi</th><th>Petak</th><th>Barang</th><th>Qty</th><th>Nominal</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $l):
          $ref = $l['ref_id'] === null ? '-' : (in_array($l['action'], ['buy_item', 'speed_up', 'cure'], true) ? ($itemName[$l['ref_id']] ?? '#' . $l['ref_id']) : ($cropName[$l['ref_id']] ?? '#' . $l['ref_id'])); ?>
        <tr>
          <td class="fm-muted"><?= (int)$l['id'] ?></td>
          <td data-order="<?= strtotime((string)$l['created_at']) ?>" style="white-space:nowrap"><?= date('d/m/y H:i', strtotime((string)$l['created_at'])) ?></td>
          <td><?= htmlspecialchars((string)($l['username'] ?? '#' . $l['user_id'])) ?> <span class="fm-muted">#<?= (int)$l['user_id'] ?></span></td>
          <td><?= htmlspecialchars($ACTION_LABEL[$l['action']] ?? $l['action']) ?></td>
          <td class="fm-num"><?= $l['plot_index'] !== null ? '#' . (int)$l['plot_index'] : '-' ?></td>
          <td><?= htmlspecialchars((string)$ref) ?></td>
          <td class="fm-num"><?= (int)$l['qty'] ?></td>
          <td class="fm-num <?= $l['wallet'] === 'wd' ? 'fm-good' : ($l['wallet'] === 'dep' ? 'fm-bad' : 'fm-muted') ?>" style="white-space:nowrap">
            <?= $l['wallet'] === 'none' ? '-' : ($l['wallet'] === 'wd' ? '+' : '−') . format_rp((float)$l['amount']) ?>
            <?php if ($l['wallet'] !== 'none'): ?><div class="fm-muted"><?= $l['wallet'] === 'wd' ? 'Saldo Tarik' : 'Saldo Beli' ?></div><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</section>

<section class="c-card mb-3">
  <div class="c-card-header"><b>Audit Perubahan Admin</b></div>
  <div class="c-card-body p-0"><div class="table-responsive">
    <table class="c-table w-100">
      <thead><tr><th>#</th><th>Waktu</th><th>Admin</th><th>Aksi</th><th>Detail</th></tr></thead>
      <tbody>
      <?php foreach ($audit as $a): ?>
        <tr>
          <td class="fm-muted"><?= (int)$a['id'] ?></td>
          <td data-order="<?= strtotime((string)$a['created_at']) ?>" style="white-space:nowrap"><?= date('d/m/y H:i', strtotime((string)$a['created_at'])) ?></td>
          <td><?= htmlspecialchars((string)$a['admin']) ?></td>
          <td><code style="font-size:11.5px"><?= htmlspecialchars((string)$a['action']) ?></code></td>
          <td style="max-width:520px;font-size:11.5px;color:#94a3b8;word-break:break-all"><?= htmlspecialchars(mb_substr((string)$a['detail'], 0, 400)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>
</section>

<script>
// Analisis ekonomi bibit dihitung ulang saat angka diubah (sebelum disimpan)
const fmRp = (n) => (n < 0 ? '-' : '') + 'Rp ' + Math.round(Math.abs(n)).toLocaleString('id-ID');
const fmDur = (s) => s < 60 ? s + ' dtk' : s < 3600 ? (Math.round(s / 6) / 10) + ' mnt' : (Math.round(s / 360) / 10) + ' jam';
function fmCalc(el) {
  const tr = el.closest('tr'), v = (f) => parseFloat(tr.querySelector('[data-f="' + f + '"]').value) || 0;
  const g = Math.max(5, v('g')), seed = v('s'), profit = (v('y0') + v('y1')) / 2 * v('u') - seed;
  const roi = seed > 0 ? profit / seed * 100 : null;
  const cls = roi === null ? 'fm-warn' : roi < 0 ? 'fm-bad' : roi > 100 ? 'fm-warn' : 'fm-good';
  tr.querySelector('[data-dur]').textContent = fmDur(g);
  tr.querySelector('[data-econ]').innerHTML = '<span class="' + cls + '" style="font-weight:700">' + (profit >= 0 ? '+' : '') + fmRp(profit) + '</span> / panen<br>'
    + '<span class="fm-muted">ROI ' + (roi === null ? '∞' : Math.round(roi) + '%') + ' · ' + fmRp(profit / g * 3600) + '/jam</span>';
}
// Pratinjau harga petak 1..12
function fmPlot() {
  const base = parseFloat(document.getElementById('fm-pb').value) || 0, step = parseFloat(document.getElementById('fm-ps').value) || 0;
  const free = parseInt(document.querySelector('[name=farm_free_plots]').value, 10) || 4, out = [];
  let total = 0;
  for (let n = free; n < <?= FARM_TOTAL_PLOTS ?>; n++) { const c = base + (n - free) * step; total += c; out.push('#' + (n + 1) + ' ' + fmRp(c)); }
  document.getElementById('fm-plot-preview').textContent = out.length ? 'Harga petak berikutnya: ' + out.join(' · ') + ' — total buka semua ' + fmRp(total) : 'Semua petak gratis.';
}
document.querySelector('[name=farm_free_plots]').addEventListener('input', fmPlot);
fmPlot();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
