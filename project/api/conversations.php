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

// ─── GET: my conversations (for the "открыть чат" entry points) ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // last_message_at/last_message/unread_count are computed per-conversation
    // for the "Чат" inbox page — cheap enough at this scale (one row per
    // conversation, correlated subqueries hit the messages(conversation_id)
    // index), not worth a JOIN+GROUP BY for what's a handful of rows per user.
    if ($me['role'] === 'client') {
        $stmt = $db->prepare('
            SELECT c.id, u.id AS other_user_id, u.first_name, u.last_name, u.avatar_path,
                   (SELECT body FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                   (SELECT created_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message_at,
                   (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.sender_id != ? AND m.read_at IS NULL) AS unread_count
            FROM conversations c
            JOIN users u ON u.id = c.freelancer_user_id
            WHERE c.client_user_id = ?
            ORDER BY last_message_at DESC
        ');
    } elseif ($me['role'] === 'freelancer') {
        $stmt = $db->prepare('
            SELECT c.id, u.id AS other_user_id, u.first_name, u.last_name, u.avatar_path,
                   (SELECT body FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                   (SELECT created_at FROM messages m WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_message_at,
                   (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.sender_id != ? AND m.read_at IS NULL) AS unread_count
            FROM conversations c
            JOIN users u ON u.id = c.client_user_id
            WHERE c.freelancer_user_id = ?
            ORDER BY last_message_at DESC
        ');
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'Chat is only for clients and freelancers']);
        exit;
    }
    $stmt->execute([$me['user_id'], $me['user_id']]);
    $conversations = $stmt->fetchAll();

    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        $one = null;
        foreach ($conversations as $c) {
            if ((int)$c['id'] === $id) { $one = $c; break; }
        }
        if (!$one) {
            http_response_code(404);
            echo json_encode(['error' => 'Conversation not found']);
            exit;
        }
        echo json_encode($one);
        exit;
    }

    echo json_encode($conversations);
    exit;
}

// ─── POST: start (find-or-create) a conversation ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($me['role'] !== 'client') {
        http_response_code(403);
        echo json_encode(['error' => 'Только клиент может начать переписку']);
        exit;
    }

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
    if ($action !== 'start') {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
    }

    $freelancerId = (int) ($body['other_user_id'] ?? 0);
    $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'freelancer'");
    $stmt->execute([$freelancerId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Фрилансер не найден']);
        exit;
    }

    $stmt = $db->prepare('SELECT id FROM conversations WHERE client_user_id = ? AND freelancer_user_id = ?');
    $stmt->execute([$me['user_id'], $freelancerId]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        echo json_encode(['id' => (int) $existing]);
        exit;
    }

    $stmt = $db->prepare('INSERT INTO conversations (client_user_id, freelancer_user_id) VALUES (?, ?)');
    $stmt->execute([$me['user_id'], $freelancerId]);

    http_response_code(201);
    echo json_encode(['id' => (int) $db->lastInsertId()]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
