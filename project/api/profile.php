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

// ─── SHARED: name (users table, every role) ──────────────────────────────────
$first_name = trim($body['first_name'] ?? '');
$last_name  = trim($body['last_name'] ?? '');

if (!$first_name) {
    http_response_code(422);
    echo json_encode(['error' => 'Укажите имя']);
    exit;
}

$db->prepare('UPDATE users SET first_name = ?, last_name = ? WHERE id = ?')
   ->execute([$first_name, $last_name ?: null, $me['user_id']]);

// ─── ADMIN: also allowed to change their login (users.email) ────────────────
if ($me['role'] === 'admin') {
    $login = trim($body['login'] ?? '');
    if ($login) {
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $stmt->execute([$login, $me['user_id']]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'Логин уже занят']);
            exit;
        }
        $db->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$login, $me['user_id']]);
    }

    echo json_encode(['first_name' => $first_name, 'last_name' => $last_name]);
    exit;
}

// ─── FREELANCER ───────────────────────────────────────────────────────────────
if ($me['role'] === 'freelancer') {
    $phone          = trim($body['phone'] ?? '');
    $website        = trim($body['website'] ?? '');
    $specialization = trim($body['specialization'] ?? '');
    $about          = trim($body['about'] ?? '');

    if (mb_strlen($about) > 3000) {
        http_response_code(422);
        echo json_encode(['error' => 'Описание не должно превышать 3000 символов']);
        exit;
    }

    $stmt = $db->prepare('
        UPDATE freelancer_profiles
        SET phone = ?, website = ?, specialization = ?, about = ?
        WHERE user_id = ?
    ');
    $stmt->execute([$phone, $website, $specialization, $about, $me['user_id']]);

    echo json_encode([
        'first_name'     => $first_name,
        'last_name'      => $last_name,
        'phone'          => $phone,
        'website'        => $website,
        'specialization' => $specialization,
        'about'          => $about,
    ]);
    exit;
}

// ─── CLIENT ───────────────────────────────────────────────────────────────────
$company_name = trim($body['company_name'] ?? '');
$phone        = trim($body['phone'] ?? '');

$stmt = $db->prepare('UPDATE client_profiles SET company_name = ?, phone = ? WHERE user_id = ?');
$stmt->execute([$company_name ?: null, $phone, $me['user_id']]);

echo json_encode([
    'first_name'   => $first_name,
    'last_name'    => $last_name,
    'company_name' => $company_name,
    'phone'        => $phone,
]);
