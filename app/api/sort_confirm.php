<?php
require_once __DIR__ . '/db.php';
require_role(['staff']);

$data       = get_json_input();
$batch_id   = $data['batch_id'] ?? null;
$item_id    = $data['item_id']  ?? null;
$match_type = $data['st']       ?? 'auto';
$sim        = isset($data['sim']) && is_numeric($data['sim']) ? (float)$data['sim'] : null;

if (!$batch_id || !$item_id) send_json(['error' => 'batch_id and item_id are required'], 400);
if (!in_array($match_type, ['auto', 'manual'], true)) send_json(['error' => 'Invalid match type'], 400);

$bs = $pdo->prepare("SELECT status FROM batches WHERE batch_id = ?");
$bs->execute([$batch_id]);
$b = $bs->fetch();
if (!$b) send_json(['error' => 'Batch not found'], 404);
if ($b['status'] !== 'Ready to Sort') send_json(['error' => 'This batch is not ready to sort.'], 409);

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare(
        "UPDATE garments SET match_status = 'Matched', match_type = ?
         WHERE garment_id = ? AND batch_id = ? AND match_type = 'pending'");
    $upd->execute([$match_type, $item_id, $batch_id]);
    if ($upd->rowCount() === 0) {                      // wrong garment, wrong batch, or already matched
        $pdo->rollBack();
        send_json(['error' => 'That garment is not pending in this batch.'], 409);
    }

    $pdo->prepare(
        "INSERT INTO rescans (batch_id, matched_garment_id, similarity_score, decision, confirmed_by)
         VALUES (?, ?, ?, ?, ?)")
        ->execute([$batch_id, $item_id, $sim, ucfirst($match_type), $_SESSION['user_id']]);

    $completed = maybe_complete_batch($pdo, $batch_id);
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    send_json(['error' => 'Failed to confirm: ' . $e->getMessage()], 500);
}

$pr = $pdo->prepare("SELECT COUNT(*) FROM garments WHERE batch_id = ? AND match_type = 'pending'");
$pr->execute([$batch_id]);
$fr = $pdo->prepare("SELECT COUNT(*) FROM flags WHERE batch_id = ?");
$fr->execute([$batch_id]);

send_json([
    'success'       => true,
    'batch_id'      => (string)$batch_id,
    'item_id'       => $item_id,
    'pending_count' => (int)$pr->fetchColumn(),
    'flags_count'   => (int)$fr->fetchColumn(),
    'completed'     => $completed
]);
