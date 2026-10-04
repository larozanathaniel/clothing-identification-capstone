<?php
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
require_role($method === 'POST' ? ['staff'] : ['staff', 'admin']);

// ── helper: map a MariaDB garment row → app shape ─────────────────────────
function map_garment($g) {
    return [
        'id'   => (string)$g['garment_id'],
        'name' => $g['garment_name'] ?? 'Item',
        'img'  => $g['intake_image'] ?? '',
        'st'   => $g['match_type']   ?? 'pending'
    ];
}

// ── helper: map a MariaDB flag row → app shape ────────────────────────────
function map_flag($f) {
    return [
        'id'  => $f['flag_id'],
        'img' => $f['img']
    ];
}

// ── helper: load items + flags for one batch_id ───────────────────────────
function load_batch_detail($pdo, $batch_id) {
    $gi = $pdo->prepare(
        "SELECT g.*, c.name AS customer_name
         FROM garments g
         JOIN batches b ON b.batch_id = g.batch_id
         JOIN customers c ON c.customer_id = b.customer_id
         WHERE g.batch_id = ?
         ORDER BY g.created_at ASC"
    );
    $gi->execute([$batch_id]);
    $garments = $gi->fetchAll();

    $fi = $pdo->prepare(
        "SELECT * FROM flags WHERE batch_id = ? ORDER BY created_at ASC"
    );
    $fi->execute([$batch_id]);
    $flags = $fi->fetchAll();

    return [
        'items' => array_map('map_garment', $garments),
        'flags' => array_map('map_flag',   $flags)
    ];
}

// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

if ($method === 'GET') {
    $id = $_GET['id'] ?? null;

    if ($id) {
        // ── Single batch ────────────────────────────────────────────────
        $stmt = $pdo->prepare(
            "SELECT b.*, c.name AS customer, c.contact_number
             FROM batches b
             JOIN customers c ON c.customer_id = b.customer_id
             WHERE b.batch_id = ?"
        );
        $stmt->execute([$id]);
        $b = $stmt->fetch();
        if (!$b) send_json(['error' => 'Batch not found'], 404);

        $detail = load_batch_detail($pdo, $id);

        send_json([
            'batch' => [
                'id'       => (string)$b['batch_id'],
                'customer' => $b['customer'],
                'status'   => $b['status'],
                'created'  => strtotime($b['created_at']) * 1000,
                'done'     => $b['updated_at'] && $b['status'] === 'Completed'
                                ? strtotime($b['updated_at']) * 1000
                                : null,
                'items'    => $detail['items'],
                'flags'    => $detail['flags']
            ]
        ]);

    } else {
        // ── List all batches ────────────────────────────────────────────
        $status = $_GET['status'] ?? null;
        $q      = trim($_GET['q'] ?? '');
        $params = [];

        $sql = "SELECT b.*, c.name AS customer
                FROM batches b
                JOIN customers c ON c.customer_id = b.customer_id
                WHERE 1=1";

        if ($status) {
            $sql    .= " AND b.status = ?";
            $params[] = $status;
        }
        if ($q) {
            $sql    .= " AND (c.name LIKE ? OR CAST(b.batch_id AS CHAR) LIKE ?)";
            $like    = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= " ORDER BY b.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll();

        $result = [];
        foreach ($batches as $b) {
            $detail = load_batch_detail($pdo, $b['batch_id']);
            $result[] = [
                'id'       => (string)$b['batch_id'],
                'customer' => $b['customer'],
                'status'   => $b['status'],
                'created'  => strtotime($b['created_at']) * 1000,
                'done'     => $b['status'] === 'Completed'
                                ? strtotime($b['updated_at']) * 1000
                                : null,
                'items'    => $detail['items'],
                'flags'    => $detail['flags']
            ];
        }

        $thr_row = $pdo->query("SELECT value FROM settings WHERE `key` = 'thr'")->fetch();
        $thr     = (int)($thr_row['value'] ?? 80);

        // seq mirrors the last batch_id for frontend display
        $seq_row = $pdo->query("SELECT MAX(batch_id) AS mx FROM batches")->fetch();
        $seq     = (int)($seq_row['mx'] ?? 1000);

        send_json([
            'batches' => $result,
            'seq'     => $seq,
            'thr'     => $thr
        ]);
    }

} elseif ($method === 'POST') {
    // ── Create new batch ────────────────────────────────────────────────────
    $data     = get_json_input();
    $customer = trim($data['customer'] ?? '');
    $items    = $data['items'] ?? [];

    if (empty($customer)) send_json(['error' => 'Customer name is required'], 400);
    if (empty($items))    send_json(['error' => 'Scan at least one garment'],  400);

    $pdo->beginTransaction();
    try {
        // 1. Upsert customer by name
        $cs = $pdo->prepare(
            "INSERT INTO customers (name) VALUES (?)
             ON DUPLICATE KEY UPDATE customer_id = LAST_INSERT_ID(customer_id)"
        );
        $cs->execute([$customer]);
        $customer_id = (int)$pdo->lastInsertId();

        if (!$customer_id) {
            $cr = $pdo->prepare("SELECT customer_id FROM customers WHERE name = ?");
            $cr->execute([$customer]);
            $customer_id = (int)$cr->fetch()['customer_id'];
        }

        // 2. Create batch (staff_id from session)
        $staff_id = (int)($_SESSION['user_id'] ?? 2);
        $bs = $pdo->prepare(
            "INSERT INTO batches (customer_id, staff_id, status, item_count)
             VALUES (?, ?, 'Open', ?)"
        );
        $bs->execute([$customer_id, $staff_id, count($items)]);
        $batch_id = (int)$pdo->lastInsertId();

        // 3. Insert garments
        $gs = $pdo->prepare(
            "INSERT INTO garments
                (batch_id, garment_name, intake_image_path, intake_image, match_status, match_type)
             VALUES (?, ?, ?, ?, 'Pending', 'pending')"
        );
        foreach ($items as $idx => $it) {
            $name = $it['name'] ?? ('Item ' . ($idx + 1));
            $img  = $it['img']  ?? '';
            $gs->execute([$batch_id, $name, '', $img]);
        }

        $pdo->commit();
        send_json([
            'success' => true,
            'id'      => (string)$batch_id,
            'message' => "Batch #$batch_id created successfully"
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_json(['error' => 'Failed to create batch: ' . $e->getMessage()], 500);
    }

} elseif ($method === 'PUT') {
    // ── Update batch status ─────────────────────────────────────────────────
    $data   = get_json_input();
    $id     = $data['id']     ?? null;
    $status = $data['status'] ?? null;

    if (!$id || !$status) send_json(['error' => 'Batch ID and status are required'], 400);

    $upd = $pdo->prepare("UPDATE batches SET status = ? WHERE batch_id = ?");
    $upd->execute([$status, $id]);

    send_json(['success' => true, 'id' => (string)$id, 'status' => $status]);
}
