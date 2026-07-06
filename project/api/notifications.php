<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

$me = require_auth();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare('
        SELECT id, type, message, is_read, created_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ');
    $stmt->execute([$me['user_id']]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'mark_read') {
        $id = (int)($body['id'] ?? 0);
        $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?')
           ->execute([$id, $me['user_id']]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')
           ->execute([$me['user_id']]);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
