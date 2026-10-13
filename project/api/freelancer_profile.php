<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

$me = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if ($me['role'] !== 'client') {
    http_response_code(403);
    echo json_encode(['error' => 'Профили фрилансеров доступны только клиентам']);
    exit;
}

$userId = (int) ($_GET['user_id'] ?? 0);
if (!$userId) {
    http_response_code(422);
    echo json_encode(['error' => 'user_id is required']);
    exit;
}

$db = get_db();
$stmt = $db->prepare("
    SELECT u.id, u.first_name, u.last_name, u.avatar_path, u.verification_status,
           fp.specialization, fp.about
    FROM users u
    JOIN freelancer_profiles fp ON fp.user_id = u.id
    WHERE u.id = ? AND u.role = 'freelancer'
");
$stmt->execute([$userId]);
$freelancer = $stmt->fetch();

if (!$freelancer) {
    http_response_code(404);
    echo json_encode(['error' => 'Фрилансер не найден']);
    exit;
}

// Peer-facing view — same masking rule as bids.php: last name is never
// shown in full to another user, only the initial.
$freelancer['last_name_initial'] = $freelancer['last_name'] ? mb_substr($freelancer['last_name'], 0, 1) . '.' : null;
unset($freelancer['last_name']);

echo json_encode($freelancer);
