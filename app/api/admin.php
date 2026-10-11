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
    $statuses = ['Open', 'Completed'];
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
        "SELECT user_id AS id, username AS u, full_name, plain_password AS p, role, on_status, avatar
         FROM users ORDER BY user_id ASC"
    );
    $users = array_map(function($u) {
        return [
            'id'     => (int)$u['id'],
            'u'      => $u['u'],
            'name'   => $u['full_name'] ?? '',
            'p'      => $u['p'],
            'role'   => strtolower($u['role']),
            'on'     => (bool)$u['on_status'],
            'avatar' => $u['avatar'] ?? null
        ];
    }, $u_stmt->fetchAll());

    // System counts for Overview and Settings
    $total_garments  = (int)$pdo->query("SELECT COUNT(*) AS cnt FROM garments")->fetch()['cnt'];
    $total_batches   = (int)$pdo->query("SELECT COUNT(*) AS cnt FROM batches")->fetch()['cnt'];
    $total_customers = (int)$pdo->query("SELECT COUNT(*) AS cnt FROM customers")->fetch()['cnt'];

    send_json([
        'accuracy'        => $acc,
        'avg_time'        => $avg,
        'status_counts'   => $status_counts,
        'thr'             => $thr,
        'users'           => $users,
        'total_garments'  => $total_garments,
        'total_batches'   => $total_batches,
        'total_customers' => $total_customers
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
    $name = trim($data['name'] ?? '');
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
            "INSERT INTO users (username, full_name, plain_password, password_hash, role, on_status)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([$u, $name, $p, password_hash($p, PASSWORD_DEFAULT), $role, $on]);
    } else {
        // UPDATE existing user
        $chk = $pdo->prepare("SELECT COUNT(*) AS cnt FROM users WHERE username = ? AND user_id != ?");
        $chk->execute([$u, $id]);
        if ((int)$chk->fetch()['cnt'] > 0) send_json(['error' => 'Username already exists.'], 400);

        if (!empty($p)) {
            if (strlen($p) < 6) send_json(['error' => 'Password must be at least 6 characters.'], 400);
            $upd = $pdo->prepare(
                "UPDATE users SET username = ?, full_name = ?, plain_password = ?, password_hash = ?,
                 role = ?, on_status = ? WHERE user_id = ?"
            );
            $upd->execute([$u, $name, $p, password_hash($p, PASSWORD_DEFAULT), $role, $on, $id]);
        } else {
            $upd = $pdo->prepare(
                "UPDATE users SET username = ?, full_name = ?, role = ?, on_status = ? WHERE user_id = ?"
            );
            $upd->execute([$u, $name, $role, $on, $id]);
        }
    }
    send_json(['success' => true]);

} elseif ($action === 'delete_user') {
    $data = get_json_input();
    $id   = isset($data['id']) ? (int)$data['id'] : -1;
    if ($id <= 0) send_json(['error' => 'Invalid user ID.'], 400);

    // Fetch user to delete
    $stmt = $pdo->prepare("SELECT user_id, username, full_name, avatar FROM users WHERE user_id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) send_json(['error' => 'User not found.'], 404);

    // Protect currently logged-in account
    if ($user['user_id'] == ($_SESSION['user_id'] ?? 0) || $user['username'] === ($_SESSION['username'] ?? '')) {
        send_json(['error' => "You cannot delete your own account."], 400);
    }

    // Delete avatar image file if exists
    if (!empty($user['avatar'])) {
        delete_upload($user['avatar']);
    }

    // Clear foreign references in batches and rescans if any
    $pdo->prepare("UPDATE batches SET staff_id = NULL WHERE staff_id = ?")->execute([$id]);
    $pdo->prepare("UPDATE rescans SET confirmed_by = NULL WHERE confirmed_by = ?")->execute([$id]);

    // Delete user record
    $pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$id]);

    send_json(['success' => true, 'message' => 'User removed successfully.']);
} else {
    send_json(['error' => 'Invalid action.'], 400);
}
