<?php
declare(strict_types=1);

ini_set('display_errors', '0');
define('GAP_PUBLIC', __DIR__);
require __DIR__ . '/private_path.php';
gap_require_private(__DIR__);

gap_send_security_headers();
gap_start_session();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'GET') {
    echo json_encode(['ok' => true, 'csrf' => gap_csrf(), 'ai' => gap_ai_ready()], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($method !== 'POST') {
    gap_exit(405, 'Use Gap App from its own page.');
}
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 7000000) {
    gap_exit(413, 'That photo is too large. Try a closer crop of the question.');
}
gap_check_origin_and_csrf();
gap_throttle('identify', 30, 1);

try {
    $result = gap_accept_task();
} catch (InvalidArgumentException $e) {
    gap_exit(422, $e->getMessage());
} catch (Throwable $e) {
    gap_exit(500, 'The task could not be read. Paste it, or describe it in your own words.');
}

echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);

function gap_accept_task(): array
{
    $task = gap_redact_task((string) ($_POST['task'] ?? ''));
    $file = $_FILES['image'] ?? null;
    $hasFile = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($hasFile) {
        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException('That photo did not upload. Try again, or paste the task.');
        }
        $bytes = file_get_contents($file['tmp_name']);
        if (!is_string($bytes) || $bytes === '') {
            throw new InvalidArgumentException('That photo did not upload. Try again, or paste the task.');
        }
        $sampleHash = gap_sample_hash();
        $hash = hash('sha256', $bytes);
        if ($sampleHash !== '' && hash_equals($sampleHash, $hash)) {
            $found = gap_identify_text(GAP_SAMPLE_TASK, false);
            $found['source'] = 'sample';
            return $found;
        }
        if ($task !== '') {
            return gap_identify_text($task, true);
        }
        $jpeg = gap_prepare_image($bytes);
        unset($bytes);
        return gap_identify_image($jpeg, true);
    }
    if ((string) ($_POST['sample'] ?? '') === '1' && $task === '') {
        $found = gap_identify_text(GAP_SAMPLE_TASK, false);
        $found['source'] = 'sample';
        return $found;
    }
    return gap_identify_text($task, true);
}
