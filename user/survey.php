<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth/guard.php';

// Cek apakah user sudah pernah mengisi survei sebelumnya
$checkStmt = $pdo->prepare("SELECT * FROM user_surveys WHERE user_id = ? LIMIT 1");
$checkStmt->execute([$user['id']]);
$existingSurvey = $checkStmt->fetch(PDO::FETCH_ASSOC);

// Survei bisa ditutup admin (Console › Survei). Yang sudah mengisi tetap bisa melihat jawabannya.
$survey_open = setting($pdo, 'survey_enabled', '1') === '1';
if (!$survey_open && !$existingSurvey) {
    redirect('/home');
}

$flash = '';
$flashType = '';

// Proses Submit Survei
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$survey_open) {
        $flash = 'Survei sedang ditutup.';
        $flashType = 'info';
    } elseif ($existingSurvey) {
        $flash = 'Kamu sudah pernah mengisi survei ini dan mengklaim hadiah Rp 15.000!';
        $flashType = 'info';
    } else {
        $source_choice = trim((string)($_POST['source_choice'] ?? ''));
        $source_custom = trim((string)($_POST['source_custom'] ?? ''));
        $experience    = trim((string)($_POST['experience'] ?? ''));
        $rating        = (int)($_POST['satisfaction_rating'] ?? 0);
        $feedback      = trim((string)($_POST['feedback_message'] ?? ''));

        // Menentukan final source info
        $source_info = $source_choice;
        if ($source_choice === 'Lainnya' || empty($source_choice)) {
            $source_info = !empty($source_custom) ? $source_custom : 'Lainnya';
        } elseif (!empty($source_custom) && $source_choice !== 'Lainnya') {
            $source_info = "{$source_choice} ({$source_custom})";
        }

        // Validasi
        if (empty($source_info)) {
            $flash = 'Mohon pilih atau tuliskan dari mana kamu mengetahui website ini.';
            $flashType = 'danger';
        } elseif (mb_strlen($experience) < 5) {
            $flash = 'Mohon ceritakan sedikit pengalamanmu menggunakan web ini (minimal 5 karakter).';
            $flashType = 'danger';
        } elseif ($rating < 1 || $rating > 5) {
            $flash = 'Mohon pilih rating kepuasanmu dengan salah satu emoji di bawah.';
            $flashType = 'danger';
        } elseif (mb_strlen($feedback) < 3) {
            $flash = 'Mohon tuliskan pesan, saran, atau keluhanmu untuk kami (minimal 3 karakter).';
            $flashType = 'danger';
        } else {
            // Lakukan Transaksi Penambahan Hadiah & Penyimpanan Survei
            try {
                $pdo->beginTransaction();

                // 1. Kunci dan pastikan belum pernah ada di database
                $lockCheck = $pdo->prepare("SELECT id FROM user_surveys WHERE user_id = ? FOR UPDATE");
                $lockCheck->execute([$user['id']]);
                if ($lockCheck->fetch()) {
                    $pdo->rollBack();
                    $flash = 'Kamu sudah pernah mengklaim hadiah survei ini.';
                    $flashType = 'info';
                } else {
                    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
                    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
                    $reward = 15000.00;

                    // 2. Simpan Jawaban Survei
                    $ins = $pdo->prepare("
                        INSERT INTO user_surveys 
                        (user_id, source_info, source_other, experience, satisfaction_rating, feedback_message, reward_amount, ip_address, user_agent, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $ins->execute([
                        $user['id'],
                        $source_info,
                        $source_custom ?: null,
                        $experience,
                        $rating,
                        $feedback,
                        $reward,
                        $ip,
                        $ua
                    ]);

                    // 3. Tambahkan Saldo Tarik (balance_wd) dan total_earned
                    $updUser = $pdo->prepare("
                        UPDATE users 
                        SET balance_wd = balance_wd + ?, total_earned = total_earned + ? 
                        WHERE id = ?
                    ");
                    $updUser->execute([$reward, $reward, $user['id']]);

                    // 4. Tambahkan Notifikasi Masuk
                    $notifTitle = "Hadiah Survei Rp 15.000 Diterima! 🎉";
                    $notifMsg   = "Terima kasih sudah meluangkan waktu mengisi survei. Hadiah Rp 15.000 saldo tarik telah otomatis ditambahkan ke akunmu.";
                    $insNotif   = $pdo->prepare("
                        INSERT INTO notifications 
                        (title, message, type, icon, target_type, target_user_ids, action_url, action_text, created_at)
                        VALUES (?, ?, 'congrats', '🎁', 'single', ?, '/withdraw', 'Withdraw', NOW())
                    ");
                    $insNotif->execute([$notifTitle, $notifMsg, (string)$user['id']]);

                    $pdo->commit();

                    // Re-fetch survey
                    $checkStmt->execute([$user['id']]);
                    $existingSurvey = $checkStmt->fetch(PDO::FETCH_ASSOC);

                    // Re-fetch user balance
                    $uStmt = $pdo->prepare("SELECT balance_wd, total_earned FROM users WHERE id = ?");
                    $uStmt->execute([$user['id']]);
                    $freshU = $uStmt->fetch(PDO::FETCH_ASSOC);
                    if ($freshU) {
                        $user['balance_wd'] = $freshU['balance_wd'];
                        $user['total_earned'] = $freshU['total_earned'];
                    }

                    $flash = 'Terima kasih banyak! Jawabanmu tersimpan dan Saldo Tarik Rp 15.000 telah masuk ke akunmu.';
                    $flashType = 'success';
                }
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flash = 'Terjadi kesalahan sistem saat menyimpan jawaban. Silakan coba lagi.';
                $flashType = 'danger';
            }
        }
    }
}

$pageTitle = 'Survei Berhadiah Rp 15.000';
$activePage = 'survey';
require_once dirname(__DIR__) . '/partials/header.php';
?>

<style>
/* ══ SURVEI — Golden Hour game UI (komponen dasar: assets/css/pc-game.css) ══ */
.sv-flash { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 16px; font-size: 12px; font-weight: 800; }
.sv-flash i { font-size: 18px; flex-shrink: 0; }
.sv-flash.danger { background: #ffe4e6; color: #9f1239; box-shadow: inset 0 0 0 2px #fecdd3; }
.sv-flash.success { background: #dcfce7; color: #14532d; box-shadow: inset 0 0 0 2px #bbf7d0; }
.sv-flash.info { background: #e0f2fe; color: #0369a1; box-shadow: inset 0 0 0 2px #bae6fd; }
.sv-bounty { display: inline-flex; align-items: center; gap: 5px; margin-top: 8px; }
.sv-bounty .g-coin { font-size: 15px; padding: 3px 12px 3px 4px; }
.sv-bounty .g-coin img { width: 22px; height: 22px; }

.sv-warn { display: flex; align-items: flex-start; gap: 9px; padding: 10px 11px; border-radius: 16px; background: #fff3c4; box-shadow: inset 0 0 0 2px #fde68a; font-size: 11.5px; font-weight: 700; color: #7a4f00; line-height: 1.45; }
.sv-warn strong { color: #4a2b00; }

.sv-q { display: flex; flex-direction: column; gap: 10px; }
.sv-q-h { display: flex; align-items: center; gap: 9px; }
.sv-q-h em { width: 28px; height: 28px; flex-shrink: 0; border-radius: 50%; display: grid; place-items: center; font-style: normal; font-family: var(--pc-font); font-size: 15px; font-weight: 700; color: #4a3000; background: linear-gradient(180deg, #ffd54f, #f6b70a); box-shadow: 0 2px 0 #b07800; }
.sv-q-h b { display: block; font-family: var(--pc-font); font-size: 15.5px; font-weight: 600; line-height: 1.2; color: #4a2b00; }
.sv-q-h small { display: block; font-size: 11px; font-weight: 700; color: var(--pc-muted); }

/* Pilihan sumber */
.source-reef-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; }
.source-pearl-chip { position: relative; display: flex; align-items: center; gap: 7px; min-width: 0; padding: 9px 10px; border-radius: 14px; cursor: pointer; user-select: none; background: #fff; box-shadow: inset 0 0 0 2px #f1dfb4, 0 3px 0 #ecd59c; transition: transform .15s var(--pc-ease); }
.source-pearl-chip:active { transform: scale(.95); }
.source-pearl-chip.selected { transform: translateY(-2px); background: #f0fdf4; box-shadow: inset 0 0 0 2.5px #22c55e, 0 4px 0 #15803d; }
.source-pearl-chip input[type="radio"] { display: none; }
.source-pearl-icon { flex-shrink: 0; font-size: 18px; color: #8a6a3a; }
.source-pearl-chip.selected .source-pearl-icon { color: #15803d; }
.source-pearl-label { min-width: 0; font-size: 12.5px; font-weight: 800; color: var(--pc-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.source-pearl-chip .source-pearl-check { display: none; margin-left: auto; flex-shrink: 0; font-size: 15px; color: #16a34a; }
.source-pearl-chip.selected .source-pearl-check { display: inline-block; }

.survey-field-input { width: 100%; box-sizing: border-box; padding: 11px 12px; border: 0; border-radius: 14px; outline: none; background: #fff; box-shadow: inset 0 0 0 2px #f1dfb4; font: 700 13.5px/1.45 'Nunito', sans-serif; color: var(--pc-ink); transition: box-shadow .15s; }
.survey-field-input:focus { box-shadow: inset 0 0 0 2px #22c55e, 0 0 0 4px rgba(34, 197, 94, .15); }
.survey-field-input::placeholder { color: #a3a89a; }
.survey-textarea { min-height: 84px; resize: vertical; }
.field-meta-row { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-top: -4px; font-size: 11px; font-weight: 700; color: var(--pc-muted); }
.field-meta-counter { flex-shrink: 0; font-weight: 900; color: #c2410c; font-variant-numeric: tabular-nums; }
.field-meta-counter.valid { color: #15803d; }

/* Rating */
.rating-marine-deck { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 6px; }
.rating-marine-node { min-width: 0; padding: 8px 2px 7px; border-radius: 14px; text-align: center; cursor: pointer; user-select: none; background: #fff; box-shadow: inset 0 0 0 2px #f1dfb4, 0 3px 0 #ecd59c; transition: transform .2s var(--pc-ease); }
.rating-marine-node:active { transform: scale(.92); }
.rating-marine-node.selected { transform: translateY(-4px) scale(1.04); background: #fff7d1; box-shadow: inset 0 0 0 2.5px #f6b70a, 0 4px 0 #b07800; }
.rating-marine-node input[type="radio"] { display: none; }
.rating-marine-emoji { display: block; margin-bottom: 3px; font-size: 24px; line-height: 1; transition: transform .2s var(--pc-ease); }
.rating-marine-node.selected .rating-marine-emoji { transform: scale(1.2); }
.rating-marine-lbl { display: block; font-size: 10px; font-weight: 900; color: var(--pc-muted); }
.rating-marine-node.selected .rating-marine-lbl { color: #8a5a00; }
.rating-callout { min-height: 18px; text-align: center; font-size: 11.5px; font-weight: 800; color: #8a5a00; }

.sv-pledge { display: flex; align-items: center; gap: 8px; padding: 9px 11px; border-radius: 14px; background: #fff1f2; box-shadow: inset 0 0 0 2px #fecdd3; font-size: 11.5px; font-weight: 700; color: #9f1239; line-height: 1.4; }
.sv-foot { text-align: center; font-size: 11.5px; font-weight: 700; color: var(--pc-muted); }

/* Hasil */
.sv-done { text-align: center; }
.sv-done .art { display: block; width: 92px; height: 92px; margin: 0 auto 4px; object-fit: contain; animation: gBob 3s ease-in-out infinite; filter: drop-shadow(0 8px 8px rgba(120, 70, 10, .25)); }
.sv-done h2 { margin: 0; font-family: var(--pc-font); font-size: 20px; font-weight: 700; color: #4a2b00; }
.sv-done h2.bad { color: #be123c; }
.sv-done > p { margin: 4px 0 12px; font-size: 12px; font-weight: 700; color: var(--pc-muted); line-height: 1.45; }
.sv-bal { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; padding: 10px 12px; border-radius: 16px; text-align: left; background: #dcfce7; }
.sv-bal.bad { background: #ffe4e6; }
.sv-bal small { display: block; font-size: 11px; font-weight: 900; color: #14532d; }
.sv-bal.bad small { color: #9f1239; }
.sv-bal b { display: block; font-family: var(--pc-font); font-size: 19px; font-weight: 700; color: #15803d; }
.sv-bal.bad b { color: #be123c; }
.sv-reason { margin-bottom: 12px; padding: 9px 11px; border-radius: 14px; text-align: left; background: #fff1f2; box-shadow: inset 0 0 0 2px #fecdd3; font-size: 12px; font-weight: 700; color: #334155; }
.sv-reason small { display: block; margin-bottom: 2px; font-size: 10.5px; font-weight: 900; color: #be123c; text-transform: uppercase; }
.sv-log { padding: 10px 12px; border-radius: 16px; text-align: left; background: #fff; box-shadow: inset 0 0 0 1.5px #f1dfb4; font-size: 12px; }
.sv-log-h { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; font-weight: 900; color: var(--pc-ink); }
.sv-log-row { display: flex; justify-content: space-between; gap: 10px; padding: 6px 0; border-bottom: 1.5px dashed #f1dfb4; }
.sv-log-row span:first-child { flex-shrink: 0; font-weight: 700; color: var(--pc-muted); }
.sv-log-row span:last-child { font-weight: 800; color: var(--pc-ink); text-align: right; }
.sv-log-q { margin-top: 6px; }
.sv-log-q span { font-weight: 700; color: var(--pc-muted); }
.sv-log-q div { margin-top: 3px; padding: 7px 10px; border-radius: 11px; background: #fff7d1; font-weight: 700; font-style: italic; color: #6b4300; word-break: break-word; }
.sv-log-t { margin-top: 8px; text-align: right; font-size: 10.5px; font-weight: 700; color: var(--pc-muted); }
.sv-acts { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }
.sv-acts.one { grid-template-columns: 1fr; }
</style>

<?php
$sv_rating = [1 => '😡 Sangat buruk (1/5)', 2 => '🙁 Kurang puas (2/5)', 3 => '😐 Biasa saja (3/5)', 4 => '🙂 Puas (4/5)', 5 => '🤩 Sangat puas (5/5)'];
$sv_log = static function (array $s, bool $withTime) use ($sv_rating): void { ?>
  <div class="sv-log">
    <div class="sv-log-h"><img src="/assets/img/icons3d/survey.png" alt="" style="width:20px;height:20px">Jawaban kamu</div>
    <div class="sv-log-row"><span>Sumber info</span><span><?= htmlspecialchars((string)$s['source_info']) ?></span></div>
    <div class="sv-log-row"><span>Rating</span><span><?= $sv_rating[(int)$s['satisfaction_rating']] ?? ((int)$s['satisfaction_rating'] . '/5') ?></span></div>
    <div class="sv-log-q"><span>Pengalaman</span><div>"<?= htmlspecialchars((string)$s['experience']) ?>"</div></div>
    <div class="sv-log-q"><span>Pesan / masukan</span><div>"<?= htmlspecialchars((string)$s['feedback_message']) ?>"</div></div>
    <?php if ($withTime): ?><div class="sv-log-t">Dikirim <?= date('d M Y, H:i', strtotime($s['created_at'])) ?> WIB</div><?php endif; ?>
  </div>
<?php };
?>
<div class="g-page">

  <?php if ($flash): ?>
  <div class="sv-flash <?= htmlspecialchars($flashType) ?>">
    <i class="ph-fill <?= $flashType === 'success' ? 'ph-check-circle' : ($flashType === 'info' ? 'ph-info' : 'ph-warning-circle') ?>"></i>
    <span><?= htmlspecialchars($flash) ?></span>
  </div>
  <?php endif; ?>

  <?php if ($existingSurvey): $revoked = ($existingSurvey['status'] ?? 'completed') === 'revoked'; ?>
    <div class="g-panel sv-done" data-rv>
      <img class="art" src="/assets/img/icons3d/<?= $revoked ? 'lock' : 'chest_open' ?>.png" alt="">
      <?php if ($revoked): ?>
        <h2 class="bad">Hadiah survei dibatalkan</h2>
        <p>Jawaban survei <strong>dibatalkan</strong> dan hadiah <strong><?= format_rp((float)$existingSurvey['reward_amount']) ?></strong> ditarik kembali oleh admin karena terindikasi asal-asalan.</p>
        <?php if (!empty($existingSurvey['revoke_reason'])): ?>
          <div class="sv-reason"><small>Alasan</small><?= htmlspecialchars((string)$existingSurvey['revoke_reason']) ?></div>
        <?php endif; ?>
        <div class="sv-bal bad"><span class="g-slot sm"><img src="/assets/img/icons3d/moneybag.png" alt=""></span><div><small>Saldo Tarik sekarang</small><b><?= format_rp((float)($user['balance_wd'] ?? 0)) ?></b></div></div>
        <?php $sv_log($existingSurvey, false); ?>
        <div class="sv-acts one"><a href="/home" class="g-btn soft"><i class="ph-bold ph-house"></i>Kembali ke Home</a></div>
      <?php else: ?>
        <h2>Survei selesai!</h2>
        <p>Terima kasih atas masukanmu. Hadiah <strong><?= format_rp((float)$existingSurvey['reward_amount']) ?></strong> sudah masuk ke Saldo Tarik.</p>
        <div class="sv-bal"><span class="g-slot sm"><img src="/assets/img/icons3d/moneybag.png" alt=""></span><div><small>Saldo Tarik sekarang</small><b><?= format_rp((float)($user['balance_wd'] ?? 0)) ?></b></div></div>
        <?php $sv_log($existingSurvey, true); ?>
        <div class="sv-acts">
          <a href="/withdraw" class="g-btn gold shine"><img src="/assets/img/icons3d/moneybag.png" alt="">Withdraw</a>
          <a href="/home" class="g-btn soft"><i class="ph-bold ph-house"></i>Ke Home</a>
        </div>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <section class="g-hero" data-rv>
      <span class="g-sun"></span><span class="g-cloud c1"></span><span class="g-cloud c2"></span>
      <div class="g-hero-row">
        <div style="flex:1;min-width:0">
          <h1>Survei Berhadiah</h1>
          <p>Jawab 4 pertanyaan singkat, hadiah langsung masuk Saldo Tarik.</p>
          <div class="sv-bounty"><span class="g-coin"><img src="/assets/img/icons3d/coins.png" alt="">+Rp 15.000</span></div>
        </div>
        <div class="g-hero-art"><img src="/assets/img/icons3d/survey.png" alt=""></div>
      </div>
    </section>

    <div class="sv-warn" data-rv style="--d:.05s">
      <span class="g-slot sm"><img src="/assets/img/icons3d/lock.png" alt=""></span>
      <span><strong>Isi dengan jujur.</strong> Admin meninjau semua jawaban; jawaban asal-asalan atau spam membuat hadiah <u>ditarik kembali</u>.</span>
    </div>

    <form method="POST" id="surveyForm" onsubmit="return validateSurvey(event)" style="display:flex;flex-direction:column;gap:12px">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="g-panel sv-q" data-rv>
        <div class="sv-q-h"><em>1</em><div><b>Dari mana kamu tahu PanenCuan?</b><small>Pilih tempat pertama kamu menemukan kami.</small></div></div>
        <div class="source-reef-grid">
          <?php foreach ([['Threads', 'ph-at', 'src-threads', 'Threads'], ['Facebook', 'ph-facebook-logo', 'src-fb', 'Facebook'], ['Instagram', 'ph-instagram-logo', 'src-ig', 'Instagram'],
                          ['Google', 'ph-google-logo', 'src-google', 'Google'], ['Teman', 'ph-users-three', 'src-teman', 'Teman / Keluarga'], ['Lainnya', 'ph-pencil-simple-line', 'src-other', 'Lainnya...']] as [$val, $sic, $sid, $slbl]): ?>
            <label class="source-pearl-chip" onclick="selectSource('<?= $val ?>')">
              <input type="radio" name="source_choice" value="<?= $val ?>" id="<?= $sid ?>">
              <i class="ph-bold <?= $sic ?> source-pearl-icon"></i>
              <span class="source-pearl-label"><?= $slbl ?></span>
              <i class="ph-fill ph-check-circle source-pearl-check"></i>
            </label>
          <?php endforeach; ?>
        </div>
        <input type="text" name="source_custom" id="source_custom" class="survey-field-input" placeholder="Keterangan sumber lainnya (opsional)..." maxlength="150">
      </div>

      <div class="g-panel sv-q" data-rv>
        <div class="sv-q-h"><em>2</em><div><b>Bagaimana pengalamanmu di sini?</b><small>Ceritakan kesanmu soal video, sawah, deposit atau withdraw.</small></div></div>
        <textarea name="experience" id="experience" class="survey-field-input survey-textarea" placeholder="Tulis pengalamanmu dengan kalimat sendiri (min. 10 karakter)..." required oninput="updateCharCount('experience', 'exp-counter', 10)"></textarea>
        <div class="field-meta-row"><span>Kalimat jelas &amp; jujur</span><span class="field-meta-counter" id="exp-counter">0 / 10 karakter</span></div>
      </div>

      <div class="g-panel sv-q" data-rv>
        <div class="sv-q-h"><em>3</em><div><b>Seberapa puas kamu?</b><small>Pilih ekspresi yang paling pas.</small></div></div>
        <div class="rating-marine-deck">
          <?php foreach ([1 => ['😡', 'Buruk', 'Sangat buruk / butuh banyak perbaikan'], 2 => ['🙁', 'Kurang', 'Kurang puas / butuh peningkatan'], 3 => ['😐', 'Biasa', 'Biasa saja / cukup standar'],
                          4 => ['🙂', 'Puas', 'Puas / pengalaman seru'], 5 => ['🤩', 'Mantap!', 'Sangat puas / rekomen banget!']] as $rv => [$emo, $rl, $rdesc]): ?>
            <label class="rating-marine-node" onclick="selectRating(<?= $rv ?>, '<?= $emo ?> <?= $rdesc ?>')">
              <input type="radio" name="satisfaction_rating" value="<?= $rv ?>" id="rate-<?= $rv ?>">
              <span class="rating-marine-emoji"><?= $emo ?></span>
              <span class="rating-marine-lbl"><?= $rl ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="rating-callout" id="ratingCallout">Ketuk salah satu emoji</div>
      </div>

      <div class="g-panel sv-q" data-rv>
        <div class="sv-q-h"><em>4</em><div><b>Pesan untuk kami</b><small>Ide fitur, keluhan, atau saran.</small></div></div>
        <textarea name="feedback_message" id="feedback_message" class="survey-field-input survey-textarea" placeholder="Tulis pesan, saran, atau masukanmu di sini..." required oninput="updateCharCount('feedback_message', 'fb-counter', 5)"></textarea>
        <div class="field-meta-row"><span>Pendapatmu sangat berarti</span><span class="field-meta-counter" id="fb-counter">0 / 5 karakter</span></div>
      </div>

      <div class="sv-pledge"><i class="ph-fill ph-shield-check" style="font-size:18px;flex-shrink:0"></i><span>Saya mengisi dengan sadar &amp; jujur. Hadiah dapat dibatalkan bila terbukti spam.</span></div>

      <button type="submit" class="g-btn gold full shine" id="btnSubmitSurvey"><img src="/assets/img/icons3d/coins.png" alt="">Kirim &amp; klaim Rp 15.000</button>
      <div class="sv-foot">Hadiah otomatis masuk Saldo Tarik (1x per akun).</div>
    </form>
  <?php endif; ?>

</div>

<script>
let currentSelectedSource = '';
let currentSelectedRating = 0;
const svWarn = (m) => (typeof nToast === 'function' ? nToast(m, 'warn') : alert(m));

function selectSource(val) {
  currentSelectedSource = val;
  document.querySelectorAll('.source-pearl-chip').forEach(el => el.classList.remove('selected'));
  
  const radio = document.querySelector(`input[name="source_choice"][value="${val}"]`);
  if (radio) {
    radio.checked = true;
    radio.closest('.source-pearl-chip').classList.add('selected');
  }

  const customInput = document.getElementById('source_custom');
  if (customInput) {
    if (val === 'Lainnya') {
      customInput.placeholder = "Ketikkan dari mana kamu tahu web ini...";
      customInput.focus();
    } else {
      customInput.placeholder = `Atau tuliskan catatan tambahan tentang ${val} (opsional)...`;
    }
  }
}

function selectRating(val, label) {
  currentSelectedRating = val;
  document.querySelectorAll('.rating-marine-node').forEach(el => el.classList.remove('selected'));

  const radio = document.getElementById(`rate-${val}`);
  if (radio) {
    radio.checked = true;
    radio.closest('.rating-marine-node').classList.add('selected');
  }

  const callout = document.getElementById('ratingCallout');
  if (callout) {
    callout.innerHTML = `Pilihanmu: <strong>${label}</strong>`;
  }
}

function updateCharCount(inputId, counterId, minLen) {
  const input = document.getElementById(inputId);
  const counter = document.getElementById(counterId);
  if (!input || !counter) return;

  const len = input.value.trim().length;
  counter.textContent = `${len} / ${minLen} karakter`;
  if (len >= minLen) {
    counter.classList.add('valid');
  } else {
    counter.classList.remove('valid');
  }
}

function validateSurvey(e) {
  const custom = (document.getElementById('source_custom')?.value || '').trim();
  if (!currentSelectedSource && !custom) {
    svWarn('Mohon pilih salah satu sumber info di pertanyaan 1.');
    e.preventDefault();
    return false;
  }

  const exp = (document.getElementById('experience')?.value || '').trim();
  if (exp.length < 10) {
    svWarn('Mohon ceritakan pengalamanmu secara jelas di pertanyaan 2 (minimal 10 karakter). Dilarang mengisi asal-asalan.');
    document.getElementById('experience')?.focus();
    e.preventDefault();
    return false;
  }

  if (currentSelectedRating < 1 || currentSelectedRating > 5) {
    svWarn('Mohon tentukan rating kepuasan emoji di pertanyaan 3.');
    e.preventDefault();
    return false;
  }

  const fb = (document.getElementById('feedback_message')?.value || '').trim();
  if (fb.length < 5) {
    svWarn('Mohon tuliskan pesan atau keluhanmu di pertanyaan 4 (minimal 5 karakter).');
    document.getElementById('feedback_message')?.focus();
    e.preventDefault();
    return false;
  }

  const btn = document.getElementById('btnSubmitSurvey');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="ph-bold ph-spinner ph-spin"></i> Mengirim...';
  }
  return true;
}
</script>

<?php require_once dirname(__DIR__) . '/partials/footer.php'; ?>
