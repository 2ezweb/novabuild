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
require_once __DIR__ . '/../includes/contract_payouts.php';
require_once __DIR__ . '/../includes/dates.php';

const FREELANCER_CLOSE_SILENCE_DAYS = 14;

$me = require_auth();
$db = get_db();

function contract_or_404(PDO $db, int $id, int $userId): array {
    $stmt = $db->prepare('SELECT * FROM contracts WHERE id = ?');
    $stmt->execute([$id]);
    $c = $stmt->fetch();
    if (!$c || ((int)$c['client_user_id'] !== $userId && (int)$c['freelancer_user_id'] !== $userId)) {
        http_response_code(404);
        echo json_encode(['error' => 'Contract not found']);
        exit;
    }
    return $c;
}

// ─── GET: my contracts, optionally filtered by status or conversation ────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!in_array($me['role'], ['client', 'freelancer'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Контракты доступны только клиентам и фрилансерам']);
        exit;
    }

    $roleCol  = $me['role'] === 'client' ? 'client_user_id' : 'freelancer_user_id';
    $otherCol = $me['role'] === 'client' ? 'freelancer_user_id' : 'client_user_id';

    // Only top-level contracts here; stages (parent_contract_id set) are nested
    // into their parent below. last_message_* powers the freelancer's
    // unilateral-close gate on the contracts page (same rule as close()).
    $sql = "
        SELECT c.*, u.first_name AS other_first_name, u.last_name AS other_last_name, u.avatar_path AS other_avatar_path,
               (SELECT m.sender_id  FROM messages m WHERE m.conversation_id = c.conversation_id ORDER BY m.id DESC LIMIT 1) AS last_message_sender_id,
               (SELECT m.created_at FROM messages m WHERE m.conversation_id = c.conversation_id ORDER BY m.id DESC LIMIT 1) AS last_message_at
        FROM contracts c
        JOIN users u ON u.id = c.$otherCol
        WHERE c.$roleCol = ? AND c.parent_contract_id IS NULL
    ";
    $params = [$me['user_id']];

    if (!empty($_GET['conversation_id'])) {
        $sql .= ' AND c.conversation_id = ?';
        $params[] = (int) $_GET['conversation_id'];
    }
    if (!empty($_GET['status'])) {
        $sql .= ' AND c.status = ?';
        $params[] = $_GET['status'];
    }
    $sql .= ' ORDER BY c.id DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $contracts = $stmt->fetchAll();

    $stageStmt = $db->prepare('SELECT * FROM contracts WHERE parent_contract_id = ? ORDER BY seq ASC');

    foreach ($contracts as &$c) {
        $c['other_last_name_initial'] = $c['other_last_name'] ? mb_substr($c['other_last_name'], 0, 1) . '.' : null;
        unset($c['other_last_name']);
        $c['number'] = contract_number((int) $c['id']);

        if ($c['type'] === 'staged') {
            $stageStmt->execute([$c['id']]);
            $stages = $stageStmt->fetchAll();
            foreach ($stages as &$s) {
                $s['number'] = contract_number((int) $s['id']);
                $s['parent_number'] = $c['number'];
            }
            unset($s);
            $c['stages'] = $stages;
            // Aggregate view — the staged parent has no price/escrow of its own.
            $c['price'] = array_sum(array_map(
                fn($s) => $s['status'] === 'rejected' ? 0 : (float) $s['price'],
                $stages
            ));
            $c['escrowed_amount'] = array_sum(array_map(fn($s) => (float) $s['escrowed_amount'], $stages));
        }
    }
    unset($c);

    echo json_encode($contracts);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ─── propose: client proposes a full contract, or a staged one with stage 1 ──
if ($action === 'propose') {
    if ($me['role'] !== 'client') {
        http_response_code(403);
        echo json_encode(['error' => 'Только клиент может предложить контракт']);
        exit;
    }

    $conversationId = (int) ($body['conversation_id'] ?? 0);
    $type           = ($body['type'] ?? 'full') === 'staged' ? 'staged' : 'full';
    $price          = is_numeric($body['price'] ?? '') ? (float) $body['price'] : 0;
    $deadline       = validate_deadline($body['deadline'] ?? null);
    $offerId        = !empty($body['offer_id']) ? (int) $body['offer_id'] : null;
    $stageTitle     = trim($body['stage_title'] ?? '');

    if ($price <= 0) {
        http_response_code(422);
        echo json_encode(['error' => 'Укажите цену' . ($type === 'staged' ? ' первого этапа' : ' контракта')]);
        exit;
    }
    if ($type === 'staged' && $stageTitle === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Укажите название первого этапа']);
        exit;
    }

    $stmt = $db->prepare('SELECT * FROM conversations WHERE id = ? AND client_user_id = ?');
    $stmt->execute([$conversationId, $me['user_id']]);
    $conv = $stmt->fetch();
    if (!$conv) {
        http_response_code(404);
        echo json_encode(['error' => 'Conversation not found']);
        exit;
    }

    // offer_id is optional context — must belong to this client, else dropped.
    if ($offerId !== null) {
        $stmt = $db->prepare('
            SELECT o.id FROM offers o
            JOIN client_profiles cp ON cp.id = o.client_id
            WHERE o.id = ? AND cp.user_id = ?
        ');
        $stmt->execute([$offerId, $me['user_id']]);
        if (!$stmt->fetch()) $offerId = null;
    }

    // One top-level contract at a time per conversation (staged or full).
    $stmt = $db->prepare("SELECT id FROM contracts WHERE conversation_id = ? AND parent_contract_id IS NULL AND status IN ('proposed','active')");
    $stmt->execute([$conversationId]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'В этой переписке уже есть предложенный или активный контракт']);
        exit;
    }

    $freelancerId = (int) $conv['freelancer_user_id'];

    if ($type === 'full') {
        $stmt = $db->prepare('
            INSERT INTO contracts (conversation_id, client_user_id, freelancer_user_id, offer_id, type, price, deadline, status)
            VALUES (?, ?, ?, ?, "full", ?, ?, "proposed")
        ');
        $stmt->execute([$conversationId, $me['user_id'], $freelancerId, $offerId, $price, $deadline]);
        $contractId = (int) $db->lastInsertId();
    } else {
        $db->beginTransaction();
        // Parent container — no price/escrow of its own; proposed until stage 1 is accepted.
        $db->prepare('
            INSERT INTO contracts (conversation_id, client_user_id, freelancer_user_id, offer_id, type, price, deadline, status, title)
            VALUES (?, ?, ?, ?, "staged", 0, NULL, "proposed", "Поэтапный контракт")
        ')->execute([$conversationId, $me['user_id'], $freelancerId, $offerId]);
        $contractId = (int) $db->lastInsertId();
        // Stage 1.
        $db->prepare('
            INSERT INTO contracts (conversation_id, client_user_id, freelancer_user_id, parent_contract_id, seq, type, title, price, deadline, status)
            VALUES (?, ?, ?, ?, 1, "full", ?, ?, ?, "proposed")
        ')->execute([$conversationId, $me['user_id'], $freelancerId, $contractId, $stageTitle, $price, $deadline]);
        $db->commit();
    }

    create_notification($db, $freelancerId, 'contract_proposed',
        $type === 'staged' ? 'Вам предложили поэтапный контракт' : 'Вам предложили контракт');

    http_response_code(201);
    echo json_encode(['id' => $contractId]);
    exit;
}

// ─── add_stage: client forms the next stage of a staged contract ─────────────
if ($action === 'add_stage') {
    if ($me['role'] !== 'client') {
        http_response_code(403);
        echo json_encode(['error' => 'Только клиент может сформировать этап']);
        exit;
    }

    $parentId = (int) ($body['contract_id'] ?? 0);
    $parent   = contract_or_404($db, $parentId, $me['user_id']);

    if ($parent['type'] !== 'staged' || $parent['parent_contract_id'] !== null) {
        http_response_code(409);
        echo json_encode(['error' => 'Этапы можно добавлять только в поэтапный контракт']);
        exit;
    }
    if ($parent['status'] !== 'active') {
        http_response_code(409);
        echo json_encode(['error' => 'Контракт не активен']);
        exit;
    }

    $title    = trim($body['title'] ?? '');
    $price    = is_numeric($body['price'] ?? '') ? (float) $body['price'] : 0;
    $deadline = validate_deadline($body['deadline'] ?? null, 'Срок этапа');

    if ($title === '' || $price <= 0) {
        http_response_code(422);
        echo json_encode(['error' => 'Укажите название и цену этапа']);
        exit;
    }

    // Next stage only after the previous one is done.
    $stmt = $db->prepare('SELECT status, seq FROM contracts WHERE parent_contract_id = ? ORDER BY seq DESC LIMIT 1');
    $stmt->execute([$parentId]);
    $last = $stmt->fetch();
    if ($last && !in_array($last['status'], ['completed', 'failed', 'rejected'], true)) {
        http_response_code(409);
        echo json_encode(['error' => 'Сначала завершите текущий этап']);
        exit;
    }
    $nextSeq = $last ? ((int) $last['seq'] + 1) : 1;

    $db->prepare('
        INSERT INTO contracts (conversation_id, client_user_id, freelancer_user_id, parent_contract_id, seq, type, title, price, deadline, status)
        VALUES (?, ?, ?, ?, ?, "full", ?, ?, ?, "proposed")
    ')->execute([
        $parent['conversation_id'], $me['user_id'], $parent['freelancer_user_id'],
        $parentId, $nextSeq, $title, $price, $deadline,
    ]);
    $stageId = (int) $db->lastInsertId();

    create_notification($db, (int) $parent['freelancer_user_id'], 'contract_proposed',
        'Вам предложили этап ' . $nextSeq . ' по контракту #' . contract_number($parentId));

    http_response_code(201);
    echo json_encode(['id' => $stageId]);
    exit;
}

// ─── accept / reject: freelancer decides on a proposed contract or stage ─────
if ($action === 'accept' || $action === 'reject') {
    if ($me['role'] !== 'freelancer') {
        http_response_code(403);
        echo json_encode(['error' => 'Только фрилансер может подтвердить или отклонить контракт']);
        exit;
    }

    $contractId = (int) ($body['contract_id'] ?? 0);
    $contract   = contract_or_404($db, $contractId, $me['user_id']);

    if ((int)$contract['freelancer_user_id'] !== $me['user_id'] || $contract['status'] !== 'proposed') {
        http_response_code(409);
        echo json_encode(['error' => 'Контракт нельзя подтвердить или отклонить в текущем статусе']);
        exit;
    }

    $newStatus = $action === 'accept' ? 'active' : 'rejected';
    $db->prepare('UPDATE contracts SET status = ? WHERE id = ?')->execute([$newStatus, $contractId]);

    // A stage accept/reject can settle the parent's proposed state, and the
    // originating offer is carried on the parent (or on a plain contract).
    $offerToBadge = null;
    if ($contract['parent_contract_id']) {
        $parentId = (int) $contract['parent_contract_id'];
        $stmt = $db->prepare('SELECT status, offer_id FROM contracts WHERE id = ?');
        $stmt->execute([$parentId]);
        $parent = $stmt->fetch();
        if ($parent && $parent['status'] === 'proposed') {
            if ($action === 'accept') {
                $db->prepare("UPDATE contracts SET status = 'active' WHERE id = ?")->execute([$parentId]);
                $offerToBadge = $parent['offer_id'];
            } else {
                // No stage survives → the whole staged contract falls through.
                $stmt = $db->prepare("SELECT COUNT(*) FROM contracts WHERE parent_contract_id = ? AND status <> 'rejected'");
                $stmt->execute([$parentId]);
                if ((int) $stmt->fetchColumn() === 0) {
                    $db->prepare("UPDATE contracts SET status = 'rejected' WHERE id = ?")->execute([$parentId]);
                }
            }
        }
    } elseif ($action === 'accept') {
        $offerToBadge = $contract['offer_id'];
    }

    if ($offerToBadge) {
        $db->prepare('UPDATE offers SET outdated_at = NOW() WHERE id = ? AND outdated_at IS NULL')
           ->execute([$offerToBadge]);
    }

    $label = $contract['parent_contract_id'] ? 'этап' : 'контракт';
    create_notification(
        $db,
        (int)$contract['client_user_id'],
        $action === 'accept' ? 'contract_accepted' : 'contract_rejected',
        $action === 'accept' ? "Фрилансер подтвердил $label" : "Фрилансер отклонил $label"
    );

    echo json_encode(['success' => true, 'status' => $newStatus]);
    exit;
}

// ─── close: successful or failed, asymmetric rights ─────────────────────────
if ($action === 'close') {
    if (!in_array($me['role'], ['client', 'freelancer'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Method not allowed for this role']);
        exit;
    }

    $contractId = (int) ($body['contract_id'] ?? 0);
    $outcome    = $body['outcome'] ?? '';
    if (!in_array($outcome, ['success', 'failure'], true)) {
        http_response_code(422);
        echo json_encode(['error' => 'outcome must be success or failure']);
        exit;
    }

    $contract = contract_or_404($db, $contractId, $me['user_id']);
    if ($contract['status'] !== 'active') {
        http_response_code(409);
        echo json_encode(['error' => 'Контракт не активен']);
        exit;
    }

    if ($me['role'] === 'client') {
        if ((int)$contract['client_user_id'] !== $me['user_id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Not your contract']);
            exit;
        }
    } else {
        if ((int)$contract['freelancer_user_id'] !== $me['user_id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Not your contract']);
            exit;
        }
        // Freelancer unilateral close only after 14 days of client silence.
        $stmt = $db->prepare('SELECT sender_id, created_at FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$contract['conversation_id']]);
        $lastMessage = $stmt->fetch();
        $eligible = $lastMessage
            && (int)$lastMessage['sender_id'] === $me['user_id']
            && strtotime($lastMessage['created_at']) <= strtotime('-' . FREELANCER_CLOSE_SILENCE_DAYS . ' days');
        if (!$eligible) {
            http_response_code(403);
            echo json_encode(['error' => 'Закрыть можно только если клиент не отвечает на ваше последнее сообщение ' . FREELANCER_CLOSE_SILENCE_DAYS . ' дней']);
            exit;
        }
    }

    $isStagedParent = $contract['type'] === 'staged' && $contract['parent_contract_id'] === null;

    $db->beginTransaction();

    if ($isStagedParent) {
        // Terminate the container: pending stages → rejected, active stages →
        // failed with a refund+hold, done stages left as they are.
        $stmt = $db->prepare("SELECT * FROM contracts WHERE parent_contract_id = ?");
        $stmt->execute([$contractId]);
        foreach ($stmt->fetchAll() as $stage) {
            if ($stage['status'] === 'proposed') {
                $db->prepare("UPDATE contracts SET status = 'rejected' WHERE id = ?")->execute([$stage['id']]);
            } elseif ($stage['status'] === 'active') {
                refund_with_hold($db, $stage);
                settle_pending_submissions($db, (int)$stage['id'], false);
                $db->prepare("UPDATE contracts SET status = 'failed', escrowed_amount = 0 WHERE id = ?")->execute([$stage['id']]);
            }
        }
        $db->prepare("UPDATE contracts SET status = ? WHERE id = ?")
           ->execute([$outcome === 'success' ? 'completed' : 'failed', $contractId]);
    } elseif ($outcome === 'success') {
        pay_freelancer($db, $contract);
        settle_pending_submissions($db, $contractId, true);
        $db->prepare("UPDATE contracts SET status = 'completed', escrowed_amount = 0 WHERE id = ?")->execute([$contractId]);
    } else {
        refund_with_hold($db, $contract);
        settle_pending_submissions($db, $contractId, false);
        $db->prepare("UPDATE contracts SET status = 'failed', escrowed_amount = 0 WHERE id = ?")->execute([$contractId]);
    }

    $db->commit();

    $otherUserId = $me['user_id'] === (int)$contract['client_user_id'] ? (int)$contract['freelancer_user_id'] : (int)$contract['client_user_id'];
    $label = $contract['parent_contract_id'] ? 'Этап' : 'Контракт';
    create_notification($db, $otherUserId, 'contract_closed',
        "$label закрыт как " . ($outcome === 'success' ? 'выполненный' : 'невыполненный'));

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown action']);
