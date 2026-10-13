<?php
// Lightweight DB-backed rate limiting — no Redis/Memcached on this hosting,
// so failed attempts are logged as rows and counted over a sliding window.
// Used to slow down password guessing, email-verification code guessing,
// and resend/registration spam. Not a full WAF — just enough that scripted
// brute force stops being free.

function rate_limit_check(PDO $db, string $bucket, string $identifier, int $maxAttempts, int $windowSeconds): bool {
    $stmt = $db->prepare('
        SELECT COUNT(*) FROM rate_limit_hits
        WHERE bucket = ? AND identifier = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
    ');
    $stmt->execute([$bucket, $identifier, $windowSeconds]);
    return (int) $stmt->fetchColumn() < $maxAttempts;
}

function rate_limit_record(PDO $db, string $bucket, string $identifier): void {
    $db->prepare('INSERT INTO rate_limit_hits (bucket, identifier) VALUES (?, ?)')
       ->execute([$bucket, $identifier]);

    // Opportunistic cleanup so the table doesn't grow forever — no cron on
    // this hosting to rely on, so just prune occasionally on write.
    if (random_int(1, 50) === 1) {
        $db->exec("DELETE FROM rate_limit_hits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    }
}

function rate_limit_clear(PDO $db, string $bucket, string $identifier): void {
    $db->prepare('DELETE FROM rate_limit_hits WHERE bucket = ? AND identifier = ?')
       ->execute([$bucket, $identifier]);
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
