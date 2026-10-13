<?php
// db.php is require_once'd first by literally every api/*.php — this is the
// one place that reliably runs before any endpoint's real logic. A PHP
// warning/notice/deprecation (not just a fatal error) prints straight into
// the response body by default (html_errors — the "<br /><b>Warning</b>..."
// you see in a broken JSON response) and keeps executing, so it lands ahead
// of the intended echo json_encode(...) and breaks res.json() on the
// frontend. try/catch doesn't help — warnings don't throw. Turning display
// off (but keeping logging on) means a stray warning can no longer corrupt
// a response; it just quietly goes to the PHP error log instead, which is
// also better hygiene — verbose PHP errors otherwise leak file paths and
// stack traces to whoever's calling the API.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Belt-and-suspenders on top of the ini_set() above: some hosts force
// display_errors=On at the Apache level (php_admin_flag in the vhost/
// .htaccess), which a runtime ini_set() from inside the script CANNOT
// override — that would explain a warning still leaking into a JSON
// response even with the ini_set() above in place. A custom error handler
// runs at the PHP-userland level instead and takes priority over the
// built-in HTML-formatted handler for anything except fatal/parse errors
// (which nothing here can intercept anyway — but those would stop
// execution outright, not leak a warning while the rest of the response
// still completes successfully, which is the symptom actually seen).
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    error_log("PHP [$errno] $errstr in $errfile:$errline");
    return true; // handled — stop PHP's default handler from printing it
});

define('DB_HOST', 'localhost');
define('DB_NAME', 'kotegg007_novabuild');
define('DB_USER', 'kotegg007_gospodin');
define('DB_PASS', 'only4test');
define('DB_CHARSET', 'utf8mb4');

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
