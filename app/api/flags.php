<?php
require_once __DIR__ . '/db.php';
require_role(['staff']);

$method = $_SERVER['REQUEST_METHOD'];
$data   = get_json_input();
$action = $_GET['action'] ?? $data['action'] ?? '';

if ($method === 'GET' || $action === 'list') {
    $flags = $pdo->query(
        "SELECT f.*, b.status AS batch_status, c.name AS customer
         FROM flags f
         JOIN batches b   ON b.batch_id    = f.batch_id
         JOIN customers c ON c.customer_id = b.customer_id
         ORDER BY f.created_at DESC")->fetchAll();

    $gs = $pdo->prepare("SELECT * FROM garments WHERE batch_id = ? ORDER BY garment_id ASC");
    $result = [];
    foreach ($flags as $f) {
        $gs->execute([$f['batch_id']]);
        $items = array_map(fn($g) => [
            'id'   => (string)$g['garment_id'],
            'name' => $g['garment_name'] ?? 'Item',
            'img'  => garment_img_url($g),
            'st'   => $g['match_type'] ?? 'pending'
        ], $gs->fetchAll());

        $result[] = [
            'b' => ['id' => (string)$f['batch_id'], 'customer' => $f['customer'],
                    'status' => $f['batch_status'], 'items' => $items],
            'f' => ['id' => $f['flag_id'], 'img' => $f['img']]
        ];
    }
    send_json(['unresolved' => $result]);
}

if ($method !== 'POST') send_json(['error' => 'Method not allowed'], 405);

if ($action === 'flag') {
    $batch_id = $data['batch_id'] ?? null;
    if (!$batch_id) send_json(['error' => 'batch_id and img required'], 400);

    $chk = $pdo->prepare("SELECT status FROM batches WHERE batch_id = ?");
    $chk->execute([$batch_id]);
    $b = $chk->fetch();
    if (!$b) send_json(['error' => 'Batch not found'], 404);
    if ($b['status'] !== 'Ready to Sort') send_json(['error' => 'This batch is not ready to sort.'], 409);

    $path = save_data_uri_image($data['img'] ?? '', 'flags');
    if ($path === null) send_json(['error' => 'The scanned image is not valid. Rescan it.'], 400);

    $flag_id = 'f' . (int)(microtime(true) * 1000) . mt_rand(100, 999);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO flags (flag_id, batch_id, img) VALUES (?, ?, ?)")
            ->execute([$flag_id, $batch_id, $path]);
        $pdo->prepare("INSERT INTO rescans (batch_id, decision, confirmed_by, rescan_image_path)
                       VALUES (?, 'Flagged', ?, ?)")
            ->execute([$batch_id, $_SESSION['user_id'], $path]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        delete_upload($path);
        send_json(['error' => 'Failed to flag: ' . $e->getMessage()], 500);
    }
    send_json(['success' => true, 'id' => $flag_id]);

} elseif ($action === 'assign') {
    $flag_id  = $data['flag_id']  ?? null;
    $batch_id = $data['batch_id'] ?? null;
    $item_id  = $data['item_id']  ?? null;
    if (!$flag_id || !$batch_id || !$item_id) {
        send_json(['error' => 'flag_id, batch_id and item_id are required'], 400);
    }

    $fl = $pdo->prepare("SELECT img FROM flags WHERE flag_id = ? AND batch_id = ?");
    $fl->execute([$flag_id, $batch_id]);
    $flag = $fl->fetch();
    if (!$flag) send_json(['error' => 'Flag not found'], 404);

    $pdo->beginTransaction();
    try {
        $u = $pdo->prepare(
            "UPDATE garments SET match_status = 'Matched', match_type = 'manual'
             WHERE garment_id = ? AND batch_id = ? AND match_type = 'pending'");
        $u->execute([$item_id, $batch_id]);
        if ($u->rowCount() === 0) {
            $pdo->rollBack();
            send_json(['error' => 'That garment is not pending in this batch.'], 409);
        }
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
    // keep the image: rescans.rescan_image_path still points to it
    send_json(['success' => true, 'completed' => $done]);

} elseif ($action === 'dismiss') {
    $flag_id  = $data['flag_id']  ?? null;
    $batch_id = $data['batch_id'] ?? null;
    if (!$flag_id) send_json(['error' => 'flag_id is required'], 400);

    $fl = $pdo->prepare("SELECT img, batch_id FROM flags WHERE flag_id = ?");
    $fl->execute([$flag_id]);
    $flag = $fl->fetch();
    if (!$flag) send_json(['error' => 'Flag not found'], 404);

    $pdo->prepare("DELETE FROM flags WHERE flag_id = ?")->execute([$flag_id]);
    delete_upload($flag['img']);
    $done = maybe_complete_batch($pdo, $flag['batch_id']);
    send_json(['success' => true, 'completed' => $done]);

} else {
    send_json(['error' => 'Invalid action'], 400);
}
