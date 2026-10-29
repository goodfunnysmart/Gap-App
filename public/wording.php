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
gap_throttle('wording', 20, 2);

$input = gap_json_body(8000);
$ids = [];
foreach ($input['ids'] ?? [] as $id) {
    if (is_string($id) && preg_match('/^mt_[A-Za-z0-9_-]{10}$/', $id)) {
        $ids[] = $id;
    }
}
$ids = array_values(array_unique($ids));
if ($ids === []) {
    gap_exit(422, 'Choose a skill first.');
}
$task = (string) ($input['task'] ?? '');
try {
    $result = gap_polish_wording(array_slice($ids, 0, 8), $task);
} catch (Throwable $e) {
    gap_exit(500, 'The checks could not be worded. The Marble wording is unchanged.');
}

echo json_encode(['ok' => true, 'source' => $result['source'], 'items' => $result['items']], JSON_UNESCAPED_UNICODE);
