<?php
require_once __DIR__ . '/contract_number.php';

// How long a client's refund sits in a disputable hold after a contract or
// stage closes as failed (contracts.php::close, and admin.php's force-close).
const CONTRACT_HOLD_DAYS = 7;

// Pay a contract/stage's full escrow to the freelancer — a normal "close:
// success" or an admin force-close in the freelancer's favor. Caller owns
// the transaction.
function pay_freelancer(PDO $db, array $row): void {
    $amount = (float) $row['escrowed_amount'];
    if ($amount <= 0) return;
    $cid = (int) $row['id'];
    $db->prepare('UPDATE freelancer_profiles SET balance = balance + ? WHERE user_id = ?')
       ->execute([$amount, $row['freelancer_user_id']]);
    $db->prepare("
        INSERT INTO wallet_transactions (user_id, type, amount, description, related_contract_id)
        VALUES (?, 'contract_payment', ?, ?, ?)
    ")->execute([$row['client_user_id'], $amount, 'Оплата за контрактом #' . contract_number($cid), $cid]);
    $db->prepare("
        INSERT INTO wallet_transactions (user_id, type, amount, description, related_contract_id)
        VALUES (?, 'contract_earning', ?, ?, ?)
    ")->execute([$row['freelancer_user_id'], $amount, 'Оплата за контрактом #' . contract_number($cid), $cid]);
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
