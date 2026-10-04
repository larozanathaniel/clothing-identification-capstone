<?php
require_once __DIR__ . '/db.php';

require_role(['admin']);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($action)) {
    // Admin Dashboard Stats
    $auto_stmt = $pdo->query("SELECT COUNT(*) as count FROM items WHERE st = 'auto'");
    $auto = (int)$auto_stmt->fetch()['count'];

    $man_stmt = $pdo->query("SELECT COUNT(*) as count FROM items WHERE st = 'manual'");
    $man = (int)$man_stmt->fetch()['count'];

    $acc = ($auto + $man > 0) ? (int)round(($auto / ($auto + $man)) * 100) : 0;

    // Average match duration from match_logs
    $avg_stmt = $pdo->query("SELECT AVG(match_time) as avg_time FROM match_logs");
    $avg_val = $avg_stmt->fetch()['avg_time'];
    $avg = $avg_val ? number_format((float)$avg_val, 1) : '0.0';

    // Status counts
    $statuses = ['Open', 'Washing', 'Ready to Sort', 'Completed'];
    $status_counts = [];
    foreach ($statuses as $s) {
        $st_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM batches WHERE status = ?");
        $st_stmt->execute([$s]);
        $status_counts[$s] = (int)$st_stmt->fetch()['count'];
    }

    // Threshold
    $thr_stmt = $pdo->query("SELECT value FROM settings WHERE key = 'thr'");
    $thr = (int)($thr_stmt->fetch()['value'] ?? 80);

    // Users
    $u_stmt = $pdo->query("SELECT id, u, p, role, on_status FROM users ORDER BY id ASC");
    $raw_users = $u_stmt->fetchAll();
    $users = array_map(function($u) {
        return [
            'id' => (int)$u['id'],
            'u' => $u['u'],
            'p' => $u['p'],
            'role' => $u['role'],
            'on' => (bool)$u['on_status']
        ];
    }, $raw_users);

    send_json([
        'accuracy' => $acc,
        'avg_time' => $avg,
        'status_counts' => $status_counts,
        'thr' => $thr,
        'users' => $users
    ]);
} elseif ($action === 'threshold') {
    $data = get_json_input();
    $thr = (int)($data['thr'] ?? 80);
    if ($thr < 50 || $thr > 99) {
        send_json(['error' => 'Enter a value from 50 to 99'], 400);
    }
    $stmt = $pdo->prepare("UPDATE settings SET value = ? WHERE key = 'thr'");
    $stmt->execute([(string)$thr]);

    send_json(['success' => true, 'thr' => $thr]);
} elseif ($action === 'save_user') {
    $data = get_json_input();
    $id = isset($data['id']) ? (int)$data['id'] : -1;
    $u = trim($data['u'] ?? '');
    $p = $data['p'] ?? '';
    $role = $data['role'] ?? 'staff';
    $on = isset($data['on']) ? ($data['on'] ? 1 : 0) : 1;

    if (empty($u)) {
        send_json(['error' => 'Username is required.'], 400);
    }

    // Check duplicate username
    if ($id < 0) {
        $chk = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE u = ?");
        $chk->execute([$u]);
        if ((int)$chk->fetch()['count'] > 0) {
            send_json(['error' => 'Username already exists.'], 400);
        }
        if (strlen($p) < 6) {
            send_json(['error' => 'Password must be at least 6 characters.'], 400);
        }
        $stmt = $pdo->prepare("INSERT INTO users (u, p, role, on_status) VALUES (?, ?, ?, ?)");
        $stmt->execute([$u, $p, $role, $on]);
    } else {
        $chk = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE u = ? AND id != ?");
        $chk->execute([$u, $id]);
        if ((int)$chk->fetch()['count'] > 0) {
            send_json(['error' => 'Username already exists.'], 400);
        }
        if (!empty($p) && strlen($p) < 6) {
            send_json(['error' => 'Password must be at least 6 characters.'], 400);
        }

        if (!empty($p)) {
            $stmt = $pdo->prepare("UPDATE users SET u = ?, p = ?, role = ?, on_status = ? WHERE id = ?");
            $stmt->execute([$u, $p, $role, $on, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET u = ?, role = ?, on_status = ? WHERE id = ?");
            $stmt->execute([$u, $role, $on, $id]);
        }
    }

    send_json(['success' => true]);
}
