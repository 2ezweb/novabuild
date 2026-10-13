<?php
// Client site balance = refunds from failed contracts (contract_payouts.php::refund_with_hold).
// Part of it can be frozen: holds still inside the 7-day window, or under an
// unresolved dispute (blocks release even past 7 days). Frozen money can't be
// spent on a new deposit; the rest is spent FIRST by escrow.php.
// The client can't withdraw the balance themselves — only via support.

const CLIENT_FROZEN_SQL = "
    SELECT COALESCE(SUM(bh.amount), 0) FROM balance_holds bh
    WHERE bh.user_id = ? AND bh.resolution = 'pending'
      AND (bh.release_at > NOW() OR bh.disputed_at IS NOT NULL)
";

function client_frozen_amount(PDO $db, int $userId): float {
    $stmt = $db->prepare(CLIENT_FROZEN_SQL);
    $stmt->execute([$userId]);
    return (float) $stmt->fetchColumn();
}

function client_available_balance(PDO $db, int $userId): float {
    $stmt = $db->prepare('SELECT balance FROM client_profiles WHERE user_id = ?');
    $stmt->execute([$userId]);
    $balance = (float) $stmt->fetchColumn();
    return round(max(0, $balance - client_frozen_amount($db, $userId)), 2);
}
