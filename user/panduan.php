<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

$pageTitle  = 'Panduan Resmi';
$activePage = 'panduan';

$min_wd      = (float) setting($pdo, 'min_withdraw', '50000');
$ref_bonus   = (float) setting($pdo, 'referral_bonus', '1000');
$ref_pct     = (float) setting($pdo, 'referral_commission_percent', '5');
$ref_hold    = (int)   setting($pdo, 'referral_hold_days', '3');
$ci_min      = (float) setting($pdo, 'checkin_reward_min', '500');
$ci_max      = max($ci_min, (float) setting($pdo, 'checkin_reward_max', '2000'));
$thr_reward  = (float) setting($pdo, 'threads_campaign_reward', '25000');
$thr_reward2 = (float) setting($pdo, 'threads_campaign_reward_step2', '50000');

$wd_free_dana = setting($pdo, 'wd_free_only_dana', '1') === '1';
$wd_free_1x   = setting($pdo, 'wd_free_limit_1x', '1') === '1';
$wd_free_1day = setting($pdo, 'wd_free_require_1day', '1') === '1';
$wd_lock_s    = setting($pdo, 'wd_lock_start', '');
$wd_lock_e    = setting($pdo, 'wd_lock_end', '');

$panduan_intro    = setting($pdo, 'panduan_intro', 'Semua cara panen cuan: nonton video, bertani, quest, sampai withdraw.');
$panduan_faq      = setting($pdo, 'panduan_faq_custom', '');
$panduan_cta_text = setting($pdo, 'panduan_cta_text', 'Mulai Nonton Video');
$panduan_cta_url  = setting($pdo, 'panduan_cta_url', '/videos');

try {
    $memberships = $pdo->query("SELECT name, price, watch_limit, duration_days, min_wd, max_wd FROM memberships WHERE is_active=1 ORDER BY sort_order ASC")->fetchAll();
} catch (\Throwable) { $memberships = []; }

// Ringkasan misi aktif per kategori
$mission_sum = [];
try {
    foreach ($pdo->query("SELECT category, COUNT(*) n, SUM(reward_amount) total FROM missions WHERE is_active = 1 GROUP BY category") as $r) {
        $mission_sum[$r['category']] = ['n' => (int)$r['n'], 'total' => (float)$r['total']];
    }
} catch (\Throwable) {}

// Sawah Panen Cuan: bibit & biaya petak (lihat lib/farm.php)
require_once dirname(__DIR__) . '/lib/farm.php';
try { $farm_crops = $pdo->query("SELECT name, growth_secs, seed_price, yield_min, yield_max, unit_price FROM farm_crops WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll(); } catch (\Throwable) { $farm_crops = []; }
$farm_cfg = farm_config($pdo);

try {
    $wallets = $pdo->query("SELECT name FROM payment_channels WHERE is_active = 1 ORDER BY type DESC, sort_order ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable) { $wallets = []; }

$free_name = '';
foreach ($memberships as $m) if ((float)$m['price'] <= 0) { $free_name = (string)$m['name']; break; }

$rp_short = static function (float $v): string {
    if ($v >= 1000000) return 'Rp' . rtrim(rtrim(number_format($v / 1000000, 1, ',', ''), '0'), ',') . 'jt';
    if ($v >= 1000) return 'Rp' . rtrim(rtrim(number_format($v / 1000, 1, ',', ''), '0'), ',') . 'rb';
    return 'Rp' . number_format($v, 0, ',', '.');
};

require dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══ PANDUAN — Buku Petani · Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.pd-tour.g-btn { margin-top: 10px; min-height: 36px; padding: 0 12px; font-size: 13.5px; border-radius: 12px; }
.pd-tour em { font-style: normal; padding: 1px 7px; border-radius: 999px; font-family: 'Nunito', sans-serif; font-size: 10.5px; font-weight: 900; background: rgba(255, 255, 255, .3); }
.pd-tour em.done { background: #dcfce7; color: #14532d; }

/* Jalur 3 langkah */
.pd-path { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: 4px; }
.pd-path a { display: flex; flex-direction: column; align-items: center; gap: 5px; padding: 9px 4px 8px; border-radius: 18px; text-decoration: none; background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 0 0 2px #f6e2b0, 0 4px 0 #ecd59c; transition: transform .15s var(--pc-ease); }
.pd-path a:active { transform: translateY(3px); }
.pd-path img { width: 40px; height: 40px; object-fit: contain; filter: drop-shadow(0 3px 3px rgba(0, 0, 0, .2)); }
.pd-path b { font-family: var(--pc-font); font-size: 13px; font-weight: 600; color: #4a2b00; text-align: center; line-height: 1.1; }
.pd-path > i { font-size: 16px; color: #d9b25a; }

/* Navigasi bab */
.pd-nav { display: flex; gap: 6px; overflow-x: auto; margin: 0 -12px; padding: 4px 12px 6px; scrollbar-width: none; }
.pd-nav::-webkit-scrollbar { display: none; }
.pd-nav a { flex: 0 0 auto; display: inline-flex; align-items: center; min-height: 34px; padding: 0 13px; border-radius: 12px; text-decoration: none; font-family: var(--pc-font); font-size: 13.5px; font-weight: 600; color: #8a6a3a; background: #f2e6c8; box-shadow: inset 0 -2px 0 rgba(120, 70, 10, .12); }
.pd-nav a.on { color: #fff; background: linear-gradient(180deg, #22c55e, #15803d); box-shadow: 0 3px 0 #11622f; }

.pd-sec { scroll-margin-top: 80px; display: flex; flex-direction: column; gap: 10px; }
.pd-sec .g-sec small { white-space: nowrap; }

/* Sumber cuan */
.pd-bento { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.pd-tile { position: relative; display: flex; flex-direction: column; gap: 6px; padding: 10px; border-radius: 20px; text-decoration: none; color: inherit; background: linear-gradient(180deg, #fffaf0, #fff); box-shadow: 0 0 0 2px #f6e2b0, 0 4px 0 #ecd59c; transition: transform .15s var(--pc-ease); min-width: 0; }
.pd-tile:active { transform: translateY(3px); }
.pd-tile.wide { grid-column: 1 / -1; flex-direction: row; align-items: center; }
.pd-tile b { font-size: 13px; font-weight: 900; color: var(--pc-ink); line-height: 1.2; }
.pd-tile p { margin: 0; font-size: 11px; font-weight: 700; color: var(--pc-muted); line-height: 1.35; }
.pd-tile .g-coin { position: absolute; top: 9px; right: 8px; font-size: 11px; padding: 1px 8px; }
.pd-tile.wide .tx { flex: 1; min-width: 0; }
.pd-tile.wide .g-coin { position: static; flex-shrink: 0; }

/* Alur bertani */
.pd-flow { position: relative; display: flex; flex-direction: column; gap: 10px; }
.pd-flow::before { content: ''; position: absolute; left: 18px; top: 20px; bottom: 20px; width: 3px; background: repeating-linear-gradient(180deg, #ecd59c 0 6px, transparent 6px 11px); }
.pd-st { position: relative; display: flex; align-items: flex-start; gap: 10px; }
.pd-st b { display: block; font-size: 13px; font-weight: 900; color: var(--pc-ink); }
.pd-st p { margin: 1px 0 0; font-size: 11.5px; font-weight: 700; color: var(--pc-muted); line-height: 1.45; }
.pd-st p strong { color: #15803d; }
.pd-pills { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }

/* Dua saldo */
.pd-wallets { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.pd-wal { position: relative; overflow: hidden; padding: 11px; border-radius: 20px; min-width: 0; }
.pd-wal.wd { color: #fff; background: linear-gradient(160deg, #22c55e, #15803d); box-shadow: 0 4px 0 #11622f, 0 10px 18px rgba(22, 163, 74, .22); }
.pd-wal.dep { color: #4a3000; background: linear-gradient(160deg, #ffe58a, #f6b70a); box-shadow: 0 4px 0 #b07800, 0 10px 18px rgba(246, 183, 10, .22); }
.pd-wal img.big { position: absolute; right: -8px; bottom: -8px; width: 62px; height: 62px; object-fit: contain; opacity: .9; }
.pd-wal b { display: block; font-family: var(--pc-font); font-size: 16px; font-weight: 700; }
.pd-wal small { display: block; margin: 6px 0 2px; font-size: 10px; font-weight: 900; text-transform: uppercase; letter-spacing: .4px; opacity: .85; }
.pd-wal ul { position: relative; margin: 0; padding: 0; list-style: none; font-size: 11.5px; font-weight: 800; line-height: 1.45; max-width: 78%; }
.pd-wal li::before { content: '• '; opacity: .7; }

/* Pangkat */
.pd-rank { display: flex; flex-direction: column; gap: 7px; }
.pd-rk { display: flex; align-items: center; gap: 10px; padding: 8px 10px 8px 8px; border-radius: 16px; background: #fff; box-shadow: inset 0 0 0 1.5px #f1dfb4; }
.pd-rk .tx { flex: 1; min-width: 0; }
.pd-rk b { display: block; font-size: 13px; font-weight: 900; color: var(--pc-ink); }
.pd-rk .meta { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px; }
.pd-rk .pr { flex-shrink: 0; font-family: var(--pc-font); font-size: 14px; font-weight: 700; color: #be123c; }
.pd-rk .pr.free { color: #15803d; }

/* Aturan */
.pd-rules { display: flex; flex-direction: column; gap: 8px; }
.pd-rule { display: flex; align-items: center; gap: 10px; font-size: 12px; font-weight: 700; color: var(--pc-muted); line-height: 1.4; }
.pd-rule strong { color: var(--pc-ink); }

/* FAQ */
.pd-faq { display: flex; flex-direction: column; gap: 7px; }
.pd-q { border-radius: 16px; background: #fff; box-shadow: 0 0 0 2px #efe6cf, 0 3px 0 #e6dbbd; overflow: hidden; }
.pd-q summary { list-style: none; cursor: pointer; display: flex; align-items: center; gap: 8px; padding: 11px 12px; font-size: 12.5px; font-weight: 900; color: var(--pc-ink); }
.pd-q summary::-webkit-details-marker { display: none; }
.pd-q summary i { margin-left: auto; flex-shrink: 0; color: #15803d; transition: transform .25s var(--pc-ease); }
.pd-q[open] summary i { transform: rotate(180deg); }
.pd-q div { padding: 0 12px 11px; font-size: 12px; font-weight: 700; color: var(--pc-muted); line-height: 1.5; }
.pd-q a { color: #15803d; font-weight: 900; }

.pd-cta { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.pd-cta .g-btn { min-width: 0; padding: 0 8px; }
@media (max-width: 350px) { .pd-path b { font-size: 11.5px; } .pd-path img { width: 32px; height: 32px; } }
</style>

<?php $ic = static fn(string $n): string => '/assets/img/icons3d/' . $n . '.png'; ?>
<div class="g-page">

  <section class="g-hero" data-rv>
    <span class="g-sun"></span><span class="g-cloud c1"></span><span class="g-cloud c2"></span>
    <div class="g-hero-row">
      <div style="flex:1;min-width:0">
        <h1>Buku Petani</h1>
        <p><?= htmlspecialchars($panduan_intro) ?></p>
        <button type="button" class="g-btn violet pd-tour" onclick="startPanduanTour('watch')"><i class="ph-fill ph-compass"></i>Tur interaktif <em id="badge-tour-watch">Mulai</em></button>
      </div>
      <div class="g-hero-art"><img src="<?= $ic('compass') ?>" alt=""></div>
    </div>
  </section>

  <div class="pd-path" data-rv style="--d:.05s">
    <a href="/videos"><img src="<?= $ic('watch') ?>" alt=""><b>Nonton &amp; Bertani</b></a>
    <i class="ph-bold ph-caret-right"></i>
    <a href="/missions"><img src="<?= $ic('missions') ?>" alt=""><b>Kejar Quest</b></a>
    <i class="ph-bold ph-caret-right"></i>
    <a href="/withdraw"><img src="<?= $ic('moneybag') ?>" alt=""><b>Withdraw</b></a>
  </div>

  <nav class="pd-nav" id="pd-nav">
    <a href="#cuan" class="on">Sumber Cuan</a>
    <a href="#sawah">Sawah</a>
    <a href="#saldo">Saldo</a>
    <a href="#pangkat">Pangkat</a>
    <a href="#tarik">Withdraw</a>
    <a href="#faq">Tanya Jawab</a>
  </nav>

  <!-- 1. SUMBER CUAN -->
  <section class="pd-sec" id="cuan">
    <div class="g-sec"><span class="g-ribbon gold"><img src="<?= $ic('coins') ?>" alt="">Sumber Cuan</span><small>masuk Saldo Tarik</small></div>
    <div class="pd-bento">
      <a href="/videos" class="pd-tile" data-rv><span class="g-slot sm"><img src="<?= $ic('watch') ?>" alt=""></span><b>Nonton Video</b><p>Tonton sampai timer habis, reward langsung masuk.</p></a>
      <a href="/farm" class="pd-tile" data-rv style="--d:.04s"><span class="g-slot sm"><img src="<?= $ic('padi') ?>" alt=""></span><b>Sawah Panen Cuan</b><p>Tanam, panen, jual ke Pasar Pengepul.</p></a>
      <a href="/missions" class="pd-tile" data-rv style="--d:.08s"><?php if (!empty($mission_sum)): ?><span class="g-coin"><?= array_sum(array_column($mission_sum, 'n')) ?> quest</span><?php endif; ?>
        <span class="g-slot sm"><img src="<?= $ic('missions') ?>" alt=""></span><b>Quest</b><p>Harian, mingguan &amp; pencapaian.</p></a>
      <a href="/checkin" class="pd-tile" data-rv style="--d:.12s"><span class="g-coin"><?= $rp_short($ci_min) ?>–<?= $rp_short($ci_max) ?></span>
        <span class="g-slot sm"><img src="<?= $ic('checkin') ?>" alt=""></span><b>Bonus Harian</b><p>Hadiah naik tiap hari, 7 hari beruntun.</p></a>
      <a href="/referral" class="pd-tile" data-rv style="--d:.16s"><span class="g-coin"><?= $rp_short($ref_bonus) ?>/teman</span>
        <span class="g-slot sm"><img src="<?= $ic('referral') ?>" alt=""></span><b>Referral</b><p>+<?= rtrim(rtrim(number_format($ref_pct, 1, ',', ''), '0'), ',') ?>% dari deposit mereka.</p></a>
      <a href="/redeem" class="pd-tile" data-rv style="--d:.2s"><span class="g-slot sm"><img src="<?= $ic('redeem') ?>" alt=""></span><b>Kode Redeem</b><p>Tukar kode dari event &amp; pengumuman.</p></a>
      <a href="/threads" class="pd-tile wide" data-rv style="--d:.24s"><span class="g-slot sm"><img src="<?= $ic('megaphone') ?>" alt=""></span>
        <div class="tx"><b>Event Threads</b><p>Promosikan PanenCuan di Threads, kirim bukti, tunggu verifikasi.</p></div>
        <span class="g-coin"><?= $rp_short($thr_reward) ?>–<?= $rp_short($thr_reward2) ?></span></a>
    </div>
  </section>

  <!-- 2. SAWAH -->
  <section class="pd-sec" id="sawah">
    <div class="g-sec"><span class="g-ribbon"><img src="<?= $ic('padi') ?>" alt="">Cara Bertani</span><small>bibit sampai cuan</small></div>
    <div class="g-panel" data-rv>
      <div class="pd-flow">
        <div class="pd-st"><span class="g-slot sm"><img src="<?= $ic('seed') ?>" alt=""></span><div><b>1. Tanam bibit</b>
          <p>Ketuk petak kosong, pilih bibit. Bibit di kantong dipakai dulu; kalau kosong, 1 bibit dibeli otomatis pakai <strong>Saldo Beli</strong>.</p>
          <?php if ($farm_crops): ?><div class="pd-pills"><?php foreach ($farm_crops as $c): ?><span class="g-chip"><?= htmlspecialchars($c['name']) ?> · <?= $rp_short((float)$c['seed_price']) ?></span><?php endforeach; ?></div><?php endif; ?></div></div>
        <div class="pd-st"><span class="g-slot sm"><img src="<?= $ic('speed') ?>" alt=""></span><div><b>2. Tunggu tumbuh</b>
          <p>Timer di atas petak menunjukkan sisa waktu. Tanaman tetap tumbuh walau aplikasi ditutup. Mau cepat? Pakai <strong>Pupuk Kilat</strong>.</p></div></div>
        <div class="pd-st"><span class="g-slot sm"><img src="<?= $ic('cure') ?>" alt=""></span><div><b>3. Awas hama</b>
          <p>Kadang ulat datang<?= $farm_cfg['pest_chance'] > 0 ? ' (peluang ' . (int)$farm_cfg['pest_chance'] . '%)' : '' ?>. Semprot <strong>Pestisida</strong> agar panen tetap penuh; kalau dibiarkan hasilnya tinggal separuh.</p></div></div>
        <div class="pd-st"><span class="g-slot sm"><img src="<?= $ic('barn') ?>" alt=""></span><div><b>4. Panen ke Lumbung</b>
          <p>Ketuk tanaman yang berkilau emas. Hasil panen masuk <strong>Lumbung</strong> dan memberi XP untuk naik level petani &amp; membuka bibit baru.</p></div></div>
        <div class="pd-st"><span class="g-slot sm"><img src="<?= $ic('shop') ?>" alt=""></span><div><b>5. Jual di Pasar Pengepul</b>
          <p>Jual semua isi lumbung sekali ketuk, uangnya masuk <strong>Saldo Tarik</strong>. Petak baru bisa dibuka mulai <?= format_rp(farm_plot_cost($farm_cfg, $farm_cfg['free_plots'])) ?>.</p></div></div>
      </div>
      <a href="/farm" class="g-btn full shine" style="margin-top:12px"><img src="<?= $ic('padi') ?>" alt="">Mulai Bertani</a>
    </div>
  </section>

  <!-- 3. SALDO -->
  <section class="pd-sec" id="saldo">
    <div class="g-sec"><span class="g-ribbon sky"><img src="<?= $ic('piggy') ?>" alt="">Dua Jenis Saldo</span></div>
    <div class="pd-wallets" data-rv>
      <div class="pd-wal wd"><img class="big" src="<?= $ic('moneybag') ?>" alt=""><b>Saldo Tarik</b>
        <small>Dari</small><ul><li>Video &amp; jual panen</li><li>Quest &amp; bonus harian</li><li>Referral &amp; event</li></ul>
        <small>Untuk</small><ul><li>Dicairkan ke rekening</li></ul></div>
      <div class="pd-wal dep"><img class="big" src="<?= $ic('piggy') ?>" alt=""><b>Saldo Beli</b>
        <small>Dari</small><ul><li>Deposit</li></ul>
        <small>Untuk</small><ul><li>Level up</li><li>Beli bibit &amp; pupuk</li><li>Buka petak sawah</li></ul></div>
    </div>
  </section>

  <!-- 4. PANGKAT -->
  <?php if ($memberships): ?>
  <section class="pd-sec" id="pangkat">
    <div class="g-sec"><span class="g-ribbon gold"><img src="<?= $ic('upgrade') ?>" alt="">Pangkat</span><small>makin tinggi makin cuan</small></div>
    <div class="g-panel" data-rv>
      <div class="pd-rank">
        <?php foreach ($memberships as $m): $free = (float)$m['price'] <= 0; ?>
          <div class="pd-rk">
            <span class="g-slot sm"><img src="<?= $ic($free ? 'seed' : 'upgrade') ?>" alt=""></span>
            <div class="tx">
              <b><?= htmlspecialchars($m['name']) ?></b>
              <div class="meta">
                <span class="g-chip"><?= (int)$m['watch_limit'] ?> video/hari</span>
                <span class="g-chip">WD <?= $rp_short((float)$m['min_wd']) ?><?= (float)$m['max_wd'] > 0 ? '–' . $rp_short((float)$m['max_wd']) : '+' ?></span>
                <?php if (!$free): ?><span class="g-chip"><?= (int)$m['duration_days'] ?> hari</span><?php endif; ?>
              </div>
            </div>
            <span class="pr <?= $free ? 'free' : '' ?>"><?= $free ? 'Gratis' : $rp_short((float)$m['price']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <a href="/upgrade" class="g-btn gold full shine" style="margin-top:12px"><img src="<?= $ic('upgrade') ?>" alt="">Lihat Benefit &amp; Level Up</a>
    </div>
  </section>
  <?php endif; ?>

  <!-- 5. WITHDRAW -->
  <section class="pd-sec" id="tarik">
    <div class="g-sec"><span class="g-ribbon"><img src="<?= $ic('moneybag') ?>" alt="">Aturan Withdraw</span></div>
    <div class="g-panel pd-rules" data-rv>
      <div class="pd-rule"><span class="g-slot sm"><img src="<?= $ic('coins') ?>" alt=""></span><span>Batas minimal &amp; maksimal sekali tarik <strong>mengikuti pangkatmu</strong>.</span></div>
      <div class="pd-rule"><span class="g-slot sm"><img src="<?= $ic('speed') ?>" alt=""></span><span>Hanya <strong>1 withdraw diproses</strong> dalam satu waktu. Tunggu selesai sebelum tarik lagi.</span></div>
      <?php if ($wd_lock_s && $wd_lock_e): ?>
        <div class="pd-rule"><span class="g-slot sm"><img src="<?= $ic('lock') ?>" alt=""></span><span>Withdraw <strong>tutup pukul <?= htmlspecialchars(date('H:i', strtotime($wd_lock_s))) ?>–<?= htmlspecialchars(date('H:i', strtotime($wd_lock_e))) ?></strong>.</span></div>
      <?php endif; ?>
      <?php if ($free_name && ($wd_free_dana || $wd_free_1x || $wd_free_1day)): ?>
        <div class="pd-rule"><span class="g-slot sm"><img src="<?= $ic('seed') ?>" alt=""></span><span>Pangkat <strong><?= htmlspecialchars($free_name) ?></strong>:
          <?= implode(', ', array_filter([$wd_free_dana ? 'hanya ke DANA' : '', $wd_free_1x ? 'maksimal 1x withdraw' : '', $wd_free_1day ? 'akun minimal 1 hari' : ''])) ?>.</span></div>
      <?php endif; ?>
      <div class="pd-rule"><span class="g-slot sm"><img src="<?= $ic('piggy') ?>" alt=""></span><span>Rekening harus <strong>atas nama sendiri</strong>.<?= $wallets ? ' Tersedia: ' . htmlspecialchars(implode(', ', $wallets)) . '.' : '' ?></span></div>
      <a href="/withdraw" class="g-btn full" style="margin-top:4px"><img src="<?= $ic('moneybag') ?>" alt="">Withdraw</a>
    </div>
  </section>

  <!-- 6. FAQ -->
  <section class="pd-sec" id="faq">
    <div class="g-sec"><span class="g-ribbon sky"><img src="<?= $ic('chat') ?>" alt="">Tanya Jawab</span></div>
    <div class="pd-faq">
      <details class="pd-q" open><summary>Kenapa saldo tidak bisa ditarik?<i class="ph-bold ph-caret-down"></i></summary>
        <div>Cek: saldo sudah mencapai minimal pangkatmu, tidak ada withdraw lain yang diproses, rekening sudah diisi, dan sedang di luar jam tutup.</div></details>
      <details class="pd-q"><summary>Kuota video habis, lalu apa?<i class="ph-bold ph-caret-down"></i></summary>
        <div>Kuota video reset tiap hari. Sambil menunggu, kamu tetap bisa bertani, kejar quest, dan klaim bonus harian. Kuota lebih banyak ada di pangkat lebih tinggi.</div></details>
      <details class="pd-q"><summary>Bibit habis, bisa tanam gratis?<i class="ph-bold ph-caret-down"></i></summary>
        <div>Setiap tanam butuh 1 bibit. Bibit dibeli dengan Saldo Beli lewat Deposit, lalu hasil jual panennya masuk Saldo Tarik.</div></details>
      <details class="pd-q"><summary>Kapan bonus referral cair?<i class="ph-bold ph-caret-down"></i></summary>
        <div>Bonus <?= format_rp($ref_bonus) ?> per teman yang daftar<?= $ref_hold > 0 ? ' ditahan ' . $ref_hold . ' hari dulu' : '' ?>, plus <?= rtrim(rtrim(number_format($ref_pct, 1, ',', ''), '0'), ',') ?>% dari setiap deposit mereka. Akun palsu atau daftar sendiri terdeteksi otomatis dan bonusnya tidak diberikan.</div></details>
      <details class="pd-q"><summary>Bagaimana ganti rekening?<i class="ph-bold ph-caret-down"></i></summary>
        <div>Buka <a href="/edit-rekening">Bank Saya</a>. Pengajuan dicek admin dulu. Fitur ini tersedia untuk pangkat tertentu dan butuh Saldo Beli mengendap.</div></details>
      <details class="pd-q"><summary>Butuh bantuan?<i class="ph-bold ph-caret-down"></i></summary>
        <div>Ngobrol dengan petani lain di <a href="/global-chat">Komunitas</a>, atau hubungi CS lewat <a href="/livechat">Live Chat</a>.</div></details>
      <?php if (!empty($panduan_faq)): ?>
        <details class="pd-q"><summary>Info tambahan<i class="ph-bold ph-caret-down"></i></summary><div><?= nl2br(htmlspecialchars($panduan_faq)) ?></div></details>
      <?php endif; ?>
    </div>
  </section>

  <div class="pd-cta">
    <a href="/farm" class="g-btn"><img src="<?= $ic('padi') ?>" alt="">Bertani</a>
    <a href="<?= htmlspecialchars($panduan_cta_url) ?>" class="g-btn gold"><img src="<?= $ic('watch') ?>" alt=""><?= htmlspecialchars($panduan_cta_text) ?></a>
  </div>

</div>

<script>
// Status tur interaktif
(function () {
  const b = document.getElementById('badge-tour-watch');
  let done = false;
  try { done = localStorage.getItem('panen_tour_done_watch') === '1'; } catch (e) {}
  if (b && done) { b.textContent = '✓ Selesai'; b.classList.add('done'); }
})();

function startPanduanTour(track) {
  if (typeof window.startGlobalTour === 'function') {
    window.startGlobalTour('watch', 0);
  } else {
    try {
      localStorage.setItem('panen_tour_active', JSON.stringify({ track: 'watch', step: 0, isRunning: true, updatedAt: Date.now() }));
    } catch (e) {}
    window.location.href = '/home';
  }
}

// Navigasi bab: gulir halus + sorot bab aktif
(function () {
  const links = [...document.querySelectorAll('#pd-nav a')];
  links.forEach(a => a.addEventListener('click', e => {
    e.preventDefault(); e.stopPropagation();
    const t = document.querySelector(a.getAttribute('href'));
    if (t) window.scrollTo({ top: t.getBoundingClientRect().top + window.scrollY - 76, behavior: 'smooth' });
  }));
  const nav = document.getElementById('pd-nav');
  const io = new IntersectionObserver(entries => {
    entries.forEach(en => {
      if (!en.isIntersecting) return;
      links.forEach(a => a.classList.toggle('on', a.getAttribute('href') === '#' + en.target.id));
      const on = links.find(a => a.classList.contains('on'));
      if (on) nav.scrollTo({ left: on.offsetLeft - 12, behavior: 'smooth' });
    });
  }, { rootMargin: '-40% 0px -55% 0px' });
  document.querySelectorAll('.pd-sec').forEach(s => io.observe(s));
})();
</script>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>
