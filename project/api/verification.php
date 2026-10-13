<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/notifications.php';

$me = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не підтримується']);
    exit;
}

if ($me['role'] === 'admin') {
    http_response_code(400);
    echo json_encode(['error' => 'Адміністраторам верифікація не потрібна']);
    exit;
}

$db = get_db();

$stmt = $db->prepare('SELECT verification_status, verification_doc_path, email, first_name FROM users WHERE id = ?');
$stmt->execute([$me['user_id']]);
$user = $stmt->fetch();

if ($user['verification_status'] === 'pending') {
    http_response_code(409);
    echo json_encode(['error' => 'Заявка вже на розгляді']);
    exit;
}
if ($user['verification_status'] === 'verified') {
    http_response_code(409);
    echo json_encode(['error' => 'Профіль уже верифіковано']);
    exit;
}

if (empty($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(['error' => 'Файл не завантажено']);
    exit;
}

if ($_FILES['document']['size'] > 10 * 1024 * 1024) {
    http_response_code(422);
    echo json_encode(['error' => 'Файл більший за 10 МБ']);
    exit;
}

$tmpPath = $_FILES['document']['tmp_name'];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $tmpPath);
finfo_close($finfo);

$allowedExt = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
if (!isset($allowedExt[$mime])) {
    http_response_code(422);
    echo json_encode(['error' => 'Дозволені лише PDF, JPEG і PNG']);
    exit;
}

$filename = bin2hex(random_bytes(16)) . '.' . $allowedExt[$mime];
$destDir  = __DIR__ . '/../uploads/verification/';
$destPath = $destDir . $filename;

if (!move_uploaded_file($tmpPath, $destPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Не вдалося зберегти файл']);
    exit;
}

$oldPath = $user['verification_doc_path'];
$newPath = 'uploads/verification/' . $filename;

$db->prepare('
    UPDATE users SET verification_status = "pending", verification_doc_path = ?, verified_at = NULL
    WHERE id = ?
')->execute([$newPath, $me['user_id']]);

if ($oldPath) {
    $oldFile = __DIR__ . '/../' . $oldPath;
    if (is_file($oldFile)) @unlink($oldFile);
}

$name = trim($user['first_name'] ?? $user['email']);
notify_admins($db, 'verification_submitted', "Нова заявка на верифікацію від {$name} ({$user['email']})");

echo json_encode(['status' => 'pending']);
