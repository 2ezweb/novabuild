<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/brigades.php';

$me = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не підтримується']);
    exit;
}

if ($me['role'] !== 'client') {
    http_response_code(403);
    echo json_encode(['error' => 'Профілі фрилансерів доступні лише клієнтам']);
    exit;
}

$userId = (int) ($_GET['user_id'] ?? 0);
if (!$userId) {
    http_response_code(422);
    echo json_encode(['error' => 'Не вказано користувача (user_id)']);
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
    echo json_encode(['error' => 'Фрилансера не знайдено']);
    exit;
}

// Peer-facing view — same masking rule as bids.php: last name is never
// shown in full to another user, only the initial.
$freelancer['last_name_initial'] = $freelancer['last_name'] ? mb_substr($freelancer['last_name'], 0, 1) . '.' : null;
unset($freelancer['last_name']);

// Rating + work stats (reviews.php) and the latest reviews. Reviewer is shown
// by first name only — same peer-masking idea as above.
$stmt = $db->prepare('SELECT ROUND(AVG(rating), 1) AS avg, COUNT(*) AS cnt FROM reviews WHERE freelancer_user_id = ?');
$stmt->execute([$userId]);
$agg = $stmt->fetch();
$freelancer['rating_avg']   = $agg['cnt'] ? (float) $agg['avg'] : null;
$freelancer['rating_count'] = (int) $agg['cnt'];

$stmt = $db->prepare("SELECT COUNT(*) FROM contracts WHERE freelancer_user_id = ? AND parent_contract_id IS NULL AND status = 'completed'");
$stmt->execute([$userId]);
$freelancer['completed_contracts'] = (int) $stmt->fetchColumn();

$stmt = $db->prepare('
    SELECT r.rating, r.comment, r.created_at, u.first_name AS client_first_name
    FROM reviews r
    JOIN users u ON u.id = r.client_user_id
    WHERE r.freelancer_user_id = ?
    ORDER BY r.id DESC
    LIMIT 20
');
$stmt->execute([$userId]);
$freelancer['reviews'] = $stmt->fetchAll();

// Бригада: the one he leads (a client can propose contracts to it) or belongs to.
$own = brigade_by_owner($db, $userId);
if ($own) {
    $freelancer['brigade'] = brigade_public_summary($db, $own) + ['is_owner' => true];
} elseif ($m = brigade_membership($db, $userId)) {
    $freelancer['brigade'] = ['name' => $m['name'], 'is_owner' => false, 'role' => $m['my_role']];
} else {
    $freelancer['brigade'] = null;
}

echo json_encode($freelancer);
