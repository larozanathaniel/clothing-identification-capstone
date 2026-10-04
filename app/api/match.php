<?php
require_once __DIR__ . '/db.php';
require_role(['staff']);
require_once __DIR__ . '/image_matcher.php';

$data = get_json_input();
$batch_id = $data['batch_id'] ?? null;
$scanned_img = $data['scanned_img'] ?? null;

if (!$batch_id || !$scanned_img) {
    send_json(['error' => 'batch_id and scanned_img are required'], 400);
}

// Fetch batch and items
$stmt = $pdo->prepare("SELECT * FROM batches WHERE id = ?");
$stmt->execute([$batch_id]);
$batch = $stmt->fetch();

if (!$batch) {
    send_json(['error' => 'Batch not found'], 404);
}

$i_stmt = $pdo->prepare("SELECT * FROM items WHERE batch_id = ? AND st = 'pending'");
$i_stmt->execute([$batch_id]);
$pending_items = $i_stmt->fetchAll();

if (empty($pending_items)) {
    send_json(['error' => 'Nothing left to match in this batch'], 400);
}

// Fetch match threshold setting
$thr_stmt = $pdo->query("SELECT value FROM settings WHERE key = 'thr'");
$thr = (int)($thr_stmt->fetch()['value'] ?? 80);

$t0 = microtime(true);

$candidate_list = [];
foreach ($pending_items as $item) {
    $sim = compare_garment_images($scanned_img, $item['img']);
    $candidate_list[] = [
        'id' => $item['id'],
        'name' => $item['name'],
        'img' => $item['img'],
        'sim' => $sim
    ];
}

// Sort candidates by similarity descending
usort($candidate_list, function($a, $b) {
    return $b['sim'] - $a['sim'];
});

$duration = microtime(true) - $t0;
// Round duration to 1 decimal place or minimum 0.2s
$duration = max(0.2, round($duration, 2));

$top = $candidate_list[0];
$is_auto = ($top['sim'] >= $thr) ? 1 : 0;

// Log match execution timing
$log_stmt = $pdo->prepare("INSERT INTO match_logs (batch_id, match_time, is_auto, created_at) VALUES (?, ?, ?, ?)");
$log_stmt->execute([$batch_id, $duration, $is_auto, time() * 1000]);

send_json([
    'success' => true,
    'bid' => (string)$batch_id,
    'scan' => $scanned_img,
    'thr' => $thr,
    'duration' => $duration,
    'list' => array_map(function($c) {
        return [
            'id' => $c['id'],
            'name' => $c['name'],
            'sim' => $c['sim']
        ];
    }, $candidate_list),
    'top' => $top['id'],
    'top_sim' => $top['sim'],
    'auto_matched' => (bool)$is_auto
]);
