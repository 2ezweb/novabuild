<?php
// Бригади: a freelancer creates one and becomes its бригадир (brigades.owner_user_id);
// other freelancers join by invitation (brigade_members, status invited → active).
// A person is in at most ONE brigade — as its owner or as an active member.
// A client can propose a contract to the brigade of the freelancer they chat
// with (contracts.brigade_id); the бригадир then splits the pay among members
// (contract_allocations → contract_payouts.php pay_freelancer).

function brigade_by_owner(PDO $db, int $userId): ?array {
    $stmt = $db->prepare('SELECT * FROM brigades WHERE owner_user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function brigade_by_id(PDO $db, int $id): ?array {
    $stmt = $db->prepare('SELECT * FROM brigades WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

// The brigade this user belongs to as an ACTIVE member (not as owner).
function brigade_membership(PDO $db, int $userId): ?array {
    $stmt = $db->prepare("
        SELECT b.*, bm.role AS my_role FROM brigade_members bm JOIN brigades b ON b.id = bm.brigade_id
        WHERE bm.user_id = ? AND bm.status = 'active' LIMIT 1
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

// Active members (without the owner), with display data.
function brigade_active_members(PDO $db, int $brigadeId): array {
    $stmt = $db->prepare("
        SELECT u.id, u.first_name, u.last_name, u.email, u.avatar_path, bm.role, bm.joined_at
        FROM brigade_members bm JOIN users u ON u.id = bm.user_id
        WHERE bm.brigade_id = ? AND bm.status = 'active'
        ORDER BY bm.joined_at
    ");
    $stmt->execute([$brigadeId]);
    return $stmt->fetchAll();
}

function brigade_person_name(array $u): string {
    $n = trim(($u['first_name'] ?? '') . (!empty($u['last_name']) ? ' ' . mb_substr($u['last_name'], 0, 1) . '.' : ''));
    return $n !== '' ? $n : (string) ($u['email'] ?? '—');
}

// Public card of a brigade (for the client: chat propose form, freelancer profile).
function brigade_public_summary(PDO $db, array $b): array {
    $members = brigade_active_members($db, (int) $b['id']);
    return [
        'id'           => (int) $b['id'],
        'name'         => $b['name'],
        'description'  => $b['description'],
        'owner_user_id'=> (int) $b['owner_user_id'],
        'member_count' => count($members) + 1,   // + бригадир
        'members'      => array_map(fn($m) => ['name' => brigade_person_name($m), 'role' => $m['role']], $members),
    ];
}

function notify_brigade_members(PDO $db, int $brigadeId, string $type, string $message): void {
    foreach (brigade_active_members($db, $brigadeId) as $m) {
        create_notification($db, (int) $m['id'], $type, $message);
    }
}
