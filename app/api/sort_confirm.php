<?php
require_once __DIR__ . '/db.php';
require_role(['staff']);

$data = get_json_input();
$batch_id = $data['batch_id'] ?? null;
$item_id = $data['item_id'] ?? null;
$match_type = $data['st'] ?? 'auto'; // 'auto' or 'manual'

if (!$batch_id || !$item_id) {
    send_json(['error' => 'batch_id and item_id are required'], 400);
}

// Update item status
$stmt = $pdo->prepare("UPDATE items SET st = ? WHERE id = ? AND batch_id = ?");
$stmt->execute([$match_type, $item_id, $batch_id]);

// Check remaining pending items and flags count
$p_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM items WHERE batch_id = ? AND st = 'pending'");
$p_stmt->execute([$batch_id]);
$pending_count = (int)$p_stmt->fetch()['count'];

$f_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM flags WHERE batch_id = ?");
$f_stmt->execute([$batch_id]);
$flags_count = (int)$f_stmt->fetch()['count'];

$is_completed = false;
if ($pending_count === 0 && $flags_count === 0) {
    $c_stmt = $pdo->prepare("UPDATE batches SET status = 'Completed', completed_at = ? WHERE id = ?");
    $c_stmt->execute([time() * 1000, $batch_id]);
    $is_completed = true;
}

send_json([
    'success' => true,
    'batch_id' => (string)$batch_id,
    'item_id' => $item_id,
    'pending_count' => $pending_count,
    'flags_count' => $flags_count,
    'completed' => $is_completed
]);
