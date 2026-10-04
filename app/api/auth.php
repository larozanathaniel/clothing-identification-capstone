<?php
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'login':
        $data = get_json_input();
        $u = trim($data['username'] ?? '');
        $p = $data['password'] ?? '';

        if (empty($u) || empty($p)) {
            send_json(['error' => 'Username and password are required.'], 400);
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE u = ? AND p = ?");
        $stmt->execute([$u, $p]);
        $user = $stmt->fetch();

        if (!$user) {
            send_json(['error' => 'Invalid username or password.'], 401);
        }

        if ((int)$user['on_status'] !== 1) {
            send_json(['error' => 'This account is deactivated. Contact an admin.'], 403);
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['u'];
        $_SESSION['role'] = $user['role'];

        send_json([
            'success' => true,
            'user' => [
                'id' => (int)$user['id'],
                'u' => $user['u'],
                'p' => $user['p'],
                'role' => $user['role'],
                'on' => (bool)$user['on_status']
            ]
        ]);
        break;

    case 'logout':
        session_destroy();
        send_json(['success' => true]);
        break;

    case 'me':
        if (!isset($_SESSION['username'])) {
            send_json(['user' => null]);
        }
        $stmt = $pdo->prepare("SELECT * FROM users WHERE u = ?");
        $stmt->execute([$_SESSION['username']]);
        $user = $stmt->fetch();
        if (!$user || (int)$user['on_status'] !== 1) {
            session_destroy();
            send_json(['user' => null]);
        }
        send_json([
            'user' => [
                'id' => (int)$user['id'],
                'u' => $user['u'],
                'p' => $user['p'],
                'role' => $user['role'],
                'on' => (bool)$user['on_status']
            ]
        ]);
        break;

    case 'change_password':
        if (!isset($_SESSION['username'])) {
            send_json(['error' => 'Not authenticated'], 401);
        }
        $data = get_json_input();
        $old = $data['old_password'] ?? '';
        $new = $data['new_password'] ?? '';
        $new2 = $data['confirm_password'] ?? '';

        $stmt = $pdo->prepare("SELECT * FROM users WHERE u = ?");
        $stmt->execute([$_SESSION['username']]);
        $user = $stmt->fetch();

        if (!$user || $user['p'] !== $old) {
            send_json(['error' => 'Current password is incorrect.'], 400);
        }
        if (strlen($new) < 6) {
            send_json(['error' => 'New password must be at least 6 characters.'], 400);
        }
        if ($new !== $new2) {
            send_json(['error' => 'New passwords do not match.'], 400);
        }

        $update = $pdo->prepare("UPDATE users SET p = ? WHERE id = ?");
        $update->execute([$new, $user['id']]);

        send_json(['success' => true, 'message' => 'Password updated successfully']);
        break;

    default:
        send_json(['error' => 'Invalid action'], 400);
}
