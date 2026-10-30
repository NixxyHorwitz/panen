<?php
/**
 * partials/head_icons.php — favicon, ikon aplikasi & manifest (dipakai semua halaman).
 * Butuh $pdo. Ikon PNG besar diambil dari pengaturan favicon_path (Console › SEO).
 */
$_hi_root = dirname(__DIR__);
$_hi_v = static fn(string $p): string => $p . '?v=' . (@filemtime($_hi_root . '/' . ltrim(preg_replace('~\?.*$~', '', $p), '/')) ?: '1');
$_hi_fav = (string)setting($pdo, 'favicon_path', '/assets/brand/panencuan-192.png');
$_hi_fav = preg_match('~^https?://~', $_hi_fav) ? $_hi_fav : $_hi_v('/' . ltrim($_hi_fav, '/'));
?>
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" type="image/png" sizes="32x32" href="<?= $_hi_v('/assets/favicon-32.png') ?>">
<link rel="icon" type="image/png" sizes="16x16" href="<?= $_hi_v('/assets/favicon-16.png') ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?= htmlspecialchars($_hi_fav) ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= $_hi_v('/assets/apple-touch-icon.png') ?>">
<link rel="manifest" href="/site.webmanifest">
<meta name="msapplication-TileColor" content="#15803d">
<meta name="msapplication-TileImage" content="<?= $_hi_v('/assets/brand/panencuan-192.png') ?>">
<meta name="format-detection" content="telephone=no">
