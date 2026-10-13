<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/pusher.php';

// The Pusher app key + cluster are meant to be public (that's how every
// Pusher-powered frontend works — only the secret stays server-side), but
// this still requires a valid session, same as every other endpoint.
require_auth();

echo json_encode([
    'configured' => pusher_configured(),
    'key'        => pusher_configured() ? PUSHER_KEY : null,
    'cluster'    => pusher_configured() ? PUSHER_CLUSTER : null,
]);
