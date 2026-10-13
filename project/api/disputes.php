<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/contract_number.php';

$me = require_auth();
$db = get_db();

// ─── GET: the hold for a contract, if the current user is a participant ─────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $contractId = (int) ($_GET['contract_id'] ?? 0);

    $stmt = $db->prepare('SELECT client_user_id, freelancer_user_id FROM contracts WHERE id = ?');
    $stmt->execute([$contractId]);
    $contract = $stmt->fetch();
    if (!$contract || ((int)$contract['client_user_id'] !== $me['user_id'] && (int)$contract['freelancer_user_id'] !== $me['user_id'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Contract not found']);
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM balance_holds WHERE contract_id = ? AND resolution = 'pending' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$contractId]);
    echo json_encode($stmt->fetch() ?: null);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if ($me['role'] !== 'freelancer') {
    http_response_code(403);
    echo json_encode(['error' => 'Оспорить закрытие может только фрилансер']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

if ($action !== 'raise') {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

$contractId = (int) ($body['contract_id'] ?? 0);

$stmt = $db->prepare('SELECT freelancer_user_id FROM contracts WHERE id = ?');
$stmt->execute([$contractId]);
$contract = $stmt->fetch();
if (!$contract || (int)$contract['freelancer_user_id'] !== $me['user_id']) {
    http_response_code(404);
    echo json_encode(['error' => 'Contract not found']);
    exit;
}

$stmt = $db->prepare("
    SELECT * FROM balance_holds
    WHERE contract_id = ? AND resolution = 'pending' AND release_at > NOW() AND disputed_at IS NULL
    ORDER BY id DESC LIMIT 1
");
$stmt->execute([$contractId]);
$hold = $stmt->fetch();

if (!$hold) {
    http_response_code(409);
    echo json_encode(['error' => 'Нет активной заморозки по этому контракту, которую можно оспорить (либо срок истёк, либо спор уже подан)']);
    exit;
}

$db->prepare('UPDATE balance_holds SET disputed_at = NOW() WHERE id = ?')->execute([$hold['id']]);

notify_admins($db, 'contract_disputed', 'Фрилансер оспорил закрытие контракта #' . contract_number($contractId));

echo json_encode(['success' => true]);
