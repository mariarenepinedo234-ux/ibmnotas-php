<?php
declare(strict_types=1);

function validPassword(string $password, string $hash): bool
{
    if (preg_match('/^([a-f0-9]{32}):([a-f0-9]{128})$/D', $hash, $parts)) {
        $key = sodium_crypto_pwhash_scryptsalsa208sha256(64, $password, $parts[1], 524288, 16777216);
        return hash_equals($parts[2], bin2hex($key));
    }
    return defined('PASSWORD_ARGON2ID') ? password_verify($password, $hash) :
        (str_starts_with($hash, '$argon2id$') && sodium_crypto_pwhash_str_verify($hash, $password));
}

function signIn(PDO $db, string $ci, string $password, string $address): bool
{
    $key = hash('sha256', $address);
    $now = time();
    if (random_int(1, 100) === 1) {
        query($db, 'DELETE FROM login_attempts WHERE expires_at <= ?', [$now]);
    }
    $account = transaction($db, function () use ($db, $key, $now, $ci, $password) {
        query($db, 'INSERT INTO login_attempts (client_key, failures, expires_at) VALUES (?, 0, ?)
            ON DUPLICATE KEY UPDATE failures=IF(expires_at<=?,0,failures), expires_at=IF(expires_at<=?,?,expires_at)',
            [$key, $now + 900, $now, $now, $now + 900]);
        $attempt = query($db, 'SELECT failures FROM login_attempts WHERE client_key = ? FOR UPDATE', [$key])->fetch();
        if ($attempt['failures'] >= 15) {
            return false;
        }
        $user = query($db, 'SELECT id, ci, password FROM users WHERE ci = ? FOR UPDATE', [$ci])->fetch();
        if (!$user || !validPassword($password, $user['password'])) {
            query($db, 'UPDATE login_attempts SET failures = failures + 1 WHERE client_key = ?', [$key]);
            return false;
        }
        query($db, 'DELETE FROM login_attempts WHERE client_key = ?', [$key]);
        if (accountPasswordNeedsRehash($user['password'])) {
            $user['password'] = accountPasswordHash($password);
            query($db, 'UPDATE users SET password = ? WHERE id = ?', [$user['password'], $user['id']]);
        }
        return $user;
    });
    if (!$account) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION = ['user_id' => (int)$account['id'], 'expires' => $now + 28800, 'stamp' => sessionStamp($account['ci'], $account['password'])];
    return true;
}

function sessionStamp(string $ci, string $passwordHash): string
{
    return hash('sha256', $ci . "\0" . $passwordHash);
}

function accountPasswordHash(string $password): string
{
    return defined('PASSWORD_ARGON2ID') ? password_hash($password, PASSWORD_ARGON2ID) :
        sodium_crypto_pwhash_str($password, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);
}

function accountPasswordNeedsRehash(string $hash): bool
{
    return defined('PASSWORD_ARGON2ID') ? password_needs_rehash($hash, PASSWORD_ARGON2ID) :
        !str_starts_with($hash, '$argon2id$');
}
