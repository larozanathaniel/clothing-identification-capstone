<?php
require_once __DIR__ . '/db.php';
require_role(['staff']);
if (!function_exists('imagecreatefromstring')) {
    send_json(['error' => 'PHP GD extension is not enabled. In XAMPP: open php.ini, change ";extension=gd" to "extension=gd", then restart Apache.'], 500);
}
require_once __DIR__ . '/image_matcher.php';

// If the best two candidates are closer than this many points, don't auto-match:
// two look-alike garments from different customers must go to the Manual tab.
const AMBIGUITY_MARGIN = 5;

$data        = get_json_input();
$scanned_img = $data['scanned_img'] ?? null;

if (!is_string($scanned_img) || strpos($scanned_img, 'data:image') !== 0) {
    send_json(['error' => 'scanned_img must be an image data URI'], 400);
}

// Search EVERY garment still waiting, across all open batches / customers
$pending = open_pending_garments($pdo);
if (empty($pending)) send_json(['error' => 'No garments are waiting to be sorted.'], 400);

$thr = (int)($pdo->query("SELECT value FROM settings WHERE `key` = 'thr'")->fetchColumn() ?: 80);

$t0 = microtime(true);
$candidates = [];
foreach ($pending as $g) {
    $candidates[] = [
        'id'       => (string)$g['garment_id'],
        'bid'      => (string)$g['batch_id'],
        'name'     => $g['garment_name'] ?? 'Item',
        'customer' => $g['customer'],
        'sim'      => compare_garment_images($scanned_img, garment_img_src($g))
    ];
}
usort($candidates, fn($a, $b) => $b['sim'] <=> $a['sim']);
$duration = round(microtime(true) - $t0, 3);

$top       = $candidates[0];
$second    = $candidates[1]['sim'] ?? null;
$ambiguous = $top['sim'] >= $thr && $second !== null && ($top['sim'] - $second) < AMBIGUITY_MARGIN;
$is_auto   = ($top['sim'] >= $thr && !$ambiguous) ? 1 : 0;

$pdo->prepare("INSERT INTO match_logs (batch_id, match_time, is_auto) VALUES (?, ?, ?)")
    ->execute([(int)$top['bid'], $duration, $is_auto]);

send_json([
    'success'      => true,
    'thr'          => $thr,
    'duration'     => $duration,
    'list'         => $candidates,
    'top'          => $top['id'],
    'top_sim'      => $top['sim'],
    'auto_matched' => (bool)$is_auto,
    'ambiguous'    => $ambiguous
]);
