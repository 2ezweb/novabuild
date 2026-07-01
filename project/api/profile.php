<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

$me = require_auth();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ─── FREELANCER ───────────────────────────────────────────────────────────────
if ($me['role'] === 'freelancer') {
    $full_name      = trim($body['full_name'] ?? '');
    $phone          = trim($body['phone'] ?? '');
    $specialization = trim($body['specialization'] ?? '');
    $about          = trim($body['about'] ?? '');

    if (!$full_name) {
        http_response_code(422);
        echo json_encode(['error' => 'Укажите имя']);
        exit;
    }

    $stmt = $db->prepare('
        UPDATE freelancer_profiles
        SET full_name = ?, phone = ?, specialization = ?, about = ?
        WHERE user_id = ?
    ');
    $stmt->execute([$full_name, $phone, $specialization, $about, $me['user_id']]);

    echo json_encode([
        'full_name'      => $full_name,
        'phone'          => $phone,
        'specialization' => $specialization,
        'about'          => $about,
    ]);
    exit;
}

// ─── CLIENT ───────────────────────────────────────────────────────────────────
$company_name = trim($body['company_name'] ?? '');
$contact_name = trim($body['contact_name'] ?? '');
$phone        = trim($body['phone'] ?? '');

if (!$contact_name) {
    http_response_code(422);
    echo json_encode(['error' => 'Укажите контактное имя']);
    exit;
}

$stmt = $db->prepare('
    UPDATE client_profiles
    SET company_name = ?, contact_name = ?, phone = ?
    WHERE user_id = ?
');
$stmt->execute([$company_name, $contact_name, $phone, $me['user_id']]);

echo json_encode([
    'company_name' => $company_name,
    'contact_name' => $contact_name,
    'phone'        => $phone,
]);
