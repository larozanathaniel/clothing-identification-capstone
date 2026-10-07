<?php
require_once __DIR__ . '/db.php';
require_role(['admin']);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($action)) {
    // ── Dashboard stats ────────────────────────────────────────────────────

    // Auto vs manual counts (from garments.match_type)
    $auto_row = $pdo->query("SELECT COUNT(*) AS cnt FROM garments WHERE match_type = 'auto'")->fetch();
    $man_row  = $pdo->query("SELECT COUNT(*) AS cnt FROM garments WHERE match_type = 'manual'")->fetch();
    $auto = (int)$auto_row['cnt'];
    $man  = (int)$man_row['cnt'];
    $acc  = ($auto + $man > 0) ? (int)round(($auto / ($auto + $man)) * 100) : 0;

    // Average match time from match_logs
    $avg_row = $pdo->query("SELECT AVG(match_time) AS avg_time FROM match_logs")->fetch();
    $avg = $avg_row['avg_time'] ? number_format((float)$avg_row['avg_time'], 3) : '0.000';

    // Batch status counts
    $statuses = ['Open', 'Ready to Sort', 'Completed'];
    $status_counts = [];
    foreach ($statuses as $s) {
        $sr = $pdo->prepare("SELECT COUNT(*) AS cnt FROM batches WHERE status = ?");
        $sr->execute([$s]);
        $status_counts[$s] = (int)$sr->fetch()['cnt'];
    }

    // Threshold setting
    $thr_row = $pdo->query("SELECT value FROM settings WHERE `key` = 'thr'")->fetch();
    $thr = (int)($thr_row['value'] ?? 80);

    // Users list
    $u_stmt = $pdo->query(
        "SELECT user_id AS id, username AS u, plain_password AS p, role, on_status
         FROM users ORDER BY user_id ASC"
    );
    $users = array_map(function($u) {
        return [
            'id'   => (int)$u['id'],
            'u'    => $u['u'],
            'p'    => $u['p'],
            'role' => strtolower($u['role']),
            'on'   => (bool)$u['on_status']
        ];
    }, $u_stmt->fetchAll());

    send_json([
        'accuracy'      => $acc,
        'avg_time'      => $avg,
        'status_counts' => $status_counts,
        'thr'           => $thr,
        'users'         => $users
    ]);

} elseif ($action === 'threshold') {
    $data = get_json_input();
    $thr  = (int)($data['thr'] ?? 80);
    if ($thr < 50 || $thr > 99) {
        send_json(['error' => 'Enter a value from 50 to 99'], 400);
    }
    $pdo->prepare("INSERT INTO settings (`key`, value) VALUES ('thr', ?)
                   ON DUPLICATE KEY UPDATE value = ?")->execute([(string)$thr, (string)$thr]);
    send_json(['success' => true, 'thr' => $thr]);

} elseif ($action === 'save_user') {
    $data = get_json_input();
    $id   = isset($data['id']) ? (int)$data['id'] : -1;
    $u    = trim($data['u']    ?? '');
    $p    = $data['p']         ?? '';
    $role = ucfirst(strtolower($data['role'] ?? 'staff')); // 'Staff' or 'Admin'
    if (!in_array($role, ['Staff', 'Admin'], true)) send_json(['error' => 'Invalid role.'], 400);
    $on   = isset($data['on']) ? ($data['on'] ? 1 : 0) : 1;

    if (empty($u)) send_json(['error' => 'Username is required.'], 400);

    if ($id < 0) {
        // INSERT new user
        $chk = $pdo->prepare("SELECT COUNT(*) AS cnt FROM users WHERE username = ?");
        $chk->execute([$u]);
        if ((int)$chk->fetch()['cnt'] > 0) send_json(['error' => 'Username already exists.'], 400);
        if (strlen($p) < 6) send_json(['error' => 'Password must be at least 6 characters.'], 400);

        $ins = $pdo->prepare(
            "INSERT INTO users (username, plain_password, password_hash, role, on_status)
             VALUES (?, ?, ?, ?, ?)"
        );
        $ins->execute([$u, $p, password_hash($p, PASSWORD_DEFAULT), $role, $on]);
    } else {
        // UPDATE existing user
        $chk = $pdo->prepare("SELECT COUNT(*) AS cnt FROM users WHERE username = ? AND user_id != ?");
        $chk->execute([$u, $id]);
        if ((int)$chk->fetch()['cnt'] > 0) send_json(['error' => 'Username already exists.'], 400);

        if (!empty($p)) {
            if (strlen($p) < 6) send_json(['error' => 'Password must be at least 6 characters.'], 400);
            $upd = $pdo->prepare(
                "UPDATE users SET username = ?, plain_password = ?, password_hash = ?,
                 role = ?, on_status = ? WHERE user_id = ?"
            );
            $upd->execute([$u, $p, password_hash($p, PASSWORD_DEFAULT), $role, $on, $id]);
        } else {
            $upd = $pdo->prepare(
                "UPDATE users SET username = ?, role = ?, on_status = ? WHERE user_id = ?"
            );
            $upd->execute([$u, $role, $on, $id]);
        }
    }
    send_json(['success' => true]);
}
