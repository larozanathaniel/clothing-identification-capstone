<?php
require_once __DIR__ . '/db.php';
require_role(['staff']);

$method = $_SERVER['REQUEST_METHOD'];
$data   = get_json_input();
$action = $_GET['action'] ?? $data['action'] ?? '';

function map_garment_flag($g) {
    return [
        'id'   => (string)$g['garment_id'],
        'name' => $g['garment_name'] ?? 'Item',
        'img'  => $g['intake_image'] ?? '',
        'st'   => $g['match_type']   ?? 'pending'
    ];
}

if ($method === 'GET' || $action === 'list') {
    $stmt = $pdo->query(
        "SELECT f.*, b.status AS batch_status, c.name AS customer
         FROM flags f
         JOIN batches b  ON b.batch_id  = f.batch_id
         JOIN customers c ON c.customer_id = b.customer_id
         ORDER BY f.created_at DESC"
    );
    $flags = $stmt->fetchAll();

    $result = [];
    foreach ($flags as $f) {
        $bid = (string)$f['batch_id'];

        $gs = $pdo->prepare(
            "SELECT * FROM garments WHERE batch_id = ? ORDER BY created_at ASC"
        );
        $gs->execute([$bid]);
        $garments = $gs->fetchAll();

        $result[] = [
            'b' => [
                'id'       => $bid,
                'customer' => $f['customer'],
                'status'   => $f['batch_status'],
                'items'    => array_map('map_garment_flag', $garments)
            ],
            'f' => [
                'id'  => $f['flag_id'],
                'img' => $f['img']
            ]
        ];
    }
    send_json(['unresolved' => $result]);

} elseif ($method === 'POST') {

    if ($action === 'flag') {
        $batch_id = $data['batch_id'] ?? null;
        $img      = $data['img']      ?? '';
        if (!$batch_id || !$img) send_json(['error' => 'batch_id and img required'], 400);

        $flag_id = 'f' . (int)(microtime(true) * 1000) . mt_rand(100, 999);
        $ins = $pdo->prepare(
            "INSERT INTO flags (flag_id, batch_id, img) VALUES (?, ?, ?)"
        );
        $ins->execute([$flag_id, $batch_id, $img]);
        send_json(['success' => true, 'id' => $flag_id]);

    } elseif ($action === 'assign') {
        $flag_id = $data['flag_id'] ?? null;
        $batch_id = $data['batch_id'] ?? null;
        $item_id  = $data['item_id']  ?? null;

        if (!$flag_id || !$batch_id || !$item_id) {
            send_json(['error' => 'flag_id, batch_id and item_id are required'], 400);
        }

        // Mark garment as manually matched
        $pdo->prepare(
            "UPDATE garments SET match_status = 'Matched', match_type = 'manual'
             WHERE garment_id = ? AND batch_id = ?"
        )->execute([$item_id, $batch_id]);

        // Delete flag
        $pdo->prepare("DELETE FROM flags WHERE flag_id = ?")->execute([$flag_id]);

        // Check if batch is fully resolved
        $pending = (int)$pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM garments
             WHERE batch_id = ? AND match_type = 'pending'"
        )->execute([$batch_id]) ? $pdo->query(
            "SELECT COUNT(*) AS cnt FROM garments WHERE batch_id = $batch_id AND match_type = 'pending'"
        )->fetch()['cnt'] : 0;

        // safer recount
        $pr = $pdo->prepare("SELECT COUNT(*) AS cnt FROM garments WHERE batch_id = ? AND match_type = 'pending'");
        $pr->execute([$batch_id]);
        $pending = (int)$pr->fetch()['cnt'];

        $fr = $pdo->prepare("SELECT COUNT(*) AS cnt FROM flags WHERE batch_id = ?");
        $fr->execute([$batch_id]);
        $flags_left = (int)$fr->fetch()['cnt'];

        if ($pending === 0 && $flags_left === 0) {
            $pdo->prepare("UPDATE batches SET status = 'Completed' WHERE batch_id = ?")
                ->execute([$batch_id]);
        }
        send_json(['success' => true]);

    } elseif ($action === 'dismiss') {
        $flag_id  = $data['flag_id']  ?? null;
        $batch_id = $data['batch_id'] ?? null;
        if (!$flag_id) send_json(['error' => 'flag_id is required'], 400);

        $pdo->prepare("DELETE FROM flags WHERE flag_id = ?")->execute([$flag_id]);

        if ($batch_id) {
            $pr = $pdo->prepare("SELECT COUNT(*) AS cnt FROM garments WHERE batch_id = ? AND match_type = 'pending'");
            $pr->execute([$batch_id]);
            $pending = (int)$pr->fetch()['cnt'];

            $fr = $pdo->prepare("SELECT COUNT(*) AS cnt FROM flags WHERE batch_id = ?");
            $fr->execute([$batch_id]);
            $flags_left = (int)$fr->fetch()['cnt'];

            if ($pending === 0 && $flags_left === 0) {
                $pdo->prepare("UPDATE batches SET status = 'Completed' WHERE batch_id = ?")
                    ->execute([$batch_id]);
            }
        }
        send_json(['success' => true]);
    }
}
