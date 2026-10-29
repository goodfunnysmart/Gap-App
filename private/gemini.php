<?php
declare(strict_types=1);

function gap_ai_config(): array
{
    $config = gap_config();
    $key = getenv('GEMINI_API_KEY');
    $model = getenv('GEMINI_MODEL');
    if (!is_string($key) || $key === '') {
        $key = $config['GEMINI_API_KEY'] ?? '';
    }
    if (!is_string($model) || $model === '') {
        $model = $config['GEMINI_MODEL'] ?? '';
    }
    if (!is_string($key) || !is_string($model)) {
        return ['ok' => false, 'error' => 'GEMINI_API_KEY and GEMINI_MODEL must be text values.'];
    }
    $key = trim($key);
    $model = trim($model);
    if ($key === '' || $key === 'REPLACE_WITH_YOUR_KEY' || $model === '') {
        return ['ok' => false, 'error' => 'Photo and wording help is not configured.'];
    }
    if (!preg_match('/\A[a-zA-Z0-9._-]+\z/', $model)) {
        return ['ok' => false, 'error' => 'The configured model name needs attention.'];
    }
    return ['ok' => true, 'key' => $key, 'model' => $model];
}

function gap_ai_ready(): bool
{
    $config = gap_ai_config();
    return ($config['ok'] ?? false) === true && function_exists('curl_init');
}

function gap_gemini(array $parts, array $schema, int $timeout): array
{
    foreach (['curl_init', 'curl_setopt_array', 'curl_exec', 'curl_errno', 'curl_getinfo', 'curl_close'] as $fn) {
        if (!function_exists($fn)) {
            return ['ok' => false, 'status' => 503, 'error' => 'PHP cURL is not available on this server.'];
        }
    }
    $config = gap_ai_config();
    if (($config['ok'] ?? false) !== true) {
        return ['ok' => false, 'status' => 503, 'error' => 'Photo and wording help is not configured.'];
    }
    $payload = [
        'systemInstruction' => ['parts' => [['text' => gap_gemini_system()]]],
        'contents' => [['role' => 'user', 'parts' => $parts]],
        'generationConfig' => [
            'maxOutputTokens' => 2048,
            'responseMimeType' => 'application/json',
            'responseSchema' => $schema,
        ],
    ];
    try {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } catch (JsonException) {
        return ['ok' => false, 'status' => 500, 'error' => 'The request could not be prepared.'];
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($config['model']) . ':generateContent';
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 503, 'error' => 'The server could not open a connection.'];
    }
    $options = curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $config['key']],
        CURLOPT_POSTFIELDS => $encoded,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    if (!$options) {
        curl_close($ch);
        return ['ok' => false, 'status' => 503, 'error' => 'The server could not open a connection.'];
    }
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno === 28) {
        return ['ok' => false, 'status' => 504, 'error' => 'The reading took too long. Try pasting the task instead.'];
    }
    if ($errno !== 0) {
        return ['ok' => false, 'status' => 502, 'error' => 'The server could not reach the reading service.'];
    }
    if ($status === 429) {
        return ['ok' => false, 'status' => 429, 'error' => 'The reading service is busy. Try again shortly, or paste the task.'];
    }
    if ($status === 404) {
        return ['ok' => false, 'status' => 503, 'error' => 'The configured model is unavailable.'];
    }
    if (in_array($status, [401, 403], true) || ($status === 400 && gap_gemini_key_rejected((string) $body))) {
        return ['ok' => false, 'status' => 503, 'error' => 'The reading service could not be authorised. Ask the administrator to check the private key.'];
    }
    if ($status < 200 || $status >= 300 || !is_string($body)) {
        return ['ok' => false, 'status' => 502, 'error' => 'The reading service did not return a usable result.'];
    }
    try {
        $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['ok' => false, 'status' => 502, 'error' => 'The reading service did not return a usable result.'];
    }
    if (!is_array($decoded)) {
        return ['ok' => false, 'status' => 502, 'error' => 'The reading service did not return a usable result.'];
    }
    $candidate = $decoded['candidates'][0] ?? [];
    if (!empty($decoded['promptFeedback']['blockReason']) || (($candidate['finishReason'] ?? '') !== '' && ($candidate['finishReason'] ?? '') !== 'STOP')) {
        return ['ok' => false, 'status' => 422, 'error' => 'That task could not be read. Try a photo of the question only, or paste the words.'];
    }
    $text = '';
    foreach ($candidate['content']['parts'] ?? [] as $part) {
        if (is_array($part) && empty($part['thought']) && isset($part['text']) && is_string($part['text'])) {
            $text .= $part['text'];
        }
    }
    $parsed = gap_decode_model_json($text);
    if ($parsed === null) {
        return ['ok' => false, 'status' => 502, 'error' => 'The reading service did not return a usable result.'];
    }
    return ['ok' => true, 'json' => $parsed];
}

function gap_gemini_system(): string
{
    return 'You help a parent or teacher notice the learning behind one school task. '
        . 'Never repeat a person\'s name, a school name, a class code, or anything about a face. '
        . 'If those appear, ignore them. Write plain English. Use only the Marble topic ids you are given.';
}

function gap_gemini_key_rejected(string $body): bool
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return false;
    }
    foreach ($decoded['error']['details'] ?? [] as $detail) {
        if (is_array($detail) && ($detail['reason'] ?? '') === 'API_KEY_INVALID') {
            return true;
        }
    }
    return false;
}

function gap_decode_model_json(string $text): ?array
{
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;
    try {
        $decoded = json_decode(trim($text), true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    return is_array($decoded) ? $decoded : null;
}

function gap_schema_read(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'task' => ['type' => 'string'],
            'subject' => ['type' => 'string'],
            'keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => ['task', 'subject', 'keywords'],
    ];
}

function gap_schema_picks(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'task' => ['type' => 'string'],
            'picks' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'detail' => ['type' => 'string'],
                    ],
                    'required' => ['id', 'title', 'detail'],
                ],
            ],
        ],
        'required' => ['task', 'picks'],
    ];
}

function gap_schema_wording(): array
{
    return [
        'type' => 'object',
        'properties' => [
            'items' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'ask' => ['type' => 'string'],
                        'answer' => ['type' => 'string'],
                        'activity' => ['type' => 'string'],
                    ],
                    'required' => ['id', 'ask', 'answer', 'activity'],
                ],
            ],
        ],
        'required' => ['items'],
    ];
}

function gap_identify_text(string $task, bool $allowModel): array
{
    $task = gap_redact_task($task);
    if (mb_strlen($task) < 8) {
        throw new InvalidArgumentException('Add a little more of the task, or take a photo of the question.');
    }
    $ranked = gap_search($task, 24);
    $cards = gap_fill_candidates(array_slice($ranked, 0, 3), []);
    $source = 'keywords';
    if ($allowModel && $ranked !== [] && gap_ai_ready()) {
        $refined = gap_gemini_pick($task, $ranked);
        if ($refined !== null) {
            $cards = $refined['cards'];
            if ($refined['task'] !== '') {
                $task = gap_redact_task($refined['task']);
            }
            $source = 'gemini';
        }
    }
    if ($cards === []) {
        throw new InvalidArgumentException('We could not match that task. Describe it in a few everyday words.');
    }
    return ['task' => $task, 'candidates' => $cards, 'source' => $source];
}

function gap_gemini_pick(string $task, array $ranked): ?array
{
    $lines = [];
    $ids = [];
    foreach (array_slice($ranked, 0, 24) as $topic) {
        $ids[] = $topic['id'];
        $lines[] = $topic['id'] . ' | ' . $topic['name'] . ' | ' . gap_clip($topic['description'], 140);
    }
    $prompt = "The task, with names already removed:\n" . $task
        . "\n\nCandidate Marble skills. Choose 2 or 3 ids from this list only.\n"
        . implode("\n", $lines)
        . "\n\nReturn a short task sentence with no names, and a plain-English title and one-line detail for each pick.";
    $result = gap_gemini([['text' => $prompt]], gap_schema_picks(), 25);
    if (($result['ok'] ?? false) !== true) {
        return null;
    }
    $json = $result['json'];
    $picks = gap_filter_picks(is_array($json['picks'] ?? null) ? $json['picks'] : [], $ids);
    $cards = gap_fill_candidates(array_slice($ranked, 0, 3), $picks);
    if ($cards === []) {
        return null;
    }
    $taskOut = gap_one_line((string) ($json['task'] ?? ''));
    return ['cards' => $cards, 'task' => $taskOut];
}

function gap_identify_image(string $jpeg, bool $allowModel): array
{
    if (!$allowModel || !gap_ai_ready()) {
        throw new InvalidArgumentException('Photo reading is not set up on this server. Paste the task, or describe it in your own words.');
    }
    $read = gap_gemini([
        ['text' => 'Read only the school question in this photo. Ignore names, faces, school names, and class codes. Return the question, a school subject, and up to 8 keywords.'],
        ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode($jpeg)]],
    ], gap_schema_read(), 30);
    if (($read['ok'] ?? false) !== true) {
        throw new InvalidArgumentException((string) ($read['error'] ?? 'That photo could not be read. Paste the task instead.'));
    }
    $json = $read['json'];
    $task = gap_redact_task((string) ($json['task'] ?? ''));
    $keywords = [];
    foreach ($json['keywords'] ?? [] as $word) {
        if (is_string($word)) {
            $keywords[] = gap_clip(gap_one_line($word), 40);
        }
    }
    $subject = gap_one_line((string) ($json['subject'] ?? ''));
    $blob = trim($task . ' ' . implode(' ', $keywords));
    if (mb_strlen($blob) < 8) {
        throw new InvalidArgumentException('That photo did not show a clear question. Paste the task instead.');
    }
    $ranked = gap_search($blob, 24, $subject !== '' ? $subject : null);
    if ($ranked === []) {
        throw new InvalidArgumentException('We could not match that task. Describe it in a few everyday words.');
    }
    $refined = gap_gemini_pick($task !== '' ? $task : $blob, $ranked);
    if ($refined !== null) {
        return [
            'task' => $refined['task'] !== '' ? gap_redact_task($refined['task']) : gap_redact_task($task),
            'candidates' => $refined['cards'],
            'source' => 'gemini',
        ];
    }
    return [
        'task' => gap_redact_task($task !== '' ? $task : $blob),
        'candidates' => gap_fill_candidates(array_slice($ranked, 0, 3), []),
        'source' => 'keywords',
    ];
}

function gap_polish_wording(array $ids, string $task): array
{
    $pack = gap_wording_pack($ids, $task);
    if ($pack === [] || !gap_ai_ready()) {
        return ['source' => 'marble', 'items' => gap_public_wording($pack)];
    }
    $lines = [];
    foreach ($pack as $item) {
        $lines[] = $item['id'] . "\nSkill: " . $item['name']
            . "\nDescription: " . $item['description']
            . "\nAssessment prompt: " . $item['prompt']
            . "\nCheck to keep: " . $item['ask']
            . "\nAnswer to keep: " . $item['answer'];
    }
    $prompt = "Task the learner is stuck on:\n" . gap_redact_task($task)
        . "\n\nRewrite each check in words a parent can say aloud. Keep the same numbers and the same answer. "
        . "Ground the activity in the assessment prompt and description. Do not invent a different skill. Use only these ids.\n\n"
        . implode("\n\n", $lines);
    $result = gap_gemini([['text' => $prompt]], gap_schema_wording(), 25);
    if (($result['ok'] ?? false) !== true) {
        return ['source' => 'marble', 'items' => gap_public_wording($pack)];
    }
    $items = gap_apply_ai_wording(is_array($result['json']['items'] ?? null) ? $result['json']['items'] : [], $ids, $task);
    if ($items === []) {
        return ['source' => 'marble', 'items' => gap_public_wording($pack)];
    }
    return ['source' => 'gemini', 'items' => $items];
}

function gap_public_wording(array $pack): array
{
    $items = [];
    foreach ($pack as $item) {
        $items[] = [
            'id' => $item['id'],
            'ask' => $item['ask'],
            'answer' => $item['answer'],
            'answerLabel' => $item['answerLabel'],
            'activity' => $item['activity'],
        ];
    }
    return $items;
}
