<?php
require_once __DIR__ . '/db.php';
require_role(['staff']);
require_once __DIR__ . '/image_matcher.php';

$data       = get_json_input();
$batch_id   = $data['batch_id']    ?? null;
$scanned_img = $data['scanned_img'] ?? null;

if (!$batch_id || !$scanned_img) {
    send_json(['error' => 'batch_id and scanned_img are required'], 400);
}

// Fetch batch
$stmt = $pdo->prepare("SELECT * FROM batches WHERE batch_id = ?");
$stmt->execute([$batch_id]);
$batch = $stmt->fetch();
if (!$batch) send_json(['error' => 'Batch not found'], 404);

// Fetch pending garments in this batch
$gs = $pdo->prepare(
    "SELECT * FROM garments WHERE batch_id = ? AND match_type = 'pending'"
);
$gs->execute([$batch_id]);
$pending = $gs->fetchAll();

if (empty($pending)) {
    send_json(['error' => 'Nothing left to match in this batch'], 400);
}

// Fetch threshold
$thr_row = $pdo->query("SELECT value FROM settings WHERE `key` = 'thr'")->fetch();
$thr = (int)($thr_row['value'] ?? 80);

$t0 = microtime(true);

$candidate_list = [];
foreach ($pending as $g) {
    $sim = compare_garment_images($scanned_img, $g['intake_image'] ?? '');
    $candidate_list[] = [
        'id'   => (string)$g['garment_id'],
        'name' => $g['garment_name'] ?? 'Item',
        'img'  => $g['intake_image'] ?? '',
        'sim'  => $sim
    ];
}

usort($candidate_list, fn($a, $b) => $b['sim'] - $a['sim']);

$duration = max(0.2, round(microtime(true) - $t0, 2));
$top      = $candidate_list[0];
$is_auto  = ($top['sim'] >= $thr) ? 1 : 0;

// Log to match_logs
$pdo->prepare(
    "INSERT INTO match_logs (batch_id, match_time, is_auto) VALUES (?, ?, ?)"
)->execute([$batch_id, $duration, $is_auto]);

send_json([
    'success'      => true,
    'bid'          => (string)$batch_id,
    'scan'         => $scanned_img,
    'thr'          => $thr,
    'duration'     => $duration,
    'list'         => array_map(fn($c) => [
        'id'  => $c['id'],
        'name'=> $c['name'],
        'sim' => $c['sim']
    ], $candidate_list),
    'top'          => $top['id'],
    'top_sim'      => $top['sim'],
    'auto_matched' => (bool)$is_auto
]);
