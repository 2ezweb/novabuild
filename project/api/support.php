<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';
require_once __DIR__ . '/../includes/notifications.php';

// Support widget («Звернутися до підтримки», chat.html → support-widget.js).
// Only «Виклик адміністратора» is real for now: it opens a TEMPORARY chat
// between the user and the admins that lives SUPPORT_TICKET_TTL_DAYS. All
// admins are alerted «ТЕРМІНОВА ЗАЯВКА №ДДММРР-ГГХХ-ID»; any admin can join.
// Admin side: admin.html → «🚨 Екстрені заявки». No cron — expiry is applied
// lazily on every call (expire_support_tickets). Clients poll (~4 s).
const SUPPORT_TICKET_TTL_DAYS = 3;
const SUPPORT_MESSAGE_MAX     = 2000;

$me = require_auth();
$db = get_db();
$isAdmin = $me['role'] === 'admin';

// ─── helpers ─────────────────────────────────────────────────────────────────
function expire_support_tickets(PDO $db): void {
    $db->exec("UPDATE support_tickets SET status = 'expired' WHERE status = 'open' AND expires_at <= NOW()");
}

// №ДДММРР-ГГХХ-ID, e.g. 131026-1545-001 (created 13.10.2026 15:45, id 1).
function support_ticket_number(array $t): string {
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $t['created_at']) ?: new DateTime();
    return $dt->format('dmy') . '-' . $dt->format('Hi') . '-' . str_pad((string) $t['id'], 3, '0', STR_PAD_LEFT);
}

function user_display_name(?array $u): string {
    if (!$u) return '—';
    $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    return $name !== '' ? $name : ($u['email'] ?? '—');
}

function ticket_or_404(PDO $db, int $ticketId, array $me): array {
    $stmt = $db->prepare('SELECT * FROM support_tickets WHERE id = ?');
    $stmt->execute([$ticketId]);
    $t = $stmt->fetch();
    if (!$t || ($me['role'] !== 'admin' && (int) $t['user_id'] !== (int) $me['user_id'])) {
        http_response_code(404);
        echo json_encode(['error' => 'Заявку не знайдено']);
        exit;
    }
    return $t;
}

function ticket_admins(PDO $db, int $ticketId): array {
    $stmt = $db->prepare('
        SELECT u.id, u.first_name, u.last_name, u.email, sta.joined_at
        FROM support_ticket_admins sta JOIN users u ON u.id = sta.admin_user_id
        WHERE sta.ticket_id = ? ORDER BY sta.joined_at
    ');
    $stmt->execute([$ticketId]);
    return array_map(fn($a) => ['id' => (int) $a['id'], 'name' => user_display_name($a), 'joined_at' => $a['joined_at']], $stmt->fetchAll());
}

function ticket_view(PDO $db, array $t): array {
    return [
        'id'         => (int) $t['id'],
        'number'     => support_ticket_number($t),
        'category'   => $t['category'],
        'status'     => $t['status'],
        'created_at' => $t['created_at'],
        'expires_at' => $t['expires_at'],
        'closed_at'  => $t['closed_at'],
        'admins'     => ticket_admins($db, (int) $t['id']),
    ];
}

// Messages after $afterId. sender_role: system (sender_id NULL) | admin | user.
function ticket_messages(PDO $db, int $ticketId, int $afterId = 0): array {
    $stmt = $db->prepare('
        SELECT m.id, m.sender_id, m.body, m.created_at, u.role, u.first_name, u.last_name, u.email
        FROM support_messages m LEFT JOIN users u ON u.id = m.sender_id
        WHERE m.ticket_id = ? AND m.id > ? ORDER BY m.id ASC
    ');
    $stmt->execute([$ticketId, $afterId]);
    return array_map(fn($m) => [
        'id'          => (int) $m['id'],
        'sender_id'   => $m['sender_id'] !== null ? (int) $m['sender_id'] : null,
        'sender_role' => $m['sender_id'] === null ? 'system' : ($m['role'] === 'admin' ? 'admin' : 'user'),
        'sender_name' => $m['sender_id'] === null ? null : user_display_name($m),
        'body'        => $m['body'],
        'created_at'  => $m['created_at'],
    ], $stmt->fetchAll());
}

function add_support_message(PDO $db, int $ticketId, ?int $senderId, string $body): void {
    $db->prepare('INSERT INTO support_messages (ticket_id, sender_id, body) VALUES (?, ?, ?)')
       ->execute([$ticketId, $senderId, $body]);
}

function require_open_ticket(array $t): void {
    if ($t['status'] !== 'open') {
        http_response_code(409);
        echo json_encode(['error' => $t['status'] === 'expired'
            ? 'Термін дії заявки минув (' . SUPPORT_TICKET_TTL_DAYS . ' дні). Створіть нову, якщо проблема не вирішена.'
            : 'Заявку завершено. Створіть нову, якщо проблема не вирішена.']);
        exit;
    }
}

function admin_name(PDO $db, int $adminId): string {
    $stmt = $db->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
    $stmt->execute([$adminId]);
    return user_display_name($stmt->fetch() ?: null);
}

expire_support_tickets($db);

// ─── GET ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    // The user's current (open, not expired) admin chat, if any.
    if ($action === 'mine') {
        $stmt = $db->prepare("SELECT * FROM support_tickets WHERE user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$me['user_id']]);
        $t = $stmt->fetch();
        echo json_encode($t
            ? ['ticket' => ticket_view($db, $t), 'messages' => ticket_messages($db, (int) $t['id'])]
            : ['ticket' => null, 'messages' => []]);
        exit;
    }

    // Polling: new messages + fresh ticket state (status, joined admins).
    if ($action === 'messages') {
        $t = ticket_or_404($db, (int) ($_GET['ticket_id'] ?? 0), $me);
        echo json_encode([
            'ticket'   => ticket_view($db, $t),
            'messages' => ticket_messages($db, (int) $t['id'], (int) ($_GET['after_id'] ?? 0)),
        ]);
        exit;
    }

    if (!$isAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Лише для адміністраторів']);
        exit;
    }

    // Admin: all open emergency requests, oldest first.
    if ($action === 'list') {
        $rows = $db->query("
            SELECT t.*, u.first_name, u.last_name, u.email, u.role AS user_role,
                   (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id AND m.sender_id IS NOT NULL) AS message_count,
                   (SELECT MAX(m.created_at) FROM support_messages m WHERE m.ticket_id = t.id) AS last_message_at
            FROM support_tickets t JOIN users u ON u.id = t.user_id
            WHERE t.status = 'open'
            ORDER BY t.created_at ASC
        ")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $view = ticket_view($db, $r);
            $view['user'] = ['id' => (int) $r['user_id'], 'name' => user_display_name($r), 'email' => $r['email'], 'role' => $r['user_role']];
            $view['message_count']   = (int) $r['message_count'];
            $view['last_message_at'] = $r['last_message_at'];
            $view['joined_by_me']    = (bool) array_filter($view['admins'], fn($a) => $a['id'] === (int) $me['user_id']);
            $out[] = $view;
        }
        echo json_encode($out);
        exit;
    }

    // Admin: one request in full — chat + who raised it + the conversation it came from.
    if ($action === 'detail') {
        $t = ticket_or_404($db, (int) ($_GET['ticket_id'] ?? 0), $me);
        $stmt = $db->prepare('SELECT id, first_name, last_name, email, role, status FROM users WHERE id = ?');
        $stmt->execute([$t['user_id']]);
        $u = $stmt->fetch();

        $context = null;
        if ($t['conversation_id']) {
            $stmt = $db->prepare('SELECT * FROM conversations WHERE id = ?');
            $stmt->execute([$t['conversation_id']]);
            $conv = $stmt->fetch();
            if ($conv) {
                $otherId = (int) $conv['client_user_id'] === (int) $t['user_id'] ? (int) $conv['freelancer_user_id'] : (int) $conv['client_user_id'];
                $stmt = $db->prepare('SELECT id, first_name, last_name, email, role FROM users WHERE id = ?');
                $stmt->execute([$otherId]);
                $other = $stmt->fetch();
                $stmt = $db->prepare('
                    SELECT * FROM (
                        SELECT m.id, m.body, m.created_at, u.first_name, u.last_name, u.email
                        FROM messages m JOIN users u ON u.id = m.sender_id
                        WHERE m.conversation_id = ? ORDER BY m.id DESC LIMIT 50
                    ) last50 ORDER BY id ASC
                ');
                $stmt->execute([$conv['id']]);
                $context = [
                    'conversation_id' => (int) $conv['id'],
                    'other'    => $other ? ['id' => (int) $other['id'], 'name' => user_display_name($other), 'email' => $other['email'], 'role' => $other['role']] : null,
                    'messages' => array_map(fn($m) => ['id' => (int) $m['id'], 'sender_name' => user_display_name($m), 'body' => $m['body'], 'created_at' => $m['created_at']], $stmt->fetchAll()),
                ];
            }
        }

        $view = ticket_view($db, $t);
        $view['user'] = $u ? ['id' => (int) $u['id'], 'name' => user_display_name($u), 'email' => $u['email'], 'role' => $u['role'], 'status' => $u['status']] : null;
        $view['joined_by_me'] = (bool) array_filter($view['admins'], fn($a) => $a['id'] === (int) $me['user_id']);
        echo json_encode(['ticket' => $view, 'messages' => ticket_messages($db, (int) $t['id']), 'context' => $context]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Невідома дія']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не підтримується']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ─── create: «Виклик адміністратора» ─────────────────────────────────────────
if ($action === 'create') {
    if ($isAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Адміністратор не створює заявки в підтримку']);
        exit;
    }
    if (($body['category'] ?? '') !== 'admin_call') {
        http_response_code(422);
        echo json_encode(['error' => 'Цей розділ підтримки ще в розробці. Скористайтеся «Виклик адміністратора».']);
        exit;
    }
    $text = trim((string) ($body['body'] ?? ''));
    if (mb_strlen($text) > SUPPORT_MESSAGE_MAX) {
        http_response_code(422);
        echo json_encode(['error' => 'Опис — не більше ' . SUPPORT_MESSAGE_MAX . ' символів']);
        exit;
    }

    // One live request per user — reopening the widget returns the same chat.
    $stmt = $db->prepare("SELECT * FROM support_tickets WHERE user_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$me['user_id']]);
    if ($existing = $stmt->fetch()) {
        echo json_encode(['ticket' => ticket_view($db, $existing), 'messages' => ticket_messages($db, (int) $existing['id']), 'existing' => true]);
        exit;
    }

    // The conversation it was raised from — context for the admin; must be the user's own.
    $conversationId = !empty($body['conversation_id']) ? (int) $body['conversation_id'] : null;
    if ($conversationId) {
        $stmt = $db->prepare('SELECT id FROM conversations WHERE id = ? AND (client_user_id = ? OR freelancer_user_id = ?)');
        $stmt->execute([$conversationId, $me['user_id'], $me['user_id']]);
        if (!$stmt->fetch()) $conversationId = null;
    }

    $db->beginTransaction();
    $db->prepare('
        INSERT INTO support_tickets (user_id, conversation_id, category, expires_at)
        VALUES (?, ?, \'admin_call\', DATE_ADD(NOW(), INTERVAL ' . SUPPORT_TICKET_TTL_DAYS . ' DAY))
    ')->execute([$me['user_id'], $conversationId]);
    $ticketId = (int) $db->lastInsertId();
    $stmt = $db->prepare('SELECT * FROM support_tickets WHERE id = ?');
    $stmt->execute([$ticketId]);
    $t = $stmt->fetch();
    $number = support_ticket_number($t);

    add_support_message($db, $ticketId, null,
        "Заявку №{$number} створено. Адміністраторів сповіщено — щойно хтось підключиться, ви побачите це тут. "
        . 'Чат діє ' . SUPPORT_TICKET_TTL_DAYS . ' дні.');
    if ($text !== '') add_support_message($db, $ticketId, (int) $me['user_id'], $text);
    $db->commit();

    notify_admins($db, 'support_urgent', "ТЕРМІНОВА ЗАЯВКА №{$number}");

    http_response_code(201);
    echo json_encode(['ticket' => ticket_view($db, $t), 'messages' => ticket_messages($db, $ticketId)]);
    exit;
}

$ticket = ticket_or_404($db, (int) ($body['ticket_id'] ?? 0), $me);
$number = support_ticket_number($ticket);

// ─── send: the user or an admin writes into the chat ────────────────────────
if ($action === 'send') {
    require_open_ticket($ticket);
    $text = trim((string) ($body['body'] ?? ''));
    if ($text === '' || mb_strlen($text) > SUPPORT_MESSAGE_MAX) {
        http_response_code(422);
        echo json_encode(['error' => 'Повідомлення має містити від 1 до ' . SUPPORT_MESSAGE_MAX . ' символів']);
        exit;
    }

    if ($isAdmin) {
        // Writing = joining (no separate click needed).
        $stmt = $db->prepare('INSERT IGNORE INTO support_ticket_admins (ticket_id, admin_user_id) VALUES (?, ?)');
        $stmt->execute([$ticket['id'], $me['user_id']]);
        if ($stmt->rowCount() > 0) {
            add_support_message($db, (int) $ticket['id'], null, 'Адміністратор ' . admin_name($db, (int) $me['user_id']) . ' підключився до чату.');
        }
    }
    add_support_message($db, (int) $ticket['id'], (int) $me['user_id'], $text);

    if ($isAdmin) {
        create_notification($db, (int) $ticket['user_id'], 'support_reply', "Адміністратор відповів у чаті підтримки (заявка №{$number})");
    } else {
        foreach (ticket_admins($db, (int) $ticket['id']) as $a) {
            create_notification($db, $a['id'], 'support_message', "Нове повідомлення в терміновій заявці №{$number}");
        }
    }

    echo json_encode(['messages' => ticket_messages($db, (int) $ticket['id'], (int) ($body['after_id'] ?? 0))]);
    exit;
}

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Лише для адміністраторів']);
    exit;
}

// ─── join: any admin connects to the chat ───────────────────────────────────
if ($action === 'join') {
    require_open_ticket($ticket);
    $stmt = $db->prepare('INSERT IGNORE INTO support_ticket_admins (ticket_id, admin_user_id) VALUES (?, ?)');
    $stmt->execute([$ticket['id'], $me['user_id']]);
    if ($stmt->rowCount() > 0) {
        $name = admin_name($db, (int) $me['user_id']);
        add_support_message($db, (int) $ticket['id'], null, "Адміністратор {$name} підключився до чату.");
        create_notification($db, (int) $ticket['user_id'], 'support_joined', "Адміністратор підключився до вашої заявки №{$number}");
    }
    echo json_encode(['success' => true]);
    exit;
}

// ─── close: an admin finishes the request before the 3 days run out ─────────
if ($action === 'close') {
    require_open_ticket($ticket);
    $db->prepare("UPDATE support_tickets SET status = 'closed', closed_at = NOW(), closed_by_user_id = ? WHERE id = ?")
       ->execute([$me['user_id'], $ticket['id']]);
    add_support_message($db, (int) $ticket['id'], null, 'Адміністратор ' . admin_name($db, (int) $me['user_id']) . ' завершив заявку.');
    create_notification($db, (int) $ticket['user_id'], 'support_closed', "Заявку №{$number} завершено адміністратором");
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Невідома дія']);
