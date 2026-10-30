<?php
/**
 * seo.php — berkas SEO dinamis (domain mengikuti base_url, nama mengikuti pengaturan):
 *   /robots.txt       → seo.php?f=robots
 *   /sitemap.xml      → seo.php?f=sitemap
 *   /site.webmanifest → seo.php?f=manifest
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$f = (string)($_GET['f'] ?? '');
$name = (string)setting($pdo, 'site_name', 'PanenCuan');
header('Cache-Control: public, max-age=3600');

if ($f === 'robots') {
    header('Content-Type: text/plain; charset=utf-8');
    $robots = (string)setting($pdo, 'seo_robots', 'index,follow');
    echo "User-agent: *\n";
    if (str_contains($robots, 'noindex')) { echo "Disallow: /\n"; exit; }
    echo "Allow: /\n";
    foreach (['/console/', '/api/', '/auth/', '/user/', '/uploads/', '/scratch/', '/webhook', '/chat_action', '/notif_action'] as $p) echo "Disallow: {$p}\n";
    echo "\nSitemap: " . base_url('sitemap.xml') . "\n";
    exit;
}

if ($f === 'sitemap') {
    header('Content-Type: application/xml; charset=utf-8');
    // Hanya halaman publik (bisa dibuka tanpa login)
    $today = date('Y-m-d');
    $pages = [
        ['',         'daily',   '1.0', true],
        ['register', 'monthly', '0.9', false],
        ['login',    'monthly', '0.7', false],
    ];
    $img = base_url('assets/brand/panencuan-og.jpg');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
    foreach ($pages as [$path, $freq, $prio, $withImg]) {
        echo "  <url>\n    <loc>" . htmlspecialchars(base_url($path)) . "</loc>\n    <lastmod>{$today}</lastmod>\n    <changefreq>{$freq}</changefreq>\n    <priority>{$prio}</priority>\n";
        if ($withImg) echo "    <image:image>\n      <image:loc>" . htmlspecialchars($img) . "</image:loc>\n      <image:title>" . htmlspecialchars($name) . "</image:title>\n    </image:image>\n";
        echo "  </url>\n";
    }
    echo "</urlset>\n";
    exit;
}

if ($f === 'manifest') {
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo json_encode([
        'name'             => $name . ' — Nonton & Bertani Cuan',
        'short_name'       => $name,
        'description'      => (string)setting($pdo, 'seo_description', ''),
        'lang'             => 'id',
        'start_url'        => '/home?source=pwa',
        'scope'            => '/',
        'display'          => 'standalone',
        'orientation'      => 'portrait',
        'background_color' => '#fff0cf',
        'theme_color'      => '#15803d',
        'categories'       => ['games', 'entertainment', 'finance'],
        'icons'            => [
            ['src' => '/assets/brand/panencuan-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/assets/brand/panencuan-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/assets/brand/panencuan-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

http_response_code(404);
