<?php
require_once __DIR__ . '/db.php';
require_role(['staff']);
require_once __DIR__ . '/image_matcher.php';

$data        = get_json_input();
$batch_id    = $data['batch_id']    ?? null;
$scanned_img = $data['scanned_img'] ?? null;

if (!$batch_id || !$scanned_img) send_json(['error' => 'batch_id and scanned_img are required'], 400);
if (!is_string($scanned_img) || strpos($scanned_img, 'data:image') !== 0) {
    send_json(['error' => 'scanned_img must be an image data URI'], 400);
}

$stmt = $pdo->prepare("SELECT * FROM batches WHERE batch_id = ?");
$stmt->execute([$batch_id]);
$batch = $stmt->fetch();
if (!$batch) send_json(['error' => 'Batch not found'], 404);
if ($batch['status'] !== 'Ready to Sort') send_json(['error' => 'This batch is not ready to sort.'], 409);

// Similarity search is restricted to garments of THIS batch that are still pending
$gs = $pdo->prepare("SELECT * FROM garments WHERE batch_id = ? AND match_type = 'pending'");
$gs->execute([$batch_id]);
$pending = $gs->fetchAll();
if (empty($pending)) send_json(['error' => 'Nothing left to match in this batch'], 400);

$thr = (int)($pdo->query("SELECT value FROM settings WHERE `key` = 'thr'")->fetchColumn() ?: 80);

$t0 = microtime(true);
$candidates = [];
foreach ($pending as $g) {
    $candidates[] = [
        'id'   => (string)$g['garment_id'],
        'name' => $g['garment_name'] ?? 'Item',
        'sim'  => compare_garment_images($scanned_img, garment_img_src($g))
    ];
}
usort($candidates, fn($a, $b) => $b['sim'] <=> $a['sim']);
$duration = round(microtime(true) - $t0, 3);          // real elapsed time, no artificial floor

$top     = $candidates[0];
$is_auto = ($top['sim'] >= $thr) ? 1 : 0;

$pdo->prepare("INSERT INTO match_logs (batch_id, match_time, is_auto) VALUES (?, ?, ?)")
    ->execute([$batch_id, $duration, $is_auto]);

send_json([
    'success'      => true,
    'bid'          => (string)$batch_id,
    'thr'          => $thr,
    'duration'     => $duration,
    'list'         => $candidates,
    'top'          => $top['id'],
    'top_sim'      => $top['sim'],
    'auto_matched' => (bool)$is_auto
]);
