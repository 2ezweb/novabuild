<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

$me = require_auth();
$db = get_db();

// Fetch user + profile in one query depending on role
if ($me['role'] === 'freelancer') {
    $stmt = $db->prepare('
        SELECT u.id, u.email, u.role, u.status, u.created_at,
               fp.full_name, fp.phone, fp.specialization, fp.about,
               fp.verification_status
        FROM users u
        LEFT JOIN freelancer_profiles fp ON fp.user_id = u.id
        WHERE u.id = ?
    ');
} else {
    $stmt = $db->prepare('
        SELECT u.id, u.email, u.role, u.status, u.created_at,
               cp.company_name, cp.contact_name, cp.phone
        FROM users u
        LEFT JOIN client_profiles cp ON cp.user_id = u.id
        WHERE u.id = ?
    ');
}

$stmt->execute([$me['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

echo json_encode($user);
