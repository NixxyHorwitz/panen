<?php
declare(strict_types=1);
/**
 * api/farm_action.php — REST endpoint Sawah Panen Cuan.
 *
 * POST /api/farm_action.php   (JSON body, header X-CSRF-Token)
 *   { action: "get_state" }
 *   { action: "plant",       plot_index, crop_id }
 *   { action: "speed_up",    plot_index, item_id? }
 *   { action: "cure",        plot_index, item_id? }
 *   { action: "harvest",     plot_index }
 *   { action: "sell_all" }
 *   { action: "unlock_plot", plot_index }
 *   { action: "buy",         kind: "seed"|"item", ref_id, qty }
 *
 * Setiap respon menyertakan `state` terbaru agar klien tidak perlu fetch ulang.
 * Sengaja tidak memakai auth/guard.php: guard menolak body JSON (dianggap
 * upload terlalu besar karena $_POST kosong) dan me-redirect, bukan JSON.
 */
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/farm.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function farm_json(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') farm_json(['ok' => false, 'msg' => 'Method Not Allowed'], 405);

$user = auth_user($pdo);
if (!$user) farm_json(['ok' => false, 'msg' => 'Sesi berakhir. Silakan login lagi.', 'reason' => 'auth'], 401);
if (!csrf_verify()) farm_json(['ok' => false, 'msg' => 'Sesi keamanan kedaluwarsa. Muat ulang halaman.', 'reason' => 'csrf'], 403);
if (is_maintenance($pdo) && !auth_admin()) farm_json(['ok' => false, 'msg' => 'Sistem sedang dalam perbaikan.', 'reason' => 'maintenance'], 503);

farm_ensure_schema($pdo);
if (!farm_config($pdo)['enabled']) farm_json(['ok' => false, 'msg' => 'Sawah Panen Cuan sedang dinonaktifkan.', 'reason' => 'disabled'], 403);

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) $body = $_POST; // fallback form-encoded
$uid = (int)$user['id'];
$plot = (int)($body['plot_index'] ?? 0);

$needsPlot = ['plant', 'speed_up', 'cure', 'harvest', 'unlock_plot'];
$action = (string)($body['action'] ?? '');
if (in_array($action, $needsPlot, true) && ($plot < 1 || $plot > FARM_TOTAL_PLOTS)) {
    farm_json(['ok' => false, 'msg' => 'Petak tidak valid.', 'reason' => 'invalid'], 422);
}

$result = match ($action) {
    'get_state'   => ['ok' => true, 'state' => farm_state($pdo, $uid)],
    'plant'       => farm_plant($pdo, $uid, $plot, (int)($body['crop_id'] ?? 0)),
    'speed_up'    => farm_speed_up($pdo, $uid, $plot, (int)($body['item_id'] ?? 0)),
    'cure'        => farm_cure($pdo, $uid, $plot, (int)($body['item_id'] ?? 0)),
    'harvest'     => farm_harvest($pdo, $uid, $plot),
    'sell_all'    => farm_sell_all($pdo, $uid),
    'unlock_plot' => farm_unlock_plot($pdo, $uid, $plot),
    'buy'         => farm_buy($pdo, $uid, (string)($body['kind'] ?? '') === 'seed' ? 'seed' : 'item', (int)($body['ref_id'] ?? 0), (int)($body['qty'] ?? 1)),
    default       => null,
};
if ($result === null) farm_json(['ok' => false, 'msg' => 'Aksi tidak dikenal.', 'reason' => 'invalid'], 400);

farm_json($result, ($result['reason'] ?? '') === 'server' ? 500 : 200);
