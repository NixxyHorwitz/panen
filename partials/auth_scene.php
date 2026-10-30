<?php
/**
 * partials/auth_scene.php — latar "Gerbang Sawah" untuk halaman Masuk & Daftar.
 * Gaya di assets/css/pc-auth.css. Bila ada assets/brand/auth-bg-wide.jpg /
 * auth-bg-portrait.jpg, foto itu dipakai sebagai latar di belakang sawah SVG.
 */
$_as_dir = dirname(__DIR__) . '/assets/brand/';
$_as_bg = [];
foreach (['wide' => 'auth-bg-wide.jpg', 'portrait' => 'auth-bg-portrait.jpg'] as $_k => $_f) {
    if (is_file($_as_dir . $_f)) $_as_bg[$_k] = '/assets/brand/' . $_f . '?v=' . filemtime($_as_dir . $_f);
}
?>
<?php if ($_as_bg): $_w = $_as_bg['wide'] ?? $_as_bg['portrait']; $_p = $_as_bg['portrait'] ?? $_w; ?>
<style>
.sc-photo { background-image: url('<?= $_w ?>'); }
@media (max-aspect-ratio: 4/5) { .sc-photo { background-image: url('<?= $_p ?>'); } }
.sc.has-photo .sc-sun, .sc.has-photo .sc-rays, .sc.has-photo .sc-land { display: none; }
</style>
<?php endif; ?>
<div class="sc<?= $_as_bg ? ' has-photo' : '' ?>" aria-hidden="true">
  <?php if ($_as_bg): ?><div class="sc-photo"></div><?php endif; ?>
  <div class="sc-rays"></div>
  <div class="sc-sun"></div>

  <span class="sc-cloud" style="top:7%;width:80px;animation-duration:46s;animation-delay:-12s"></span>
  <span class="sc-cloud" style="top:17%;width:60px;animation-duration:60s;animation-delay:-38s;opacity:.75"></span>
  <span class="sc-cloud" style="top:30%;width:70px;animation-duration:52s;animation-delay:-5s;opacity:.6"></span>

  <?php foreach ([[12, 26, 0], [15, 30, -1.2], [10, 22, -2.1], [36, 40, -17]] as [$_t, $_d, $_dl]): ?>
  <span class="sc-bird" style="top:<?= $_t ?>%;animation-duration:<?= $_d ?>s;animation-delay:<?= $_dl ?>s"><svg viewBox="0 0 26 12"><path d="M1 8Q7 1 13 7Q19 1 25 8" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
  <?php endforeach; ?>

  <span class="sc-kite"><svg viewBox="0 0 40 90">
    <path d="M20 2L36 22L20 46L4 22Z" fill="#fff" stroke="#9f1239" stroke-width="1.6" stroke-linejoin="round"/>
    <path d="M20 2L36 22H4Z" fill="#ef4444"/>
    <path d="M20 2V46M4 22H36" stroke="#7f1d1d" stroke-width="1" opacity=".5"/>
    <path d="M20 46Q14 58 22 66T18 88" fill="none" stroke="#ef4444" stroke-width="1.6" stroke-linecap="round"/>
    <path d="M17 56l6 2M18 68l6-1M16 80l6 1" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>
    <path d="M20 24Q-40 120 -120 200" fill="none" stroke="#fff" stroke-width=".8" opacity=".7"/>
  </svg></span>

  <div class="sc-land">
    <svg class="sc-terr" viewBox="0 0 1440 380" preserveAspectRatio="xMidYMax slice">
      <defs>
        <pattern id="scRows" width="14" height="10" patternUnits="userSpaceOnUse"><path d="M3 10V3M10 10V5" stroke="#3a8a28" stroke-width="2" stroke-linecap="round"/></pattern>
        <linearGradient id="scVolc" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#9aa9cf"/><stop offset="1" stop-color="#b9cbe0"/></linearGradient>
      </defs>
      <!-- Gunung & asap kawah -->
      <path d="M470 232L690 76Q720 60 750 76L980 232Z" fill="url(#scVolc)"/>
      <path d="M690 76Q720 60 750 76L732 92Q720 84 708 92Z" fill="#8193bd"/>
      <path d="M560 168L620 128L640 150L600 176Z" fill="#fff" opacity=".18"/>
      <g fill="#fff" opacity=".75"><circle cx="716" cy="54" r="10"/><circle cx="730" cy="44" r="8"/><circle cx="744" cy="32" r="6" opacity=".7"/></g>
      <!-- Bukit jauh & pepohonan -->
      <path d="M0 226Q180 160 360 208T720 200T1080 196T1440 210V380H0Z" fill="#a4d98b"/>
      <g fill="#7fc266"><circle cx="300" cy="206" r="14"/><circle cx="322" cy="200" r="18"/><circle cx="344" cy="208" r="12"/><circle cx="1010" cy="200" r="16"/><circle cx="1032" cy="196" r="20"/><circle cx="1056" cy="204" r="13"/><circle cx="540" cy="214" r="12"/><circle cx="560" cy="208" r="15"/></g>
      <!-- Teras 1 -->
      <path d="M0 252Q360 212 720 242T1440 236V380H0Z" fill="#7dcb5c"/>
      <path d="M0 252Q360 212 720 242T1440 236" fill="none" stroke="#5aab3e" stroke-width="5"/>
      <path class="sc-water" d="M0 264Q360 226 720 256T1440 250V258Q1080 268 720 264T0 274Z" fill="#d6f2ff" opacity=".85"/>
      <!-- Saung & nyiur -->
      <g transform="translate(868 196)">
        <path d="M6 44V22M50 44V22M28 44V24" stroke="#8a5a2b" stroke-width="3.5"/>
        <path d="M2 30H54" stroke="#a16c34" stroke-width="4"/>
        <path d="M-6 24L28 2L62 24Z" fill="#d9a441" stroke="#9a6a1e" stroke-width="2" stroke-linejoin="round"/>
        <path d="M6 18L28 4L50 18" fill="none" stroke="#f3cf73" stroke-width="2" opacity=".8"/>
      </g>
      <?php foreach ([[598, 240, 1], [150, 250, 1.2], [1300, 238, 1.15], [1188, 244, .8]] as [$_x, $_y, $_s]): ?>
      <g transform="translate(<?= $_x ?> <?= $_y ?>) scale(<?= $_s ?>)">
        <path d="M0 0Q-6 -44 8 -86" fill="none" stroke="#8a5a2b" stroke-width="6" stroke-linecap="round"/>
        <g fill="#3f9a2c" transform="translate(8 -88)">
          <path d="M0 0Q-26 -14 -46 6Q-24 -4 0 0Z"/><path d="M0 0Q26 -16 48 2Q24 -6 0 0Z"/>
          <path d="M0 0Q-14 -26 -36 -24Q-12 -16 0 0Z"/><path d="M0 0Q16 -28 38 -22Q14 -14 0 0Z"/>
          <path d="M0 0Q-30 4 -40 24Q-22 8 0 0Z" fill="#358a24"/><path d="M0 0Q30 6 40 26Q22 10 0 0Z" fill="#358a24"/>
          <circle cx="0" cy="2" r="4" fill="#7a4a1a"/>
        </g>
      </g>
      <?php endforeach; ?>
      <!-- Teras 2 -->
      <path d="M0 294Q300 264 720 290T1440 284V380H0Z" fill="#62bb45"/>
      <path d="M0 294Q300 264 720 290T1440 284" fill="none" stroke="#4a9a35" stroke-width="5"/>
      <path class="sc-water" style="animation-delay:-2s" d="M0 306Q300 276 720 302T1440 296V304Q1080 314 720 310T0 316Z" fill="#d6f2ff" opacity=".8"/>
      <!-- Teras depan + barisan padi -->
      <path d="M0 334Q360 308 720 328T1440 324V380H0Z" fill="#4aa534"/>
      <path d="M0 334Q360 308 720 328T1440 324V380H0Z" fill="url(#scRows)" opacity=".7"/>
      <path d="M0 334Q360 308 720 328T1440 324" fill="none" stroke="#3a8a28" stroke-width="5"/>
    </svg>

    <?php foreach (['l', 'r'] as $_side): ?>
    <svg class="sc-rice <?= $_side ?>" viewBox="0 0 150 130">
      <?php foreach ([[22, 'st', 92], [48, 'st b', 112], [74, 'st c', 100], [100, 'st', 120], [126, 'st b', 88]] as [$_x, $_c, $_h]): $_top = 130 - $_h; ?>
      <g class="<?= $_c ?>">
        <path d="M<?= $_x ?> 130Q<?= $_x - 4 ?> <?= 130 - $_h / 2 ?> <?= $_x + 6 ?> <?= $_top ?>" fill="none" stroke="#3f8f2c" stroke-width="3.2" stroke-linecap="round"/>
        <path d="M<?= $_x ?> 128Q<?= $_x - 22 ?> <?= 130 - $_h * .45 ?> <?= $_x - 26 ?> <?= 130 - $_h * .3 ?>" fill="none" stroke="#5cb843" stroke-width="2.6" stroke-linecap="round"/>
        <g fill="#f5c542" stroke="#c48a12" stroke-width=".8">
          <?php for ($_g = 0; $_g < 5; $_g++): $_gy = $_top + 4 + $_g * 7; $_gx = $_x + 8 + $_g * 2.6; ?>
          <ellipse cx="<?= $_gx ?>" cy="<?= $_gy ?>" rx="3.4" ry="2" transform="rotate(50 <?= $_gx ?> <?= $_gy ?>)"/>
          <?php endfor; ?>
        </g>
      </g>
      <?php endforeach; ?>
    </svg>
    <?php endforeach; ?>
  </div>

  <?php foreach ([[8, 9, 0], [21, 12, -4], [34, 10, -7], [52, 13, -2], [66, 9, -9], [79, 11, -5], [92, 14, -1]] as [$_l, $_d, $_dl]): ?>
  <span class="sc-spark" style="left:<?= $_l ?>%;animation-duration:<?= $_d ?>s;animation-delay:<?= $_dl ?>s"></span>
  <?php endforeach; ?>
</div>
