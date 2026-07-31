<?php
// ============================================================
// Stockora POS Pro — MySQL Configuration
// ============================================================

// ── MySQL Database credentials ───────────────────────────────
define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'stockora');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

// ── Application constants ────────────────────────────────────
define('APP_NAME',           'Stockora AI');
define('APP_VERSION',        '1.0.0');
define('CURRENCY',           'PKR');
define('PKR_SYMBOL',         'Rs.');
define('SUBSCRIPTION_PRICE', 10000);   // Monthly base price in PKR
define('BASE_URL',           'http://localhost/stockora');
define('UPLOAD_PATH',        __DIR__ . '/../assets/uploads/');
define('UPLOAD_URL',         BASE_URL . '/assets/uploads/');
define('SESSION_TIMEOUT',    3600);

// ── Shortcut variable (for use in PHP templates) ─────────────
$Baseurl = BASE_URL;

// ── PHP error settings (set to 0 / off in production) ────────
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors',     1);

// ── Timezone ─────────────────────────────────────────────────
date_default_timezone_set('Asia/Karachi');

// ============================================================
// MySQL PDO Connection  (singleton)
// ============================================================
function getDB(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die('<h2 style="font-family:sans-serif;color:#c0392b">Database connection failed.</h2>'
                . '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>'
                . '<p style="font-family:sans-serif">Check DB_HOST / DB_USER / DB_PASS in includes/config.php</p>');
        }
    }
    return $pdo;
}
