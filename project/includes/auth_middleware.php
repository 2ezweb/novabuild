<?php
require_once __DIR__ . '/jwt.php';

// Apache (common under OSPanel/OpenServer) often does not forward the
// Authorization header into $_SERVER['HTTP_AUTHORIZATION'] by default.
// Fall back to the other places it can show up depending on SAPI/config.
function get_authorization_header(): string {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) return $value;
        }
    }
    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) return $value;
        }
    }
    return '';
}

function require_auth(): array {
    $header = get_authorization_header();
    if (!str_starts_with($header, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $token = substr($header, 7);
    $payload = jwt_decode($token);
    if (!$payload) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired token']);
        exit;
    }
    return $payload;
}

function require_admin(): array {
    $me = require_auth();
    if ($me['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admins only']);
        exit;
    }
    return $me;
}
