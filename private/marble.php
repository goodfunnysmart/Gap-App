<?php
declare(strict_types=1);

const GAP_SAMPLE_TASK = 'A square has an area of 81 square centimetres. How long is each side of the square?';

const GAP_SYMPTOMS = [
    'start' => 0.30,
    'wrong' => 0.55,
    'understand' => 0.40,
    'partway' => 0.66,
    'ages' => 0.82,
    'forgets' => 0.74,
];

function gap_marble(): array
{
    static $data = null;
    if (is_array($data)) {
        return $data;
    }
    $path = gap_marble_file();
    $raw = is_file($path) ? file_get_contents($path) : false;
    if ($raw === false || $raw === '') {
        throw new RuntimeException('The Marble skill index is missing.');
    }
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || ($decoded['v'] ?? '') !== 'v1' || !is_array($decoded['topics'] ?? null)) {
        throw new RuntimeException('The Marble skill index could not be read.');
    }
    $topics = [];
    foreach ($decoded['topics'] as $row) {
        if (!is_array($row) || !is_string($row['i'] ?? null)) {
            continue;
        }
        $topic = [
            'id' => $row['i'],
            'name' => (string) ($row['n'] ?? ''),
            'subject' => (string) ($row['s'] ?? ''),
            'domain' => (string) ($row['d'] ?? ''),
            'description' => (string) ($row['ds'] ?? ''),
            'prompt' => (string) ($row['p'] ?? ''),
            'ageStart' => (int) ($row['a'] ?? 0),
            'ageEnd' => (int) ($row['b'] ?? 0),
            'centrality' => (float) ($row['c'] ?? 0),
            'acara' => is_array($row['ac'] ?? null) ? array_values(array_filter($row['ac'], 'is_string')) : [],
        ];
        $topic['tokens'] = [
            'name' => gap_tokens($topic['name']),
            'domain' => gap_tokens($topic['domain']),
            'desc' => gap_tokens($topic['description']),
            'prompt' => gap_tokens($topic['prompt']),
        ];
        $topics[$topic['id']] = $topic;
    }
    $hard = [];
    $soft = [];
    $reason = [];
    foreach ($decoded['edges'] ?? [] as $edge) {
        if (!is_array($edge) || count($edge) < 3) {
            continue;
        }
        $topicId = (string) $edge[0];
        $prereqId = (string) $edge[1];
        if (!isset($topics[$topicId], $topics[$prereqId])) {
            continue;
        }
        $isHard = (int) $edge[2] === 1;
        if ($isHard) {
            $hard[$topicId][] = $prereqId;
        } else {
            $soft[$topicId][] = $prereqId;
        }
        $reason[$topicId . '>' . $prereqId] = (string) ($edge[3] ?? '');
    }
    $data = [
        'attribution' => is_array($decoded['attribution'] ?? null) ? $decoded['attribution'] : [],
        'topics' => $topics,
        'hard' => $hard,
        'soft' => $soft,
        'reason' => $reason,
    ];
    return $data;
}

function gap_marble_file(): string
{
    if (defined('GAP_PUBLIC')) {
        return rtrim(GAP_PUBLIC, '/') . '/data/marble.json';
    }
    return dirname(__DIR__) . '/public/data/marble.json';
}

function gap_sample_hash(): string
{
    $path = defined('GAP_PUBLIC')
        ? rtrim(GAP_PUBLIC, '/') . '/assets/sample-task.png'
        : dirname(__DIR__) . '/public/assets/sample-task.png';
    if (!is_file($path)) {
        return '';
    }
    $hash = hash_file('sha256', $path);
    return is_string($hash) ? $hash : '';
}

function gap_tokens(string $text): array
{
    $text = mb_strtolower($text);
    $text = str_replace(['²', '³', '×'], ['2', '3', ' x '], $text);
    $text = preg_replace('/[^a-z0-9+ ]+/u', ' ', $text) ?? $text;
    $stop = array_flip(['a', 'an', 'the', 'of', 'to', 'and', 'or', 'in', 'on', 'for', 'with', 'by', 'from', 'that', 'this', 'is', 'are', 'was', 'be', 'it', 'its', 'their', 'them', 'they', 'can', 'how', 'what', 'when', 'if', 'into', 'as', 'at', 'has', 'each', 'long', 'do', 'does']);
    $out = [];
    foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
        if ($word === '' || isset($stop[$word]) || strlen($word) < 2) {
            continue;
        }
        $out[] = gap_stem($word);
    }
    return $out;
}

function gap_stem(string $word): string
{
    if (str_ends_with($word, 'es') && strlen($word) > 4) {
        return substr($word, 0, -2);
    }
    if (str_ends_with($word, 's') && !str_ends_with($word, 'ss') && strlen($word) > 3) {
        return substr($word, 0, -1);
    }
    return $word;
}

function gap_is_side_of_square(string $text): bool
{
    $lower = mb_strtolower($text);
    $square = str_contains($lower, 'square');
    $area = str_contains($lower, 'area');
    $side = str_contains($lower, 'side') || str_contains($lower, 'how long');
    return $square && $area && $side;
}

function gap_perfect_square(string $text): ?int
{
    if (!preg_match_all('/\b(\d+)\b/', $text, $matches)) {
        return null;
    }
    foreach ($matches[1] as $raw) {
        $n = (int) $raw;
        if ($n < 4 || $n > 400) {
            continue;
        }
        $root = (int) round(sqrt($n));
        if ($root * $root === $n) {
            return $n;
        }
    }
    return null;
}

function gap_search(string $text, int $limit = 24, ?string $subject = null): array
{
    $marble = gap_marble();
    $query = array_values(array_unique(gap_tokens($text)));
    if ($query === []) {
        return [];
    }
    $qset = array_flip($query);
    $side = gap_is_side_of_square($text);
    $off = ['triangle', 'circle', 'fraction', 'volume', 'net', 'scale', 'compound', 'parallelogram', 'solid', 'prism', 'cylinder', 'pythagora', 'trapez', 'decimal', 'percent', 'angle', 'coordinate', 'transform', 'symmetr'];
    $ranked = [];
    foreach ($marble['topics'] as $topic) {
        $score = 0.0;
        $nameHits = 0;
        foreach ($query as $word) {
            if (in_array($word, $topic['tokens']['name'], true)) {
                $score += 12;
                $nameHits++;
            }
            if (in_array($word, $topic['tokens']['domain'], true)) {
                $score += 2;
            }
            if (in_array($word, $topic['tokens']['desc'], true)) {
                $score += 4;
            }
            if (in_array($word, $topic['tokens']['prompt'], true)) {
                $score += 1.5;
            }
        }
        $score += $nameHits * 2;
        $nameLine = implode(' ', $topic['tokens']['name']);
        foreach ($off as $bad) {
            $stem = gap_stem($bad);
            if (str_contains($nameLine, $stem) && !isset($qset[$stem]) && !isset($qset[$bad])) {
                $score -= 14;
            }
        }
        if ($side) {
            $name = $topic['name'];
            if (str_contains($name, 'Square and cube')) {
                $score += str_contains($topic['description'], 'real roots') ? 18 : 30;
            }
            if ($name === 'Area of rectangles by multiplication') {
                $score += 22;
            }
            if ($name === 'Understanding Area') {
                $score += 20;
            }
            if ($name === 'Area by Tiling') {
                $score += 8;
            }
            if ($name === 'Area and perimeter of rectangles') {
                $score += 12;
            }
            if ($name === 'Area (age 8+)') {
                $score += 10;
            }
        }
        if (is_string($subject) && $subject !== '' && strcasecmp($topic['subject'], $subject) === 0) {
            $score += 6;
        }
        if ($score <= 0) {
            continue;
        }
        $ranked[] = ['topic' => $topic, 'score' => $score];
    }
    usort($ranked, static function (array $a, array $b): int {
        if ($a['score'] != $b['score']) {
            return $a['score'] < $b['score'] ? 1 : -1;
        }
        $aRoot = str_contains($a['topic']['description'], 'real roots') ? 1 : 0;
        $bRoot = str_contains($b['topic']['description'], 'real roots') ? 1 : 0;
        if ($aRoot !== $bRoot) {
            return $aRoot <=> $bRoot;
        }
        if ($a['topic']['ageStart'] !== $b['topic']['ageStart']) {
            return $a['topic']['ageStart'] <=> $b['topic']['ageStart'];
        }
        return $b['topic']['centrality'] <=> $a['topic']['centrality'];
    });
    $picked = [];
    $seen = [];
    foreach ($ranked as $row) {
        $key = mb_strtolower(gap_strip_age($row['topic']['name']));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $picked[] = $row['topic'];
        if (count($picked) >= $limit) {
            break;
        }
    }
    return $picked;
}

function gap_choose_cards(string $text, int $count = 3): array
{
    $found = gap_search($text, 8);
    return array_slice($found, 0, $count);
}

function gap_strip_age(string $name): string
{
    $stripped = preg_replace('/\s*\(age [^)]*\)\s*/i', ' ', $name) ?? $name;
    return trim(preg_replace('/\s+/', ' ', $stripped) ?? $stripped);
}

function gap_card(array $topic): array
{
    $name = $topic['name'];
    $title = gap_strip_age($name);
    $detail = gap_first_sentence($topic['description']);
    if ($name === 'Understanding Area') {
        $title = 'Area: the space inside a shape';
        $detail = 'Counting the squares that cover a shape';
    } elseif ($name === 'Square and cube numbers' && !str_contains($topic['description'], 'real roots')) {
        $title = 'Square numbers';
        $detail = 'A number times itself, such as 9 × 9';
    } elseif (str_contains($name, 'Square and cube') && str_contains($topic['description'], 'real roots')) {
        $title = 'Square roots';
        $detail = 'The number which times itself gives this amount';
    } elseif ($name === 'Area of rectangles by multiplication') {
        $title = 'Area by multiplying the sides';
        $detail = 'Length times width, instead of counting every square';
    } elseif ($name === 'Area by Tiling') {
        $title = 'Covering a shape with squares';
        $detail = 'Tile it, then see that this matches the side lengths';
    } elseif ($name === 'Area (age 8+)') {
        $title = 'Counting unit squares';
        $detail = 'Count the squares that cover a shape, including parts';
    } elseif ($name === 'Adding fractions (different denominators)') {
        $title = 'Adding fractions with different bottoms';
        $detail = 'Combining fractions with unlike denominators';
    } elseif ($name === 'Area and perimeter of rectangles') {
        $title = 'Area and the distance around';
        $detail = 'The space inside a rectangle or square, and the edge';
    }
    return [
        'title' => $title,
        'detail' => gap_clip($detail, 120),
        'icon' => gap_icon_kind($topic),
    ];
}

function gap_icon_kind(array $topic): string
{
    $name = mb_strtolower($topic['name'] . ' ' . $topic['domain']);
    if (str_contains($name, 'fraction')) {
        return 'fraction';
    }
    if (str_contains($name, 'square and cube') || str_contains($name, 'square number') || str_contains($name, 'square root')) {
        return 'square';
    }
    if (str_contains($name, 'area') || str_contains($name, 'perimeter')) {
        return 'area';
    }
    if (str_contains($topic['domain'], 'Measurement')) {
        return 'measure';
    }
    return 'number';
}

function gap_first_sentence(string $text): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if ($text === '') {
        return '';
    }
    $parts = preg_split('/(?<=[.])\s+/', $text, 2);
    $sentence = trim($parts[0] ?? $text);
    return gap_clip($sentence, 160);
}

function gap_clip(string $text, int $max): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max - 1);
    $space = mb_strrpos($cut, ' ');
    if ($space !== false && $space > 40) {
        $cut = mb_substr($cut, 0, $space);
    }
    return rtrim($cut, ' ,;:') . '…';
}

function gap_spine(string $topicId): array
{
    $marble = gap_marble();
    if (!isset($marble['topics'][$topicId])) {
        return [];
    }
    $chain = [];
    $seen = [];
    $current = $topicId;
    while ($current !== '' && !isset($seen[$current])) {
        $seen[$current] = true;
        $chain[] = $current;
        $candidates = [];
        foreach ($marble['hard'][$current] ?? [] as $prereq) {
            if (!isset($seen[$prereq])) {
                $candidates[] = $prereq;
            }
        }
        if ($candidates === []) {
            foreach ($marble['soft'][$current] ?? [] as $prereq) {
                if (!isset($seen[$prereq])) {
                    $candidates[] = $prereq;
                }
            }
        }
        if ($candidates === []) {
            break;
        }
        $best = $candidates[0];
        $bestKey = gap_prereq_key($marble, $current, $best);
        foreach ($candidates as $prereq) {
            $key = gap_prereq_key($marble, $current, $prereq);
            if ($key > $bestKey) {
                $best = $prereq;
                $bestKey = $key;
            }
        }
        $current = $best;
    }
    return array_reverse($chain);
}

function gap_prereq_key(array $marble, string $current, string $prereq): array
{
    $topic = $marble['topics'][$prereq];
    $same = $topic['subject'] === $marble['topics'][$current]['subject'] ? 1 : 0;
    return [$same, $topic['ageEnd'], $topic['centrality']];
}

function gap_bias(array $symptoms): float
{
    $values = [];
    foreach ($symptoms as $symptom) {
        if (isset(GAP_SYMPTOMS[$symptom])) {
            $values[] = GAP_SYMPTOMS[$symptom];
        }
    }
    if ($values === []) {
        return 0.5;
    }
    return array_sum($values) / count($values);
}

function gap_probes(array $spine): array
{
    $count = count($spine);
    if ($count === 0) {
        return [];
    }
    if ($count <= 3) {
        return $spine;
    }
    $fracs = $count <= 6 ? [0.2, 0.45, 0.7, 1.0] : [0.22, 0.42, 0.58, 0.75, 1.0];
    $picked = [];
    $last = $count - 1;
    foreach ($fracs as $frac) {
        $index = (int) round($frac * $last);
        $id = $spine[$index];
        if (!in_array($id, $picked, true)) {
            $picked[] = $id;
        }
    }
    $target = $spine[$last];
    if (!in_array($target, $picked, true)) {
        $picked[count($picked) - 1] = $target;
    }
    $wanted = array_flip($picked);
    $ordered = [];
    foreach ($spine as $id) {
        if (isset($wanted[$id])) {
            $ordered[] = $id;
        }
    }
    return $ordered;
}

function gap_normalise_symptoms(array $symptoms): array
{
    $out = [];
    foreach ($symptoms as $symptom) {
        if (is_string($symptom) && isset(GAP_SYMPTOMS[$symptom]) && !in_array($symptom, $out, true)) {
            $out[] = $symptom;
        }
    }
    return $out;
}

function gap_normalise_history(array $history, array $probes): array
{
    $allowed = array_flip($probes);
    $out = [];
    $seen = [];
    foreach ($history as $row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException('A check answer was not recognised.');
        }
        $id = (string) ($row['id'] ?? '');
        if (!isset($allowed[$id]) || isset($seen[$id])) {
            throw new InvalidArgumentException('A check answer was not recognised.');
        }
        $seen[$id] = true;
        $out[] = ['id' => $id, 'got' => (bool) ($row['got'] ?? false)];
    }
    if (count($out) > 5) {
        throw new InvalidArgumentException('Too many check answers.');
    }
    return $out;
}

function gap_plan(string $topicId, array $symptoms, string $task, array $history): array
{
    $marble = gap_marble();
    if (!isset($marble['topics'][$topicId])) {
        throw new InvalidArgumentException('Choose one of the skills we suggested.');
    }
    $symptoms = gap_normalise_symptoms($symptoms);
    $spine = gap_spine($topicId);
    $probes = gap_probes($spine);
    if ($probes === []) {
        throw new InvalidArgumentException('Choose one of the skills we suggested.');
    }
    $history = gap_normalise_history($history, $probes);
    $bias = gap_bias($symptoms);
    $next = gap_next_probe($probes, $history, $bias);
    $task = gap_redact_task($task);
    if ($next['phase'] === 'check') {
        $checkId = $probes[$next['pick']];
        $wording = gap_wording($marble['topics'][$checkId], $task);
        return [
            'ok' => true,
            'phase' => 'check',
            'number' => count($history) + 1,
            'total' => count($probes),
            'source' => 'marble',
            'probeIds' => $probes,
            'check' => gap_public_check($marble, $spine, $checkId, $wording),
        ];
    }
    $gapId = gap_gap_id($spine, $probes, $history);
    $siblings = gap_search($task, 6);
    $forward = gap_forward($spine, $gapId, $task, $siblings);
    $wording = gap_wording($marble['topics'][$gapId], $task);
    $card = gap_card($marble['topics'][$gapId]);
    return [
        'ok' => true,
        'phase' => 'start',
        'source' => 'marble',
        'probeIds' => $probes,
        'gap' => [
            'id' => $gapId,
            'title' => $card['title'],
            'detail' => $card['detail'],
            'activity' => $wording['activity'],
            'sketch' => $wording['sketch'],
            'info' => gap_info($marble, $spine, $gapId),
        ],
        'next' => $forward,
    ];
}

function gap_next_probe(array $probes, array $history, float $bias): array
{
    $count = count($probes);
    $asked = [];
    foreach ($history as $row) {
        $asked[$row['id']] = $row['got'];
    }
    $indexOf = array_flip($probes);
    $floor = -1;
    $ceil = $count;
    foreach ($probes as $index => $id) {
        if (!isset($asked[$id])) {
            continue;
        }
        if ($asked[$id]) {
            $floor = max($floor, $index);
        } else {
            $ceil = min($ceil, $index);
        }
    }
    $done = count($history);
    $minChecks = min(3, $count);
    $maxChecks = min(5, $count);
    $inside = [];
    for ($index = $floor + 1; $index < $ceil; $index++) {
        if (!isset($asked[$probes[$index]])) {
            $inside[] = $index;
        }
    }
    $unasked = [];
    foreach ($probes as $index => $id) {
        if (!isset($asked[$id])) {
            $unasked[] = $index;
        }
    }
    $bracketClosed = ($ceil - $floor) <= 1;
    if ($unasked === [] || $done >= $maxChecks || ($done >= $minChecks && ($inside === [] || $bracketClosed))) {
        return ['phase' => 'start'];
    }
    if ($inside === [] && $done < $minChecks) {
        $boundary = $floor >= 0 ? $floor : 0;
        return ['phase' => 'check', 'pick' => gap_closest($unasked, $boundary)];
    }
    if ($done === 0) {
        $want = (int) round($bias * ($count - 1));
        return ['phase' => 'check', 'pick' => gap_closest($inside, $want)];
    }
    $last = $history[$done - 1];
    $lastIndex = $indexOf[$last['id']];
    if ($last['got']) {
        $harder = array_values(array_filter($inside, static fn (int $index): bool => $index > $lastIndex));
        $pool = $harder !== [] ? $harder : $inside;
        $target = (int) floor(($lastIndex + $ceil) / 2);
    } else {
        $easier = array_values(array_filter($inside, static fn (int $index): bool => $index < $lastIndex));
        $pool = $easier !== [] ? $easier : $inside;
        $target = (int) ceil(($floor + $lastIndex) / 2);
    }
    return ['phase' => 'check', 'pick' => gap_closest($pool, $target)];
}

function gap_closest(array $indexes, int $target): int
{
    $best = $indexes[0];
    foreach ($indexes as $index) {
        $better = abs($index - $target) < abs($best - $target);
        $tieHigher = abs($index - $target) === abs($best - $target) && $index > $best;
        if ($better || $tieHigher) {
            $best = $index;
        }
    }
    return $best;
}

function gap_gap_id(array $spine, array $probes, array $history): string
{
    $asked = [];
    foreach ($history as $row) {
        $asked[$row['id']] = $row['got'];
    }
    foreach ($probes as $id) {
        if (isset($asked[$id]) && $asked[$id] === false) {
            return $id;
        }
    }
    return $spine[count($spine) - 1];
}

function gap_forward(array $spine, string $gapId, string $task, array $siblings): array
{
    $gapAt = array_search($gapId, $spine, true);
    $after = $gapAt === false ? [] : array_slice($spine, (int) $gapAt + 1);
    $marble = gap_marble();
    $labels = [];
    foreach (array_slice($after, 0, 2) as $id) {
        $labels[] = gap_card($marble['topics'][$id])['title'];
    }
    if ($labels === []) {
        $extra = gap_forward_sibling($marble, $spine, $gapAt === false ? 0 : (int) $gapAt, $siblings);
        if ($extra !== null) {
            $labels[] = gap_card($extra)['title'];
        }
    }
    $bridge = gap_task_bridge($task);
    if ($bridge !== null) {
        if (count($labels) >= 2) {
            $labels[1] = $bridge;
        } else {
            $labels[] = $bridge;
        }
    }
    if ($labels === []) {
        $labels[] = 'Practise it on a smaller question';
    }
    if (count($labels) === 1) {
        $labels[] = 'Use the same idea once more, a little larger';
    }
    $labels = array_slice($labels, 0, 2);
    $labels[] = 'Back to the task';
    $steps = [];
    foreach ($labels as $index => $label) {
        $steps[] = ['n' => $index + 1, 'label' => $label];
    }
    return $steps;
}

function gap_forward_sibling(array $marble, array $spine, int $gapAt, array $siblings): ?array
{
    $before = array_flip(array_slice($spine, 0, $gapAt + 1));
    $gap = $marble['topics'][$spine[$gapAt]] ?? null;
    foreach ($siblings as $topic) {
        if (isset($before[$topic['id']])) {
            continue;
        }
        if ($gap !== null && $topic['ageEnd'] < $gap['ageStart']) {
            continue;
        }
        if (str_contains($topic['description'], 'real roots')) {
            continue;
        }
        return $topic;
    }
    return null;
}

function gap_task_bridge(string $task): ?string
{
    if (!gap_is_side_of_square($task)) {
        return null;
    }
    $n = gap_perfect_square($task);
    if ($n === null) {
        return null;
    }
    return 'What number times itself makes ' . $n . '?';
}

function gap_wording(array $topic, string $task): array
{
    $prompt = $topic['prompt'];
    $name = $topic['name'];
    $figure = null;
    $label = 'Answer';
    $ask = '';
    $answer = '';

    if ($name === 'Understanding Area') {
        $ask = 'How many little squares are inside?';
        $answer = '9';
        $figure = ['type' => 'grid', 'cols' => 3, 'rows' => 3];
    } elseif ($name === 'Area (age 8+)') {
        $ask = 'How many whole squares does this shape cover?';
        $answer = '6';
        $figure = ['type' => 'grid', 'cols' => 3, 'rows' => 2];
    } elseif (preg_match("/['‘](\d+)²['’]\s*means\s*(\d+)\s*[×x]\s*(\d+)\s*=\s*(\d+)/u", $prompt, $square)) {
        $ask = 'What does ' . $square[1] . '² mean?';
        $answer = $square[2] . ' × ' . $square[3] . ' = ' . $square[4];
    } elseif (preg_match('/√\s*(\d+)\s*=\s*(\d+)/u', $prompt, $root)) {
        $ask = 'What is √' . $root[1] . '?';
        $answer = $root[2] . ', because ' . $root[2] . ' × ' . $root[2] . ' = ' . $root[1];
    } elseif (preg_match("/['‘](\d+)\s*[×x]\s*(\d+)\s*=\s*(\d+)['’]/u", $prompt, $fact)) {
        $ask = 'What is ' . $fact[1] . ' × ' . $fact[2] . '?';
        $answer = $fact[3];
    } elseif (str_contains($prompt, 'skip-count in twos') || $name === 'Counting in 2s') {
        $ask = 'Count in twos from 2 up to 20.';
        $answer = '2, 4, 6, 8, 10, 12, 14, 16, 18, 20';
    } elseif ($dims = gap_dimensions($prompt)) {
        $area = $dims['w'] * $dims['h'];
        $unit = $dims['unit'];
        $squareUnit = $unit === 'cm' ? 'square cm' : 'square metres';
        $perimeter = str_contains(mb_strtolower($name . ' ' . $prompt), 'perimeter') || str_contains(mb_strtolower($prompt), 'skirting') || str_contains(mb_strtolower($prompt), 'around');
        if ($perimeter && str_contains(mb_strtolower($name), 'perimeter') && !str_contains(mb_strtolower($name), 'area')) {
            $edge = 2 * ($dims['w'] + $dims['h']);
            $ask = 'A rectangle is ' . gap_num($dims['w']) . ' ' . $unit . ' by ' . gap_num($dims['h']) . ' ' . $unit . '. How far is it around the edge?';
            $answer = gap_num($edge) . ' ' . $unit;
            $figure = ['type' => 'rect', 'w' => gap_num($dims['w']) . ' ' . $unit, 'h' => gap_num($dims['h']) . ' ' . $unit];
        } elseif ($perimeter && str_contains(mb_strtolower($name), 'area')) {
            $edge = 2 * ($dims['w'] + $dims['h']);
            $ask = 'A rectangle is ' . gap_num($dims['w']) . ' ' . $unit . ' by ' . gap_num($dims['h']) . ' ' . $unit . '. What is the area, and how far is it around?';
            $answer = gap_num($area) . ' ' . $squareUnit . ', and ' . gap_num($edge) . ' ' . $unit . ' around';
            $figure = ['type' => 'rect', 'w' => gap_num($dims['w']) . ' ' . $unit, 'h' => gap_num($dims['h']) . ' ' . $unit];
        } else {
            $ask = 'What is the area of this ' . ($dims['w'] === $dims['h'] ? 'square' : 'rectangle') . '?';
            $answer = gap_num($area) . ' ' . $squareUnit;
            $figure = [
                'type' => $dims['w'] === $dims['h'] ? 'square' : 'rect',
                'w' => gap_num($dims['w']) . ' ' . $unit,
                'h' => gap_num($dims['h']) . ' ' . $unit,
                'side' => gap_num($dims['w']) . ' ' . $unit,
            ];
        }
    } else {
        $ask = gap_generic_ask($prompt, $name);
        $answer = gap_first_sentence($topic['description']);
        $label = 'Listen for';
    }

    if ($ask === '') {
        $ask = gap_generic_ask($prompt, $name);
    }
    if ($answer === '') {
        $answer = gap_first_sentence($topic['description']);
        $label = 'Listen for';
    }

    $activity = gap_activity_text($topic, $task, $ask);
    return [
        'ask' => gap_clip($ask, 180),
        'answer' => gap_clip($answer, 160),
        'answerLabel' => $label,
        'activity' => $activity,
        'figure' => $figure,
        'sketch' => str_starts_with($activity, 'Draw 2×2') ? 'squares' : 'none',
    ];
}

function gap_activity_text(array $topic, string $task, string $ask): string
{
    $name = $topic['name'];
    $squareTask = str_contains(mb_strtolower($task), 'square');
    if (($name === 'Understanding Area' || $name === 'Area (age 8+)') && $squareTask) {
        return 'Draw 2×2, 3×3 and 4×4 squares. Count the little squares inside. Spot the pattern.';
    }
    if ($name === 'Understanding Area' || $name === 'Area (age 8+)') {
        return 'Draw a shape on squared paper. Count the whole squares, then estimate any partial squares.';
    }
    if (str_contains($name, 'Square and cube') && !str_contains($topic['description'], 'real roots')) {
        return 'Make a 5 by 5 square with counters and count them. Then try one a little bigger.';
    }
    $sentence = gap_first_sentence($topic['description']);
    if ($sentence !== '') {
        return $sentence;
    }
    return 'Try a smaller version together: ' . rtrim($ask, '.') . '.';
}

function gap_dimensions(string $prompt): ?array
{
    $patterns = [
        '/(\d+(?:\.\d+)?)\s*(cm|m)\b[^.]{0,48}?(?:by|×|x)\s*(\d+(?:\.\d+)?)\s*(cm|m)?/iu',
        '/(\d+(?:\.\d+)?)\s*(cm|m)\s+long and\s+(\d+(?:\.\d+)?)\s*(cm|m)\s+wide/iu',
    ];
    foreach ($patterns as $pattern) {
        if (!preg_match($pattern, $prompt, $match)) {
            continue;
        }
        return [
            'w' => (float) $match[1],
            'h' => (float) $match[3],
            'unit' => strtolower($match[2]) === 'cm' || strtolower((string) ($match[4] ?? '')) === 'cm' ? 'cm' : 'm',
        ];
    }
    return null;
}

function gap_num(float $number): string
{
    if (abs($number - round($number)) < 0.001) {
        return (string) (int) round($number);
    }
    return rtrim(rtrim(number_format($number, 1, '.', ''), '0'), '.');
}

function gap_generic_ask(string $prompt, string $name): string
{
    $clean = preg_replace('/\{\{\s*name\s*\}\}/i', 'they', $prompt) ?? $prompt;
    $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
    if (preg_match('/^If they (.+?), can they (.+)\?$/iu', $clean, $match)) {
        $actionBody = trim(preg_split('/\s+[—–]\s+/u', rtrim($match[2], '?'))[0] ?? $match[2]);
        $action = 'Can they ' . $actionBody . '?';
        $situation = preg_replace('/^(?:measures|needs to measure|needs to|wants to|is)\s+/iu', '', trim($match[1])) ?? trim($match[1]);
        $situation = ucfirst(rtrim($situation, '.'));
        if ($situation === '' || mb_strlen($situation) > 48) {
            return $action;
        }
        return $situation . '. ' . $action;
    }
    if (preg_match('/^Can they (.+)\?$/iu', $clean, $match)) {
        return 'Can they ' . rtrim($match[1], '?') . '?';
    }
    if (preg_match('/^Does they know that (.+?) — and can they (.+)\?$/iu', $clean, $match)) {
        return ucfirst(rtrim($match[1], '.')) . '?';
    }
    if ($clean === '') {
        return 'Can they show you ' . gap_strip_age($name) . '?';
    }
    return rtrim($clean, '.?') . '?';
}

function gap_public_check(array $marble, array $spine, string $id, array $wording): array
{
    $topic = $marble['topics'][$id];
    $card = gap_card($topic);
    return [
        'id' => $id,
        'title' => $card['title'],
        'ask' => $wording['ask'],
        'answer' => $wording['answer'],
        'answerLabel' => $wording['answerLabel'],
        'figure' => $wording['figure'],
        'activity' => $wording['activity'],
        'info' => gap_info($marble, $spine, $id),
    ];
}

function gap_info(array $marble, array $spine, string $id): array
{
    $topic = $marble['topics'][$id];
    $at = array_search($id, $spine, true);
    $why = trim(preg_replace('/\s+/u', ' ', $topic['description']) ?? '');
    if ($why === '') {
        $why = 'This is the skill closest to the task.';
    }
    if ($at !== false && $at < count($spine) - 1) {
        $next = $spine[$at + 1];
        $reason = $marble['reason'][$next . '>' . $id] ?? '';
        if ($reason !== '') {
            $why = $reason;
        }
    }
    $path = [];
    foreach ($spine as $stepId) {
        $step = $marble['topics'][$stepId];
        $path[] = [
            'id' => $stepId,
            'name' => gap_strip_age($step['name']),
            'codes' => $step['acara'],
            'current' => $stepId === $id,
        ];
    }
    $link = null;
    if ($at !== false && $at > 0) {
        $previous = $spine[$at - 1];
        $hard = in_array($previous, $marble['hard'][$id] ?? [], true);
        $link = [
            'topicId' => $id,
            'topicName' => gap_strip_age($topic['name']),
            'prerequisiteId' => $previous,
            'prerequisiteName' => gap_strip_age($marble['topics'][$previous]['name']),
            'strength' => $hard ? 'hard' : 'helpful',
            'reason' => $marble['reason'][$id . '>' . $previous] ?? '',
        ];
    }
    return [
        'name' => $topic['name'],
        'subject' => $topic['subject'],
        'domain' => $topic['domain'],
        'codes' => $topic['acara'],
        'why' => $why,
        'path' => $path,
        'link' => $link,
    ];
}

function gap_public_candidate(array $topic, ?array $override = null): array
{
    $card = gap_card($topic);
    if ($override !== null) {
        if (($override['title'] ?? '') !== '') {
            $card['title'] = gap_clip((string) $override['title'], 80);
        }
        if (($override['detail'] ?? '') !== '') {
            $card['detail'] = gap_clip((string) $override['detail'], 140);
        }
    }
    return [
        'id' => $topic['id'],
        'title' => $card['title'],
        'detail' => $card['detail'],
        'icon' => $card['icon'],
        'subject' => $topic['subject'],
        'domain' => $topic['domain'],
        'codes' => $topic['acara'],
    ];
}

function gap_filter_picks(array $picks, array $allowedIds): array
{
    $allowed = array_flip($allowedIds);
    $marble = gap_marble();
    $out = [];
    $seen = [];
    foreach ($picks as $pick) {
        if (!is_array($pick)) {
            continue;
        }
        $id = (string) ($pick['id'] ?? '');
        if (!isset($allowed[$id], $marble['topics'][$id]) || isset($seen[$id])) {
            continue;
        }
        $title = gap_clip(gap_one_line((string) ($pick['title'] ?? '')), 80);
        if ($title === '' || gap_looks_like_name_line($title)) {
            continue;
        }
        $seen[$id] = true;
        $out[] = gap_public_candidate($marble['topics'][$id], [
            'title' => $title,
            'detail' => gap_one_line((string) ($pick['detail'] ?? '')),
        ]);
        if (count($out) === 3) {
            break;
        }
    }
    return $out;
}

function gap_fill_candidates(array $topics, array $already): array
{
    $seen = [];
    foreach ($already as $row) {
        $seen[$row['id']] = true;
    }
    $out = $already;
    foreach ($topics as $topic) {
        if (isset($seen[$topic['id']])) {
            continue;
        }
        $out[] = gap_public_candidate($topic);
        if (count($out) === 3) {
            break;
        }
    }
    return $out;
}

function gap_redact_task(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;
    $kept = [];
    foreach (explode("\n", $text) as $line) {
        if (preg_match('/^\s*(student|pupil|name|child|learner|school|class|teacher)\s*[:\-]/iu', $line)) {
            continue;
        }
        $kept[] = $line;
    }
    $text = trim(preg_replace('/[ \t]+/', ' ', implode("\n", $kept)) ?? '');
    $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    if (mb_strlen($text) > 2000) {
        $text = mb_substr($text, 0, 2000);
    }
    return trim($text);
}

function gap_one_line(string $text): string
{
    $text = gap_redact_task($text);
    return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
}

function gap_looks_like_name_line(string $text): bool
{
    return (bool) preg_match('/^\s*(student|pupil|name|school|class)\b/iu', $text);
}

function gap_apply_ai_wording(array $items, array $ids, string $task): array
{
    $marble = gap_marble();
    $allowed = array_flip($ids);
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $id = (string) ($item['id'] ?? '');
        if (!isset($allowed[$id], $marble['topics'][$id])) {
            continue;
        }
        $base = gap_wording($marble['topics'][$id], $task);
        $ask = gap_clip(gap_one_line((string) ($item['ask'] ?? '')), 180);
        $answer = gap_clip(gap_one_line((string) ($item['answer'] ?? '')), 160);
        $activity = gap_clip(gap_one_line((string) ($item['activity'] ?? '')), 220);
        if ($ask === '' || !gap_ai_keeps_numbers($base['answer'], $answer)) {
            $ask = $base['ask'];
            $answer = $base['answer'];
        }
        if ($activity === '') {
            $activity = $base['activity'];
        }
        $out[] = [
            'id' => $id,
            'ask' => $ask,
            'answer' => $answer,
            'answerLabel' => $base['answerLabel'],
            'activity' => $activity,
        ];
    }
    return $out;
}

function gap_ai_keeps_numbers(string $golden, string $rewrite): bool
{
    if ($rewrite === '' || mb_strlen($rewrite) > 160) {
        return false;
    }
    if (!preg_match_all('/\d+(?:\.\d+)?/', $golden, $matches) || $matches[0] === []) {
        return true;
    }
    foreach ($matches[0] as $number) {
        if (!str_contains($rewrite, $number)) {
            return false;
        }
    }
    return true;
}

function gap_wording_pack(array $ids, string $task): array
{
    $marble = gap_marble();
    $task = gap_redact_task($task);
    $items = [];
    foreach ($ids as $id) {
        if (!is_string($id) || !isset($marble['topics'][$id])) {
            continue;
        }
        $wording = gap_wording($marble['topics'][$id], $task);
        $items[] = [
            'id' => $id,
            'ask' => $wording['ask'],
            'answer' => $wording['answer'],
            'answerLabel' => $wording['answerLabel'],
            'activity' => $wording['activity'],
            'prompt' => gap_clip(str_replace('{{name}}', 'the learner', $marble['topics'][$id]['prompt']), 320),
            'description' => gap_clip($marble['topics'][$id]['description'], 320),
            'name' => $marble['topics'][$id]['name'],
        ];
        if (count($items) === 8) {
            break;
        }
    }
    return $items;
}
