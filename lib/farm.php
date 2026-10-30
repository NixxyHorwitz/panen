<?php
declare(strict_types=1);
/**
 * lib/farm.php — Sawah Panen Cuan (Farm System, pengganti Pemancingan)
 *
 * Tabel (lihat database_migrations/create_farm_system.sql):
 *   farm_crops      katalog bibit: waktu tumbuh, harga bibit, rentang hasil, harga jual
 *   farm_items      barang toko: Pupuk Kilat (speed), Pestisida (cure), Perluas Lumbung (barn)
 *   farm_users      profil petani: petak terbuka, XP, kapasitas lumbung
 *   farm_plots      petak lahan milik user (baris ada = petak sudah terbuka)
 *   farm_inventory  bibit / hasil panen (lumbung) / barang
 *   farm_logs       audit aksi bernilai uang
 *
 * Alur cuan: beli bibit (Saldo Beli) → tanam → tunggu → panen masuk Lumbung →
 * jual di Pasar Pengepul → Saldo Tarik. Semua angka diputuskan di server;
 * klien hanya menampilkan.
 *
 * Status petak dihitung dari waktu: baris 'growing' dengan NOW >= matures_at
 * dilaporkan sebagai 'ready'. Hama aktif bila pest_at > 0, NOW >= pest_at dan
 * pest_at <= matures_at (hama hanya datang selama masa tumbuh).
 */

const FARM_TOTAL_PLOTS = 12;

/** Buat semua tabel Farm System (idempoten, murah dipanggil tiap request). */
function farm_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $tables = ['farm_crops', 'farm_items', 'farm_users', 'farm_plots', 'farm_inventory', 'farm_logs', 'farm_admin_logs'];
    $in = implode(',', array_fill(0, count($tables), '?'));
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)");
    $s->execute($tables);
    if ((int)$s->fetchColumn() >= count($tables)) return;

    $sqlFile = dirname(__DIR__) . '/database_migrations/create_farm_system.sql';
    if (!is_file($sqlFile)) return;
    foreach (array_filter(array_map('trim', explode(";\n", str_replace("\r\n", "\n", (string)file_get_contents($sqlFile))))) as $stmt) {
        $lines = array_filter(explode("\n", $stmt), fn($l) => !str_starts_with(ltrim($l), '--'));
        $q = trim(implode("\n", $lines));
        if ($q !== '') { try { $pdo->exec($q); } catch (\Throwable) {} }
    }
}

/** Catat perubahan admin (Console › Sawah Panen Cuan). */
function farm_admin_log(PDO $pdo, string $action, $detail = null): void {
    $who = (string)($_SESSION['admin']['username'] ?? ($_SESSION['staff_username'] ?? 'system'));
    try {
        $pdo->prepare("INSERT INTO farm_admin_logs (admin, action, detail) VALUES (?, ?, ?)")
            ->execute([$who, $action, is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)]);
    } catch (\Throwable) {}
}

/** Model 3D prosedural yang tersedia di assets/js/farm_engine.js. */
const FARM_MODELS = ['rice' => 'Padi', 'chili' => 'Cabai', 'corn' => 'Jagung', 'watermelon' => 'Semangka'];
/** Efek barang toko yang dipahami server. */
const FARM_EFFECTS = ['speed' => 'Pupuk Kilat (langsung panen)', 'cure' => 'Pestisida (basmi hama)', 'barn' => 'Perluas Lumbung (+kapasitas)'];

/* ───────────────────────── PENGATURAN ───────────────────────── */

/** Pengaturan ekonomi (tabel settings; bisa diubah admin tanpa deploy). */
function farm_config(PDO $pdo): array {
    return [
        'enabled'        => setting($pdo, 'farm_enabled', '1') === '1',
        'free_plots'     => max(1, min(FARM_TOTAL_PLOTS, (int)setting($pdo, 'farm_free_plots', '4'))),
        'plot_cost_base' => max(0.0, (float)setting($pdo, 'farm_plot_cost_base', '10000')),
        'plot_cost_step' => max(0.0, (float)setting($pdo, 'farm_plot_cost_step', '5000')),
        'pest_chance'    => max(0, min(100, (int)setting($pdo, 'farm_pest_chance', '12'))),
        'barn_capacity'  => max(5, (int)setting($pdo, 'farm_barn_capacity', '30')),
    ];
}

/** Level petani dari XP: level n butuh 50·(n-1)² XP. */
function farm_level(int $xp): array {
    $level = (int)floor(sqrt(max(0, $xp) / 50)) + 1;
    $cur = 50 * ($level - 1) ** 2;
    $next = 50 * $level ** 2;
    return ['level' => $level, 'xp' => $xp, 'xp_cur' => $cur, 'xp_next' => $next];
}

/** Harga membuka petak berikutnya (naik bertahap sesuai jumlah petak berbayar yang sudah dibuka). */
function farm_plot_cost(array $cfg, int $unlocked): float {
    return $cfg['plot_cost_base'] + max(0, $unlocked - $cfg['free_plots']) * $cfg['plot_cost_step'];
}

/* ───────────────────────── KATALOG ───────────────────────── */

/**
 * Semua bibit, termasuk yang dinonaktifkan admin: tanaman yang sudah terlanjur
 * tumbuh tetap bisa dipanen & dijual. Cek is_active saat tanam/beli.
 */
function farm_crops(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach ($pdo->query("SELECT * FROM farm_crops ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cache[(int)$r['id']] = $r;
    }
    return $cache;
}

function farm_items(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach ($pdo->query("SELECT * FROM farm_items WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cache[(int)$r['id']] = $r;
    }
    return $cache;
}

/** Cari barang berdasarkan efek (mis. 'speed'), atau berdasarkan id bila diberikan. */
function farm_item_for(PDO $pdo, string $effect, int $itemId = 0): ?array {
    foreach (farm_items($pdo) as $id => $it) {
        if ($it['effect'] === $effect && ($itemId === 0 || $itemId === $id)) return $it;
    }
    return null;
}

/* ───────────────────────── PROFIL & STATE ───────────────────────── */

/** Profil petani; dibuat otomatis (dengan petak gratis) saat pertama kali bertani. */
function farm_profile(PDO $pdo, int $uid, bool $lock = false): array {
    $s = $pdo->prepare("SELECT * FROM farm_users WHERE user_id = ?" . ($lock ? " FOR UPDATE" : ""));
    $s->execute([$uid]);
    $p = $s->fetch(PDO::FETCH_ASSOC);
    if ($p) return $p;

    $cfg = farm_config($pdo);
    $pdo->prepare("INSERT IGNORE INTO farm_users (user_id, unlocked_plots, barn_capacity) VALUES (?, ?, ?)")
        ->execute([$uid, $cfg['free_plots'], $cfg['barn_capacity']]);
    $ins = $pdo->prepare("INSERT IGNORE INTO farm_plots (user_id, plot_index) VALUES (?, ?)");
    for ($i = 1; $i <= $cfg['free_plots']; $i++) $ins->execute([$uid, $i]);
    $s->execute([$uid]);
    return $s->fetch(PDO::FETCH_ASSOC);
}

/** Apakah hama sedang menyerang petak ini (lihat docblock file). */
function farm_pest_active(array $plot, int $now): bool {
    $pa = (int)$plot['pest_at'];
    return $pa > 0 && $now >= $pa && $pa <= (int)$plot['matures_at'];
}

/** Bentuk petak untuk klien: status 'ready' dihitung dari waktu server. */
function farm_plot_view(array $row, int $now): array {
    $status = $row['status'];
    if ($status === 'growing' && $now >= (int)$row['matures_at']) $status = 'ready';
    return [
        'plot_index' => (int)$row['plot_index'],
        'status'     => $status,
        'crop_id'    => $row['crop_id'] !== null ? (int)$row['crop_id'] : null,
        'planted_at' => (int)$row['planted_at'],
        'matures_at' => (int)$row['matures_at'],
        'pest_at'    => (int)$row['pest_at'],
        'pest'       => farm_pest_active($row, $now) ? 1 : 0,
    ];
}

/** Isi inventori user: ['seeds' => [crop_id => qty], 'harvest' => [...], 'items' => [...]]. */
function farm_inventory(PDO $pdo, int $uid): array {
    $out = ['seeds' => new stdClass(), 'harvest' => new stdClass(), 'items' => new stdClass()];
    $s = $pdo->prepare("SELECT kind, ref_id, qty FROM farm_inventory WHERE user_id = ? AND qty > 0");
    $s->execute([$uid]);
    $map = ['seed' => 'seeds', 'harvest' => 'harvest', 'item' => 'items'];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$map[$r['kind']]]->{$r['ref_id']} = (int)$r['qty'];
    }
    return $out;
}

/** State lengkap untuk klien (respon get_state & disertakan di setiap aksi). */
function farm_state(PDO $pdo, int $uid): array {
    $now = time();
    $cfg = farm_config($pdo);
    $prof = farm_profile($pdo, $uid);

    $s = $pdo->prepare("SELECT * FROM farm_plots WHERE user_id = ? ORDER BY plot_index");
    $s->execute([$uid]);
    $rows = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $rows[(int)$r['plot_index']] = $r;
    $plots = [];
    for ($i = 1; $i <= FARM_TOTAL_PLOTS; $i++) {
        $plots[] = isset($rows[$i]) ? farm_plot_view($rows[$i], $now)
            : ['plot_index' => $i, 'status' => 'locked', 'crop_id' => null, 'planted_at' => 0, 'matures_at' => 0, 'pest_at' => 0, 'pest' => 0];
    }

    $u = $pdo->prepare("SELECT balance_dep, balance_wd FROM users WHERE id = ?");
    $u->execute([$uid]);
    $bal = $u->fetch(PDO::FETCH_ASSOC) ?: ['balance_dep' => 0, 'balance_wd' => 0];

    $inv = farm_inventory($pdo, $uid);
    $unlocked = count($rows);

    return [
        'server_time'    => $now,
        'balance_dep'    => (float)$bal['balance_dep'],
        'balance_wd'     => (float)$bal['balance_wd'],
        'level'          => farm_level((int)$prof['xp']),
        'unlocked_plots' => $unlocked,
        'next_plot_cost' => $unlocked < FARM_TOTAL_PLOTS ? farm_plot_cost($cfg, $unlocked) : 0,
        'barn_capacity'  => (int)$prof['barn_capacity'],
        'barn_used'      => array_sum((array)$inv['harvest']),
        'plots'          => $plots,
        'inventory'      => $inv,
        'crops'          => array_values(array_map(fn($c) => [
            'id' => (int)$c['id'], 'code' => $c['code'], 'name' => $c['name'], 'model' => $c['model'], 'color' => $c['color'],
            'growth_secs' => (int)$c['growth_secs'], 'seed_price' => (float)$c['seed_price'],
            'yield_min' => (int)$c['yield_min'], 'yield_max' => (int)$c['yield_max'],
            'unit_price' => (float)$c['unit_price'], 'xp' => (int)$c['xp'], 'min_level' => (int)$c['min_level'],
            'is_active' => (int)$c['is_active'],
        ], farm_crops($pdo))),
        'items'          => array_values(array_map(fn($it) => [
            'id' => (int)$it['id'], 'code' => $it['code'], 'name' => $it['name'], 'effect' => $it['effect'],
            'effect_value' => (int)$it['effect_value'], 'price' => (float)$it['price'], 'description' => $it['description'],
        ], farm_items($pdo))),
    ];
}

/* ───────────────────────── HELPER TRANSAKSI ───────────────────────── */

/** Kunci baris user (wajib di awal setiap transaksi agar aksi paralel tidak balapan). */
function farm_lock_user(PDO $pdo, int $uid): array {
    $s = $pdo->prepare("SELECT id, balance_dep, balance_wd FROM users WHERE id = ? FOR UPDATE");
    $s->execute([$uid]);
    $u = $s->fetch(PDO::FETCH_ASSOC);
    if (!$u) throw new RuntimeException('Pengguna tidak ditemukan.');
    return $u;
}

function farm_lock_plot(PDO $pdo, int $uid, int $idx): ?array {
    $s = $pdo->prepare("SELECT * FROM farm_plots WHERE user_id = ? AND plot_index = ? FOR UPDATE");
    $s->execute([$uid, $idx]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function farm_inv_qty(PDO $pdo, int $uid, string $kind, int $ref): int {
    $s = $pdo->prepare("SELECT qty FROM farm_inventory WHERE user_id = ? AND kind = ? AND ref_id = ? FOR UPDATE");
    $s->execute([$uid, $kind, $ref]);
    return (int)$s->fetchColumn();
}

function farm_inv_add(PDO $pdo, int $uid, string $kind, int $ref, int $delta): void {
    $pdo->prepare("INSERT INTO farm_inventory (user_id, kind, ref_id, qty) VALUES (?, ?, ?, GREATEST(0, ?))
                   ON DUPLICATE KEY UPDATE qty = GREATEST(0, CAST(qty AS SIGNED) + ?)")
        ->execute([$uid, $kind, $ref, $delta, $delta]);
}

function farm_log(PDO $pdo, int $uid, string $action, ?int $plot, ?int $ref, int $qty, float $amount, string $wallet = 'none'): void {
    $pdo->prepare("INSERT INTO farm_logs (user_id, action, plot_index, ref_id, qty, amount, wallet) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$uid, $action, $plot, $ref, $qty, $amount, $wallet]);
}

/** Potong Saldo Beli; lempar exception bila kurang (transaksi di-rollback pemanggil). */
function farm_charge_dep(PDO $pdo, array $u, int $uid, float $amount): void {
    if ($amount <= 0) return;
    if ((float)$u['balance_dep'] < $amount) {
        throw new FarmUserError('Saldo Modal tidak cukup (butuh ' . format_rp($amount) . '). Top up dulu ya!', 'no_balance');
    }
    $pdo->prepare("UPDATE users SET balance_dep = balance_dep - ? WHERE id = ?")->execute([$amount, $uid]);
}

/** Kesalahan yang aman ditampilkan ke user (bukan bug server). */
final class FarmUserError extends RuntimeException {
    public function __construct(string $msg, public readonly string $reason = 'invalid') { parent::__construct($msg); }
}

/**
 * Jalankan aksi di dalam transaksi. $fn menerima ($pdo, $userRow) dan
 * mengembalikan array data tambahan; state terbaru selalu disertakan.
 */
function farm_tx(PDO $pdo, int $uid, callable $fn): array {
    try {
        $pdo->beginTransaction();
        $u = farm_lock_user($pdo, $uid);
        farm_profile($pdo, $uid, true);
        $data = $fn($pdo, $u);
        $pdo->commit();
        return ['ok' => true] + $data + ['state' => farm_state($pdo, $uid)];
    } catch (FarmUserError $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'msg' => $e->getMessage(), 'reason' => $e->reason, 'state' => farm_state($pdo, $uid)];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[farm] ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'Terjadi gangguan. Coba lagi ya.', 'reason' => 'server'];
    }
}

/**
 * Pakai 1 barang dari inventori; bila stok kosong, beli otomatis 1 dengan Saldo Beli.
 * Mengembalikan harga yang dibayar (0 bila dari stok).
 */
function farm_consume_or_buy(PDO $pdo, array $u, int $uid, string $kind, int $ref, float $price): float {
    if (farm_inv_qty($pdo, $uid, $kind, $ref) > 0) {
        farm_inv_add($pdo, $uid, $kind, $ref, -1);
        return 0.0;
    }
    farm_charge_dep($pdo, $u, $uid, $price);
    return $price;
}

/* ───────────────────────── AKSI ───────────────────────── */

function farm_plant(PDO $pdo, int $uid, int $idx, int $cropId): array {
    return farm_tx($pdo, $uid, function (PDO $pdo, array $u) use ($uid, $idx, $cropId) {
        $crop = farm_crops($pdo)[$cropId] ?? null;
        if (!$crop || !(int)$crop['is_active']) throw new FarmUserError('Bibit tidak tersedia.');
        $prof = farm_profile($pdo, $uid);
        if (farm_level((int)$prof['xp'])['level'] < (int)$crop['min_level']) {
            throw new FarmUserError($crop['name'] . ' terbuka di Level ' . $crop['min_level'] . '.', 'level');
        }
        $plot = farm_lock_plot($pdo, $uid, $idx);
        if (!$plot) throw new FarmUserError('Petak ini masih terkunci.', 'locked');
        if ($plot['status'] !== 'empty') throw new FarmUserError('Petak ini sudah ditanami.', 'busy');

        $paid = farm_consume_or_buy($pdo, $u, $uid, 'seed', $cropId, (float)$crop['seed_price']);

        $now = time();
        $grow = max(5, (int)$crop['growth_secs']);
        $pestAt = 0;
        if (random_int(1, 100) <= farm_config($pdo)['pest_chance']) {
            $pestAt = $now + (int)round($grow * random_int(30, 80) / 100); // muncul di tengah masa tumbuh
        }
        $pdo->prepare("UPDATE farm_plots SET status = 'growing', crop_id = ?, planted_at = ?, matures_at = ?, pest_at = ? WHERE user_id = ? AND plot_index = ?")
            ->execute([$cropId, $now, $now + $grow, $pestAt, $uid, $idx]);
        farm_log($pdo, $uid, 'plant', $idx, $cropId, 1, $paid, $paid > 0 ? 'dep' : 'none');

        return ['paid' => $paid, 'plot' => farm_plot_view(farm_lock_plot($pdo, $uid, $idx), $now)];
    });
}

function farm_speed_up(PDO $pdo, int $uid, int $idx, int $itemId): array {
    return farm_tx($pdo, $uid, function (PDO $pdo, array $u) use ($uid, $idx, $itemId) {
        $item = farm_item_for($pdo, 'speed', $itemId);
        if (!$item) throw new FarmUserError('Pupuk Kilat tidak tersedia.');
        $plot = farm_lock_plot($pdo, $uid, $idx);
        $now = time();
        if (!$plot || $plot['status'] !== 'growing') throw new FarmUserError('Tidak ada tanaman yang sedang tumbuh di sini.');
        if ($now >= (int)$plot['matures_at']) throw new FarmUserError('Tanaman sudah siap panen!', 'ready');

        $paid = farm_consume_or_buy($pdo, $u, $uid, 'item', (int)$item['id'], (float)$item['price']);
        // matures_at = sekarang → hama yang dijadwalkan setelahnya otomatis tidak pernah aktif
        $pdo->prepare("UPDATE farm_plots SET matures_at = ? WHERE user_id = ? AND plot_index = ?")->execute([$now, $uid, $idx]);
        farm_log($pdo, $uid, 'speed_up', $idx, (int)$item['id'], 1, $paid, $paid > 0 ? 'dep' : 'none');

        return ['paid' => $paid, 'plot' => farm_plot_view(farm_lock_plot($pdo, $uid, $idx), $now)];
    });
}

function farm_cure(PDO $pdo, int $uid, int $idx, int $itemId): array {
    return farm_tx($pdo, $uid, function (PDO $pdo, array $u) use ($uid, $idx, $itemId) {
        $item = farm_item_for($pdo, 'cure', $itemId);
        if (!$item) throw new FarmUserError('Pestisida tidak tersedia.');
        $plot = farm_lock_plot($pdo, $uid, $idx);
        $now = time();
        if (!$plot || $plot['status'] !== 'growing' || !farm_pest_active($plot, $now)) {
            throw new FarmUserError('Tidak ada hama di petak ini.');
        }
        $paid = farm_consume_or_buy($pdo, $u, $uid, 'item', (int)$item['id'], (float)$item['price']);
        $pdo->prepare("UPDATE farm_plots SET pest_at = 0 WHERE user_id = ? AND plot_index = ?")->execute([$uid, $idx]);
        farm_log($pdo, $uid, 'cure', $idx, (int)$item['id'], 1, $paid, $paid > 0 ? 'dep' : 'none');

        return ['paid' => $paid, 'plot' => farm_plot_view(farm_lock_plot($pdo, $uid, $idx), $now)];
    });
}

function farm_harvest(PDO $pdo, int $uid, int $idx): array {
    return farm_tx($pdo, $uid, function (PDO $pdo, array $u) use ($uid, $idx) {
        $plot = farm_lock_plot($pdo, $uid, $idx);
        $now = time();
        if (!$plot || $plot['status'] !== 'growing') throw new FarmUserError('Tidak ada tanaman untuk dipanen.');
        if ($now < (int)$plot['matures_at']) throw new FarmUserError('Belum waktunya panen, sabar ya!', 'not_ready');
        $crop = farm_crops($pdo)[(int)$plot['crop_id']] ?? null;
        if (!$crop) throw new FarmUserError('Data tanaman tidak ditemukan.');

        $prof = farm_profile($pdo, $uid, true);
        $yield = random_int((int)$crop['yield_min'], max((int)$crop['yield_min'], (int)$crop['yield_max']));
        $pest = farm_pest_active($plot, $now);
        if ($pest) $yield = max(1, intdiv($yield, 2)); // hama tidak dibasmi → hasil separuh

        $s = $pdo->prepare("SELECT COALESCE(SUM(qty), 0) FROM farm_inventory WHERE user_id = ? AND kind = 'harvest' FOR UPDATE");
        $s->execute([$uid]);
        $used = (int)$s->fetchColumn();
        if ($used + $yield > (int)$prof['barn_capacity']) {
            throw new FarmUserError('Lumbung penuh (' . $used . '/' . $prof['barn_capacity'] . '). Jual hasil panen di Pasar Pengepul dulu!', 'barn_full');
        }

        farm_inv_add($pdo, $uid, 'harvest', (int)$crop['id'], $yield);
        $before = farm_level((int)$prof['xp']);
        $pdo->prepare("UPDATE farm_users SET xp = xp + ? WHERE user_id = ?")->execute([(int)$crop['xp'], $uid]);
        $after = farm_level((int)$prof['xp'] + (int)$crop['xp']);
        $pdo->prepare("UPDATE farm_plots SET status = 'empty', crop_id = NULL, planted_at = 0, matures_at = 0, pest_at = 0 WHERE user_id = ? AND plot_index = ?")
            ->execute([$uid, $idx]);
        farm_log($pdo, $uid, 'harvest', $idx, (int)$crop['id'], $yield, 0);

        return [
            'yield'     => $yield,
            'crop_id'   => (int)$crop['id'],
            'crop_name' => $crop['name'],
            'value'     => $yield * (float)$crop['unit_price'],
            'pest'      => $pest,
            'xp_gain'   => (int)$crop['xp'],
            'level_up'  => $after['level'] > $before['level'] ? $after['level'] : 0,
        ];
    });
}

function farm_sell_all(PDO $pdo, int $uid): array {
    return farm_tx($pdo, $uid, function (PDO $pdo) use ($uid) {
        $s = $pdo->prepare("SELECT ref_id, qty FROM farm_inventory WHERE user_id = ? AND kind = 'harvest' AND qty > 0 FOR UPDATE");
        $s->execute([$uid]);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) throw new FarmUserError('Lumbung masih kosong. Panen dulu ya!', 'empty');

        // Harga dari tabel lengkap (termasuk tanaman yang sudah dinonaktifkan admin)
        $prices = $pdo->query("SELECT id, unit_price FROM farm_crops")->fetchAll(PDO::FETCH_KEY_PAIR);
        $total = 0.0; $count = 0;
        foreach ($rows as $r) {
            $total += (int)$r['qty'] * (float)($prices[$r['ref_id']] ?? 0);
            $count += (int)$r['qty'];
        }
        $pdo->prepare("UPDATE farm_inventory SET qty = 0 WHERE user_id = ? AND kind = 'harvest'")->execute([$uid]);
        $pdo->prepare("UPDATE users SET balance_wd = balance_wd + ?, total_earned = total_earned + ? WHERE id = ?")->execute([$total, $total, $uid]);
        farm_log($pdo, $uid, 'sell_all', null, null, $count, $total, 'wd');
        try {
            $pdo->prepare("INSERT INTO minigame_logs (user_id, game_type, score, reward) VALUES (?, 'farm', ?, ?)")->execute([$uid, $count, $total]);
        } catch (\Throwable) {}

        return ['credited' => $total, 'sold_count' => $count];
    });
}

function farm_unlock_plot(PDO $pdo, int $uid, int $idx): array {
    return farm_tx($pdo, $uid, function (PDO $pdo, array $u) use ($uid, $idx) {
        if (farm_lock_plot($pdo, $uid, $idx)) throw new FarmUserError('Petak ini sudah terbuka.');
        $s = $pdo->prepare("SELECT COUNT(*) FROM farm_plots WHERE user_id = ?");
        $s->execute([$uid]);
        $cost = farm_plot_cost(farm_config($pdo), (int)$s->fetchColumn());

        farm_charge_dep($pdo, $u, $uid, $cost);
        $pdo->prepare("INSERT INTO farm_plots (user_id, plot_index) VALUES (?, ?)")->execute([$uid, $idx]);
        $pdo->prepare("UPDATE farm_users SET unlocked_plots = unlocked_plots + 1 WHERE user_id = ?")->execute([$uid]);
        farm_log($pdo, $uid, 'unlock_plot', $idx, null, 1, $cost, 'dep');

        return ['paid' => $cost];
    });
}

/** Beli bibit / barang di Toko Tani. Perluas Lumbung langsung diterapkan. */
function farm_buy(PDO $pdo, int $uid, string $kind, int $refId, int $qty): array {
    $qty = max(1, min(50, $qty));
    return farm_tx($pdo, $uid, function (PDO $pdo, array $u) use ($uid, $kind, $refId, $qty) {
        if ($kind === 'seed') {
            $crop = farm_crops($pdo)[$refId] ?? null;
            if (!$crop || !(int)$crop['is_active']) throw new FarmUserError('Bibit tidak tersedia.');
            $prof = farm_profile($pdo, $uid);
            if (farm_level((int)$prof['xp'])['level'] < (int)$crop['min_level']) {
                throw new FarmUserError($crop['name'] . ' terbuka di Level ' . $crop['min_level'] . '.', 'level');
            }
            $cost = $qty * (float)$crop['seed_price'];
            farm_charge_dep($pdo, $u, $uid, $cost);
            farm_inv_add($pdo, $uid, 'seed', $refId, $qty);
            farm_log($pdo, $uid, 'buy_seed', null, $refId, $qty, $cost, 'dep');
            return ['paid' => $cost, 'msg' => $qty . ' bibit ' . $crop['name'] . ' masuk kantong!'];
        }
        $item = farm_items($pdo)[$refId] ?? null;
        if (!$item) throw new FarmUserError('Barang tidak tersedia.');
        $cost = $qty * (float)$item['price'];
        farm_charge_dep($pdo, $u, $uid, $cost);
        if ($item['effect'] === 'barn') {
            $pdo->prepare("UPDATE farm_users SET barn_capacity = barn_capacity + ? WHERE user_id = ?")
                ->execute([$qty * max(1, (int)$item['effect_value']), $uid]);
        } else {
            farm_inv_add($pdo, $uid, 'item', $refId, $qty);
        }
        farm_log($pdo, $uid, 'buy_item', null, $refId, $qty, $cost, 'dep');
        return ['paid' => $cost, 'msg' => $item['name'] . ' berhasil dibeli!'];
    });
}
