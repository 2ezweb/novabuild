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

// «Вызов администратора» — either side of an ACTIVE contract or stage can
// flag a conflict for admin review. The admin then sees the contract terms,
// the full chat history, and the estimate from admin.php (action=
// escalation_detail) and resolves it there: force-change terms, force-close
// either way, or freeze pending investigation (contracts.frozen_at — a stub,
// no real support-ticket/investigation system behind it yet).

$me = require_auth();
$db = get_db();

function contract_participant_or_404(PDO $db, int $contractId, int $userId): array {
    $stmt = $db->prepare('SELECT * FROM contracts WHERE id = ?');
    $stmt->execute([$contractId]);
    $c = $stmt->fetch();
    if (!$c || ((int)$c['client_user_id'] !== $userId && (int)$c['freelancer_user_id'] !== $userId)) {
        http_response_code(404);
        echo json_encode(['error' => 'Контракт не знайдено']);
        exit;
    }
    return $c;
}

// ─── GET: the latest escalation for a contract/stage (any status) ───────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $contractId = (int) ($_GET['contract_id'] ?? 0);
    contract_participant_or_404($db, $contractId, $me['user_id']);

    $stmt = $db->prepare('SELECT * FROM admin_escalations WHERE contract_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$contractId]);
    echo json_encode($stmt->fetch() ?: null);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не підтримується']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

if ($action !== 'raise') {
    http_response_code(400);
    echo json_encode(['error' => 'Невідома дія']);
    exit;
}

$contractId = (int) ($body['contract_id'] ?? 0);
$contract   = contract_participant_or_404($db, $contractId, $me['user_id']);

if ($contract['status'] !== 'active') {
    http_response_code(409);
    echo json_encode(['error' => 'Звернутися до адміністрації можна лише щодо активного контракту або етапу']);
    exit;
}
if ($contract['type'] === 'staged' && $contract['parent_contract_id'] === null) {
    http_response_code(409);
    echo json_encode(['error' => 'Звернення оформлюється щодо конкретного етапу, а не всього поетапного контракту']);
    exit;
}

$stmt = $db->prepare("SELECT id FROM admin_escalations WHERE contract_id = ? AND status = 'open'");
$stmt->execute([$contractId]);
if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode(['error' => 'Звернення щодо цього контракту вже надіслано, дочекайтеся рішення адміністрації']);
    exit;
}

$reason = trim($body['reason'] ?? '');
if ($reason === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Опишіть суть конфлікту']);
    exit;
}

$stmt = $db->prepare('INSERT INTO admin_escalations (contract_id, raised_by_user_id, reason) VALUES (?, ?, ?)');
$stmt->execute([$contractId, $me['user_id'], $reason]);

$noun = $contract['parent_contract_id'] ? 'етапу' : 'контракту';
notify_admins($db, 'escalation_raised', 'Звернення щодо ' . $noun . ' #' . contract_number($contractId));

http_response_code(201);
echo json_encode(['id' => (int) $db->lastInsertId()]);
