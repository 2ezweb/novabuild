<?php
// Password hashing — Argon2id (OWASP-recommended, memory-hard, GPU/ASIC-
// resistant) with a pepper on top of what password_hash() already does.
//
// password_hash() already generates a fresh random SALT per password and
// stores it inside the hash string itself — that part was never missing,
// bcrypt/argon2 do it automatically. What was missing:
//   1. A stronger algorithm than bcrypt (argon2id > bcrypt against modern
//      cracking hardware).
//   2. A PEPPER — a secret HMAC key kept OUTSIDE the database entirely
//      (unlike a salt, which lives next to the hash). Even a full DB dump
//      doesn't let an attacker crack passwords offline without this file.
define('PASSWORD_PEPPER', 'c91691a7e5ab00953e0858b09f234cc7643f54de113f504e30f71e7955e574fb');

// Falls back to bcrypt if this PHP build somehow lacks Argon2id (libargon2
// not compiled in) — rare on modern hosting, but this must never hard-fail.
// NOTE: must use defined() here, not `??` — an undefined *constant*
// (unlike an undefined variable) is a fatal Error in PHP 8, `??` won't catch it.
define('PASSWORD_HASH_ALGO', defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);

// OWASP's minimum recommended Argon2id profile (19 MiB memory, t=2, p=1) —
// deliberately the lighter of OWASP's two vetted profiles, since this runs
// on shared hosting with a shared PHP memory_limit, not dedicated hardware.
define('PASSWORD_HASH_OPTIONS', PASSWORD_HASH_ALGO === PASSWORD_BCRYPT
    ? ['cost' => 12]
    : ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]);

function peppered_password(string $password): string {
    return hash_hmac('sha256', $password, PASSWORD_PEPPER);
}

function hash_password(string $password): string {
    return password_hash(peppered_password($password), PASSWORD_HASH_ALGO, PASSWORD_HASH_OPTIONS);
}

// Verifies a password, transparently migrating older hashes (plain bcrypt,
// created before peppering/Argon2id existed) to the new scheme on a
// successful login — no forced logout/reset, accounts upgrade one login at
// a time. The rehash is a side effect; it never blocks or fails the login.
function verify_and_upgrade_password(PDO $db, int $userId, string $password, string $storedHash): bool {
    if (password_verify(peppered_password($password), $storedHash)) {
        if (password_needs_rehash($storedHash, PASSWORD_HASH_ALGO, PASSWORD_HASH_OPTIONS)) {
            rehash_password($db, $userId, $password);
        }
        return true;
    }

    // Legacy path — hash predates peppering (plain password_verify, no HMAC).
    if (password_verify($password, $storedHash)) {
        rehash_password($db, $userId, $password);
        return true;
    }

    return false;
}

function rehash_password(PDO $db, int $userId, string $password): void {
    $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
       ->execute([hash_password($password), $userId]);
}
