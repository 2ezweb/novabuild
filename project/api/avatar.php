<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/image.php';

$me = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(['error' => 'Файл не загружен']);
    exit;
}

$tmpPath = $_FILES['avatar']['tmp_name'];

if ($_FILES['avatar']['size'] > 5 * 1024 * 1024) {
    http_response_code(422);
    echo json_encode(['error' => 'Файл больше 5МБ']);
    exit;
}

// Trust the actual file bytes, not the client-supplied filename/Content-Type.
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $tmpPath);
finfo_close($finfo);

if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Разрешены только JPEG и PNG']);
    exit;
}

$db = get_db();

$filename = bin2hex(random_bytes(16)) . '.jpg';
$destDir  = __DIR__ . '/../uploads/avatars/';
$destPath = $destDir . $filename;

if (!resize_square_image($tmpPath, $destPath, 200)) {
    http_response_code(422);
    echo json_encode(['error' => 'Не удалось обработать изображение']);
    exit;
}

$stmt = $db->prepare('SELECT avatar_path FROM users WHERE id = ?');
$stmt->execute([$me['user_id']]);
$oldPath = $stmt->fetchColumn();

$newPath = 'uploads/avatars/' . $filename;
$db->prepare('UPDATE users SET avatar_path = ? WHERE id = ?')->execute([$newPath, $me['user_id']]);

if ($oldPath) {
    $oldFile = __DIR__ . '/../' . $oldPath;
    if (is_file($oldFile)) @unlink($oldFile);
}

echo json_encode(['avatar_path' => $newPath]);
