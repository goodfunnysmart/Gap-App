<?php
declare(strict_types=1);

ini_set('display_errors', '0');
define('GAP_PUBLIC', __DIR__);
require __DIR__ . '/private_path.php';
gap_require_private(__DIR__);

gap_send_security_headers();
gap_start_session();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    gap_exit(405, 'Use Gap App from its own page.');
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8000) {
    gap_exit(413, 'That was too long. Shorten it and try again.');
}
gap_check_origin_and_csrf();
gap_throttle('plan', 80, 0);

$input = gap_json_body(8000);
try {
    $topicId = (string) ($input['topicId'] ?? '');
    $symptoms = is_array($input['symptoms'] ?? null) ? $input['symptoms'] : [];
    $task = (string) ($input['task'] ?? '');
    $history = is_array($input['history'] ?? null) ? $input['history'] : [];
    $result = gap_plan($topicId, $symptoms, $task, $history);
} catch (InvalidArgumentException $e) {
    gap_exit(422, $e->getMessage());
} catch (Throwable $e) {
    gap_exit(500, 'The checks could not be prepared. Try again.');
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
