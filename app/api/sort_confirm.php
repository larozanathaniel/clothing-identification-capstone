<?php
require_once __DIR__ . '/db.php';
require_role(['staff']);

$data       = get_json_input();
$batch_id   = $data['batch_id'] ?? null;
$item_id    = $data['item_id']  ?? null;
$match_type = $data['st']       ?? 'auto';   // 'auto' or 'manual'

if (!$batch_id || !$item_id) {
    send_json(['error' => 'batch_id and item_id are required'], 400);
}

// Map legacy 'st' values to match_type + match_status
$db_match_type = in_array($match_type, ['auto', 'manual']) ? $match_type : 'auto';

$upd = $pdo->prepare(
    "UPDATE garments
     SET match_status = 'Matched', match_type = ?
     WHERE garment_id = ? AND batch_id = ?"
);
$upd->execute([$db_match_type, $item_id, $batch_id]);

// Rescan record (rescans table)
$rescan = $pdo->prepare(
    "INSERT INTO rescans
        (batch_id, matched_garment_id, similarity_score, decision, confirmed_by)
     VALUES (?, ?, ?, ?, ?)"
);
$decision = ucfirst($db_match_type); // 'Auto' or 'Manual'
$rescan->execute([
    $batch_id,
    $item_id,
    $data['sim'] ?? null,
    $decision,
    $_SESSION['user_id'] ?? null
]);

// Check remaining pending garments and flags
$pr = $pdo->prepare("SELECT COUNT(*) AS cnt FROM garments WHERE batch_id = ? AND match_type = 'pending'");
$pr->execute([$batch_id]);
$pending_count = (int)$pr->fetch()['cnt'];

$fr = $pdo->prepare("SELECT COUNT(*) AS cnt FROM flags WHERE batch_id = ?");
$fr->execute([$batch_id]);
$flags_count = (int)$fr->fetch()['cnt'];

$is_completed = false;
if ($pending_count === 0 && $flags_count === 0) {
    $pdo->prepare("UPDATE batches SET status = 'Completed' WHERE batch_id = ?")
        ->execute([$batch_id]);
    $is_completed = true;
}

send_json([
    'success'       => true,
    'batch_id'      => (string)$batch_id,
    'item_id'       => $item_id,
    'pending_count' => $pending_count,
    'flags_count'   => $flags_count,
    'completed'     => $is_completed
]);
