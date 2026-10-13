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

// Отзывы клиента о фрилансере. One per closed TOP-LEVEL contract (a full
// contract, or a staged one as a whole — not per stage), completed or failed.
// Not editable. The aggregate rating is read by freelancer_profile.php and bids.php.

$me = require_auth();
$db = get_db();

function review_contract_or_404(PDO $db, int $contractId, int $userId): array {
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

// ─── GET ?contract_id: this contract's review (both sides can read it) ──────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $contract = review_contract_or_404($db, (int)($_GET['contract_id'] ?? 0), $me['user_id']);
    $stmt = $db->prepare('SELECT id, rating, comment, created_at FROM reviews WHERE contract_id = ?');
    $stmt->execute([$contract['id']]);
    echo json_encode(['review' => $stmt->fetch() ?: null]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не підтримується']);
    exit;
}

// ─── POST {contract_id, rating 1..5, comment?}: client leaves the review ────
$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$contract = review_contract_or_404($db, (int)($body['contract_id'] ?? 0), $me['user_id']);

if ((int)$contract['client_user_id'] !== $me['user_id']) {
    http_response_code(403);
    echo json_encode(['error' => 'Відгук залишає лише клієнт']);
    exit;
}
if ($contract['parent_contract_id'] !== null) {
    http_response_code(409);
    echo json_encode(['error' => 'Відгук залишається на весь контракт, а не на етап']);
    exit;
}
if (!in_array($contract['status'], ['completed', 'failed'], true)) {
    http_response_code(409);
    echo json_encode(['error' => 'Відгук можна залишити лише після закриття контракту']);
    exit;
}

$rating = (int)($body['rating'] ?? 0);
if ($rating < 1 || $rating > 5) {
    http_response_code(422);
    echo json_encode(['error' => 'Оцінка — від 1 до 5']);
    exit;
}
$comment = trim((string)($body['comment'] ?? ''));
if (mb_strlen($comment) > 2000) {
    http_response_code(422);
    echo json_encode(['error' => 'Відгук — не більше 2000 символів']);
    exit;
}

try {
    $db->prepare('INSERT INTO reviews (contract_id, client_user_id, freelancer_user_id, rating, comment) VALUES (?, ?, ?, ?, ?)')
       ->execute([$contract['id'], $me['user_id'], $contract['freelancer_user_id'], $rating, $comment !== '' ? $comment : null]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {   // UNIQUE(contract_id)
        http_response_code(409);
        echo json_encode(['error' => 'Відгук щодо цього контракту вже залишено']);
        exit;
    }
    throw $e;
}

create_notification($db, (int)$contract['freelancer_user_id'], 'review_received',
    "Клієнт залишив відгук ($rating/5) щодо контракту #" . contract_number((int)$contract['id']));

http_response_code(201);
echo json_encode(['success' => true]);
