<?php
// Database Helper - Intelligent Laundry Management System
// MariaDB via XAMPP (database: laundry_mgmt_db)

session_start();

// ── MariaDB connection ──────────────────────────────────────────────────────
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'laundry_mgmt_db');
define('DB_USER', 'root');
define('DB_PASS', '');          // default XAMPP password is empty

// Where uploaded garment images are stored (inside the app folder)
define('UPLOAD_ROOT', realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads');

// Batch workflow (must match the frontend STATUS list and the batches.status ENUM)
const BATCH_STATUSES = ['Open', 'Washing', 'Ready to Sort', 'Completed'];

$pdo = null;
try {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,   false);
} catch (PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// ── One-time schema upgrade ────────────────────────────────────────────────
// Runs ONLY when settings.schema_v is below SCHEMA_VERSION, so it no longer
// touches the database (or resets passwords) on every request.
const SCHEMA_VERSION = 3;

function column_exists($pdo, $table, $col) {
    // SHOW statements cannot take bound parameters, so the value is quoted instead
    return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch();
}

function init_app($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
        `key`   VARCHAR(50)  NOT NULL,
        `value` VARCHAR(255) NOT NULL,
        PRIMARY KEY (`key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $row = $pdo->query("SELECT `value` FROM `settings` WHERE `key` = 'schema_v'")->fetch();
    if ($row && (int)$row['value'] >= SCHEMA_VERSION) return;   // already upgraded

    $pdo->exec("CREATE TABLE IF NOT EXISTS `flags` (
        `flag_id`    VARCHAR(40)  NOT NULL,
        `batch_id`   INT(11)      NOT NULL,
        `img`        VARCHAR(255) NOT NULL,
        `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`flag_id`),
        KEY `batch_id` (`batch_id`),
        CONSTRAINT `flags_ibfk_1`
            FOREIGN KEY (`batch_id`) REFERENCES `batches` (`batch_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `match_logs` (
        `log_id`     INT(11)      NOT NULL AUTO_INCREMENT,
        `batch_id`   INT(11)      NOT NULL,
        `match_time` FLOAT        NOT NULL,
        `is_auto`    TINYINT(1)   NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`log_id`),
        KEY `batch_id` (`batch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!column_exists($pdo, 'garments', 'match_type')) {
        $pdo->exec("ALTER TABLE `garments`
            ADD COLUMN `match_type` ENUM('auto','manual','pending') NOT NULL DEFAULT 'pending'
            AFTER `match_status`");
    }
    if (!column_exists($pdo, 'garments', 'garment_name')) {
        $pdo->exec("ALTER TABLE `garments`
            ADD COLUMN `garment_name` VARCHAR(100) NOT NULL DEFAULT 'Item'
            AFTER `garment_id`");
    }
    if (!column_exists($pdo, 'users', 'on_status')) {
        $pdo->exec("ALTER TABLE `users`
            ADD COLUMN `on_status` TINYINT(1) NOT NULL DEFAULT 1 AFTER `role`");
    }
    if (!column_exists($pdo, 'users', 'plain_password')) {
        $pdo->exec("ALTER TABLE `users`
            ADD COLUMN `plain_password` VARCHAR(255) NOT NULL DEFAULT '' AFTER `password_hash`");
    }

    // The UI has a 'Washing' step; the original ENUM did not.
    $t = $pdo->query("SHOW COLUMNS FROM `batches` LIKE 'status'")->fetch();
    if ($t && stripos($t['Type'], 'Washing') === false) {
        $pdo->exec("ALTER TABLE `batches`
            MODIFY `status` ENUM('Open','Washing','Ready to Sort','Completed')
            NOT NULL DEFAULT 'Open'");
    }

    $pdo->exec("INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('thr', '80')");

    // Default logins - only filled in when empty, never overwrites a changed password
    $pdo->exec("UPDATE `users` SET `plain_password`='admin123', `on_status`=1
                WHERE `username`='admin' AND `plain_password`=''");
    $pdo->exec("UPDATE `users` SET `plain_password`='staff123', `on_status`=1
                WHERE `username`='staff' AND `plain_password`=''");

    $pdo->prepare("INSERT INTO `settings` (`key`, `value`) VALUES ('schema_v', ?)
                   ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)")
        ->execute([(string)SCHEMA_VERSION]);
}

init_app($pdo);

// ── Response / request helpers ─────────────────────────────────────────────

function send_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function get_json_input() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

function require_role($roles) {
    if (!isset($_SESSION['role'])) {
        send_json(['error' => 'Not authenticated'], 401);
    }
    if (!in_array($_SESSION['role'], $roles, true)) {
        send_json(['error' => 'You do not have permission to do this.'], 403);
    }
}

// ── Image storage (files on disk, path in the database) ────────────────────

function ensure_upload_dir($sub) {
    $dir = UPLOAD_ROOT . DIRECTORY_SEPARATOR . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        send_json(['error' => 'Cannot create upload folder. Check folder permissions.'], 500);
    }
    // Never let anything inside uploads/ run as PHP
    $ht = UPLOAD_ROOT . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|php5|php7)$\">\n    Require all denied\n</FilesMatch>\n");
    }
    return $dir;
}

// Saves a data:image/...;base64 string and returns a relative path like
// "uploads/intake/ab12cd34ef567890.jpg". Returns null when the input is not a valid image.
function save_data_uri_image($dataUri, $sub) {
    if (!is_string($dataUri) || !preg_match('#^data:image/(jpeg|jpg|png|webp|gif);base64,#i', $dataUri, $m)) {
        return null;
    }
    $bin = base64_decode(substr($dataUri, strpos($dataUri, ',') + 1), true);
    if ($bin === false || strlen($bin) < 20 || strlen($bin) > 8 * 1024 * 1024) return null;
    if (@getimagesizefromstring($bin) === false) return null;       // must really be an image
    $ext  = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
    $dir  = ensure_upload_dir($sub);
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (file_put_contents($dir . DIRECTORY_SEPARATOR . $name, $bin) === false) return null;
    return 'uploads/' . $sub . '/' . $name;
}

// Turns a stored relative path into an absolute path, only if it is inside uploads/.
function upload_abs_path($rel) {
    if (!$rel || !is_string($rel)) return null;
    $abs  = realpath(UPLOAD_ROOT . DIRECTORY_SEPARATOR . ltrim(preg_replace('#^uploads/#', '', $rel), '/\\'));
    $root = realpath(UPLOAD_ROOT);
    if ($abs && $root && strpos($abs, $root . DIRECTORY_SEPARATOR) === 0 && is_file($abs)) return $abs;
    return null;
}

function delete_upload($rel) {
    $abs = upload_abs_path($rel);
    if ($abs) @unlink($abs);
}

// What the browser should use as <img src> for a garment row
function garment_img_url($g) {
    if (!empty($g['intake_image_path'])) return $g['intake_image_path'];
    return $g['intake_image'] ?? '';          // legacy rows saved before Phase 1
}

// What the matcher should read for a garment row (absolute path or legacy data URI)
function garment_img_src($g) {
    if (!empty($g['intake_image_path'])) {
        $abs = upload_abs_path($g['intake_image_path']);
        if ($abs) return $abs;
    }
    return $g['intake_image'] ?? '';
}

// ── Batch completion (shared by sort_confirm.php and flags.php) ────────────
// A batch completes when nothing is pending and no flags remain.
function maybe_complete_batch($pdo, $batch_id) {
    $p = $pdo->prepare("SELECT COUNT(*) FROM garments WHERE batch_id = ? AND match_type = 'pending'");
    $p->execute([$batch_id]);
    $f = $pdo->prepare("SELECT COUNT(*) FROM flags WHERE batch_id = ?");
    $f->execute([$batch_id]);
    $t = $pdo->prepare("SELECT COUNT(*) FROM garments WHERE batch_id = ?");
    $t->execute([$batch_id]);

    if ((int)$p->fetchColumn() === 0 && (int)$f->fetchColumn() === 0 && (int)$t->fetchColumn() > 0) {
        $u = $pdo->prepare("UPDATE batches SET status = 'Completed'
                            WHERE batch_id = ? AND status = 'Ready to Sort'");
        $u->execute([$batch_id]);
        return $u->rowCount() > 0;
    }
    return false;
}
