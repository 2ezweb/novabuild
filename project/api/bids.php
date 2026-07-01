<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

$me = require_auth();
$db = get_db();

// ─── GET: my bids (freelancer) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($me['role'] !== 'freelancer') {
        http_response_code(403);
        echo json_encode(['error' => 'Freelancers only']);
        exit;
    }

    $stmt = $db->prepare('
        SELECT b.*, o.title AS offer_title, o.status AS offer_status
        FROM bids b
        JOIN freelancer_profiles fp ON fp.id = b.freelancer_id
        JOIN offers o ON o.id = b.offer_id
        WHERE fp.user_id = ?
        ORDER BY b.created_at DESC
    ');
    $stmt->execute([$me['user_id']]);
    echo json_encode($stmt->fetchAll());
    exit;
}

// ─── POST: place bid (freelancer only) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($me['role'] !== 'freelancer') {
        http_response_code(403);
        echo json_encode(['error' => 'Only freelancers can place bids']);
        exit;
    }

    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $offer_id = (int)($body['offer_id'] ?? 0);
    $note     = trim($body['cover_note'] ?? '');

    if (!$offer_id) {
        http_response_code(422);
        echo json_encode(['error' => 'offer_id is required']);
        exit;
    }

    // Check offer exists and is open
    $stmt = $db->prepare('SELECT id, status FROM offers WHERE id = ?');
    $stmt->execute([$offer_id]);
    $offer = $stmt->fetch();

    if (!$offer) {
        http_response_code(404);
        echo json_encode(['error' => 'Offer not found']);
        exit;
    }
    if ($offer['status'] !== 'open') {
        http_response_code(409);
        echo json_encode(['error' => 'Offer is no longer open']);
        exit;
    }

    // Get freelancer profile id
    $stmt = $db->prepare('SELECT id FROM freelancer_profiles WHERE user_id = ?');
    $stmt->execute([$me['user_id']]);
    $profile = $stmt->fetch();

    if (!$profile) {
        http_response_code(404);
        echo json_encode(['error' => 'Freelancer profile not found']);
        exit;
    }

    // Insert — UNIQUE KEY prevents duplicate
    try {
        $stmt = $db->prepare('
            INSERT INTO bids (offer_id, freelancer_id, cover_note) VALUES (?, ?, ?)
        ');
        $stmt->execute([$offer_id, $profile['id'], $note]);
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            http_response_code(409);
            echo json_encode(['error' => 'You have already applied to this offer']);
            exit;
        }
        throw $e;
    }

    http_response_code(201);
    echo json_encode(['id' => (int)$db->lastInsertId()]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
