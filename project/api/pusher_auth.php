<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/pusher.php';

$me = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Pusher's JS client posts this as regular form data, not JSON.
$channelName = $_POST['channel_name'] ?? '';
$socketId    = $_POST['socket_id'] ?? '';

if (!preg_match('/^private-conversation-(\d+)$/', $channelName, $m)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unknown channel']);
    exit;
}
$conversationId = (int) $m[1];

$db = get_db();
$stmt = $db->prepare('SELECT client_user_id, freelancer_user_id FROM conversations WHERE id = ?');
$stmt->execute([$conversationId]);
$conv = $stmt->fetch();

if (!$conv || ((int)$conv['client_user_id'] !== $me['user_id'] && (int)$conv['freelancer_user_id'] !== $me['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Not a participant of this conversation']);
    exit;
}

$auth = pusher_channel_auth($socketId, $channelName);
if (!$auth) {
    http_response_code(503);
    echo json_encode(['error' => 'Realtime not configured']);
    exit;
}

echo json_encode(['auth' => $auth]);
