<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/notifications.php';

$me = require_admin();
$db = get_db();

// ─── GET: users list / verification requests / document stream ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'users') {
        $stmt = $db->query('
            SELECT id, email, role, status, first_name, last_name, verification_status, created_at
            FROM users
            ORDER BY created_at DESC
        ');
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($action === 'verification_requests') {
        $stmt = $db->query("
            SELECT id, email, role, first_name, last_name, verification_doc_path
            FROM users
            WHERE verification_status = 'pending'
            ORDER BY id DESC
        ");
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($action === 'document') {
        $userId = (int)($_GET['user_id'] ?? 0);
        $stmt = $db->prepare('SELECT verification_doc_path FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $path = $stmt->fetchColumn();

        if (!$path) {
            http_response_code(404);
            echo json_encode(['error' => 'Document not found']);
            exit;
        }

        $fullPath = __DIR__ . '/../' . $path;
        if (!is_file($fullPath)) {
            http_response_code(404);
            echo json_encode(['error' => 'Document not found']);
            exit;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $fullPath);
        finfo_close($finfo);

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
        readfile($fullPath);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ─── POST: approve / reject verification ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
    $userId = (int)($body['user_id'] ?? 0);

    if (!$userId) {
        http_response_code(422);
        echo json_encode(['error' => 'user_id is required']);
        exit;
    }

    $stmt = $db->prepare('SELECT verification_status FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }
    if ($status !== 'pending') {
        http_response_code(409);
        echo json_encode(['error' => 'У пользователя нет заявки на рассмотрении']);
        exit;
    }

    if ($action === 'approve') {
        $db->prepare("UPDATE users SET verification_status = 'verified', verified_at = NOW() WHERE id = ?")
           ->execute([$userId]);
        create_notification($db, $userId, 'verification_approved', 'Ваш профиль верифицирован!');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'reject') {
        $db->prepare("UPDATE users SET verification_status = 'rejected', verified_at = NULL WHERE id = ?")
           ->execute([$userId]);
        create_notification($db, $userId, 'verification_rejected', 'Ваша заявка на верификацию была отклонена.');
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
