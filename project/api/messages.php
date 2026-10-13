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
require_once __DIR__ . '/../includes/uploads.php';

$me = require_auth();
$db = get_db();

// Adds attachments[] (message_attachments) to every message row — one query
// for the whole batch. A files-only message has body = ''.
function attach_message_files(PDO $db, array $messages): array {
    if (!$messages) return $messages;
    $ids = array_map(fn($m) => (int)$m['id'], $messages);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, message_id, file_path, original_name, mime_type, size FROM message_attachments WHERE message_id IN ($in) ORDER BY id");
    $stmt->execute($ids);
    $byMessage = [];
    foreach ($stmt->fetchAll() as $a) {
        $byMessage[(int)$a['message_id']][] = $a;
    }
    foreach ($messages as &$m) {
        $m['attachments'] = $byMessage[(int)$m['id']] ?? [];
    }
    unset($m);
    return $messages;
}

function conversation_or_403(PDO $db, int $conversationId, int $userId): array {
    $stmt = $db->prepare('SELECT * FROM conversations WHERE id = ?');
    $stmt->execute([$conversationId]);
    $conv = $stmt->fetch();

    if (!$conv || ((int)$conv['client_user_id'] !== $userId && (int)$conv['freelancer_user_id'] !== $userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Ви не є учасником цієї переписки']);
        exit;
    }
    return $conv;
}

// ─── GET: message history (supports ?after_id= for polling fallback) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $conversationId = (int) ($_GET['conversation_id'] ?? 0);
    if (!$conversationId) {
        http_response_code(422);
        echo json_encode(['error' => 'Не вказано переписку (conversation_id)']);
        exit;
    }

    conversation_or_403($db, $conversationId, $me['user_id']);

    $afterId = (int) ($_GET['after_id'] ?? 0);
    $stmt = $db->prepare('
        SELECT id, sender_id, body, created_at FROM messages
        WHERE conversation_id = ? AND id > ? ORDER BY id ASC
    ');
    $stmt->execute([$conversationId, $afterId]);
    $messages = $stmt->fetchAll();

    $db->prepare('UPDATE messages SET read_at = NOW() WHERE conversation_id = ? AND sender_id != ? AND read_at IS NULL')
       ->execute([$conversationId, $me['user_id']]);

    echo json_encode(attach_message_files($db, $messages));
    exit;
}

// ─── POST: send a message ─────────────────────────────────────────────────────
// JSON {conversation_id, body} for text, or multipart (conversation_id, body,
// files[]) when files are attached — then body may be empty.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isMultipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
    $body = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?? []);
    $conversationId = (int) ($body['conversation_id'] ?? 0);
    $text = trim((string)($body['body'] ?? ''));
    $hasFiles = $isMultipart && !empty($_FILES['files']['name'][0]);

    if (!$conversationId) {
        http_response_code(422);
        echo json_encode(['error' => 'Не вказано переписку (conversation_id)']);
        exit;
    }
    if ($text === '' && !$hasFiles) {
        http_response_code(422);
        echo json_encode(['error' => 'Повідомлення не може бути порожнім']);
        exit;
    }
    if (mb_strlen($text) > 5000) {
        http_response_code(422);
        echo json_encode(['error' => 'Повідомлення задовге']);
        exit;
    }

    $conv = conversation_or_403($db, $conversationId, $me['user_id']);
    $otherUserId = (int)$conv['client_user_id'] === $me['user_id'] ? (int)$conv['freelancer_user_id'] : (int)$conv['client_user_id'];

    $saved = $hasFiles ? store_uploaded_files($_FILES['files'], 'chat') : [];
    if ($hasFiles && !$saved && $text === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Файли не пройшли перевірку: ' . UPLOAD_FORMATS_HINT]);
        exit;
    }

    try {
        $db->beginTransaction();
        $db->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?, ?, ?)')
           ->execute([$conversationId, $me['user_id'], $text]);
        $messageId = (int) $db->lastInsertId();
        $ins = $db->prepare('INSERT INTO message_attachments (message_id, file_path, original_name, mime_type, size) VALUES (?, ?, ?, ?, ?)');
        foreach ($saved as $f) {
            $ins->execute([$messageId, $f['file_path'], $f['original_name'], $f['mime_type'], $f['size']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        delete_stored_files($saved);
        throw $e;
    }

    $stmt = $db->prepare('SELECT id, sender_id, body, created_at FROM messages WHERE id = ?');
    $stmt->execute([$messageId]);
    $message = attach_message_files($db, [$stmt->fetch()])[0];

    pusher_trigger("private-conversation-{$conversationId}", 'new-message', $message);

    create_notification($db, $otherUserId, 'new_message', $saved && $text === '' ? 'Вам надіслали файл у чаті' : 'Нове повідомлення в чаті');

    http_response_code(201);
    echo json_encode($message + ['files_requested' => $hasFiles ? count($_FILES['files']['name']) : 0]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Метод не підтримується']);
