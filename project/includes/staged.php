<?php
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/contract_number.php';

// Staged contracts in "planned" mode (contracts.stage_mode = 'planned'): every
// stage is described up front; stages after the current one wait with
// status 'planned' («За планом») and start one by one.
const PLANNED_STAGES_MIN = 2;
const PLANNED_STAGES_MAX = 30;

function is_planned_parent(?array $parent): bool {
    return $parent && $parent['type'] === 'staged' && $parent['parent_contract_id'] === null
        && ($parent['stage_mode'] ?? null) === 'planned';
}

// Starts the next 'planned' stage of a planned staged contract — if the
// contract is active and no stage is currently proposed/active. Returns the
// started stage row or null. Called automatically after a stage is completed
// successfully (contracts.php close, work_submissions.php accept, admin
// force-close) and manually by the client after a failed stage
// (contracts.php start_next_stage). Caller may or may not own a transaction.
function activate_next_planned_stage(PDO $db, int $parentId): ?array {
    $stmt = $db->prepare('SELECT * FROM contracts WHERE id = ?');
    $stmt->execute([$parentId]);
    $parent = $stmt->fetch();
    if (!is_planned_parent($parent) || $parent['status'] !== 'active') return null;

    $stmt = $db->prepare("SELECT COUNT(*) FROM contracts WHERE parent_contract_id = ? AND status IN ('proposed','active')");
    $stmt->execute([$parentId]);
    if ((int) $stmt->fetchColumn() > 0) return null;

    $stmt = $db->prepare("SELECT * FROM contracts WHERE parent_contract_id = ? AND status = 'planned' ORDER BY seq ASC LIMIT 1");
    $stmt->execute([$parentId]);
    $next = $stmt->fetch();
    if (!$next) return null;

    $db->prepare("UPDATE contracts SET status = 'active' WHERE id = ?")->execute([$next['id']]);

    $msg = 'Розпочато етап ' . $next['seq'] . ' «' . $next['title'] . '» за планом контракту #' . contract_number($parentId);
    create_notification($db, (int) $parent['client_user_id'], 'stage_started', $msg . ' — внесіть депозит за етап');
    create_notification($db, (int) $parent['freelancer_user_id'], 'stage_started', $msg);

    $next['status'] = 'active';
    return $next;
}

// After a stage closed successfully: if it belongs to a planned contract, roll on.
function after_stage_completed(PDO $db, array $stage): void {
    if (!empty($stage['parent_contract_id'])) {
        activate_next_planned_stage($db, (int) $stage['parent_contract_id']);
    }
}
