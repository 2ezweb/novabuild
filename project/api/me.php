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

// Fetch user + profile in one query depending on role.
// first_name/last_name/avatar_path/verification_status live on users — shared across all roles.
if ($me['role'] === 'freelancer') {
    $stmt = $db->prepare('
        SELECT u.id, u.email, u.role, u.status, u.created_at,
               u.first_name, u.last_name, u.avatar_path, u.verification_status,
               fp.phone, fp.website, fp.specialization, fp.about, fp.connects_balance, fp.balance
        FROM users u
        LEFT JOIN freelancer_profiles fp ON fp.user_id = u.id
        WHERE u.id = ?
    ');
} elseif ($me['role'] === 'client') {
    // frozen_amount = holds from failure-closed contracts still within the
    // 7-day window, or under an unresolved dispute (which blocks release
    // even past 7 days) — see contracts.php::close() and disputes.php.
    $stmt = $db->prepare("
        SELECT u.id, u.email, u.role, u.status, u.created_at,
               u.first_name, u.last_name, u.avatar_path, u.verification_status,
               cp.company_name, cp.phone, cp.balance,
               (SELECT COALESCE(SUM(bh.amount), 0) FROM balance_holds bh
                WHERE bh.user_id = u.id AND bh.resolution = 'pending'
                  AND (bh.release_at > NOW() OR bh.disputed_at IS NOT NULL)) AS frozen_amount
        FROM users u
        LEFT JOIN client_profiles cp ON cp.user_id = u.id
        WHERE u.id = ?
    ");
} else {
    $stmt = $db->prepare('
        SELECT id, email, role, status, created_at,
               first_name, last_name, avatar_path, verification_status
        FROM users
        WHERE id = ?
    ');
}

$stmt->execute([$me['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 'User not found']);
    exit;
}

// What escrow.php will actually take from the balance before charging the card.
if ($me['role'] === 'client') {
    $user['available_balance'] = round(max(0, (float)$user['balance'] - (float)$user['frozen_amount']), 2);
}

echo json_encode($user);
