<?php
/**
 * lib/checkin.php — Kalender Panen 7 hari.
 * Hadiah tiap hari sudah pasti (bukan tebakan): naik bertahap dari checkin_reward_min
 * di Hari 1 sampai checkin_reward_max di Hari 7. Lewat sehari, streak mulai lagi dari Hari 1.
 */
declare(strict_types=1);

const CHECKIN_CYCLE = 7;

/** Hadiah per hari siklus, indeks 1..7. */
function checkin_rewards(PDO $pdo): array {
    $min = max(1, (int) setting($pdo, 'checkin_reward_min', '500'));
    $max = max($min, (int) setting($pdo, 'checkin_reward_max', '2000'));
    $out = [];
    for ($d = 1; $d <= CHECKIN_CYCLE; $d++) {
        $v = $min + ($max - $min) * ($d - 1) / (CHECKIN_CYCLE - 1);
        $out[$d] = $d === CHECKIN_CYCLE ? $max : max($min, (int) (round($v / 50) * 50));
    }
    return $out;
}

/**
 * Status check-in user dari kolom last_checkin + checkin_streak.
 * streak = hari beruntun yang masih hidup, day = hari siklus yang diklaim/akan diklaim.
 */
function checkin_state(array $user): array {
    $today = date('Y-m-d');
    $last  = (string) ($user['last_checkin'] ?? '');
    $saved = (int) ($user['checkin_streak'] ?? 0);
    $claimed = $last === $today;
    $alive   = $claimed || $last === date('Y-m-d', strtotime('-1 day'));
    $streak  = $alive ? max($claimed ? 1 : 0, $saved) : 0;
    $next    = $claimed ? $streak : $streak + 1;
    return [
        'claimed' => $claimed,
        'streak'  => $streak,
        'broken'  => !$alive && $saved > 0,
        'day'     => ($next - 1) % CHECKIN_CYCLE + 1,
    ];
}
