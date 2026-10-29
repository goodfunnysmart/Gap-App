<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME'])) {
    $script = realpath((string) $_SERVER['SCRIPT_FILENAME']);
    $self = realpath(__FILE__);
    if ($script !== false && $self !== false && $script === $self) {
        http_response_code(404);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        exit;
    }
}

define('PRIVATE_DIR', __DIR__);

function gap_send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('Cache-Control: no-store');
    header('Content-Type: application/json; charset=utf-8');
}

function gap_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    $cookiePath = rtrim(dirname($script), '/');
    if ($cookiePath === '') {
        $cookiePath = '/';
    }
    session_name('GAPAPP');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function gap_csrf(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function gap_config(): array
{
    $path = PRIVATE_DIR . '/config.php';
    if (!is_file($path)) {
        return [];
    }
    $config = require $path;
    return is_array($config) ? $config : [];
}

function gap_exit(int $status, string $message): never
{
    http_response_code($status);
    if ($status === 405) {
        header('Allow: GET, POST');
    }
    if ($status === 429) {
        header('Retry-After: 60');
    }
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function gap_same_origin_request(): bool
{
    return gap_same_origin($_SERVER);
}

function gap_check_origin_and_csrf(): void
{
    if (!gap_same_origin_request()) {
        gap_exit(403, 'Open Gap App from its own page.');
    }
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $csrf = (string) ($_SESSION['csrf'] ?? '');
    if ($csrf === '' || $token === '' || !hash_equals($csrf, $token)) {
        gap_exit(403, 'Reload the page and try again.');
    }
}

function gap_json_body(int $max): array
{
    $type = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($type !== 'application/json') {
        gap_exit(415, 'Send that again from the Gap App page.');
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) {
        gap_exit(413, 'That was too long. Shorten it and try again.');
    }
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if (!is_string($raw) || strlen($raw) > $max) {
        gap_exit(413, 'That was too long. Shorten it and try again.');
    }
    try {
        $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        gap_exit(422, 'Send that again from the Gap App page.');
    }
    if (!is_array($input) || array_is_list($input)) {
        gap_exit(422, 'Send that again from the Gap App page.');
    }
    return $input;
}

function gap_throttle(string $bucket, int $limit, int $minGap): void
{
    $dir = PRIVATE_DIR . '/throttle';
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (empty($_SESSION['rateKey']) || !is_string($_SESSION['rateKey'])) {
        $_SESSION['rateKey'] = bin2hex(random_bytes(16));
    }
    $limited = gap_rate_limited($dir, 'ip|' . $bucket . '|' . $ip, time(), $limit, $minGap)
        || gap_rate_limited($dir, 'session|' . $bucket . '|' . $_SESSION['rateKey'], time(), $limit, $minGap);
    if ($limited) {
        gap_exit(429, 'Please wait a moment, then try again.');
    }
}

require __DIR__ . '/feedback_lib.php';
require __DIR__ . '/image.php';
require __DIR__ . '/marble.php';
require __DIR__ . '/gemini.php';
