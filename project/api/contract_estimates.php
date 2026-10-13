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
require_once __DIR__ . '/../includes/estimate_document.php';

// КОШТОРИС — the freelancer's estimate. It belongs to ONE specific signed
// contract or stage (a staged contract's parent holds no estimate of its own —
// each stage is its own mini-contract, see contracts.php). items is a JSON array
// of {kind: work|material, name, qty, unit, unit_price}: «Роботи» (informative,
// affects nothing) and «Матеріали» (as before); items without kind = material.
// The freelancer fills a form; the CLIENT gets a generated .docx document
// (includes/estimate_document.php, stored at submit time in document_path) plus
// a short summary. No money moves here; submit() also stamps
// contracts.estimate_id on that row as a quick pointer.

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

function clean_items($raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $it) {
        if (!is_array($it)) continue;
        $name = trim((string)($it['name'] ?? ''));
        $qty  = is_numeric($it['qty'] ?? null) ? (float) $it['qty'] : 0;
        $unit = trim((string)($it['unit'] ?? ''));
        $up   = is_numeric($it['unit_price'] ?? null) ? (float) $it['unit_price'] : 0;
        if ($name === '' || $qty <= 0 || $up < 0) continue;
        $kind = ($it['kind'] ?? 'material') === 'work' ? 'work' : 'material';
        $out[] = ['kind' => $kind, 'name' => mb_substr($name, 0, 255), 'qty' => $qty, 'unit' => mb_substr($unit, 0, 20), 'unit_price' => $up];
    }
    return $out;
}

// ─── GET: estimate history for one specific contract/stage (newest first) ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $contractId = (int) ($_GET['contract_id'] ?? 0);
    contract_participant_or_404($db, $contractId, $me['user_id']);

    $stmt = $db->prepare('SELECT * FROM contract_estimates WHERE contract_id = ? ORDER BY id DESC');
    $stmt->execute([$contractId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['items'] = array_map(fn($it) => $it + ['kind' => 'material'], json_decode($r['items'], true) ?: []);
        $totals = estimate_totals($r['items']);
        $r['works_total']     = $totals['work'];
        $r['materials_total'] = $totals['material'];
        // Estimates from before documents existed get theirs on first read.
        if (empty($r['document_path'])) {
            try { $r['document_path'] = generate_estimate_document($db, $r); } catch (Throwable $e) { $r['document_path'] = null; }
        }
    }
    unset($r);

    echo json_encode($rows);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не підтримується']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ─── submit: freelancer sends an estimate for THIS contract/stage ───────────
if ($action === 'submit') {
    if ($me['role'] !== 'freelancer') {
        http_response_code(403);
        echo json_encode(['error' => 'Кошторис складає фрилансер']);
        exit;
    }

    $contractId = (int) ($body['contract_id'] ?? 0);
    $contract   = contract_participant_or_404($db, $contractId, $me['user_id']);

    if ((int)$contract['freelancer_user_id'] !== $me['user_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Це не ваш контракт']);
        exit;
    }
    if ($contract['type'] === 'staged' && $contract['parent_contract_id'] === null) {
        http_response_code(409);
        echo json_encode(['error' => 'Кошторис складається для конкретного етапу, а не для всього поетапного контракту']);
        exit;
    }
    // Active contract/stage — or a not-yet-started stage of an ACCEPTED plan
    // («За планом»): a planned contract gets an estimate for every stage up front.
    $estimable = $contract['status'] === 'active';
    if ($contract['status'] === 'planned' && $contract['parent_contract_id']) {
        $stmt = $db->prepare('SELECT status FROM contracts WHERE id = ?');
        $stmt->execute([$contract['parent_contract_id']]);
        $estimable = $stmt->fetchColumn() === 'active';
    }
    if (!$estimable) {
        http_response_code(409);
        echo json_encode(['error' => 'Кошторис можна скласти лише після підписання контракту']);
        exit;
    }

    $items = clean_items($body['items'] ?? null);
    $note  = trim($body['note'] ?? '');
    if (!$items) {
        http_response_code(422);
        echo json_encode(['error' => 'Додайте хоча б одну позицію кошторису']);
        exit;
    }
    // Both parts are required — without them the client can't make the deposit (escrow.php).
    if (!estimate_covers_both($items)) {
        http_response_code(422);
        echo json_encode(['error' => 'Кошторис має містити і роботи, і матеріали — додайте хоча б по одній позиції в кожен розділ']);
        exit;
    }

    $stmt = $db->prepare("SELECT id, items FROM contract_estimates WHERE contract_id = ? AND status = 'submitted'");
    $stmt->execute([$contractId]);
    if ($pending = $stmt->fetch()) {
        // An old-format estimate (no «Роботи» part) blocks the deposit — the
        // freelancer may replace it right away; it's marked rejected (superseded).
        if (estimate_covers_both(json_decode($pending['items'], true) ?: [])) {
            http_response_code(409);
            echo json_encode(['error' => 'Попередній кошторис щодо цього контракту ще на розгляді в замовника']);
            exit;
        }
        $db->prepare("UPDATE contract_estimates SET status = 'rejected', decided_at = NOW() WHERE id = ?")->execute([$pending['id']]);
    }

    $total = 0.0;
    foreach ($items as $it) $total += $it['qty'] * $it['unit_price'];

    $stmt = $db->prepare('
        INSERT INTO contract_estimates (conversation_id, contract_id, freelancer_user_id, items, note, total)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([$contract['conversation_id'], $contractId, $me['user_id'], json_encode($items, JSON_UNESCAPED_UNICODE), $note ?: null, $total]);
    $estimateId = (int) $db->lastInsertId();

    $db->prepare('UPDATE contracts SET estimate_id = ? WHERE id = ?')->execute([$estimateId, $contractId]);

    // The client's copy — a .docx snapshot. Non-fatal: if it fails here, GET retries.
    $stmt = $db->prepare('SELECT * FROM contract_estimates WHERE id = ?');
    $stmt->execute([$estimateId]);
    try { generate_estimate_document($db, $stmt->fetch()); } catch (Throwable $e) { /* regenerated lazily on read */ }

    $noun = $contract['parent_contract_id'] ? 'етапу' : 'контракту';
    create_notification($db, (int)$contract['client_user_id'], 'estimate_submitted',
        "Фрилансер надіслав кошторис щодо $noun #" . contract_number($contractId));

    http_response_code(201);
    echo json_encode(['id' => $estimateId, 'total' => $total]);
    exit;
}

// ─── respond: client accepts or rejects an estimate ────────────────────────
if ($action === 'respond') {
    if ($me['role'] !== 'client') {
        http_response_code(403);
        echo json_encode(['error' => 'Кошторис затверджує замовник']);
        exit;
    }

    $estimateId = (int) ($body['estimate_id'] ?? 0);
    $accept     = !empty($body['accept']);

    $stmt = $db->prepare('SELECT * FROM contract_estimates WHERE id = ?');
    $stmt->execute([$estimateId]);
    $est = $stmt->fetch();
    if (!$est) {
        http_response_code(404);
        echo json_encode(['error' => 'Кошторис не знайдено']);
        exit;
    }

    $contract = contract_participant_or_404($db, (int)$est['contract_id'], $me['user_id']);
    if ((int)$contract['client_user_id'] !== $me['user_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Це не ваш контракт']);
        exit;
    }
    if ($est['status'] !== 'submitted') {
        http_response_code(409);
        echo json_encode(['error' => 'Кошторис уже оброблено']);
        exit;
    }

    $db->prepare("UPDATE contract_estimates SET status = ?, decided_at = NOW() WHERE id = ?")
       ->execute([$accept ? 'accepted' : 'rejected', $estimateId]);

    $noun = $contract['parent_contract_id'] ? 'етапу' : 'контракту';
    create_notification($db, (int)$est['freelancer_user_id'],
        $accept ? 'estimate_accepted' : 'estimate_rejected',
        ($accept ? 'Замовник затвердив кошторис щодо ' : 'Замовник відхилив кошторис щодо ') . $noun . ' #' . contract_number((int)$contract['id']));

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Невідома дія']);
