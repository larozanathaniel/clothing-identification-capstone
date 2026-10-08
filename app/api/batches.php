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

function batch_shape($pdo, $b) {
    $gi = $pdo->prepare("SELECT * FROM garments WHERE batch_id = ? ORDER BY garment_id ASC");
    $gi->execute([$b['batch_id']]);
    return [
        'id'          => (string)$b['batch_id'],
        'customer_id' => (string)$b['customer_id'],
        'customer'    => $b['customer'],
        'status'      => $b['status'],
        'created'     => strtotime($b['created_at']) * 1000,
        'done'        => $b['status'] === 'Completed' ? strtotime($b['updated_at']) * 1000 : null,
        'items'       => array_map('map_garment', $gi->fetchAll())
    ];
}

if ($method === 'GET') {
    $rows = $pdo->query(
        "SELECT b.*, c.name AS customer FROM batches b
         JOIN customers c ON c.customer_id = b.customer_id
         ORDER BY b.created_at DESC, b.batch_id DESC")->fetchAll();
    $result = array_map(fn($b) => batch_shape($pdo, $b), $rows);

    // Garments set aside from the Sorting tab (owner unknown yet)
    $fl = $pdo->query("SELECT flag_id, img FROM flags ORDER BY created_at ASC")->fetchAll();
    $flags = array_map(fn($f) => ['id' => $f['flag_id'], 'img' => $f['img']], $fl);

    $thr = (int)($pdo->query("SELECT value FROM settings WHERE `key` = 'thr'")->fetchColumn() ?: 80);
    $seq = (int)($pdo->query("SELECT MAX(batch_id) FROM batches")->fetchColumn() ?: 1000);
    send_json(['batches' => $result, 'flags' => $flags, 'seq' => $seq, 'thr' => $thr]);

} elseif ($method === 'POST') {
    $data     = get_json_input();
    $customer = trim($data['customer'] ?? '');
    $items    = $data['items'] ?? [];

    if ($customer === '')             send_json(['error' => 'Customer name is required'], 400);
    if (mb_strlen($customer) > 100)   send_json(['error' => 'Customer name is too long (max 100)'], 400);
    if (!is_array($items) || !$items) send_json(['error' => 'Scan at least one garment'], 400);

    // Save images first so one bad image rejects the whole batch cleanly
    $saved = [];
    foreach ($items as $idx => $it) {
        $path = save_data_uri_image($it['img'] ?? '', 'intake');
        if ($path === null) {
            foreach ($saved as $s) delete_upload($s['path']);
            send_json(['error' => 'Garment ' . ($idx + 1) . ' has no valid image. Rescan it.'], 400);
        }
        $label = mb_substr(trim($it['name'] ?? ''), 0, 100);
        $saved[] = ['name' => $label !== '' ? $label : 'Item ' . ($idx + 1), 'path' => $path];
    }

    $pdo->beginTransaction();
    try {
        $cr = $pdo->prepare("SELECT customer_id FROM customers WHERE name = ? LIMIT 1");
        $cr->execute([$customer]);
        $customer_id = (int)$cr->fetchColumn();
        if (!$customer_id) {
            $pdo->prepare("INSERT INTO customers (name) VALUES (?)")->execute([$customer]);
            $customer_id = (int)$pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO batches (customer_id, staff_id, status, item_count) VALUES (?, ?, 'Open', ?)")
            ->execute([$customer_id, (int)$_SESSION['user_id'], count($saved)]);
        $batch_id = (int)$pdo->lastInsertId();

        $gs = $pdo->prepare(
            "INSERT INTO garments (batch_id, garment_name, intake_image_path, match_status, match_type)
             VALUES (?, ?, ?, 'Pending', 'pending')");
        foreach ($saved as $s) $gs->execute([$batch_id, $s['name'], $s['path']]);

        $pdo->commit();
        send_json(['success' => true, 'id' => (string)$batch_id, 'message' => "Batch #$batch_id saved"]);
    } catch (Exception $e) {
        $pdo->rollBack();
        foreach ($saved as $s) delete_upload($s['path']);
        send_json(['error' => 'Failed to create batch: ' . $e->getMessage()], 500);
    }
} else {
    send_json(['error' => 'Method not allowed'], 405);
}
