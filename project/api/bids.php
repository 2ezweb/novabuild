<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth_middleware.php';

const MIN_CONNECTS = 10;

$me = require_auth();
$db = get_db();

// ─── GET: my bids (freelancer) / bidders on an offer (client) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($me['role'] === 'client') {
        $offer_id = (int)($_GET['offer_id'] ?? 0);
        if (!$offer_id) {
            http_response_code(422);
            echo json_encode(['error' => 'offer_id is required']);
            exit;
        }

        // Ownership check via client_profiles.user_id, not offers.client_id directly
        $stmt = $db->prepare('
            SELECT o.id FROM offers o
            JOIN client_profiles cp ON cp.id = o.client_id
            WHERE o.id = ? AND cp.user_id = ?
        ');
        $stmt->execute([$offer_id, $me['user_id']]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Offer not found']);
            exit;
        }

        $stmt = $db->prepare('
            SELECT b.id, b.cover_note, b.status, b.connects_spent, b.created_at,
                   u.first_name, u.last_name, u.avatar_path, u.verification_status,
                   fp.specialization
            FROM bids b
            JOIN freelancer_profiles fp ON fp.id = b.freelancer_id
            JOIN users u ON u.id = fp.user_id
            WHERE b.offer_id = ?
            ORDER BY b.connects_spent DESC, b.created_at ASC
        ');
        $stmt->execute([$offer_id]);
        $bids = $stmt->fetchAll();

        // Last name is peer-facing here (client viewing a freelancer) — mask to initial.
        foreach ($bids as &$b) {
            $b['last_name_initial'] = $b['last_name'] ? mb_substr($b['last_name'], 0, 1) . '.' : null;
            unset($b['last_name']);
        }
        unset($b);

        // Top 5 slots go to the highest stakes; everyone else is shuffled.
        $top  = array_slice($bids, 0, 5);
        $rest = array_slice($bids, 5);
        shuffle($rest);

        echo json_encode(array_merge($top, $rest));
        exit;
    }

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
    $connects = (int)($body['connects'] ?? MIN_CONNECTS);

    if (!$offer_id) {
        http_response_code(422);
        echo json_encode(['error' => 'offer_id is required']);
        exit;
    }
    if ($connects < MIN_CONNECTS) {
        http_response_code(422);
        echo json_encode(['error' => 'Минимальная ставка — ' . MIN_CONNECTS . ' коннектов']);
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

    // Get freelancer profile id + connects balance
    $stmt = $db->prepare('SELECT id, connects_balance FROM freelancer_profiles WHERE user_id = ?');
    $stmt->execute([$me['user_id']]);
    $profile = $stmt->fetch();

    if (!$profile) {
        http_response_code(404);
        echo json_encode(['error' => 'Freelancer profile not found']);
        exit;
    }
    if ($connects > $profile['connects_balance']) {
        http_response_code(422);
        echo json_encode(['error' => 'Недостаточно коннектов']);
        exit;
    }

    // Insert bid + deduct balance atomically — UNIQUE KEY prevents duplicate bids
    try {
        $db->beginTransaction();

        $stmt = $db->prepare('
            INSERT INTO bids (offer_id, freelancer_id, cover_note, connects_spent) VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$offer_id, $profile['id'], $note, $connects]);
        $bidId = (int)$db->lastInsertId();

        $db->prepare('UPDATE freelancer_profiles SET connects_balance = connects_balance - ? WHERE id = ?')
           ->execute([$connects, $profile['id']]);

        $db->commit();
    } catch (\PDOException $e) {
        $db->rollBack();
        if ($e->getCode() === '23000') {
            http_response_code(409);
            echo json_encode(['error' => 'You have already applied to this offer']);
            exit;
        }
        throw $e;
    }

    http_response_code(201);
    echo json_encode([
        'id'               => $bidId,
        'connects_spent'   => $connects,
        'connects_balance' => $profile['connects_balance'] - $connects,
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
