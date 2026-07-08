<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

const MAX_FILE_SIZE = 15 * 1024 * 1024; // 15MB

// extension => list of MIME types finfo may legitimately report for it.
// docx/xlsx are zip containers — finfo very commonly reports them as plain
// application/zip, so that's accepted too and double-checked with ZipArchive below.
const ALLOWED_TYPES = [
    'png'  => ['image/png'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'pdf'  => ['application/pdf'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
];

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

    $files  = $_FILES['files'];
    $count  = is_array($files['name']) ? count($files['name']) : 0;
    $saved  = [];
    $finfo  = finfo_open(FILEINFO_MIME_TYPE);

    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;

        $originalName = $files['name'][$i];
        $tmpPath      = $files['tmp_name'][$i];
        $size         = (int) $files['size'][$i];
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!isset(ALLOWED_TYPES[$ext])) {
            continue; // silently skip unsupported files, reported back as not-saved
        }
        if ($size > MAX_FILE_SIZE) {
            continue;
        }

        $mime = finfo_file($finfo, $tmpPath);
        if (!in_array($mime, ALLOWED_TYPES[$ext], true)) {
            continue;
        }
        // docx/xlsx are OOXML zip packages — confirm it's actually a valid zip,
        // not just an arbitrary file renamed to dodge the extension check.
        if (in_array($ext, ['docx', 'xlsx'], true)) {
            $zip = new ZipArchive();
            if ($zip->open($tmpPath) !== true) continue;
            $zip->close();
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = __DIR__ . '/../uploads/offers/' . $filename;
        if (!move_uploaded_file($tmpPath, $destPath)) continue;

        $relativePath = 'uploads/offers/' . $filename;
        $stmt = $db->prepare('
            INSERT INTO offer_attachments (offer_id, file_path, original_name, mime_type, size)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$offer_id, $relativePath, $originalName, $mime, $size]);

        $saved[] = [
            'id'            => (int) $db->lastInsertId(),
            'file_path'     => $relativePath,
            'original_name' => $originalName,
            'mime_type'     => $mime,
            'size'          => $size,
        ];
    }

    if (!$saved) {
        http_response_code(422);
        echo json_encode(['error' => 'Не удалось сохранить файлы — проверьте формат (png/jpg/pdf/docx/xlsx) и размер (до 15МБ)']);
        exit;
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
