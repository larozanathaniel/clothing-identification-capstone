<?php
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
require_role($method === 'POST' ? ['staff'] : ['staff', 'admin']);

if ($method === 'GET') {
    $id = $_GET['id'] ?? null;
    if ($id) {
        // Fetch single batch with items and flags
        $stmt = $pdo->prepare("SELECT * FROM batches WHERE id = ?");
        $stmt->execute([$id]);
        $b = $stmt->fetch();
        if (!$b) {
            send_json(['error' => 'Batch not found'], 404);
        }

        // Get items
        $i_stmt = $pdo->prepare("SELECT * FROM items WHERE batch_id = ? ORDER BY created_at ASC");
        $i_stmt->execute([$id]);
        $items = $i_stmt->fetchAll();

        // Get flags
        $f_stmt = $pdo->prepare("SELECT * FROM flags WHERE batch_id = ? ORDER BY created_at ASC");
        $f_stmt->execute([$id]);
        $flags = $f_stmt->fetchAll();

        $b['id'] = (string)$b['id'];
        $b['items'] = array_map(function($item) {
            return [
                'id' => $item['id'],
                'name' => $item['name'],
                'img' => $item['img'],
                'st' => $item['st']
            ];
        }, $items);

        $b['flags'] = array_map(function($flag) {
            return [
                'id' => $flag['id'],
                'img' => $flag['img']
            ];
        }, $flags);

        send_json(['batch' => $b]);
    } else {
        // List all batches
        $status = $_GET['status'] ?? null;
        $q = trim($_GET['q'] ?? '');

        $sql = "SELECT b.* FROM batches b WHERE 1=1";
        $params = [];

        if ($status) {
            $sql .= " AND b.status = ?";
            $params[] = $status;
        }

        $sql .= " ORDER BY b.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $batches = $stmt->fetchAll();

        $result = [];
        foreach ($batches as $b) {
            $bid = (string)$b['id'];
            if (!empty($q)) {
                $q_lower = strtolower($q);
                if (strpos(strtolower($bid), $q_lower) === false && strpos(strtolower($b['customer']), $q_lower) === false) {
                    continue;
                }
            }

            // Fetch items count and items
            $i_stmt = $pdo->prepare("SELECT * FROM items WHERE batch_id = ?");
            $i_stmt->execute([$b['id']]);
            $items = $i_stmt->fetchAll();

            $f_stmt = $pdo->prepare("SELECT * FROM flags WHERE batch_id = ?");
            $f_stmt->execute([$b['id']]);
            $flags = $f_stmt->fetchAll();

            $result[] = [
                'id' => $bid,
                'customer' => $b['customer'],
                'status' => $b['status'],
                'created' => (int)$b['created_at'],
                'done' => $b['completed_at'] ? (int)$b['completed_at'] : null,
                'items' => array_map(function($item) {
                    return [
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'img' => $item['img'],
                        'st' => $item['st']
                    ];
                }, $items),
                'flags' => array_map(function($flag) {
                    return [
                        'id' => $flag['id'],
                        'img' => $flag['img']
                    ];
                }, $flags)
            ];
        }

        // Fetch current sequence & threshold
        $seq_stmt = $pdo->query("SELECT value FROM settings WHERE key = 'seq'");
        $seq = (int)($seq_stmt->fetch()['value'] ?? 1000);

        $thr_stmt = $pdo->query("SELECT value FROM settings WHERE key = 'thr'");
        $thr = (int)($thr_stmt->fetch()['value'] ?? 80);

        send_json([
            'batches' => $result,
            'seq' => $seq,
            'thr' => $thr
        ]);
    }
} elseif ($method === 'POST') {
    // Create new batch
    $data = get_json_input();
    $customer = trim($data['customer'] ?? '');
    $items = $data['items'] ?? [];

    if (empty($customer)) {
        send_json(['error' => 'Customer name is required'], 400);
    }
    if (empty($items)) {
        send_json(['error' => 'Scan at least one garment'], 400);
    }

    $pdo->beginTransaction();
    try {
        // Increment sequence
        $seq_stmt = $pdo->query("SELECT value FROM settings WHERE key = 'seq'");
        $seq = (int)($seq_stmt->fetch()['value'] ?? 1000) + 1;
        $pdo->prepare("UPDATE settings SET value = ? WHERE key = 'seq'")->execute([(string)$seq]);

        $batch_id = $seq;
        $now = time() * 1000;

        $b_stmt = $pdo->prepare("INSERT INTO batches (id, customer, status, created_at) VALUES (?, ?, 'Open', ?)");
        $b_stmt->execute([$batch_id, $customer, $now]);

        $i_stmt = $pdo->prepare("INSERT INTO items (id, batch_id, name, img, st, created_at) VALUES (?, ?, ?, ?, 'pending', ?)");
        foreach ($items as $idx => $it) {
            $item_id = $it['id'] ?? ('i' . (time() * 1000) . $idx);
            $item_name = $it['name'] ?? ('Item ' . ($idx + 1));
            $item_img = $it['img'] ?? '';
            $i_stmt->execute([$item_id, $batch_id, $item_name, $item_img, $now]);
        }

        $pdo->commit();

        send_json([
            'success' => true,
            'id' => (string)$batch_id,
            'message' => "Batch #$batch_id created successfully"
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        send_json(['error' => 'Failed to create batch: ' . $e->getMessage()], 500);
    }
} elseif ($method === 'PUT') {
    // Update batch status or item status
    $data = get_json_input();
    $id = $data['id'] ?? null;
    $status = $data['status'] ?? null;

    if (!$id || !$status) {
        send_json(['error' => 'Batch ID and status are required'], 400);
    }

    $completed_at = ($status === 'Completed') ? (time() * 1000) : null;
    $stmt = $pdo->prepare("UPDATE batches SET status = ?, completed_at = COALESCE(?, completed_at) WHERE id = ?");
    $stmt->execute([$status, $completed_at, $id]);

    send_json(['success' => true, 'id' => (string)$id, 'status' => $status]);
}
