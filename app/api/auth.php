<?php
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'login':
        $data = get_json_input();
        $u    = trim($data['username'] ?? '');
        $p    = $data['password']  ?? '';

        if (empty($u) || empty($p)) {
            send_json(['error' => 'Username and password are required.'], 400);
        }

        // Match against plain_password (dev/capstone mode)
        $stmt = $pdo->prepare(
            "SELECT user_id AS id, username AS u, full_name, plain_password AS p,
                    role, on_status, avatar
             FROM users
             WHERE username = ? AND plain_password = ?"
        );
        $stmt->execute([$u, $p]);
        $user = $stmt->fetch();

        if (!$user) {
            send_json(['error' => 'Invalid username or password.'], 401);
        }
        if ((int)$user['on_status'] !== 1) {
            send_json(['error' => 'This account is deactivated. Contact an admin.'], 403);
        }

        $_SESSION['user_id']   = $user['id'];
        $_SESSION['username']  = $user['u'];
        $_SESSION['full_name'] = $user['full_name'] ?? '';
        $_SESSION['role']      = strtolower($user['role']); // normalize to lowercase
        $_SESSION['avatar']    = $user['avatar'] ?? null;

        send_json([
            'success' => true,
            'user' => [
                'id'     => (int)$user['id'],
                'u'      => $user['u'],
                'name'   => $user['full_name'] ?: $user['u'],
                'p'      => $user['p'],
                'role'   => strtolower($user['role']),
                'on'     => (bool)$user['on_status'],
                'avatar' => $user['avatar'] ?? null
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
        $stmt = $pdo->prepare(
            "SELECT user_id AS id, username AS u, full_name, plain_password AS p,
                    role, on_status, avatar
             FROM users WHERE username = ?"
        );
        $stmt->execute([$_SESSION['username']]);
        $user = $stmt->fetch();
        if (!$user || (int)$user['on_status'] !== 1) {
            session_destroy();
            send_json(['user' => null]);
        }
        send_json([
            'user' => [
                'id'     => (int)$user['id'],
                'u'      => $user['u'],
                'name'   => $user['full_name'] ?: $user['u'],
                'p'      => $user['p'],
                'role'   => strtolower($user['role']),
                'on'     => (bool)$user['on_status'],
                'avatar' => $user['avatar'] ?? null
            ]
        ]);
        break;

    case 'change_password':
        if (!isset($_SESSION['username'])) {
            send_json(['error' => 'Not authenticated'], 401);
        }
        $data = get_json_input();
        $old  = $data['old_password']     ?? '';
        $new  = $data['new_password']     ?? '';
        $new2 = $data['confirm_password'] ?? '';

        $stmt = $pdo->prepare(
            "SELECT user_id AS id, plain_password AS p
              FROM users WHERE username = ?"
        );
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

        $upd = $pdo->prepare(
            "UPDATE users SET plain_password = ?, password_hash = ?
             WHERE user_id = ?"
        );
        $upd->execute([$new, password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        send_json(['success' => true, 'message' => 'Password updated successfully']);
        break;

    case 'update_avatar':
        if (!isset($_SESSION['username'])) {
            send_json(['error' => 'Not authenticated'], 401);
        }
        $data       = get_json_input();
        $avatarData = $data['avatar'] ?? null;
        $remove     = !empty($data['remove']);

        $stmt = $pdo->prepare("SELECT user_id, avatar FROM users WHERE username = ?");
        $stmt->execute([$_SESSION['username']]);
        $user = $stmt->fetch();
        if (!$user) send_json(['error' => 'User not found'], 404);

        $oldAvatar     = $user['avatar'];
        $newAvatarPath = null;

        if (!$remove && $avatarData) {
            $newAvatarPath = save_data_uri_image($avatarData, 'avatars');
            if (!$newAvatarPath) {
                send_json(['error' => 'Invalid image format. Please upload a valid JPG, PNG, or WebP image.'], 400);
            }
        }

        $upd = $pdo->prepare("UPDATE users SET avatar = ? WHERE user_id = ?");
        $upd->execute([$newAvatarPath, $user['user_id']]);

        // Delete previous avatar file if replaced or removed
        if ($oldAvatar && $oldAvatar !== $newAvatarPath) {
            delete_upload($oldAvatar);
        }

        $_SESSION['avatar'] = $newAvatarPath;

        send_json([
            'success' => true,
            'avatar'  => $newAvatarPath,
            'message' => $newAvatarPath ? 'Profile picture updated' : 'Profile picture removed'
        ]);
        break;

    default:
        send_json(['error' => 'Invalid action'], 400);
}
