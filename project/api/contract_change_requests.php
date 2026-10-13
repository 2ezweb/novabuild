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
require_once __DIR__ . '/../includes/dates.php';

$me = require_auth();
$db = get_db();

function contract_participant_or_404(PDO $db, int $contractId, int $userId): array {
    $stmt = $db->prepare('SELECT * FROM contracts WHERE id = ?');
    $stmt->execute([$contractId]);
    $c = $stmt->fetch();
    if (!$c || ((int)$c['client_user_id'] !== $userId && (int)$c['freelancer_user_id'] !== $userId)) {
        http_response_code(404);
        echo json_encode(['error' => 'Contract not found']);
        exit;
    }
    return $c;
}

// ─── GET: pending change request for a contract (if any) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $contractId = (int) ($_GET['contract_id'] ?? 0);
    contract_participant_or_404($db, $contractId, $me['user_id']);

    $stmt = $db->prepare("
        SELECT * FROM contract_change_requests
        WHERE contract_id = ? AND status = 'pending' ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$contractId]);
    $req = $stmt->fetch();
    echo json_encode($req ?: null);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ─── propose: either side suggests a new price/deadline ──────────────────────
if ($action === 'propose') {
    $contractId = (int) ($body['contract_id'] ?? 0);
    $contract   = contract_participant_or_404($db, $contractId, $me['user_id']);

    if ($contract['status'] !== 'active') {
        http_response_code(409);
        echo json_encode(['error' => 'Изменения можно предлагать только для активного контракта']);
        exit;
    }
    if ($contract['type'] === 'staged' && $contract['parent_contract_id'] === null) {
        http_response_code(409);
        echo json_encode(['error' => 'Условия поэтапного контракта меняются по этапам, а не целиком']);
        exit;
    }

    $newPrice    = isset($body['new_price']) && is_numeric($body['new_price']) ? (float) $body['new_price'] : null;
    $newDeadline = validate_deadline($body['new_deadline'] ?? null, 'Новый срок');

    if ($newPrice === null && $newDeadline === null) {
        http_response_code(422);
        echo json_encode(['error' => 'Укажите новую цену и/или срок']);
        exit;
    }

    $stmt = $db->prepare("SELECT id FROM contract_change_requests WHERE contract_id = ? AND status = 'pending'");
    $stmt->execute([$contractId]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'По этому контракту уже есть предложение на рассмотрении']);
        exit;
    }

    $stmt = $db->prepare('
        INSERT INTO contract_change_requests (contract_id, proposed_by, new_price, new_deadline)
        VALUES (?, ?, ?, ?)
    ');
    $stmt->execute([$contractId, $me['user_id'], $newPrice, $newDeadline]);

    $otherUserId = $me['user_id'] === (int)$contract['client_user_id'] ? (int)$contract['freelancer_user_id'] : (int)$contract['client_user_id'];
    create_notification($db, $otherUserId, 'contract_change_proposed', 'Предложено изменение условий контракта #' . contract_number((int)$contract['id']));

    http_response_code(201);
    echo json_encode(['id' => (int) $db->lastInsertId()]);
    exit;
}

// ─── respond: the OTHER side accepts or rejects ──────────────────────────────
if ($action === 'respond') {
    $requestId = (int) ($body['request_id'] ?? 0);
    $accept    = !empty($body['accept']);

    $stmt = $db->prepare('SELECT * FROM contract_change_requests WHERE id = ?');
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();
    if (!$req) {
        http_response_code(404);
        echo json_encode(['error' => 'Request not found']);
        exit;
    }

    $contract = contract_participant_or_404($db, (int)$req['contract_id'], $me['user_id']);

    if ((int)$req['proposed_by'] === $me['user_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Нельзя подтвердить своё же предложение']);
        exit;
    }
    if ($req['status'] !== 'pending') {
        http_response_code(409);
        echo json_encode(['error' => 'Предложение уже обработано']);
        exit;
    }

    $db->beginTransaction();
    $db->prepare("UPDATE contract_change_requests SET status = ?, decided_at = NOW() WHERE id = ?")
       ->execute([$accept ? 'accepted' : 'rejected', $requestId]);

    if ($accept) {
        $fields = [];
        $params = [];
        if ($req['new_price'] !== null) { $fields[] = 'price = ?'; $params[] = $req['new_price']; }
        if ($req['new_deadline'] !== null) { $fields[] = 'deadline = ?'; $params[] = $req['new_deadline']; }
        if ($fields) {
            $params[] = $req['contract_id'];
            $db->prepare('UPDATE contracts SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        }
    }
    $db->commit();

    create_notification(
        $db,
        (int)$req['proposed_by'],
        'contract_change_' . ($accept ? 'accepted' : 'rejected'),
        ($accept ? 'Изменение условий контракта принято' : 'Изменение условий контракта отклонено')
            . ' — #' . contract_number((int)$req['contract_id'])
    );

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
