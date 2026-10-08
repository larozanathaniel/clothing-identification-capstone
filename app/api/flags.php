<?php
// "Set aside" garments: scanned at Sorting, not identified, owner still unknown.
require_once __DIR__ . '/db.php';
require_role(['staff']);

$method = $_SERVER['REQUEST_METHOD'];
$data   = get_json_input();
$action = $_GET['action'] ?? $data['action'] ?? '';

if ($method === 'GET' || $action === 'list') {
    $rows = $pdo->query("SELECT flag_id AS id, img, created_at FROM flags ORDER BY created_at ASC")->fetchAll();
    send_json(['unresolved' => $rows]);
}
if ($method !== 'POST') send_json(['error' => 'Method not allowed'], 405);

if ($action === 'flag') {
    $path = save_data_uri_image($data['img'] ?? '', 'flags');
    if ($path === null) send_json(['error' => 'The scanned image is not valid. Rescan it.'], 400);

    $flag_id = 'f' . (int)(microtime(true) * 1000) . mt_rand(100, 999);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO flags (flag_id, batch_id, img) VALUES (?, NULL, ?)")->execute([$flag_id, $path]);
        $pdo->prepare("INSERT INTO rescans (batch_id, decision, confirmed_by, rescan_image_path)
                       VALUES (NULL, 'Flagged', ?, ?)")->execute([$_SESSION['user_id'], $path]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        delete_upload($path);
        send_json(['error' => 'Failed to set aside: ' . $e->getMessage()], 500);
    }
    send_json(['success' => true, 'id' => $flag_id]);

} elseif ($action === 'assign') {
    $flag_id = $data['flag_id'] ?? null;
    $item_id = $data['item_id'] ?? null;
    if (!$flag_id || !$item_id) send_json(['error' => 'flag_id and item_id are required'], 400);

    $fl = $pdo->prepare("SELECT img FROM flags WHERE flag_id = ?");
    $fl->execute([$flag_id]);
    $flag = $fl->fetch();
    if (!$flag) send_json(['error' => 'Flag not found'], 404);

    $gq = $pdo->prepare("SELECT g.batch_id FROM garments g JOIN batches b ON b.batch_id = g.batch_id
                         WHERE g.garment_id = ? AND g.match_type = 'pending' AND b.status = 'Open'");
    $gq->execute([$item_id]);
    $batch_id = $gq->fetchColumn();
    if (!$batch_id) send_json(['error' => 'That garment is not waiting to be sorted.'], 409);

    $pdo->beginTransaction();
    try {
        $u = $pdo->prepare("UPDATE garments SET match_status = 'Matched', match_type = 'manual'
                            WHERE garment_id = ? AND match_type = 'pending'");
        $u->execute([$item_id]);
        if ($u->rowCount() === 0) { $pdo->rollBack(); send_json(['error' => 'That garment is already sorted.'], 409); }
        $pdo->prepare("DELETE FROM flags WHERE flag_id = ?")->execute([$flag_id]);
        $pdo->prepare("INSERT INTO rescans (batch_id, matched_garment_id, decision, confirmed_by, rescan_image_path)
                       VALUES (?, ?, 'Manual', ?, ?)")
            ->execute([$batch_id, $item_id, $_SESSION['user_id'], $flag['img']]);
        $done = maybe_complete_batch($pdo, $batch_id);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        send_json(['error' => 'Failed to assign: ' . $e->getMessage()], 500);
    }
    send_json(['success' => true, 'completed' => $done, 'batch_id' => (string)$batch_id]);

} elseif ($action === 'dismiss') {
    $flag_id = $data['flag_id'] ?? null;
    if (!$flag_id) send_json(['error' => 'flag_id is required'], 400);
    $fl = $pdo->prepare("SELECT img FROM flags WHERE flag_id = ?");
    $fl->execute([$flag_id]);
    $flag = $fl->fetch();
    if (!$flag) send_json(['error' => 'Flag not found'], 404);
    $pdo->prepare("DELETE FROM flags WHERE flag_id = ?")->execute([$flag_id]);
    delete_upload($flag['img']);
    send_json(['success' => true]);

} else {
    send_json(['error' => 'Invalid action'], 400);
}
