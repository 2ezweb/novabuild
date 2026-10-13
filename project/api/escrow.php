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
require_once __DIR__ . '/../includes/client_balance.php';
require_once __DIR__ . '/../includes/estimate_document.php';

// Same demo-stub principle as connects.php: no real payment gateway behind
// any of the three methods, and card fields are never sent here.
//
// Deposit gate: active contract/stage AND an estimate (not rejected) that
// covers both «Роботи» and «Матеріали» — deposit_block_reason().
//
// Balance first: whatever the client has AVAILABLE on the site balance
// (client_profiles.balance minus frozen holds, see client_balance.php) is
// spent first, only the remainder goes to the payment method. If the balance
// covers everything, method isn't required at all.
const MIN_DEPOSIT_UAH = 10;
const MAX_DEPOSIT_UAH = 1000000;
const PAYMENT_METHODS  = ['card' => 'банківською карткою', 'gpay' => 'через Google Pay', 'liqpay' => 'через LiqPay'];

$me = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не підтримується']);
    exit;
}

if ($me['role'] !== 'client') {
    http_response_code(403);
    echo json_encode(['error' => 'Депозит в ескроу вносить лише клієнт']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

if ($action !== 'deposit') {
    http_response_code(400);
    echo json_encode(['error' => 'Невідома дія']);
    exit;
}

$amount = is_numeric($body['amount'] ?? '') ? round((float) $body['amount'], 2) : 0;
if ($amount < MIN_DEPOSIT_UAH || $amount > MAX_DEPOSIT_UAH) {
    http_response_code(422);
    echo json_encode(['error' => 'Сума має бути від ' . MIN_DEPOSIT_UAH . ' до ' . MAX_DEPOSIT_UAH . ' ₴']);
    exit;
}

$contractId = (int) ($body['contract_id'] ?? 0);

$db = get_db();
$stmt = $db->prepare("SELECT * FROM contracts WHERE id = ? AND client_user_id = ?");
$stmt->execute([$contractId, $me['user_id']]);
$contract = $stmt->fetch();

if (!$contract) {
    http_response_code(404);
    echo json_encode(['error' => 'Контракт не знайдено']);
    exit;
}
if ($contract['type'] === 'staged' && $contract['parent_contract_id'] === null) {
    http_response_code(409);
    echo json_encode(['error' => 'У поетапному контракті депозит вноситься за кожен етап окремо']);
    exit;
}
if ($contract['status'] !== 'active') {
    http_response_code(409);
    echo json_encode(['error' => 'Депозит можна вносити лише за активним контрактом']);
    exit;
}
// …and only once there's an estimate with both «Роботи» and «Матеріали».
if ($reason = deposit_block_reason($db, $contractId)) {
    http_response_code(409);
    echo json_encode(['error' => $reason]);
    exit;
}

$number = contract_number($contractId);

$db->beginTransaction();

// Lock the client's balance row so two parallel deposits can't both spend it.
$db->prepare('SELECT balance FROM client_profiles WHERE user_id = ? FOR UPDATE')->execute([$me['user_id']]);
$available   = client_available_balance($db, $me['user_id']);
$fromBalance = round(min($amount, $available), 2);
$fromCard    = round($amount - $fromBalance, 2);

$method = $body['method'] ?? '';
if ($fromCard > 0 && !isset(PAYMENT_METHODS[$method])) {
    $db->rollBack();
    http_response_code(422);
    echo json_encode(['error' => 'Оберіть спосіб оплати для решти ' . $fromCard . ' ₴']);
    exit;
}

$db->prepare('UPDATE contracts SET escrowed_amount = escrowed_amount + ? WHERE id = ?')
   ->execute([$amount, $contractId]);

if ($fromBalance > 0) {
    $db->prepare('UPDATE client_profiles SET balance = balance - ? WHERE user_id = ?')
       ->execute([$fromBalance, $me['user_id']]);
    $db->prepare("
        INSERT INTO wallet_transactions (user_id, type, amount, description, related_contract_id)
        VALUES (?, 'balance_payment', ?, ?, ?)
    ")->execute([$me['user_id'], $fromBalance, 'Депозит за контрактом #' . $number . ' (з балансу на сайті)', $contractId]);
}
if ($fromCard > 0) {
    $db->prepare("
        INSERT INTO wallet_transactions (user_id, type, amount, description, related_contract_id)
        VALUES (?, 'deposit', ?, ?, ?)
    ")->execute([$me['user_id'], $fromCard, 'Депозит за контрактом #' . $number . ' (' . PAYMENT_METHODS[$method] . ')', $contractId]);
}
$db->commit();

create_notification($db, (int)$contract['freelancer_user_id'], 'escrow_deposit', 'Клієнт вніс депозит за контрактом #' . $number);

$stmt = $db->prepare('SELECT escrowed_amount FROM contracts WHERE id = ?');
$stmt->execute([$contractId]);

echo json_encode([
    'escrowed_amount'   => (float) $stmt->fetchColumn(),
    'from_balance'      => $fromBalance,
    'from_card'         => $fromCard,
    'available_balance' => client_available_balance($db, $me['user_id']),
]);
