<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

const MIN_WITHDRAWAL = 1;

$me = require_auth();

if ($me['role'] !== 'freelancer') {
    http_response_code(403);
    echo json_encode(['error' => 'Виведення коштів доступне лише фрилансерам']);
    exit;
}

$db = get_db();

// ─── GET: balance + my withdrawal requests ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare('SELECT balance FROM freelancer_profiles WHERE user_id = ?');
    $stmt->execute([$me['user_id']]);
    $balance = (float) $stmt->fetchColumn();

    $stmt = $db->prepare('
        SELECT id, amount, status, created_at, decided_at
        FROM withdrawal_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 20
    ');
    $stmt->execute([$me['user_id']]);

    echo json_encode(['balance' => $balance, 'requests' => $stmt->fetchAll()]);
    exit;
}

// ─── POST: request_withdrawal ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action !== 'request_withdrawal') {
        http_response_code(400);
        echo json_encode(['error' => 'Невідома дія']);
        exit;
    }

    $amount = is_numeric($body['amount'] ?? '') ? (float)$body['amount'] : 0;
    if ($amount < MIN_WITHDRAWAL) {
        http_response_code(422);
        echo json_encode(['error' => 'Мінімальна сума виведення — ' . MIN_WITHDRAWAL . ' ₴']);
        exit;
    }

    $stmt = $db->prepare("SELECT id FROM withdrawal_requests WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$me['user_id']]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'У вас уже є заявка на виведення в обробці']);
        exit;
    }

    $stmt = $db->prepare('SELECT balance FROM freelancer_profiles WHERE user_id = ?');
    $stmt->execute([$me['user_id']]);
    $balance = (float) $stmt->fetchColumn();

    if ($amount > $balance) {
        http_response_code(422);
        echo json_encode(['error' => 'Недостатньо коштів на балансі']);
        exit;
    }

    // Funds are reserved immediately (so the same balance can't be requested
    // twice while a request is pending) and refunded automatically if the
    // admin rejects it — see admin.php::reject_withdrawal.
    $db->beginTransaction();
    $db->prepare('UPDATE freelancer_profiles SET balance = balance - ? WHERE user_id = ?')
       ->execute([$amount, $me['user_id']]);
    $db->prepare('INSERT INTO withdrawal_requests (user_id, amount) VALUES (?, ?)')
       ->execute([$me['user_id'], $amount]);
    $requestId = (int) $db->lastInsertId();
    $db->commit();

    echo json_encode(['id' => $requestId, 'balance' => $balance - $amount]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Метод не підтримується']);
