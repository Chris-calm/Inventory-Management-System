<?php

function client_ip(): string
{
    $ip = '';
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = (string)$_SERVER['HTTP_X_FORWARDED_FOR'];
        if (strpos($ip, ',') !== false) {
            $ip = trim((string)explode(',', $ip)[0]);
        }
    }
    if ($ip === '' && isset($_SERVER['REMOTE_ADDR'])) {
        $ip = (string)$_SERVER['REMOTE_ADDR'];
    }
    return $ip;
}

function audit_log(mysqli $conn, string $action, ?string $details = null, ?int $targetUserId = null): void
{
    if ($action === '') {
        return;
    }

    static $auditTableExists = null;
    if ($auditTableExists === null) {
        $auditTableExists = false;
        try {
            if ($res = $conn->query("SHOW TABLES LIKE 'audit_logs'")) {
                $auditTableExists = (bool)$res->fetch_assoc();
                $res->free();
            }
        } catch (Throwable $e) {
            $auditTableExists = false;
        }
    }

    if (!$auditTableExists) {
        return;
    }

    $actorUserId = (int)($_SESSION['user_id'] ?? 0);
    $actor = $actorUserId > 0 ? $actorUserId : null;

    $ip = client_ip();
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (strlen($ua) > 255) {
        $ua = substr($ua, 0, 255);
    }

    $target = ($targetUserId !== null && $targetUserId > 0) ? $targetUserId : null;

    try {
        $stmt = $conn->prepare("INSERT INTO audit_logs (actor_user_id, target_user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            return;
        }

        $stmt->bind_param(
            'iissss',
            $actor,
            $target,
            $action,
            $details,
            $ip,
            $ua
        );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        return;
    }
}

function require_login(): void
{
    if (!isset($_SESSION["username"])) {
        header("Location: index.php");
        exit();
    }
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_validate(): bool
{
    $token = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    return $token !== '' && $sessionToken !== '' && hash_equals($sessionToken, $token);
}

function load_user_permissions(mysqli $conn, int $userId): array
{
    $perms = [];

    $sql = "SELECT DISTINCT p.perm_key
            FROM permissions p
            JOIN role_permissions rp ON rp.permission_id = p.id
            JOIN user_roles ur ON ur.role_id = rp.role_id
            WHERE ur.user_id = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $perms;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $key = (string)($row['perm_key'] ?? '');
            if ($key !== '') {
                $perms[$key] = true;
            }
        }
    }
    $stmt->close();

    return $perms;
}

function has_perm(string $permKey): bool
{
    if (!isset($_SESSION['perms']) || !is_array($_SESSION['perms'])) {
        return false;
    }
    return isset($_SESSION['perms'][$permKey]);
}

function require_perm(string $permKey): void
{
    if (!has_perm($permKey)) {
        http_response_code(403);
        echo "Forbidden";
        exit();
    }
}

function base32_encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }

    $out = '';
    for ($i = 0; $i < strlen($bits); $i += 5) {
        $chunk = substr($bits, $i, 5);
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $out .= $alphabet[bindec($chunk)];
    }

    return $out;
}

function base32_decode(string $b32): string
{
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32) ?? '');
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';

    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) {
        $v = strpos($alphabet, $b32[$i]);
        if ($v === false) {
            continue;
        }
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }

    $out = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $byte = substr($bits, $i, 8);
        $out .= chr(bindec($byte));
    }
    return $out;
}

function totp_generate_secret(int $bytes = 20): string
{
    if ($bytes < 10) {
        $bytes = 10;
    }
    return base32_encode(random_bytes($bytes));
}

function totp_code(string $base32Secret, int $timestamp = null, int $period = 30): string
{
    $timestamp = $timestamp ?? time();
    $counter = intdiv($timestamp, $period);
    $secret = base32_decode($base32Secret);

    $binCounter = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $binCounter, $secret, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $part = substr($hash, $offset, 4);
    $value = unpack('N', $part);
    $value = $value[1] & 0x7FFFFFFF;
    $mod = $value % 1000000;
    return str_pad((string)$mod, 6, '0', STR_PAD_LEFT);
}

function totp_verify(string $base32Secret, string $code, int $window = 1, int $period = 30): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6) {
        return false;
    }

    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        $t = $now + ($i * $period);
        if (hash_equals(totp_code($base32Secret, $t, $period), $code)) {
            return true;
        }
    }
    return false;
}
