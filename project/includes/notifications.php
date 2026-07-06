<?php
function create_notification(PDO $db, int $userId, string $type, string $message): void {
    $db->prepare('INSERT INTO notifications (user_id, type, message) VALUES (?, ?, ?)')
       ->execute([$userId, $type, $message]);
}

function notify_admins(PDO $db, string $type, string $message): void {
    $adminIds = $db->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($adminIds as $adminId) {
        create_notification($db, (int)$adminId, $type, $message);
    }
}
