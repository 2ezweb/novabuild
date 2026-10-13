<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/contract_number.php';

// Same demo-stub principle as connects.php: no real payment gateway behind
// any of the three methods, and card fields are never sent here.
const MIN_DEPOSIT_UAH = 10;
const MAX_DEPOSIT_UAH = 1000000;
const PAYMENT_METHODS  = ['card' => 'банковской картой', 'gpay' => 'через Google Pay', 'liqpay' => 'через LiqPay'];

$me = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if ($me['role'] !== 'client') {
    http_response_code(403);
    echo json_encode(['error' => 'Депозит в эскроу вносит только клиент']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

if ($action !== 'deposit') {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

$method = $body['method'] ?? '';
if (!isset(PAYMENT_METHODS[$method])) {
    http_response_code(422);
    echo json_encode(['error' => 'Неизвестный способ оплаты']);
    exit;
}

$amount = is_numeric($body['amount'] ?? '') ? (float) $body['amount'] : 0;
if ($amount < MIN_DEPOSIT_UAH || $amount > MAX_DEPOSIT_UAH) {
    http_response_code(422);
    echo json_encode(['error' => 'Сумма должна быть от ' . MIN_DEPOSIT_UAH . ' до ' . MAX_DEPOSIT_UAH . ' ₴']);
    exit;
}

$contractId = (int) ($body['contract_id'] ?? 0);

$db = get_db();
$stmt = $db->prepare("SELECT * FROM contracts WHERE id = ? AND client_user_id = ?");
$stmt->execute([$contractId, $me['user_id']]);
$contract = $stmt->fetch();

if (!$contract) {
    http_response_code(404);
    echo json_encode(['error' => 'Contract not found']);
    exit;
}
if ($contract['type'] === 'staged' && $contract['parent_contract_id'] === null) {
    http_response_code(409);
    echo json_encode(['error' => 'В поэтапном контракте депозит вносится по каждому этапу отдельно']);
    exit;
}
if ($contract['status'] !== 'active') {
    http_response_code(409);
    echo json_encode(['error' => 'Депозит можно вносить только по активному контракту']);
    exit;
}

$db->beginTransaction();
$db->prepare('UPDATE contracts SET escrowed_amount = escrowed_amount + ? WHERE id = ?')
   ->execute([$amount, $contractId]);
$db->prepare("
    INSERT INTO wallet_transactions (user_id, type, amount, description, related_contract_id)
    VALUES (?, 'deposit', ?, ?, ?)
")->execute([$me['user_id'], $amount, 'Депозит по контракту #' . contract_number($contractId) . ' (' . PAYMENT_METHODS[$method] . ')', $contractId]);
$db->commit();

create_notification($db, (int)$contract['freelancer_user_id'], 'escrow_deposit', 'Клиент внёс депозит по контракту #' . contract_number($contractId));

$stmt = $db->prepare('SELECT escrowed_amount FROM contracts WHERE id = ?');
$stmt->execute([$contractId]);

echo json_encode(['escrowed_amount' => (float) $stmt->fetchColumn()]);
