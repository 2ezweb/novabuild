<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

$me = require_auth();
$db = get_db();

// ─── GET: list offers ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = $_GET['status'] ?? null;

    if ($me['role'] === 'client') {
        // Client sees only their own offers + bid count
        $stmt = $db->prepare('
            SELECT o.*, COUNT(b.id) AS bid_count
            FROM offers o
            JOIN client_profiles cp ON cp.id = o.client_id
            LEFT JOIN bids b ON b.offer_id = o.id
            WHERE cp.user_id = ?
            GROUP BY o.id
            ORDER BY o.created_at DESC
        ');
        $stmt->execute([$me['user_id']]);
    } else {
        // Freelancer sees open offers from everyone, plus who's behind them
        // (company name, or "Частное лицо" if none, and their verification badge)
        $sql = '
            SELECT o.*, COUNT(b.id) AS bid_count,
                   cp.company_name AS client_company_name,
                   u.verification_status AS client_verification_status
            FROM offers o
            JOIN client_profiles cp ON cp.id = o.client_id
            JOIN users u ON u.id = cp.user_id
            LEFT JOIN bids b ON b.offer_id = o.id
        ';
        $params = [];
        if ($status) {
            $sql .= ' WHERE o.status = ?';
            $params[] = $status;
        }
        $sql .= ' GROUP BY o.id ORDER BY o.created_at DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }

    echo json_encode($stmt->fetchAll());
    exit;
}

// ─── POST: create offer (client only) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($me['role'] !== 'client') {
        http_response_code(403);
        echo json_encode(['error' => 'Only clients can post offers']);
        exit;
    }

    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $title = trim($body['title'] ?? '');
    $desc  = trim($body['description'] ?? '');
    $budget   = is_numeric($body['budget'] ?? '') ? (float)$body['budget'] : null;
    $deadline = !empty($body['deadline']) ? $body['deadline'] : null;

    if (!$title) {
        http_response_code(422);
        echo json_encode(['error' => 'Title is required']);
        exit;
    }

    // Get client_profile id
    $stmt = $db->prepare('SELECT id FROM client_profiles WHERE user_id = ?');
    $stmt->execute([$me['user_id']]);
    $profile = $stmt->fetch();

    if (!$profile) {
        http_response_code(404);
        echo json_encode(['error' => 'Client profile not found']);
        exit;
    }

    $stmt = $db->prepare('
        INSERT INTO offers (client_id, title, description, budget, deadline)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$profile['id'], $title, $desc, $budget, $deadline]);

    http_response_code(201);
    echo json_encode(['id' => (int)$db->lastInsertId(), 'title' => $title]);
    exit;
}

// ─── PUT: edit or close offer (owner only) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    if ($me['role'] !== 'client') {
        http_response_code(403);
        echo json_encode(['error' => 'Only clients can edit offers']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id   = (int)($body['id'] ?? 0);
    if (!$id) {
        http_response_code(422);
        echo json_encode(['error' => 'id is required']);
        exit;
    }

    // Ownership check via client_profiles.user_id, not offers.client_id directly
    $stmt = $db->prepare('
        SELECT o.id FROM offers o
        JOIN client_profiles cp ON cp.id = o.client_id
        WHERE o.id = ? AND cp.user_id = ?
    ');
    $stmt->execute([$id, $me['user_id']]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Offer not found']);
        exit;
    }

    $fields = [];
    $params = [];

    if (array_key_exists('title', $body)) {
        $title = trim($body['title']);
        if (!$title) {
            http_response_code(422);
            echo json_encode(['error' => 'Title is required']);
            exit;
        }
        $fields[] = 'title = ?';
        $params[] = $title;
    }
    if (array_key_exists('description', $body)) {
        $fields[] = 'description = ?';
        $params[] = trim($body['description'] ?? '');
    }
    if (array_key_exists('budget', $body)) {
        $fields[] = 'budget = ?';
        $params[] = is_numeric($body['budget'] ?? '') ? (float)$body['budget'] : null;
    }
    if (array_key_exists('deadline', $body)) {
        $fields[] = 'deadline = ?';
        $params[] = !empty($body['deadline']) ? $body['deadline'] : null;
    }
    if (array_key_exists('status', $body)) {
        if (!in_array($body['status'], ['open', 'in_progress', 'closed'], true)) {
            http_response_code(422);
            echo json_encode(['error' => 'Invalid status']);
            exit;
        }
        $fields[] = 'status = ?';
        $params[] = $body['status'];
    }

    if (!$fields) {
        http_response_code(422);
        echo json_encode(['error' => 'Nothing to update']);
        exit;
    }

    $params[] = $id;
    $stmt = $db->prepare('UPDATE offers SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute($params);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
