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

$me = require_admin();
$db = get_db();

// ─── GET: users list / verification requests / document stream ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'users') {
        $stmt = $db->query('
            SELECT id, email, role, status, first_name, last_name, verification_status, created_at
            FROM users
            ORDER BY created_at DESC
        ');
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($action === 'verification_requests') {
        $stmt = $db->query("
            SELECT id, email, role, first_name, last_name, verification_doc_path
            FROM users
            WHERE verification_status = 'pending'
            ORDER BY id DESC
        ");
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($action === 'withdrawal_requests') {
        $stmt = $db->query("
            SELECT wr.id, wr.amount, wr.created_at,
                   u.id AS user_id, u.email, u.first_name, u.last_name
            FROM withdrawal_requests wr
            JOIN users u ON u.id = wr.user_id
            WHERE wr.status = 'pending'
            ORDER BY wr.created_at ASC
        ");
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($action === 'disputes') {
        $stmt = $db->query("
            SELECT bh.id AS hold_id, bh.contract_id, bh.amount, bh.release_at, bh.disputed_at,
                   cu.first_name AS client_first_name, cu.last_name AS client_last_name,
                   fu.first_name AS freelancer_first_name, fu.last_name AS freelancer_last_name
            FROM balance_holds bh
            JOIN contracts c ON c.id = bh.contract_id
            JOIN users cu ON cu.id = c.client_user_id
            JOIN users fu ON fu.id = c.freelancer_user_id
            WHERE bh.resolution = 'pending' AND bh.disputed_at IS NOT NULL
            ORDER BY bh.disputed_at ASC
        ");
        $disputes = $stmt->fetchAll();
        foreach ($disputes as &$d) {
            $d['contract_number'] = contract_number((int) $d['contract_id']);
        }
        unset($d);
        echo json_encode($disputes);
        exit;
    }

    // «Вызов администратора» — open escalations awaiting a decision.
    if ($action === 'escalations') {
        $stmt = $db->query("
            SELECT ae.*, c.price, c.status AS contract_status, c.parent_contract_id,
                   cu.first_name AS client_first_name, cu.last_name AS client_last_name,
                   fu.first_name AS freelancer_first_name, fu.last_name AS freelancer_last_name
            FROM admin_escalations ae
            JOIN contracts c ON c.id = ae.contract_id
            JOIN users cu ON cu.id = c.client_user_id
            JOIN users fu ON fu.id = c.freelancer_user_id
            WHERE ae.status = 'open'
            ORDER BY ae.created_at ASC
        ");
        $escalations = $stmt->fetchAll();
        foreach ($escalations as &$e) {
            $e['contract_number'] = contract_number((int) $e['contract_id']);
        }
        unset($e);
        echo json_encode($escalations);
        exit;
    }

    // Full picture for one escalation: the contract/stage it's about, the
    // whole message history of that conversation, and its estimate history —
    // everything an admin needs to judge the conflict without joining the chat.
    if ($action === 'escalation_detail') {
        $id = (int) ($_GET['id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM admin_escalations WHERE id = ?');
        $stmt->execute([$id]);
        $escalation = $stmt->fetch();
        if (!$escalation) {
            http_response_code(404);
            echo json_encode(['error' => 'Обращение не найдено']);
            exit;
        }

        $stmt = $db->prepare('SELECT * FROM contracts WHERE id = ?');
        $stmt->execute([$escalation['contract_id']]);
        $contract = $stmt->fetch();
        if (!$contract) {
            http_response_code(404);
            echo json_encode(['error' => 'Контракт не найден']);
            exit;
        }
        $contract['number'] = contract_number((int) $contract['id']);
        if ($contract['parent_contract_id']) {
            $contract['parent_number'] = contract_number((int) $contract['parent_contract_id']);
        }

        $stmt = $db->prepare('SELECT id, first_name, last_name, email FROM users WHERE id = ?');
        $stmt->execute([$contract['client_user_id']]);
        $contract['client'] = $stmt->fetch() ?: null;
        $stmt->execute([$contract['freelancer_user_id']]);
        $contract['freelancer'] = $stmt->fetch() ?: null;

        $stmt = $db->prepare('
            SELECT m.id, m.sender_id, m.body, m.created_at, u.first_name, u.last_name
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            WHERE m.conversation_id = ?
            ORDER BY m.id ASC
        ');
        $stmt->execute([$contract['conversation_id']]);
        $messages = $stmt->fetchAll();

        $stmt = $db->prepare('SELECT * FROM contract_estimates WHERE contract_id = ? ORDER BY id DESC');
        $stmt->execute([$escalation['contract_id']]);
        $estimates = $stmt->fetchAll();
        foreach ($estimates as &$e) {
            $e['items'] = json_decode($e['items'], true) ?: [];
        }
        unset($e);

        $stmt = $db->prepare('SELECT first_name, last_name, role FROM users WHERE id = ?');
        $stmt->execute([$escalation['raised_by_user_id']]);
        $escalation['raised_by'] = $stmt->fetch() ?: null;

        echo json_encode([
            'escalation' => $escalation,
            'contract'   => $contract,
            'messages'   => $messages,
            'estimates'  => $estimates,
        ]);
        exit;
    }

    if ($action === 'document') {
        $userId = (int)($_GET['user_id'] ?? 0);
        $stmt = $db->prepare('SELECT verification_doc_path FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $path = $stmt->fetchColumn();

        if (!$path) {
            http_response_code(404);
            echo json_encode(['error' => 'Document not found']);
            exit;
        }

        $fullPath = __DIR__ . '/../' . $path;
        if (!is_file($fullPath)) {
            http_response_code(404);
            echo json_encode(['error' => 'Document not found']);
            exit;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $fullPath);
        finfo_close($finfo);

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
        readfile($fullPath);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ─── POST: approve / reject verification, or a withdrawal request ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    if ($action === 'resolve_dispute') {
        $holdId     = (int)($body['hold_id'] ?? 0);
        $resolution = $body['resolution'] ?? '';

        if (!$holdId || !in_array($resolution, ['to_client', 'to_freelancer'], true)) {
            http_response_code(422);
            echo json_encode(['error' => 'hold_id и resolution (to_client|to_freelancer) обязательны']);
            exit;
        }

        $stmt = $db->prepare("
            SELECT bh.*, c.client_user_id, c.freelancer_user_id
            FROM balance_holds bh
            JOIN contracts c ON c.id = bh.contract_id
            WHERE bh.id = ?
        ");
        $stmt->execute([$holdId]);
        $hold = $stmt->fetch();

        if (!$hold) {
            http_response_code(404);
            echo json_encode(['error' => 'Заморозка не найдена']);
            exit;
        }
        if ($hold['resolution'] !== 'pending') {
            http_response_code(409);
            echo json_encode(['error' => 'Спор уже решён']);
            exit;
        }

        $db->beginTransaction();

        if ($resolution === 'to_freelancer') {
            $db->prepare('UPDATE client_profiles SET balance = balance - ? WHERE user_id = ?')
               ->execute([$hold['amount'], $hold['client_user_id']]);
            $db->prepare('UPDATE freelancer_profiles SET balance = balance + ? WHERE user_id = ?')
               ->execute([$hold['amount'], $hold['freelancer_user_id']]);
            $db->prepare("
                INSERT INTO wallet_transactions (user_id, type, amount, description, related_contract_id)
                VALUES (?, 'contract_payment', ?, 'По решению спора', ?)
            ")->execute([$hold['client_user_id'], $hold['amount'], $hold['contract_id']]);
            $db->prepare("
                INSERT INTO wallet_transactions (user_id, type, amount, description, related_contract_id)
                VALUES (?, 'contract_earning', ?, 'По решению спора', ?)
            ")->execute([$hold['freelancer_user_id'], $hold['amount'], $hold['contract_id']]);
        }

        $db->prepare("UPDATE balance_holds SET resolution = ? WHERE id = ?")
           ->execute([$resolution, $holdId]);

        $db->commit();

        create_notification(
            $db,
            $resolution === 'to_freelancer' ? (int)$hold['freelancer_user_id'] : (int)$hold['client_user_id'],
            'dispute_resolved',
            'Спор по контракту #' . contract_number((int)$hold['contract_id']) . ' решён в вашу пользу'
        );
        create_notification(
            $db,
            $resolution === 'to_freelancer' ? (int)$hold['client_user_id'] : (int)$hold['freelancer_user_id'],
            'dispute_resolved',
            'Спор по контракту #' . contract_number((int)$hold['contract_id']) . ' решён не в вашу пользу'
        );

        echo json_encode(['success' => true]);
        exit;
    }

    // «Вызов администратора» resolution — the actual lever for conflicts:
    // admin can force-change terms, force-close either way, or freeze pending
    // investigation, bypassing the normal both-sides-agree flow entirely.
    // Both parties are notified either way.
    if ($action === 'resolve_escalation') {
        $id         = (int) ($body['id'] ?? 0);
        $resolution = $body['resolution'] ?? '';
        $note       = trim($body['note'] ?? '');

        if (!in_array($resolution, ['terms_changed', 'closed_success', 'closed_failure', 'frozen'], true)) {
            http_response_code(422);
            echo json_encode(['error' => 'Некорректный тип решения']);
            exit;
        }

        $stmt = $db->prepare('SELECT * FROM admin_escalations WHERE id = ?');
        $stmt->execute([$id]);
        $escalation = $stmt->fetch();
        if (!$escalation) {
            http_response_code(404);
            echo json_encode(['error' => 'Обращение не найдено']);
            exit;
        }
        if ($escalation['status'] !== 'open') {
            http_response_code(409);
            echo json_encode(['error' => 'Обращение уже рассмотрено']);
            exit;
        }

        $stmt = $db->prepare('SELECT * FROM contracts WHERE id = ?');
        $stmt->execute([$escalation['contract_id']]);
        $contract = $stmt->fetch();
        if (!$contract) {
            http_response_code(404);
            echo json_encode(['error' => 'Контракт не найден']);
            exit;
        }
        if ($contract['status'] !== 'active') {
            http_response_code(409);
            echo json_encode(['error' => 'Контракт уже не активен — решение больше не применимо']);
            exit;
        }

        $noun    = $contract['parent_contract_id'] ? 'этапу' : 'контракту';
        $number  = contract_number((int) $contract['id']);
        $message = null;

        if ($resolution === 'terms_changed') {
            $newPrice    = isset($body['new_price']) && is_numeric($body['new_price']) ? (float) $body['new_price'] : null;
            $newDeadline = array_key_exists('new_deadline', $body) && $body['new_deadline'] !== '' ? $body['new_deadline'] : null;
            if ($newPrice === null && $newDeadline === null) {
                http_response_code(422);
                echo json_encode(['error' => 'Укажите новую цену и/или срок']);
                exit;
            }

            $fields = [];
            $params = [];
            if ($newPrice !== null)    { $fields[] = 'price = ?';    $params[] = $newPrice; }
            if ($newDeadline !== null) { $fields[] = 'deadline = ?'; $params[] = $newDeadline; }
            $fields[] = 'frozen_at = NULL';
            $params[] = $contract['id'];
            $db->prepare('UPDATE contracts SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

            $parts = [];
            if ($newPrice !== null)    $parts[] = 'цена → ' . $newPrice . ' ₴';
            if ($newDeadline !== null) $parts[] = 'срок → ' . $newDeadline;
            $message = "Администрация изменила условия $noun #$number: " . implode(', ', $parts) . '.';
        } elseif ($resolution === 'closed_success' || $resolution === 'closed_failure') {
            $db->beginTransaction();
            if ($resolution === 'closed_success') {
                pay_freelancer($db, $contract);
                $db->prepare("UPDATE contracts SET status = 'completed', escrowed_amount = 0, frozen_at = NULL WHERE id = ?")
                   ->execute([$contract['id']]);
                $message = "Администрация закрыла $noun #$number как выполненный, оплата переведена фрилансеру.";
            } else {
                refund_with_hold($db, $contract);
                $db->prepare("UPDATE contracts SET status = 'failed', escrowed_amount = 0, frozen_at = NULL WHERE id = ?")
                   ->execute([$contract['id']]);
                $message = "Администрация закрыла $noun #$number как невыполненный, депозит возвращён клиенту (с холдом на " . CONTRACT_HOLD_DAYS . ' дней).';
            }
            $db->commit();
        } else { // frozen — stub: no ticket/investigation system yet, just the flag + notice
            $db->prepare('UPDATE contracts SET frozen_at = NOW() WHERE id = ?')->execute([$contract['id']]);
            $message = "Администрация заморозила $noun #$number до выяснения обстоятельств. Открыт тикет в поддержку, будет проведено внутрибиржевое расследование.";
        }

        $db->prepare("
            UPDATE admin_escalations
            SET status = 'resolved', resolution = ?, resolution_note = ?, resolved_by_user_id = ?, resolved_at = NOW()
            WHERE id = ?
        ")->execute([$resolution, $note ?: null, $me['user_id'], $id]);

        create_notification($db, (int) $contract['client_user_id'], 'escalation_resolved', $message);
        create_notification($db, (int) $contract['freelancer_user_id'], 'escalation_resolved', $message);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'approve_withdrawal' || $action === 'reject_withdrawal') {
        $requestId = (int)($body['request_id'] ?? 0);
        if (!$requestId) {
            http_response_code(422);
            echo json_encode(['error' => 'request_id is required']);
            exit;
        }

        $stmt = $db->prepare('SELECT user_id, amount, status FROM withdrawal_requests WHERE id = ?');
        $stmt->execute([$requestId]);
        $wr = $stmt->fetch();

        if (!$wr) {
            http_response_code(404);
            echo json_encode(['error' => 'Заявка не найдена']);
            exit;
        }
        if ($wr['status'] !== 'pending') {
            http_response_code(409);
            echo json_encode(['error' => 'Заявка уже обработана']);
            exit;
        }

        if ($action === 'approve_withdrawal') {
            // The amount was already deducted from the balance when the request
            // was created (see wallet.php) — approving just finalizes it and
            // logs it so it shows up in the freelancer's financial report.
            $db->prepare("UPDATE withdrawal_requests SET status = 'approved', decided_at = NOW() WHERE id = ?")
               ->execute([$requestId]);
            $db->prepare("INSERT INTO wallet_transactions (user_id, type, amount, description) VALUES (?, 'withdrawal', ?, 'Вывод средств')")
               ->execute([$wr['user_id'], $wr['amount']]);
            create_notification($db, $wr['user_id'], 'withdrawal_approved', "Заявка на вывод {$wr['amount']} ₴ одобрена.");
        } else {
            $db->prepare("UPDATE withdrawal_requests SET status = 'rejected', decided_at = NOW() WHERE id = ?")
               ->execute([$requestId]);
            $db->prepare('UPDATE freelancer_profiles SET balance = balance + ? WHERE user_id = ?')
               ->execute([$wr['amount'], $wr['user_id']]);
            create_notification($db, $wr['user_id'], 'withdrawal_rejected', "Заявка на вывод {$wr['amount']} ₴ отклонена, средства возвращены на баланс.");
        }

        echo json_encode(['success' => true]);
        exit;
    }

    $userId = (int)($body['user_id'] ?? 0);

    if (!$userId) {
        http_response_code(422);
        echo json_encode(['error' => 'user_id is required']);
        exit;
    }

    $stmt = $db->prepare('SELECT verification_status FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $status = $stmt->fetchColumn();

    if ($status === false) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }
    if ($status !== 'pending') {
        http_response_code(409);
        echo json_encode(['error' => 'У пользователя нет заявки на рассмотрении']);
        exit;
    }

    if ($action === 'approve') {
        $db->prepare("UPDATE users SET verification_status = 'verified', verified_at = NOW() WHERE id = ?")
           ->execute([$userId]);
        create_notification($db, $userId, 'verification_approved', 'Ваш профиль верифицирован!');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'reject') {
        $db->prepare("UPDATE users SET verification_status = 'rejected', verified_at = NULL WHERE id = ?")
           ->execute([$userId]);
        create_notification($db, $userId, 'verification_rejected', 'Ваша заявка на верификацию была отклонена.');
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
