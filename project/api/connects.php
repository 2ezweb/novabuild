<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/notifications.php';

// Demo exchange rate — no real payment gateway is wired up, every method
// below is a stub that credits connects immediately on "confirmation".
const CONNECTS_PER_UAH  = 1;
const MIN_PURCHASE_UAH  = 10;
const MAX_PURCHASE_UAH  = 100000;
const PAYMENT_METHODS   = ['card' => 'банківською карткою', 'gpay' => 'через Google Pay', 'liqpay' => 'через LiqPay'];

$me = require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не підтримується']);
    exit;
}

if ($me['role'] !== 'freelancer') {
    http_response_code(403);
    echo json_encode(['error' => 'Конекти доступні лише фрилансерам']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

if ($action !== 'purchase') {
    http_response_code(400);
    echo json_encode(['error' => 'Невідома дія']);
    exit;
}

$method = $body['method'] ?? '';
if (!isset(PAYMENT_METHODS[$method])) {
    http_response_code(422);
    echo json_encode(['error' => 'Невідомий спосіб оплати']);
    exit;
}

$amount = is_numeric($body['amount'] ?? '') ? (float)$body['amount'] : 0;
if ($amount < MIN_PURCHASE_UAH || $amount > MAX_PURCHASE_UAH) {
    http_response_code(422);
    echo json_encode(['error' => 'Сума має бути від ' . MIN_PURCHASE_UAH . ' до ' . MAX_PURCHASE_UAH . ' ₴']);
    exit;
}

// Note: card fields (number/expiry/cvv) are intentionally never sent here —
// the frontend keeps them client-side only. There is no real processor
// behind any of the three methods, so "confirmation" just credits the balance.
$connects = (int) round($amount * CONNECTS_PER_UAH);

$db = get_db();
$db->prepare('UPDATE freelancer_profiles SET connects_balance = connects_balance + ? WHERE user_id = ?')
   ->execute([$connects, $me['user_id']]);

$stmt = $db->prepare('SELECT connects_balance FROM freelancer_profiles WHERE user_id = ?');
$stmt->execute([$me['user_id']]);
$balance = (int) $stmt->fetchColumn();

create_notification(
    $db,
    $me['user_id'],
    'connects_purchased',
    "Зараховано {$connects} конектів (" . PAYMENT_METHODS[$method] . ", {$amount} ₴)"
);

echo json_encode(['connects_added' => $connects, 'connects_balance' => $balance]);
