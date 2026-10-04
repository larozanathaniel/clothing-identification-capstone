<?php
// Database Helper - Intelligent Laundry Management System
// Connected to MariaDB via XAMPP (laundry_mgmt_db)

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

// ── MariaDB connection ──────────────────────────────────────────────────────
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'laundry_mgmt_db');
define('DB_USER', 'root');
define('DB_PASS', '');          // default XAMPP password is empty

$pdo = null;
try {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,   false);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// ── Bootstrap: ensure app-level settings & sequences exist ─────────────────
// The SQL dump already creates all tables.  We only seed runtime rows
// that the app needs but the dump does not include.

function init_app($pdo) {
    // settings table (key/value store for threshold and batch sequence)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
        `key`   VARCHAR(50)  NOT NULL,
        `value` VARCHAR(255) NOT NULL,
        PRIMARY KEY (`key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // flags table  (unresolved / low-confidence garments pending manual sort)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `flags` (
        `flag_id`    VARCHAR(40)  NOT NULL,
        `batch_id`   INT(11)      NOT NULL,
        `img`        MEDIUMTEXT   NOT NULL,
        `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`flag_id`),
        KEY `batch_id` (`batch_id`),
        CONSTRAINT `flags_ibfk_1`
            FOREIGN KEY (`batch_id`) REFERENCES `batches` (`batch_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // match_logs table  (timing & auto/manual stats for admin dashboard)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `match_logs` (
        `log_id`     INT(11)      NOT NULL AUTO_INCREMENT,
        `batch_id`   INT(11)      NOT NULL,
        `match_time` FLOAT        NOT NULL,
        `is_auto`    TINYINT(1)   NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`log_id`),
        KEY `batch_id` (`batch_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // garments: add match_type column if not present
    // (extends the existing match_status with auto/manual detail)
    $cols = $pdo->query("SHOW COLUMNS FROM `garments` LIKE 'match_type'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE `garments`
            ADD COLUMN `match_type` ENUM('auto','manual','pending')
                NOT NULL DEFAULT 'pending'
                AFTER `match_status`");
    }

    // garments: add intake_image (base64 / data-URI) column if not present
    $cols2 = $pdo->query("SHOW COLUMNS FROM `garments` LIKE 'intake_image'")->fetchAll();
    if (empty($cols2)) {
        $pdo->exec("ALTER TABLE `garments`
            ADD COLUMN `intake_image` MEDIUMTEXT DEFAULT NULL
                AFTER `intake_image_path`");
    }

    // garments: add garment_name column if not present
    $cols3 = $pdo->query("SHOW COLUMNS FROM `garments` LIKE 'garment_name'")->fetchAll();
    if (empty($cols3)) {
        $pdo->exec("ALTER TABLE `garments`
            ADD COLUMN `garment_name` VARCHAR(100) NOT NULL DEFAULT 'Item'
                AFTER `garment_id`");
    }

    // users: add on_status column if not present
    $cols4 = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'on_status'")->fetchAll();
    if (empty($cols4)) {
        $pdo->exec("ALTER TABLE `users`
            ADD COLUMN `on_status` TINYINT(1) NOT NULL DEFAULT 1
                AFTER `role`");
    }

    // users: add plain password column for simple auth (dev/capstone only)
    $cols5 = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'plain_password'")->fetchAll();
    if (empty($cols5)) {
        $pdo->exec("ALTER TABLE `users`
            ADD COLUMN `plain_password` VARCHAR(255) NOT NULL DEFAULT ''
                AFTER `password_hash`");
    }

    // Seed default threshold setting
    $pdo->exec("INSERT IGNORE INTO `settings` (`key`, `value`) VALUES ('thr', '80')");

    // Seed default users with plain passwords (dev only)
    $pdo->exec("UPDATE `users` SET `plain_password` = 'admin123', `on_status` = 1 WHERE `username` = 'admin'");
    $pdo->exec("UPDATE `users` SET `plain_password` = 'staff123', `on_status` = 1 WHERE `username` = 'staff'");
}

init_app($pdo);

// ── Helpers ────────────────────────────────────────────────────────────────

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

// ── Batch ID helper  (replaces SQLite integer sequence) ───────────────────
// MariaDB AUTO_INCREMENT on batches.batch_id handles sequencing natively.
// We keep a 'seq' key in settings as a readable counter mirror.
function next_batch_id($pdo) {
    // Let MariaDB assign the AUTO_INCREMENT value; we read it back after INSERT.
    return null; // signal to caller to use LAST_INSERT_ID()
}
