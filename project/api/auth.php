<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/jwt.php';

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

match ($action) {
    'register'     => handle_register($body),
    'login'        => handle_login($body),
    'verify_email' => handle_verify_email($body),
    'resend_code'  => handle_resend_code($body),
    default        => respond(400, ['error' => 'Unknown action'])
};

// ─── REGISTER ────────────────────────────────────────────────────────────────

function handle_register(array $d): void {
    $email    = trim($d['email']    ?? '');
    $password = trim($d['password'] ?? '');
    $role     = $d['role']          ?? '';

    // Validation
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        respond(422, ['error' => 'Invalid email']);

    if (strlen($password) < 6)
        respond(422, ['error' => 'Password must be at least 6 characters']);

    if (!in_array($role, ['client', 'freelancer']))
        respond(422, ['error' => 'Role must be client or freelancer']);

    $db = get_db();

    // Duplicate check
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) respond(409, ['error' => 'Email already registered']);

    // Create user
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare('INSERT INTO users (email, password_hash, role) VALUES (?, ?, ?)');
    $stmt->execute([$email, $hash, $role]);
    $user_id = (int) $db->lastInsertId();

    // Create profile
    if ($role === 'freelancer') {
        $db->prepare('INSERT INTO freelancer_profiles (user_id) VALUES (?)')->execute([$user_id]);
    } else {
        $db->prepare('INSERT INTO client_profiles (user_id) VALUES (?)')->execute([$user_id]);
    }

    issue_verification_code($db, $user_id, $email);

    respond(201, ['pending_verification' => true, 'email' => $email]);
}

// ─── LOGIN ────────────────────────────────────────────────────────────────────

function handle_login(array $d): void {
    $email    = trim($d['email']    ?? '');
    $password = trim($d['password'] ?? '');

    if (!$email || !$password)
        respond(422, ['error' => 'Email and password required']);

    $db   = get_db();
    $stmt = $db->prepare('SELECT id, password_hash, role, status, email_verified_at FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash']))
        respond(401, ['error' => 'Invalid email or password']);

    if ($user['status'] === 'banned')
        respond(403, ['error' => 'Account is banned']);

    if (!$user['email_verified_at'])
        respond(403, ['error' => 'Email не подтверждён', 'pending_verification' => true, 'email' => $email]);

    $token = jwt_encode(['user_id' => (int)$user['id'], 'role' => $user['role']]);
    respond(200, ['token' => $token, 'role' => $user['role'], 'user_id' => (int)$user['id']]);
}

// ─── VERIFY EMAIL ─────────────────────────────────────────────────────────────

function handle_verify_email(array $d): void {
    $email = trim($d['email'] ?? '');
    $code  = trim($d['code']  ?? '');

    if (!$email || !$code)
        respond(422, ['error' => 'Email и код обязательны']);

    $db   = get_db();
    $stmt = $db->prepare('
        SELECT id, role, email_verified_at, verification_code, verification_code_expires_at
        FROM users WHERE email = ?
    ');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) respond(404, ['error' => 'Пользователь не найден']);

    // Already verified (e.g. stale tab / double submit) — just log them in.
    if ($user['email_verified_at']) {
        $token = jwt_encode(['user_id' => (int)$user['id'], 'role' => $user['role']]);
        respond(200, ['token' => $token, 'role' => $user['role'], 'user_id' => (int)$user['id']]);
    }

    if (!hash_equals((string)$user['verification_code'], $code))
        respond(401, ['error' => 'Неверный код']);

    if (!$user['verification_code_expires_at'] || strtotime($user['verification_code_expires_at']) < time())
        respond(410, ['error' => 'Код истёк, запросите новый']);

    $db->prepare('
        UPDATE users SET email_verified_at = NOW(), verification_code = NULL, verification_code_expires_at = NULL
        WHERE id = ?
    ')->execute([$user['id']]);

    $token = jwt_encode(['user_id' => (int)$user['id'], 'role' => $user['role']]);
    respond(200, ['token' => $token, 'role' => $user['role'], 'user_id' => (int)$user['id']]);
}

// ─── RESEND CODE ──────────────────────────────────────────────────────────────

function handle_resend_code(array $d): void {
    $email = trim($d['email'] ?? '');
    if (!$email) respond(422, ['error' => 'Email обязателен']);

    $db   = get_db();
    $stmt = $db->prepare('SELECT id, email_verified_at FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) respond(404, ['error' => 'Пользователь не найден']);
    if ($user['email_verified_at']) respond(400, ['error' => 'Email уже подтверждён']);

    issue_verification_code($db, (int)$user['id'], $email);
    respond(200, ['sent' => true]);
}

// ─── HELPERS ──────────────────────────────────────────────────────────────────

function issue_verification_code(PDO $db, int $user_id, string $email): void {
    $code      = (string) random_int(100000, 999999);
    $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

    $db->prepare('
        UPDATE users SET verification_code = ?, verification_code_expires_at = ? WHERE id = ?
    ')->execute([$code, $expiresAt, $user_id]);

    send_verification_email($email, $code);
}

function send_verification_email(string $email, string $code): void {
    $subject = 'Код подтверждения — NovaBuild';
    $message = "Ваш код подтверждения: $code\n\nКод действителен 10 минут.";
    $headers = "From: no-reply@novabuild.loc\r\nContent-Type: text/plain; charset=utf-8";
    mail($email, $subject, $message, $headers);
}

function respond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
