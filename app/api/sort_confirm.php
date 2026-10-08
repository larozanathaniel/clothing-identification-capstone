<?php
require_once __DIR__ . '/db.php';
require_role(['staff']);

$data       = get_json_input();
$item_id    = $data['item_id'] ?? null;
$match_type = $data['st']      ?? 'auto';
$sim        = isset($data['sim']) && is_numeric($data['sim']) ? (float)$data['sim'] : null;

if (!$item_id) send_json(['error' => 'item_id is required'], 400);
if (!in_array($match_type, ['auto', 'manual'], true)) send_json(['error' => 'Invalid match type'], 400);

// The batch is taken from the garment itself - staff never has to pick it
$gq = $pdo->prepare("SELECT g.batch_id, g.match_type, b.status FROM garments g
                     JOIN batches b ON b.batch_id = g.batch_id WHERE g.garment_id = ?");
$gq->execute([$item_id]);
$g = $gq->fetch();
if (!$g) send_json(['error' => 'Garment not found'], 404);
if ($g['status'] !== 'Open' || $g['match_type'] !== 'pending') {
    send_json(['error' => 'That garment is already sorted.'], 409);
}
$batch_id = (int)$g['batch_id'];

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare("UPDATE garments SET match_status = 'Matched', match_type = ?
                          WHERE garment_id = ? AND match_type = 'pending'");
    $upd->execute([$match_type, $item_id]);
    if ($upd->rowCount() === 0) {
        $pdo->rollBack();
        send_json(['error' => 'That garment is already sorted.'], 409);
    }
    $pdo->prepare("INSERT INTO rescans (batch_id, matched_garment_id, similarity_score, decision, confirmed_by)
                   VALUES (?, ?, ?, ?, ?)")
        ->execute([$batch_id, $item_id, $sim, ucfirst($match_type), $_SESSION['user_id']]);
    $completed = maybe_complete_batch($pdo, $batch_id);
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    send_json(['error' => 'Failed to confirm: ' . $e->getMessage()], 500);
}

send_json(['success' => true, 'batch_id' => (string)$batch_id, 'item_id' => (string)$item_id, 'completed' => $completed]);
