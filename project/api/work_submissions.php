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
require_once __DIR__ . '/../includes/uploads.php';

// Сдача-приёмка работы по полному контракту или этапу (не по поэтапному
// контракту целиком — там сдаётся каждый этап).
//   freelancer: submit (comment + files) — one pending submission at a time
//   client:     decide accept → same as close:success (escrow paid out,
//               contract/stage completed); reject → back to work, with a comment
// The plain "close" actions in contracts.php stay as they are — this is the
// normal path, close is the fallback.

$me = require_auth();
$db = get_db();

function submission_contract_or_404(PDO $db, int $contractId, int $userId): array {
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

function contract_label(array $c): string {
    return ($c['parent_contract_id'] ? 'етапу' : 'контракту') . ' #' . contract_number((int)$c['id']);
}

// ─── GET ?contract_id: submission history, newest first, with files ─────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $contractId = (int) ($_GET['contract_id'] ?? 0);
    submission_contract_or_404($db, $contractId, $me['user_id']);

    $stmt = $db->prepare('SELECT * FROM work_submissions WHERE contract_id = ? ORDER BY id DESC');
    $stmt->execute([$contractId]);
    $submissions = $stmt->fetchAll();

    $fileStmt = $db->prepare('SELECT id, file_path, original_name, mime_type, size FROM work_submission_files WHERE submission_id = ? ORDER BY id');
    foreach ($submissions as &$s) {
        $fileStmt->execute([$s['id']]);
        $s['files'] = $fileStmt->fetchAll();
    }
    unset($s);

    echo json_encode($submissions);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не підтримується']);
    exit;
}

// submit comes as multipart (files), decide as JSON
$isMultipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
$body   = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?? []);
$action = $body['action'] ?? '';

// ─── submit: freelancer hands the work over ─────────────────────────────────
if ($action === 'submit') {
    $contract = submission_contract_or_404($db, (int)($body['contract_id'] ?? 0), $me['user_id']);

    if ((int)$contract['freelancer_user_id'] !== $me['user_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Здати роботу може лише виконавець']);
        exit;
    }
    if ($contract['type'] === 'staged' && $contract['parent_contract_id'] === null) {
        http_response_code(409);
        echo json_encode(['error' => 'У поетапному контракті робота здається за кожним етапом']);
        exit;
    }
    if ($contract['status'] !== 'active') {
        http_response_code(409);
        echo json_encode(['error' => 'Здати роботу можна лише за активним контрактом']);
        exit;
    }

    $stmt = $db->prepare("SELECT id FROM work_submissions WHERE contract_id = ? AND status = 'submitted'");
    $stmt->execute([$contract['id']]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Роботу вже здано, вона чекає рішення клієнта']);
        exit;
    }

    $comment = trim((string)($body['comment'] ?? ''));
    if (mb_strlen($comment) > 5000) {
        http_response_code(422);
        echo json_encode(['error' => 'Коментар — не більше 5000 символів']);
        exit;
    }

    $saved = store_uploaded_files($_FILES['files'] ?? null, 'submissions');
    if ($comment === '' && !$saved) {
        http_response_code(422);
        echo json_encode(['error' => 'Додайте коментар або файли (' . UPLOAD_FORMATS_HINT . ')']);
        exit;
    }

    try {
        $db->beginTransaction();
        $db->prepare('INSERT INTO work_submissions (contract_id, freelancer_user_id, comment) VALUES (?, ?, ?)')
           ->execute([$contract['id'], $me['user_id'], $comment !== '' ? $comment : null]);
        $submissionId = (int) $db->lastInsertId();
        $ins = $db->prepare('INSERT INTO work_submission_files (submission_id, file_path, original_name, mime_type, size) VALUES (?, ?, ?, ?, ?)');
        foreach ($saved as $f) {
            $ins->execute([$submissionId, $f['file_path'], $f['original_name'], $f['mime_type'], $f['size']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        delete_stored_files($saved);
        throw $e;
    }

    create_notification($db, (int)$contract['client_user_id'], 'work_submitted',
        'Фрилансер здав роботу щодо ' . contract_label($contract) . ' — прийміть або поверніть на доопрацювання');

    http_response_code(201);
    echo json_encode(['id' => $submissionId, 'files_saved' => count($saved)]);
    exit;
}

// ─── decide: client accepts (= close as success) or returns for rework ──────
if ($action === 'decide') {
    $submissionId = (int)($body['submission_id'] ?? 0);
    $accept       = !empty($body['accept']);
    $clientNote   = trim((string)($body['comment'] ?? ''));

    $stmt = $db->prepare('SELECT * FROM work_submissions WHERE id = ?');
    $stmt->execute([$submissionId]);
    $submission = $stmt->fetch();
    if (!$submission) {
        http_response_code(404);
        echo json_encode(['error' => 'Здачу роботи не знайдено']);
        exit;
    }

    $contract = submission_contract_or_404($db, (int)$submission['contract_id'], $me['user_id']);
    if ((int)$contract['client_user_id'] !== $me['user_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'Прийняти роботу може лише клієнт']);
        exit;
    }
    if ($submission['status'] !== 'submitted') {
        http_response_code(409);
        echo json_encode(['error' => 'Щодо цієї здачі рішення вже ухвалено']);
        exit;
    }
    if ($contract['status'] !== 'active') {
        http_response_code(409);
        echo json_encode(['error' => 'Контракт уже не активний']);
        exit;
    }
    if (!$accept && $clientNote === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Напишіть, що потрібно доопрацювати']);
        exit;
    }
    if (mb_strlen($clientNote) > 5000) {
        http_response_code(422);
        echo json_encode(['error' => 'Коментар — не більше 5000 символів']);
        exit;
    }

    $db->beginTransaction();
    $db->prepare('UPDATE work_submissions SET status = ?, client_comment = ?, decided_at = NOW() WHERE id = ?')
       ->execute([$accept ? 'accepted' : 'rejected', $clientNote !== '' ? $clientNote : null, $submissionId]);
    if ($accept) {
        // Same as contracts.php close:success for a full contract / stage.
        pay_freelancer($db, $contract);
        $db->prepare("UPDATE contracts SET status = 'completed', escrowed_amount = 0 WHERE id = ?")
           ->execute([$contract['id']]);
    }
    $db->commit();

    create_notification($db, (int)$contract['freelancer_user_id'],
        $accept ? 'work_accepted' : 'work_rejected',
        $accept
            ? 'Клієнт прийняв роботу щодо ' . contract_label($contract) . ' — оплату переведено на баланс'
            : 'Клієнт повернув роботу щодо ' . contract_label($contract) . ' на доопрацювання');

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Невідома дія']);
