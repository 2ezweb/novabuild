<?php
require_once __DIR__ . '/contract_number.php';
require_once __DIR__ . '/notifications.php';

// How long a client's refund sits in a disputable hold after a contract or
// stage closes as failed (contracts.php::close, and admin.php's force-close).
const CONTRACT_HOLD_DAYS = 7;

// Pay a contract/stage's full escrow to the freelancer — a normal "close:
// success" or an admin force-close in the freelancer's favor. Caller owns
// the transaction.
//
// Brigade contracts (contracts.brigade_id): the escrow is split by the
// бригадир's розподіл (contract_allocations) — each member gets their amount,
// the бригадир gets the rest. If less than the full price was deposited, every
// share shrinks proportionally (factor = escrow / price, capped at 1).
function pay_freelancer(PDO $db, array $row): void {
    $amount = (float) $row['escrowed_amount'];
    if ($amount <= 0) return;
    $cid = (int) $row['id'];
    $label = 'Оплата за контрактом #' . contract_number($cid);

    $db->prepare("
        INSERT INTO wallet_transactions (user_id, type, amount, description, related_contract_id)
        VALUES (?, 'contract_payment', ?, ?, ?)
    ")->execute([$row['client_user_id'], $amount, $label, $cid]);

    $shares = [];   // user_id => amount for brigade members
    if (!empty($row['brigade_id'])) {
        $stmt = $db->prepare('SELECT user_id, amount FROM contract_allocations WHERE contract_id = ? AND amount > 0');
        $stmt->execute([$cid]);
        $price  = (float) $row['price'];
        $factor = $price > 0 ? min(1.0, $amount / $price) : 1.0;
        foreach ($stmt->fetchAll() as $a) {
            $shares[(int) $a['user_id']] = round((float) $a['amount'] * $factor, 2);
        }
        // Never pay out more than the escrow, whatever the allocations say.
        $sum = array_sum($shares);
        if ($sum > $amount && $sum > 0) {
            foreach ($shares as $uid => $s) $shares[$uid] = round($s * $amount / $sum, 2);
        }
    }

    $earn = $db->prepare("
        INSERT INTO wallet_transactions (user_id, type, amount, description, related_contract_id)
        VALUES (?, 'contract_earning', ?, ?, ?)
    ");
    $credit = $db->prepare('UPDATE freelancer_profiles SET balance = balance + ? WHERE user_id = ?');

    foreach ($shares as $uid => $share) {
        if ($share <= 0) continue;
        $credit->execute([$share, $uid]);
        $earn->execute([$uid, $share, $label . ' (частка в бригаді)', $cid]);
        create_notification($db, $uid, 'brigade_payout', "Вам зараховано {$share} ₴ — ваша частка за контрактом #" . contract_number($cid));
    }

    $ownerShare = round($amount - array_sum($shares), 2);
    if ($ownerShare > 0) {
        $credit->execute([$ownerShare, $row['freelancer_user_id']]);
        $earn->execute([$row['freelancer_user_id'], $ownerShare, $label, $cid]);
    }
}

// A work hand-over still waiting for the client's ДА/НЕТ (work_submissions.php)
// is settled by any other way the contract/stage gets closed — plain close,
// staged-parent cascade, admin force-close. Caller owns the transaction.
function settle_pending_submissions(PDO $db, int $contractId, bool $accepted): void {
    $db->prepare("UPDATE work_submissions SET status = ?, decided_at = NOW() WHERE contract_id = ? AND status = 'submitted'")
       ->execute([$accepted ? 'accepted' : 'rejected', $contractId]);
}

// Refund an active row's escrow to the client + a disputable hold — a normal
// "close: failure", the staged-parent cascade, or an admin force-close in
// the client's favor. Caller owns the transaction.
function refund_with_hold(PDO $db, array $row): void {
    $amount = (float) $row['escrowed_amount'];
    if ($amount <= 0) return;
    $cid = (int) $row['id'];
    $db->prepare('UPDATE client_profiles SET balance = balance + ? WHERE user_id = ?')
       ->execute([$amount, $row['client_user_id']]);
    $db->prepare("
        INSERT INTO wallet_transactions (user_id, type, amount, description, related_contract_id)
        VALUES (?, 'deposit', ?, ?, ?)
    ")->execute([$row['client_user_id'], $amount, 'Повернення за контрактом #' . contract_number($cid) . ' (не виконано)', $cid]);
    $db->prepare('
        INSERT INTO balance_holds (user_id, contract_id, amount, release_at)
        VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . CONTRACT_HOLD_DAYS . ' DAY))
    ')->execute([$row['client_user_id'], $cid, $amount]);
}
