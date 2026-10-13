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
require_once __DIR__ . '/../includes/staged.php';
require_once __DIR__ . '/../includes/estimate_document.php';

const FREELANCER_CLOSE_SILENCE_DAYS = 14;

$me = require_auth();
$db = get_db();

function contract_or_404(PDO $db, int $id, int $userId): array {
    $stmt = $db->prepare('SELECT * FROM contracts WHERE id = ?');
    $stmt->execute([$id]);
    $c = $stmt->fetch();
    if (!$c || ((int)$c['client_user_id'] !== $userId && (int)$c['freelancer_user_id'] !== $userId)) {
        http_response_code(404);
        echo json_encode(['error' => 'Контракт не знайдено']);
        exit;
    }
    return $c;
}

// ─── GET: my contracts, optionally filtered by status or conversation ────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!in_array($me['role'], ['client', 'freelancer'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Контракти доступні лише клієнтам і фрилансерам']);
        exit;
    }

    $roleCol  = $me['role'] === 'client' ? 'client_user_id' : 'freelancer_user_id';
    $otherCol = $me['role'] === 'client' ? 'freelancer_user_id' : 'client_user_id';

    // Only top-level contracts here; stages (parent_contract_id set) are nested
    // into their parent below. last_message_* powers the freelancer's
    // unilateral-close gate on the contracts page (same rule as close()).
    $sql = "
        SELECT c.*, u.first_name AS other_first_name, u.last_name AS other_last_name, u.avatar_path AS other_avatar_path,
               (SELECT o.title FROM offers o WHERE o.id = c.offer_id) AS offer_title,
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

        // Deposit gate for the UI (escrow.php enforces it): only active rows
        // with an estimate covering both «Роботи» and «Матеріали».
        if ($c['type'] !== 'staged' && $c['status'] === 'active') {
            $c['deposit_block_reason'] = deposit_block_reason($db, (int) $c['id']);
            $c['deposit_ready'] = $c['deposit_block_reason'] === null;
        }

        if ($c['type'] === 'staged') {
            $stageStmt->execute([$c['id']]);
            $stages = $stageStmt->fetchAll();
            foreach ($stages as &$s) {
                $s['number'] = contract_number((int) $s['id']);
                $s['parent_number'] = $c['number'];
                if ($s['status'] === 'active') {
                    $s['deposit_block_reason'] = deposit_block_reason($db, (int) $s['id']);
                    $s['deposit_ready'] = $s['deposit_block_reason'] === null;
                }
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
    echo json_encode(['error' => 'Метод не підтримується']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ─── propose: client proposes a full contract, or a staged one with stage 1 ──
if ($action === 'propose') {
    if ($me['role'] !== 'client') {
        http_response_code(403);
        echo json_encode(['error' => 'Лише клієнт може запропонувати контракт']);
        exit;
    }

    $conversationId = (int) ($body['conversation_id'] ?? 0);
    $type           = ($body['type'] ?? 'full') === 'staged' ? 'staged' : 'full';
    $price          = is_numeric($body['price'] ?? '') ? (float) $body['price'] : 0;
    $deadline       = validate_deadline($body['deadline'] ?? null);
    $offerId        = !empty($body['offer_id']) ? (int) $body['offer_id'] : null;
    $stageTitle     = trim($body['stage_title'] ?? '');
    // Staged only: 'incremental' — stage 1 now, the rest one by one later;
    // 'planned' — the whole plan of stages up front ($body['stages']).
    $stageMode      = $type === 'staged' && ($body['stage_mode'] ?? '') === 'planned' ? 'planned' : ($type === 'staged' ? 'incremental' : null);

    $plan = [];   // [['title','price','deadline'], ...] for planned mode
    if ($stageMode === 'planned') {
        $rawStages = is_array($body['stages'] ?? null) ? array_values($body['stages']) : [];
        if (count($rawStages) < PLANNED_STAGES_MIN || count($rawStages) > PLANNED_STAGES_MAX) {
            http_response_code(422);
            echo json_encode(['error' => 'У плані має бути від ' . PLANNED_STAGES_MIN . ' до ' . PLANNED_STAGES_MAX . ' етапів']);
            exit;
        }
        $prevDeadline = null;
        foreach ($rawStages as $i => $s) {
            $n       = $i + 1;
            $sTitle  = mb_substr(trim((string)($s['title'] ?? '')), 0, 255);
            $sPrice  = is_numeric($s['price'] ?? '') ? (float) $s['price'] : 0;
            $sDead   = validate_deadline($s['deadline'] ?? null, "Термін етапу $n");
            if ($sTitle === '' || $sPrice <= 0) {
                http_response_code(422);
                echo json_encode(['error' => "Етап $n: вкажіть назву та ціну"]);
                exit;
            }
            if ($sDead !== null && $prevDeadline !== null && $sDead < $prevDeadline) {
                http_response_code(422);
                echo json_encode(['error' => "Термін етапу $n не може бути раніше за термін попереднього етапу"]);
                exit;
            }
            if ($sDead !== null) $prevDeadline = $sDead;
            $plan[] = ['title' => $sTitle, 'price' => $sPrice, 'deadline' => $sDead];
        }
        // Stage 1 of the plan is what the freelancer is asked to accept first.
        $price      = $plan[0]['price'];
        $deadline   = $plan[0]['deadline'];
        $stageTitle = $plan[0]['title'];
    }

    if ($price <= 0) {
        http_response_code(422);
        echo json_encode(['error' => 'Вкажіть ціну' . ($type === 'staged' ? ' першого етапу' : ' контракту')]);
        exit;
    }
    if ($type === 'staged' && $stageTitle === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Вкажіть назву першого етапу']);
        exit;
    }

    $stmt = $db->prepare('SELECT * FROM conversations WHERE id = ? AND client_user_id = ?');
    $stmt->execute([$conversationId, $me['user_id']]);
    $conv = $stmt->fetch();
    if (!$conv) {
        http_response_code(404);
        echo json_encode(['error' => 'Переписку не знайдено']);
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

    // Any number of contracts can run in parallel between the same client and
    // freelancer (one conversation). Only a duplicate for the SAME offer is
    // refused — two open contracts for one offer is always a mistake.
    if ($offerId !== null) {
        $stmt = $db->prepare("SELECT id FROM contracts WHERE conversation_id = ? AND offer_id = ? AND parent_contract_id IS NULL AND status IN ('proposed','active')");
        $stmt->execute([$conversationId, $offerId]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'За цим офером у цій переписці вже є запропонований або активний контракт']);
            exit;
        }
    }

    // Optional name — tells parallel contracts apart in the chat and lists.
    $title = mb_substr(trim((string)($body['title'] ?? '')), 0, 150);
    if ($title === '') $title = null;

    $freelancerId = (int) $conv['freelancer_user_id'];

    if ($type === 'full') {
        $stmt = $db->prepare('
            INSERT INTO contracts (conversation_id, client_user_id, freelancer_user_id, offer_id, type, title, price, deadline, status)
            VALUES (?, ?, ?, ?, "full", ?, ?, ?, "proposed")
        ');
        $stmt->execute([$conversationId, $me['user_id'], $freelancerId, $offerId, $title, $price, $deadline]);
        $contractId = (int) $db->lastInsertId();
    } else {
        $db->beginTransaction();
        // Parent container — no price/escrow of its own; proposed until stage 1 is accepted.
        $db->prepare('
            INSERT INTO contracts (conversation_id, client_user_id, freelancer_user_id, offer_id, type, stage_mode, price, deadline, status, title)
            VALUES (?, ?, ?, ?, "staged", ?, 0, NULL, "proposed", ?)
        ')->execute([$conversationId, $me['user_id'], $freelancerId, $offerId, $stageMode, $title]);
        $contractId = (int) $db->lastInsertId();
        // Stage 1 — proposed. In planned mode stages 2..N go in right away as
        // 'planned': accepting stage 1 = agreeing to the whole plan.
        $insStage = $db->prepare('
            INSERT INTO contracts (conversation_id, client_user_id, freelancer_user_id, parent_contract_id, seq, type, title, price, deadline, status)
            VALUES (?, ?, ?, ?, ?, "full", ?, ?, ?, ?)
        ');
        $insStage->execute([$conversationId, $me['user_id'], $freelancerId, $contractId, 1, $stageTitle, $price, $deadline, 'proposed']);
        foreach (array_slice($plan, 1) as $i => $s) {
            $insStage->execute([$conversationId, $me['user_id'], $freelancerId, $contractId, $i + 2, $s['title'], $s['price'], $s['deadline'], 'planned']);
        }
        $db->commit();
    }

    create_notification($db, $freelancerId, 'contract_proposed',
        ($type === 'staged'
            ? ($stageMode === 'planned' ? 'Вам запропонували поетапний контракт із планом (етапів: ' . count($plan) . ')' : 'Вам запропонували поетапний контракт')
            : 'Вам запропонували контракт')
        . ' #' . contract_number($contractId) . ($title !== null ? " «{$title}»" : ''));

    http_response_code(201);
    echo json_encode(['id' => $contractId]);
    exit;
}

// ─── add_stage: client forms the next stage of a staged contract ─────────────
if ($action === 'add_stage') {
    if ($me['role'] !== 'client') {
        http_response_code(403);
        echo json_encode(['error' => 'Лише клієнт може сформувати етап']);
        exit;
    }

    $parentId = (int) ($body['contract_id'] ?? 0);
    $parent   = contract_or_404($db, $parentId, $me['user_id']);

    if ($parent['type'] !== 'staged' || $parent['parent_contract_id'] !== null) {
        http_response_code(409);
        echo json_encode(['error' => 'Етапи можна додавати лише в поетапний контракт']);
        exit;
    }
    if ($parent['status'] !== 'active') {
        http_response_code(409);
        echo json_encode(['error' => 'Контракт не активний']);
        exit;
    }

    $title    = trim($body['title'] ?? '');
    $price    = is_numeric($body['price'] ?? '') ? (float) $body['price'] : 0;
    $deadline = validate_deadline($body['deadline'] ?? null, 'Термін етапу');

    if ($title === '' || $price <= 0) {
        http_response_code(422);
        echo json_encode(['error' => 'Вкажіть назву та ціну етапу']);
        exit;
    }

    // Next stage only after the previous one is done.
    $stmt = $db->prepare('SELECT status, seq FROM contracts WHERE parent_contract_id = ? ORDER BY seq DESC LIMIT 1');
    $stmt->execute([$parentId]);
    $last = $stmt->fetch();
    if ($last && $last['status'] === 'planned') {
        http_response_code(409);
        echo json_encode(['error' => 'У плані ще є етапи — вони стартують по черзі. Додати новий можна після останнього етапу плану']);
        exit;
    }
    if ($last && !in_array($last['status'], ['completed', 'failed', 'rejected'], true)) {
        http_response_code(409);
        echo json_encode(['error' => 'Спершу завершіть поточний етап']);
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
        'Вам запропонували етап ' . $nextSeq . ' за контрактом #' . contract_number($parentId));

    http_response_code(201);
    echo json_encode(['id' => $stageId]);
    exit;
}

// ─── start_next_stage: client starts the next stage of the plan by hand ─────
// Normally the next planned stage starts by itself after the previous one is
// completed (staged.php after_stage_completed). After a FAILED stage the
// client decides: carry on with the plan (this) or close the whole contract.
if ($action === 'start_next_stage') {
    if ($me['role'] !== 'client') {
        http_response_code(403);
        echo json_encode(['error' => 'Розпочати етап може лише клієнт']);
        exit;
    }
    $parentId = (int) ($body['contract_id'] ?? 0);
    $parent   = contract_or_404($db, $parentId, $me['user_id']);
    if (!is_planned_parent($parent) || $parent['status'] !== 'active') {
        http_response_code(409);
        echo json_encode(['error' => 'Це не активний поетапний контракт із планом']);
        exit;
    }
    $started = activate_next_planned_stage($db, $parentId);
    if (!$started) {
        http_response_code(409);
        echo json_encode(['error' => 'Немає етапу, який можна розпочати: або поточний етап ще триває, або план завершено']);
        exit;
    }
    echo json_encode(['success' => true, 'stage_id' => (int) $started['id']]);
    exit;
}

// ─── accept / reject: freelancer decides on a proposed contract or stage ─────
if ($action === 'accept' || $action === 'reject') {
    if ($me['role'] !== 'freelancer') {
        http_response_code(403);
        echo json_encode(['error' => 'Лише фрилансер може підтвердити або відхилити контракт']);
        exit;
    }

    $contractId = (int) ($body['contract_id'] ?? 0);
    $contract   = contract_or_404($db, $contractId, $me['user_id']);

    if ((int)$contract['freelancer_user_id'] !== $me['user_id'] || $contract['status'] !== 'proposed') {
        http_response_code(409);
        echo json_encode(['error' => 'Контракт не можна підтвердити або відхилити в поточному статусі']);
        exit;
    }

    $newStatus = $action === 'accept' ? 'active' : 'rejected';
    $db->prepare('UPDATE contracts SET status = ? WHERE id = ?')->execute([$newStatus, $contractId]);

    // A stage accept/reject can settle the parent's proposed state, and the
    // originating offer is carried on the parent (or on a plain contract).
    $offerToBadge = null;
    if ($contract['parent_contract_id']) {
        $parentId = (int) $contract['parent_contract_id'];
        $stmt = $db->prepare('SELECT * FROM contracts WHERE id = ?');
        $stmt->execute([$parentId]);
        $parent = $stmt->fetch();
        if ($parent && $parent['status'] === 'proposed') {
            if ($action === 'accept') {
                // In planned mode this also seals the whole plan: stages 2..N stay 'planned'.
                $db->prepare("UPDATE contracts SET status = 'active' WHERE id = ?")->execute([$parentId]);
                $offerToBadge = $parent['offer_id'];
            } elseif (is_planned_parent($parent)) {
                // Rejecting stage 1 of a plan = rejecting the whole plan.
                $db->prepare("UPDATE contracts SET status = 'rejected' WHERE parent_contract_id = ? AND status = 'planned'")->execute([$parentId]);
                $db->prepare("UPDATE contracts SET status = 'rejected' WHERE id = ?")->execute([$parentId]);
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

    $label = $contract['parent_contract_id'] ? 'етап' : 'контракт';
    create_notification(
        $db,
        (int)$contract['client_user_id'],
        $action === 'accept' ? 'contract_accepted' : 'contract_rejected',
        $action === 'accept' ? "Фрилансер підтвердив $label" : "Фрилансер відхилив $label"
    );

    echo json_encode(['success' => true, 'status' => $newStatus]);
    exit;
}

// ─── close: successful or failed, asymmetric rights ─────────────────────────
if ($action === 'close') {
    if (!in_array($me['role'], ['client', 'freelancer'], true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Дія недоступна для вашої ролі']);
        exit;
    }

    $contractId = (int) ($body['contract_id'] ?? 0);
    $outcome    = $body['outcome'] ?? '';
    if (!in_array($outcome, ['success', 'failure'], true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Результат: success або failure']);
        exit;
    }

    $contract = contract_or_404($db, $contractId, $me['user_id']);
    if ($contract['status'] !== 'active') {
        http_response_code(409);
        echo json_encode(['error' => 'Контракт не активний']);
        exit;
    }

    if ($me['role'] === 'client') {
        if ((int)$contract['client_user_id'] !== $me['user_id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Це не ваш контракт']);
            exit;
        }
    } else {
        if ((int)$contract['freelancer_user_id'] !== $me['user_id']) {
            http_response_code(403);
            echo json_encode(['error' => 'Це не ваш контракт']);
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
            echo json_encode(['error' => 'Закрити можна, лише якщо клієнт не відповідає на ваше останнє повідомлення ' . FREELANCER_CLOSE_SILENCE_DAYS . ' днів']);
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
            if ($stage['status'] === 'proposed' || $stage['status'] === 'planned') {
                // not started yet (incl. the rest of a plan) → simply cancelled
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
    $label = $contract['parent_contract_id'] ? 'Етап' : 'Контракт';
    create_notification($db, $otherUserId, 'contract_closed',
        "$label закрито як " . ($outcome === 'success' ? 'виконаний' : 'невиконаний'));

    // A stage of a planned contract done → the next stage of the plan starts.
    if (!$isStagedParent && $outcome === 'success') after_stage_completed($db, $contract);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Невідома дія']);
