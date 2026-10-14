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
require_once __DIR__ . '/../includes/brigades.php';

// Бригади (see includes/brigades.php). Freelancers only, except
// GET of_user — the public card a client sees when proposing a contract.
//   GET  mine | of_user (user_id) | contracts | allocations (contract_id)
//   POST create | update | invite | cancel_invite | respond_invite | set_role
//        | remove_member | leave | dissolve | set_allocations

$me = require_auth();
$db = get_db();
$uid = (int) $me['user_id'];

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}

function owned_brigade_or_fail(PDO $db, int $uid): array {
    $b = brigade_by_owner($db, $uid);
    if (!$b) fail(403, 'Ви не є бригадиром');
    return $b;
}

// Brigade contracts (top-level + stages) the caller may see as owner or member.
function brigade_contract_rows(PDO $db, int $brigadeId): array {
    $stmt = $db->prepare("
        SELECT c.id, c.parent_contract_id, c.seq, c.type, c.title, c.price, c.escrowed_amount, c.status, c.deadline, c.created_at,
               u.first_name AS client_first_name, u.last_name AS client_last_name
        FROM contracts c JOIN users u ON u.id = c.client_user_id
        WHERE c.brigade_id = ? AND c.status <> 'rejected'
        ORDER BY COALESCE(c.parent_contract_id, c.id) DESC, c.seq ASC
    ");
    $stmt->execute([$brigadeId]);
    return $stmt->fetchAll();
}

// ─── GET ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    // Public card: the brigade a given freelancer leads (null if none).
    if ($action === 'of_user') {
        $b = brigade_by_owner($db, (int) ($_GET['user_id'] ?? 0));
        echo json_encode(['brigade' => $b ? brigade_public_summary($db, $b) : null]);
        exit;
    }

    if ($me['role'] !== 'freelancer') fail(403, 'Бригади доступні лише фрилансерам');

    // My brigade (as owner or member) + invitations waiting for me.
    if ($action === 'mine') {
        $own = brigade_by_owner($db, $uid);
        $member = $own ? null : brigade_membership($db, $uid);
        $b = $own ?: $member;

        $out = ['brigade' => null, 'my_role' => null, 'invitations' => []];
        if ($b) {
            $stmt = $db->prepare('SELECT id, first_name, last_name, email, avatar_path FROM users WHERE id = ?');
            $stmt->execute([$b['owner_user_id']]);
            $owner = $stmt->fetch();

            $stmt = $db->prepare("
                SELECT u.id, u.first_name, u.last_name, u.email, u.avatar_path, bm.role, bm.status, bm.invited_at, bm.joined_at
                FROM brigade_members bm JOIN users u ON u.id = bm.user_id
                WHERE bm.brigade_id = ? " . ($own ? '' : "AND bm.status = 'active'") . "
                ORDER BY bm.status = 'invited', bm.joined_at, bm.invited_at
            ");
            $stmt->execute([$b['id']]);
            $members = array_map(fn($m) => [
                'id' => (int) $m['id'], 'name' => brigade_person_name($m),
                'email' => $own ? $m['email'] : null,   // e-mails only for the бригадир
                'avatar_path' => $m['avatar_path'], 'role' => $m['role'], 'status' => $m['status'],
                'invited_at' => $m['invited_at'], 'joined_at' => $m['joined_at'],
            ], $stmt->fetchAll());

            $out['brigade'] = [
                'id' => (int) $b['id'], 'name' => $b['name'], 'description' => $b['description'], 'created_at' => $b['created_at'],
                'owner' => ['id' => (int) $owner['id'], 'name' => brigade_person_name($owner), 'avatar_path' => $owner['avatar_path']],
                'members' => $members,
            ];
            $out['my_role'] = $own ? 'owner' : 'member';
        }
        $stmt = $db->prepare("
            SELECT b.id, b.name, b.description, bm.role, bm.invited_at, u.first_name, u.last_name, u.email
            FROM brigade_members bm JOIN brigades b ON b.id = bm.brigade_id JOIN users u ON u.id = b.owner_user_id
            WHERE bm.user_id = ? AND bm.status = 'invited' ORDER BY bm.invited_at DESC
        ");
        $stmt->execute([$uid]);
        $out['invitations'] = array_map(fn($i) => [
            'brigade_id' => (int) $i['id'], 'name' => $i['name'], 'description' => $i['description'],
            'role' => $i['role'], 'invited_at' => $i['invited_at'], 'owner_name' => brigade_person_name($i),
        ], $stmt->fetchAll());

        echo json_encode($out);
        exit;
    }

    // Orders of my brigade + my share in each (members see only their own share).
    if ($action === 'contracts') {
        $own = brigade_by_owner($db, $uid);
        $b = $own ?: brigade_membership($db, $uid);
        if (!$b) { echo json_encode([]); exit; }

        $rows = brigade_contract_rows($db, (int) $b['id']);
        $allocStmt = $db->prepare('SELECT user_id, amount FROM contract_allocations WHERE contract_id = ?');
        foreach ($rows as &$r) {
            $r['number'] = contract_number((int) $r['id']);
            $r['client_name'] = trim(($r['client_first_name'] ?? '') . ($r['client_last_name'] ? ' ' . mb_substr($r['client_last_name'], 0, 1) . '.' : '')) ?: 'Клієнт';
            unset($r['client_first_name'], $r['client_last_name']);
            $allocStmt->execute([$r['id']]);
            $alloc = $allocStmt->fetchAll();
            $allocated = array_sum(array_map(fn($a) => (float) $a['amount'], $alloc));
            if ($own) {
                $r['allocated_total'] = round($allocated, 2);
                $r['owner_share'] = round((float) $r['price'] - $allocated, 2);
            } else {
                $mine = array_values(array_filter($alloc, fn($a) => (int) $a['user_id'] === $uid));
                $r['my_share'] = $mine ? (float) $mine[0]['amount'] : 0.0;
            }
        }
        unset($r);
        echo json_encode($rows);
        exit;
    }

    // Розподіл оплати of one contract/stage (бригадир only).
    if ($action === 'allocations') {
        $b = owned_brigade_or_fail($db, $uid);
        $stmt = $db->prepare('SELECT * FROM contracts WHERE id = ? AND brigade_id = ? AND freelancer_user_id = ?');
        $stmt->execute([(int) ($_GET['contract_id'] ?? 0), $b['id'], $uid]);
        $c = $stmt->fetch();
        if (!$c) fail(404, 'Контракт бригади не знайдено');
        $stmt = $db->prepare('SELECT user_id, amount FROM contract_allocations WHERE contract_id = ?');
        $stmt->execute([$c['id']]);
        $alloc = [];
        foreach ($stmt->fetchAll() as $a) $alloc[(int) $a['user_id']] = (float) $a['amount'];
        $members = array_map(fn($m) => [
            'id' => (int) $m['id'], 'name' => brigade_person_name($m), 'role' => $m['role'], 'amount' => $alloc[(int) $m['id']] ?? 0,
        ], brigade_active_members($db, (int) $b['id']));
        // People allocated earlier who have since left — still shown so nothing is lost silently.
        $activeIds = array_column($members, 'id');
        foreach ($alloc as $userId => $amount) {
            if (in_array($userId, $activeIds, true)) continue;
            $stmt = $db->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $members[] = ['id' => $userId, 'name' => brigade_person_name($stmt->fetch() ?: []) . ' (вийшов з бригади)', 'role' => null, 'amount' => $amount, 'left' => true];
        }
        echo json_encode([
            'contract_id' => (int) $c['id'], 'price' => (float) $c['price'], 'status' => $c['status'],
            'editable' => in_array($c['status'], ['proposed', 'active', 'planned'], true) && $c['type'] !== 'staged',
            'members' => $members,
        ]);
        exit;
    }

    fail(400, 'Невідома дія');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'Метод не підтримується');
if ($me['role'] !== 'freelancer') fail(403, 'Бригади доступні лише фрилансерам');

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

function clean_brigade_name($v): string {
    $name = trim((string) $v);
    if ($name === '' || mb_strlen($name) > 150) fail(422, 'Назва бригади — від 1 до 150 символів');
    return $name;
}
function clean_brigade_description($v): ?string {
    $d = trim((string) ($v ?? ''));
    if (mb_strlen($d) > 2000) fail(422, 'Опис — не більше 2000 символів');
    return $d !== '' ? $d : null;
}

// ─── create: the caller becomes бригадир ────────────────────────────────────
if ($action === 'create') {
    if (brigade_by_owner($db, $uid)) fail(409, 'У вас уже є бригада');
    if (brigade_membership($db, $uid)) fail(409, 'Ви вже в бригаді — спершу вийдіть із неї');
    $name = clean_brigade_name($body['name'] ?? '');
    $desc = clean_brigade_description($body['description'] ?? null);
    $db->prepare('INSERT INTO brigades (owner_user_id, name, description) VALUES (?, ?, ?)')->execute([$uid, $name, $desc]);
    // Pending invitations elsewhere make no sense for a бригадир.
    $db->prepare("DELETE FROM brigade_members WHERE user_id = ? AND status = 'invited'")->execute([$uid]);
    http_response_code(201);
    echo json_encode(['id' => (int) $db->lastInsertId()]);
    exit;
}

// ─── respond_invite: the invitee accepts or declines ────────────────────────
if ($action === 'respond_invite') {
    $brigadeId = (int) ($body['brigade_id'] ?? 0);
    $accept = !empty($body['accept']);
    $stmt = $db->prepare("SELECT * FROM brigade_members WHERE brigade_id = ? AND user_id = ? AND status = 'invited'");
    $stmt->execute([$brigadeId, $uid]);
    if (!$stmt->fetch()) fail(404, 'Запрошення не знайдено');
    $b = brigade_by_id($db, $brigadeId);

    if (!$accept) {
        $db->prepare('DELETE FROM brigade_members WHERE brigade_id = ? AND user_id = ?')->execute([$brigadeId, $uid]);
        create_notification($db, (int) $b['owner_user_id'], 'brigade_invite_declined', 'Запрошення до бригади «' . $b['name'] . '» відхилено');
        echo json_encode(['success' => true]);
        exit;
    }
    if (brigade_by_owner($db, $uid)) fail(409, 'Ви бригадир власної бригади — спершу розпустіть її');
    if (brigade_membership($db, $uid)) fail(409, 'Ви вже в іншій бригаді — спершу вийдіть із неї');

    $db->prepare("UPDATE brigade_members SET status = 'active', joined_at = NOW() WHERE brigade_id = ? AND user_id = ?")->execute([$brigadeId, $uid]);
    // One brigade at a time — other invitations are dropped.
    $db->prepare("DELETE FROM brigade_members WHERE user_id = ? AND status = 'invited'")->execute([$uid]);
    $stmt = $db->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    create_notification($db, (int) $b['owner_user_id'], 'brigade_joined', brigade_person_name($stmt->fetch() ?: []) . ' приєднався до бригади «' . $b['name'] . '»');
    echo json_encode(['success' => true]);
    exit;
}

// ─── leave: a member leaves ─────────────────────────────────────────────────
if ($action === 'leave') {
    $b = brigade_membership($db, $uid);
    if (!$b) fail(404, 'Ви не в бригаді');
    $db->prepare('DELETE FROM brigade_members WHERE brigade_id = ? AND user_id = ?')->execute([$b['id'], $uid]);
    $stmt = $db->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
    $stmt->execute([$uid]);
    create_notification($db, (int) $b['owner_user_id'], 'brigade_left', brigade_person_name($stmt->fetch() ?: []) . ' вийшов з бригади «' . $b['name'] . '»');
    echo json_encode(['success' => true]);
    exit;
}

// ─── everything below: бригадир only ─────────────────────────────────────────
$brigade = owned_brigade_or_fail($db, $uid);
$bid = (int) $brigade['id'];

if ($action === 'update') {
    $db->prepare('UPDATE brigades SET name = ?, description = ? WHERE id = ?')
       ->execute([clean_brigade_name($body['name'] ?? ''), clean_brigade_description($body['description'] ?? null), $bid]);
    echo json_encode(['success' => true]);
    exit;
}

// Invite a freelancer by e-mail (or login).
if ($action === 'invite') {
    $email = trim((string) ($body['email'] ?? ''));
    $role  = mb_substr(trim((string) ($body['role'] ?? '')), 0, 100);
    if ($email === '') fail(422, 'Вкажіть email фрилансера');
    $stmt = $db->prepare('SELECT id, role, first_name, last_name, email FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u || $u['role'] !== 'freelancer') fail(404, 'Фрилансера з таким email не знайдено');
    if ((int) $u['id'] === $uid) fail(422, 'Ви вже бригадир цієї бригади');
    if (brigade_by_owner($db, (int) $u['id'])) fail(409, 'Цей фрилансер — бригадир іншої бригади');
    $m = brigade_membership($db, (int) $u['id']);
    if ($m) fail(409, (int) $m['id'] === $bid ? 'Він уже у вашій бригаді' : 'Цей фрилансер уже в іншій бригаді');

    $db->prepare("
        INSERT INTO brigade_members (brigade_id, user_id, role, status) VALUES (?, ?, ?, 'invited')
        ON DUPLICATE KEY UPDATE role = VALUES(role), invited_at = NOW()
    ")->execute([$bid, $u['id'], $role !== '' ? $role : null]);
    create_notification($db, (int) $u['id'], 'brigade_invite', 'Вас запросили до бригади «' . $brigade['name'] . '» — відповідь у кабінеті, вкладка «Бригада»');
    http_response_code(201);
    echo json_encode(['success' => true, 'name' => brigade_person_name($u)]);
    exit;
}

if ($action === 'cancel_invite') {
    $db->prepare("DELETE FROM brigade_members WHERE brigade_id = ? AND user_id = ? AND status = 'invited'")
       ->execute([$bid, (int) ($body['user_id'] ?? 0)]);
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'set_role') {
    $role = mb_substr(trim((string) ($body['role'] ?? '')), 0, 100);
    $stmt = $db->prepare('UPDATE brigade_members SET role = ? WHERE brigade_id = ? AND user_id = ?');
    $stmt->execute([$role !== '' ? $role : null, $bid, (int) ($body['user_id'] ?? 0)]);
    if ($stmt->rowCount() === 0) {
        $chk = $db->prepare('SELECT 1 FROM brigade_members WHERE brigade_id = ? AND user_id = ?');
        $chk->execute([$bid, (int) ($body['user_id'] ?? 0)]);
        if (!$chk->fetch()) fail(404, 'Учасника не знайдено');
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'remove_member') {
    $memberId = (int) ($body['user_id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM brigade_members WHERE brigade_id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$bid, $memberId]);
    if ($stmt->rowCount() === 0) fail(404, 'Учасника не знайдено');
    create_notification($db, $memberId, 'brigade_removed', 'Вас виключено з бригади «' . $brigade['name'] . '»');
    echo json_encode(['success' => true]);
    exit;
}

// Dissolve — only with no running brigade orders.
if ($action === 'dissolve') {
    $stmt = $db->prepare("SELECT COUNT(*) FROM contracts WHERE brigade_id = ? AND status IN ('proposed','active','planned')");
    $stmt->execute([$bid]);
    if ((int) $stmt->fetchColumn() > 0) fail(409, 'У бригади є незавершені замовлення — спершу завершіть їх');
    notify_brigade_members($db, $bid, 'brigade_dissolved', 'Бригаду «' . $brigade['name'] . '» розпущено');
    $db->prepare('DELETE FROM brigades WHERE id = ?')->execute([$bid]);
    echo json_encode(['success' => true]);
    exit;
}

// Розподіл оплати for one brigade contract/stage: [{user_id, amount}], sum ≤ price;
// the rest goes to the бригадир. Editable until the row is paid out/closed.
if ($action === 'set_allocations') {
    $stmt = $db->prepare('SELECT * FROM contracts WHERE id = ? AND brigade_id = ? AND freelancer_user_id = ?');
    $stmt->execute([(int) ($body['contract_id'] ?? 0), $bid, $uid]);
    $c = $stmt->fetch();
    if (!$c) fail(404, 'Контракт бригади не знайдено');
    if ($c['type'] === 'staged') fail(409, 'У поетапному контракті оплата розподіляється по кожному етапу');
    if (!in_array($c['status'], ['proposed', 'active', 'planned'], true)) fail(409, 'Контракт уже закрито — розподіл змінити не можна');

    $activeIds = array_map(fn($m) => (int) $m['id'], brigade_active_members($db, $bid));
    $stmt = $db->prepare('SELECT user_id FROM contract_allocations WHERE contract_id = ?');
    $stmt->execute([$c['id']]);
    $previousIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $clean = [];
    foreach ((array) ($body['allocations'] ?? []) as $a) {
        $memberId = (int) ($a['user_id'] ?? 0);
        $amount = is_numeric($a['amount'] ?? null) ? round((float) $a['amount'], 2) : 0;
        if ($amount < 0) fail(422, 'Сума не може бути від\'ємною');
        if ($amount == 0) continue;
        // Members who left keep an existing share, but can't get a new/bigger one.
        if (!in_array($memberId, $activeIds, true) && !in_array($memberId, $previousIds, true)) fail(422, 'Можна розподіляти лише між учасниками бригади');
        $clean[$memberId] = $amount;
    }
    $sum = array_sum($clean);
    if ($sum > (float) $c['price'] + 0.001) fail(422, 'Сума розподілу (' . $sum . ' ₴) більша за ціну контракту (' . (float) $c['price'] . ' ₴)');

    $db->beginTransaction();
    $db->prepare('DELETE FROM contract_allocations WHERE contract_id = ?')->execute([$c['id']]);
    $ins = $db->prepare('INSERT INTO contract_allocations (contract_id, user_id, amount) VALUES (?, ?, ?)');
    foreach ($clean as $memberId => $amount) $ins->execute([$c['id'], $memberId, $amount]);
    $db->commit();

    echo json_encode(['success' => true, 'allocated_total' => round($sum, 2), 'owner_share' => round((float) $c['price'] - $sum, 2)]);
    exit;
}

fail(400, 'Невідома дія');
