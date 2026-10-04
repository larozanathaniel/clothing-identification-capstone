<?php
// Database Helper for Intelligent Laundry Management System
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

session_start();

$db_file = __DIR__ . '/../lms.db';
$pdo = null;

try {
    $pdo = new PDO('sqlite:' . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Create tables if not exist
function init_db($pdo) {
    // Users table
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        u TEXT UNIQUE NOT NULL,
        p TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'staff',
        on_status INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Settings table
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )");

    // Batches table
    $pdo->exec("CREATE TABLE IF NOT EXISTS batches (
        id INTEGER PRIMARY KEY,
        customer TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'Open',
        created_at INTEGER NOT NULL,
        completed_at INTEGER DEFAULT NULL
    )");

    // Items table
    $pdo->exec("CREATE TABLE IF NOT EXISTS items (
        id TEXT PRIMARY KEY,
        batch_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        img TEXT NOT NULL,
        st TEXT NOT NULL DEFAULT 'pending',
        created_at INTEGER NOT NULL,
        FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
    )");

    // Flags table (unresolved garments)
    $pdo->exec("CREATE TABLE IF NOT EXISTS flags (
        id TEXT PRIMARY KEY,
        batch_id INTEGER NOT NULL,
        img TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE CASCADE
    )");

    // Match timing logs table
    $pdo->exec("CREATE TABLE IF NOT EXISTS match_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        batch_id INTEGER NOT NULL,
        match_time REAL NOT NULL,
        is_auto INTEGER NOT NULL,
        created_at INTEGER NOT NULL
    )");

    // Seed default users if empty
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    if ($stmt->fetch()['count'] == 0) {
        $pdo->exec("INSERT INTO users (u, p, role, on_status) VALUES ('staff', 'staff123', 'staff', 1)");
        $pdo->exec("INSERT INTO users (u, p, role, on_status) VALUES ('admin', 'admin123', 'admin', 1)");
    }

    // Seed default settings if empty
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM settings WHERE key = 'thr'");
    if ($stmt->fetch()['count'] == 0) {
        $pdo->exec("INSERT INTO settings (key, value) VALUES ('thr', '80')");
    }

    // Seed sequence if empty
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM settings WHERE key = 'seq'");
    if ($stmt->fetch()['count'] == 0) {
        $pdo->exec("INSERT INTO settings (key, value) VALUES ('seq', '1000')");
    }
}

init_db($pdo);

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


// Role guard: blocks the request unless the logged-in session has one of $roles.
function require_role($roles) {
    if (!isset($_SESSION['role'])) {
        send_json(['error' => 'Not authenticated'], 401);
    }
    if (!in_array($_SESSION['role'], $roles, true)) {
        send_json(['error' => 'You do not have permission to do this.'], 403);
    }
}
