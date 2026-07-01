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
    'register' => handle_register($body),
    'login'    => handle_login($body),
    default    => respond(400, ['error' => 'Unknown action'])
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

    $token = jwt_encode(['user_id' => $user_id, 'role' => $role]);
    respond(201, ['token' => $token, 'role' => $role, 'user_id' => $user_id]);
}

// ─── LOGIN ────────────────────────────────────────────────────────────────────

function handle_login(array $d): void {
    $email    = trim($d['email']    ?? '');
    $password = trim($d['password'] ?? '');

    if (!$email || !$password)
        respond(422, ['error' => 'Email and password required']);

    $db   = get_db();
    $stmt = $db->prepare('SELECT id, password_hash, role, status FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash']))
        respond(401, ['error' => 'Invalid email or password']);

    if ($user['status'] === 'banned')
        respond(403, ['error' => 'Account is banned']);

    $token = jwt_encode(['user_id' => (int)$user['id'], 'role' => $user['role']]);
    respond(200, ['token' => $token, 'role' => $user['role'], 'user_id' => (int)$user['id']]);
}

// ─── HELPER ───────────────────────────────────────────────────────────────────

function respond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
