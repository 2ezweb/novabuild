<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/uploads.php';

$me = require_auth();
$db = get_db();

function offer_owned_by(PDO $db, int $offerId, int $userId): bool {
    $stmt = $db->prepare('
        SELECT o.id FROM offers o
        JOIN client_profiles cp ON cp.id = o.client_id
        WHERE o.id = ? AND cp.user_id = ?
    ');
    $stmt->execute([$offerId, $userId]);
    return (bool) $stmt->fetch();
}

// ─── GET: list attachments for an offer ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $offer_id = (int)($_GET['offer_id'] ?? 0);
    if (!$offer_id) {
        http_response_code(422);
        echo json_encode(['error' => 'offer_id is required']);
        exit;
    }

    $stmt = $db->prepare('
        SELECT id, file_path, original_name, mime_type, size, created_at
        FROM offer_attachments WHERE offer_id = ? ORDER BY id
    ');
    $stmt->execute([$offer_id]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// ─── POST: upload attachment(s) (owning client only) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($me['role'] !== 'client') {
        http_response_code(403);
        echo json_encode(['error' => 'Only clients can attach files to offers']);
        exit;
    }

    $offer_id = (int)($_POST['offer_id'] ?? 0);
    if (!$offer_id || !offer_owned_by($db, $offer_id, $me['user_id'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Offer not found']);
        exit;
    }

    if (empty($_FILES['files'])) {
        http_response_code(422);
        echo json_encode(['error' => 'Файл не загружен']);
        exit;
    }

    $files = store_uploaded_files($_FILES['files'], 'offers');
    if (!$files) {
        http_response_code(422);
        echo json_encode(['error' => 'Не удалось сохранить файлы — проверьте формат (' . UPLOAD_FORMATS_HINT . ')']);
        exit;
    }

    $saved = [];
    $stmt  = $db->prepare('
        INSERT INTO offer_attachments (offer_id, file_path, original_name, mime_type, size)
        VALUES (?, ?, ?, ?, ?)
    ');
    foreach ($files as $f) {
        $stmt->execute([$offer_id, $f['file_path'], $f['original_name'], $f['mime_type'], $f['size']]);
        $saved[] = ['id' => (int) $db->lastInsertId()] + $f;
    }

    http_response_code(201);
    echo json_encode($saved);
    exit;
}

// ─── DELETE: remove one attachment (owning client only) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($body['id'] ?? $_GET['id'] ?? 0);

    if (!$id) {
        http_response_code(422);
        echo json_encode(['error' => 'id is required']);
        exit;
    }

    $stmt = $db->prepare('
        SELECT oa.id, oa.file_path, oa.offer_id
        FROM offer_attachments oa
        JOIN offers o ON o.id = oa.offer_id
        JOIN client_profiles cp ON cp.id = o.client_id
        WHERE oa.id = ? AND cp.user_id = ?
    ');
    $stmt->execute([$id, $me['user_id']]);
    $attachment = $stmt->fetch();

    if (!$attachment) {
        http_response_code(404);
        echo json_encode(['error' => 'Attachment not found']);
        exit;
    }

    $db->prepare('DELETE FROM offer_attachments WHERE id = ?')->execute([$id]);

    $fullPath = __DIR__ . '/../' . $attachment['file_path'];
    if (is_file($fullPath)) @unlink($fullPath);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
