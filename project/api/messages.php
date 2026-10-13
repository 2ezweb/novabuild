<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/pusher.php';

$me = require_auth();
$db = get_db();

function conversation_or_403(PDO $db, int $conversationId, int $userId): array {
    $stmt = $db->prepare('SELECT * FROM conversations WHERE id = ?');
    $stmt->execute([$conversationId]);
    $conv = $stmt->fetch();

    if (!$conv || ((int)$conv['client_user_id'] !== $userId && (int)$conv['freelancer_user_id'] !== $userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Not a participant of this conversation']);
        exit;
    }
    return $conv;
}

// ─── GET: message history (supports ?after_id= for polling fallback) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $conversationId = (int) ($_GET['conversation_id'] ?? 0);
    if (!$conversationId) {
        http_response_code(422);
        echo json_encode(['error' => 'conversation_id is required']);
        exit;
    }

    conversation_or_403($db, $conversationId, $me['user_id']);

    $afterId = (int) ($_GET['after_id'] ?? 0);
    if ($afterId) {
        $stmt = $db->prepare('
            SELECT id, sender_id, body, created_at FROM messages
            WHERE conversation_id = ? AND id > ? ORDER BY id ASC
        ');
        $stmt->execute([$conversationId, $afterId]);
    } else {
        $stmt = $db->prepare('
            SELECT id, sender_id, body, created_at FROM messages
            WHERE conversation_id = ? ORDER BY id ASC
        ');
        $stmt->execute([$conversationId]);
    }

    $db->prepare('UPDATE messages SET read_at = NOW() WHERE conversation_id = ? AND sender_id != ? AND read_at IS NULL')
       ->execute([$conversationId, $me['user_id']]);

    echo json_encode($stmt->fetchAll());
    exit;
}

// ─── POST: send a message ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $conversationId = (int) ($body['conversation_id'] ?? 0);
    $text = trim($body['body'] ?? '');

    if (!$conversationId) {
        http_response_code(422);
        echo json_encode(['error' => 'conversation_id is required']);
        exit;
    }
    if ($text === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Сообщение не может быть пустым']);
        exit;
    }
    if (mb_strlen($text) > 5000) {
        http_response_code(422);
        echo json_encode(['error' => 'Сообщение слишком длинное']);
        exit;
    }

    $conv = conversation_or_403($db, $conversationId, $me['user_id']);
    $otherUserId = (int)$conv['client_user_id'] === $me['user_id'] ? (int)$conv['freelancer_user_id'] : (int)$conv['client_user_id'];

    $stmt = $db->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?, ?, ?)');
    $stmt->execute([$conversationId, $me['user_id'], $text]);
    $messageId = (int) $db->lastInsertId();

    $stmt = $db->prepare('SELECT created_at FROM messages WHERE id = ?');
    $stmt->execute([$messageId]);
    $createdAt = $stmt->fetchColumn();

    pusher_trigger("private-conversation-{$conversationId}", 'new-message', [
        'id'         => $messageId,
        'sender_id'  => $me['user_id'],
        'body'       => $text,
        'created_at' => $createdAt,
    ]);

    create_notification($db, $otherUserId, 'new_message', 'Новое сообщение в чате');

    http_response_code(201);
    echo json_encode(['id' => $messageId, 'created_at' => $createdAt]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
