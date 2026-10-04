<?php
require_once __DIR__ . '/db.php';
require_role(['staff']);

$method = $_SERVER['REQUEST_METHOD'];
$data = get_json_input();
$action = $_GET['action'] ?? $data['action'] ?? '';

if ($method === 'GET' || $action === 'list') {
    // Fetch all unresolved flags across batches
    $stmt = $pdo->query("SELECT f.*, b.customer, b.status as batch_status FROM flags f JOIN batches b ON f.batch_id = b.id ORDER BY f.created_at DESC");
    $flags = $stmt->fetchAll();

    $result = [];
    foreach ($flags as $f) {
        $bid = (string)$f['batch_id'];
        
        $b_stmt = $pdo->prepare("SELECT * FROM batches WHERE id = ?");
        $b_stmt->execute([$bid]);
        $batch = $b_stmt->fetch();

        $i_stmt = $pdo->prepare("SELECT * FROM items WHERE batch_id = ?");
        $i_stmt->execute([$bid]);
        $items = $i_stmt->fetchAll();

        $result[] = [
            'b' => [
                'id' => $bid,
                'customer' => $batch['customer'] ?? '',
                'status' => $batch['status'] ?? '',
                'items' => array_map(function($item) {
                    return [
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'img' => $item['img'],
                        'st' => $item['st']
                    ];
                }, $items)
            ],
            'f' => [
                'id' => $f['id'],
                'img' => $f['img']
            ]
        ];
    }

    send_json(['unresolved' => $result]);
} elseif ($method === 'POST') {
    if ($action === 'flag') {
        $batch_id = $data['batch_id'] ?? null;
        $img = $data['img'] ?? '';
        if (!$batch_id || !$img) {
            send_json(['error' => 'batch_id and img required'], 400);
        }
        $flag_id = 'f' . (int)(microtime(true) * 1000) . mt_rand(100, 999);
        $stmt = $pdo->prepare("INSERT INTO flags (id, batch_id, img, created_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$flag_id, $batch_id, $img, time() * 1000]);

        send_json(['success' => true, 'id' => $flag_id]);
    } elseif ($action === 'assign') {
        $flag_id = $data['flag_id'] ?? null;
        $batch_id = $data['batch_id'] ?? null;
        $item_id = $data['item_id'] ?? null;

        if (!$flag_id || !$batch_id || !$item_id) {
            send_json(['error' => 'flag_id, batch_id and item_id are required'], 400);
        }

        // Set item match status to manual
        $i_stmt = $pdo->prepare("UPDATE items SET st = 'manual' WHERE id = ? AND batch_id = ?");
        $i_stmt->execute([$item_id, $batch_id]);

        // Delete flag
        $f_stmt = $pdo->prepare("DELETE FROM flags WHERE id = ?");
        $f_stmt->execute([$flag_id]);

        // Check if batch is completed
        $p_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM items WHERE batch_id = ? AND st = 'pending'");
        $p_stmt->execute([$batch_id]);
        $pending_count = (int)$p_stmt->fetch()['count'];

        $fl_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM flags WHERE batch_id = ?");
        $fl_stmt->execute([$batch_id]);
        $flags_count = (int)$fl_stmt->fetch()['count'];

        if ($pending_count === 0 && $flags_count === 0) {
            $c_stmt = $pdo->prepare("UPDATE batches SET status = 'Completed', completed_at = ? WHERE id = ?");
            $c_stmt->execute([time() * 1000, $batch_id]);
        }

        send_json(['success' => true]);
    } elseif ($action === 'dismiss') {
        $flag_id = $data['flag_id'] ?? null;
        $batch_id = $data['batch_id'] ?? null;

        if (!$flag_id) {
            send_json(['error' => 'flag_id is required'], 400);
        }

        $f_stmt = $pdo->prepare("DELETE FROM flags WHERE id = ?");
        $f_stmt->execute([$flag_id]);

        if ($batch_id) {
            $p_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM items WHERE batch_id = ? AND st = 'pending'");
            $p_stmt->execute([$batch_id]);
            $pending_count = (int)$p_stmt->fetch()['count'];

            $fl_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM flags WHERE batch_id = ?");
            $fl_stmt->execute([$batch_id]);
            $flags_count = (int)$fl_stmt->fetch()['count'];

            if ($pending_count === 0 && $flags_count === 0) {
                $c_stmt = $pdo->prepare("UPDATE batches SET status = 'Completed', completed_at = ? WHERE id = ?");
                $c_stmt->execute([time() * 1000, $batch_id]);
            }
        }

        send_json(['success' => true]);
    }
}
