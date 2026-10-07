<?php
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
require_role($method === 'POST' ? ['staff'] : ['staff', 'admin']);

function map_garment($g) {
    return [
        'id'   => (string)$g['garment_id'],
        'name' => $g['garment_name'] ?? 'Item',
        'img'  => garment_img_url($g),
        'st'   => $g['match_type'] ?? 'pending'
    ];
}

function load_batch_detail($pdo, $batch_id) {
    $gi = $pdo->prepare("SELECT * FROM garments WHERE batch_id = ? ORDER BY garment_id ASC");
    $gi->execute([$batch_id]);

    $fi = $pdo->prepare("SELECT * FROM flags WHERE batch_id = ? ORDER BY created_at ASC");
    $fi->execute([$batch_id]);

    return [
        'items' => array_map('map_garment', $gi->fetchAll()),
        'flags' => array_map(fn($f) => ['id' => $f['flag_id'], 'img' => $f['img']], $fi->fetchAll())
    ];
}

function batch_shape($b, $detail) {
    return [
        'id'       => (string)$b['batch_id'],
        'customer' => $b['customer'],
        'status'   => $b['status'],
        'created'  => strtotime($b['created_at']) * 1000,
        'done'     => $b['status'] === 'Completed' ? strtotime($b['updated_at']) * 1000 : null,
        'items'    => $detail['items'],
        'flags'    => $detail['flags']
    ];
}

if ($method === 'GET') {
    $id = $_GET['id'] ?? null;

    if ($id) {
        $stmt = $pdo->prepare(
            "SELECT b.*, c.name AS customer FROM batches b
             JOIN customers c ON c.customer_id = b.customer_id WHERE b.batch_id = ?");
        $stmt->execute([$id]);
        $b = $stmt->fetch();
        if (!$b) send_json(['error' => 'Batch not found'], 404);
        send_json(['batch' => batch_shape($b, load_batch_detail($pdo, $b['batch_id']))]);
    }

    $status = $_GET['status'] ?? null;
    $q      = trim($_GET['q'] ?? '');
    $params = [];
    $sql = "SELECT b.*, c.name AS customer FROM batches b
            JOIN customers c ON c.customer_id = b.customer_id WHERE 1=1";
    if ($status) { $sql .= " AND b.status = ?"; $params[] = $status; }
    if ($q) {
        $sql .= " AND (c.name LIKE ? OR CAST(b.batch_id AS CHAR) LIKE ?)";
        $params[] = "%$q%"; $params[] = "%$q%";
    }
    $sql .= " ORDER BY b.created_at DESC, b.batch_id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $result = [];
    foreach ($stmt->fetchAll() as $b) {
        $result[] = batch_shape($b, load_batch_detail($pdo, $b['batch_id']));
    }

    $thr = (int)($pdo->query("SELECT value FROM settings WHERE `key` = 'thr'")->fetchColumn() ?: 80);
    $seq = (int)($pdo->query("SELECT MAX(batch_id) FROM batches")->fetchColumn() ?: 1000);
    send_json(['batches' => $result, 'seq' => $seq, 'thr' => $thr]);

} elseif ($method === 'POST') {
    $data     = get_json_input();
    $customer = trim($data['customer'] ?? '');
    $items    = $data['items'] ?? [];

    if ($customer === '')            send_json(['error' => 'Customer name is required'], 400);
    if (mb_strlen($customer) > 100)  send_json(['error' => 'Customer name is too long (max 100)'], 400);
    if (!is_array($items) || !$items) send_json(['error' => 'Scan at least one garment'], 400);

    // Save images to disk first so a bad image rejects the whole batch cleanly
    $saved = [];
    foreach ($items as $idx => $it) {
        $path = save_data_uri_image($it['img'] ?? '', 'intake');
        if ($path === null) {
            foreach ($saved as $s) delete_upload($s['path']);
            send_json(['error' => 'Garment ' . ($idx + 1) . ' has no valid image. Rescan it.'], 400);
        }
        $saved[] = ['name' => trim($it['name'] ?? '') ?: 'Item ' . ($idx + 1), 'path' => $path];
    }

    $pdo->beginTransaction();
    try {
        // Reuse the customer if the name already exists (case-insensitive), else create
        $cr = $pdo->prepare("SELECT customer_id FROM customers WHERE name = ? LIMIT 1");
        $cr->execute([$customer]);
        $customer_id = (int)$cr->fetchColumn();
        if (!$customer_id) {
            $pdo->prepare("INSERT INTO customers (name) VALUES (?)")->execute([$customer]);
            $customer_id = (int)$pdo->lastInsertId();
        }

        $pdo->prepare("INSERT INTO batches (customer_id, staff_id, status, item_count)
                       VALUES (?, ?, 'Open', ?)")
            ->execute([$customer_id, (int)$_SESSION['user_id'], count($saved)]);
        $batch_id = (int)$pdo->lastInsertId();

        $gs = $pdo->prepare(
            "INSERT INTO garments (batch_id, garment_name, intake_image_path, match_status, match_type)
             VALUES (?, ?, ?, 'Pending', 'pending')");
        foreach ($saved as $s) $gs->execute([$batch_id, $s['name'], $s['path']]);

        $pdo->commit();
        send_json(['success' => true, 'id' => (string)$batch_id,
                   'message' => "Batch #$batch_id created successfully"]);
    } catch (Exception $e) {
        $pdo->rollBack();
        foreach ($saved as $s) delete_upload($s['path']);
        send_json(['error' => 'Failed to create batch: ' . $e->getMessage()], 500);
    }

} elseif ($method === 'PUT') {
    $data   = get_json_input();
    $id     = $data['id']     ?? null;
    $status = $data['status'] ?? null;

    if (!$id || !$status)                       send_json(['error' => 'Batch ID and status are required'], 400);
    if (!in_array($status, BATCH_STATUSES, true)) send_json(['error' => 'Invalid status'], 400);

    $chk = $pdo->prepare("SELECT status FROM batches WHERE batch_id = ?");
    $chk->execute([$id]);
    if (!$chk->fetch()) send_json(['error' => 'Batch not found'], 404);

    $pdo->prepare("UPDATE batches SET status = ? WHERE batch_id = ?")->execute([$status, $id]);
    send_json(['success' => true, 'id' => (string)$id, 'status' => $status]);
}
