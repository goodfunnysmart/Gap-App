<?php
declare(strict_types=1);

define('GAP_PUBLIC', dirname(__DIR__) . '/public');
require dirname(__DIR__) . '/private/marble.php';

$failed = 0;
function check(bool $ok, string $message): void
{
    global $failed;
    if ($ok) {
        fwrite(STDOUT, "ok  $message\n");
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL $message\n");
}

$task = GAP_SAMPLE_TASK;
$cards = gap_choose_cards($task, 3);
$titles = array_map(static fn (array $topic): string => gap_card($topic)['title'], $cards);
check($titles[0] === 'Square numbers', 'sample task ranks square numbers first');
check(in_array('Area: the space inside a shape', $titles, true), 'sample task offers understanding area');
check(in_array('Area by multiplying the sides', $titles, true), 'sample task offers multiplying the sides');

$fractions = gap_choose_cards('Add 1/3 and 1/6. Adding fractions with different denominators.', 1);
check(gap_card($fractions[0])['title'] === 'Adding fractions with different bottoms', 'fraction wording stays plain English');

$areaId = 'mt_6xNmQLzuqm';
$history = [];
$landed = null;
for ($i = 0; $i < 6; $i++) {
    $plan = gap_plan($areaId, ['start', 'wrong'], $task, $history);
    if ($plan['phase'] !== 'check') {
        $landed = $plan;
        break;
    }
    $got = $plan['check']['id'] !== $areaId;
    $history[] = ['id' => $plan['check']['id'], 'got' => $got];
}
check(is_array($landed) && $landed['phase'] === 'start', 'checks finish at a place to start');
check(($landed['gap']['title'] ?? '') === 'Area: the space inside a shape', 'the gap for the sample is understanding area');
check(($landed['gap']['activity'] ?? '') === 'Draw 2×2, 3×3 and 4×4 squares. Count the little squares inside. Spot the pattern.', 'try this matches the area activity');
check(($landed['gap']['sketch'] ?? '') === 'squares', 'the activity can show the square sketch');
$labels = array_column($landed['next'] ?? [], 'label');
check($labels === ['Square numbers', 'What number times itself makes 81?', 'Back to the task'], 'three forward steps end back at the task');
check(in_array('AC9M4M02', $landed['gap']['info']['codes'] ?? [], true), 'Australian Curriculum v9 code stays behind the detail');
check(count($landed['gap']['info']['path'] ?? []) >= 3, 'the full path is available');

$grid = null;
$history = [];
for ($i = 0; $i < 5; $i++) {
    $plan = gap_plan($areaId, ['start', 'wrong'], $task, $history);
    if ($plan['phase'] !== 'check') {
        break;
    }
    if (($plan['check']['figure']['type'] ?? '') === 'grid') {
        $grid = $plan['check'];
        break;
    }
    $history[] = ['id' => $plan['check']['id'], 'got' => true];
}
check(is_array($grid) && $grid['ask'] === 'How many little squares are inside?' && $grid['answer'] === '9', 'a quick check counts the little squares');

$multiply = gap_wording(gap_marble()['topics']['mt_GzcJEVkNRn'], $task);
check(str_contains($multiply['answer'], '20'), 'rectangle area check keeps the prompt’s numbers');
$squares = gap_wording(gap_marble()['topics']['mt_gxCIASSezX'], $task);
check($squares['ask'] === 'What does 5² mean?' && str_contains($squares['answer'], '25'), 'square-number check comes from the assessment prompt');

$spine = gap_spine('mt_GzcJEVkNRn');
$posArea = array_search('mt_6xNmQLzuqm', $spine, true);
$posTarget = array_search('mt_GzcJEVkNRn', $spine, true);
check($posArea !== false && $posTarget !== false && $posArea < $posTarget, 'understanding area sits before multiplying the sides');

$raw = json_decode((string) file_get_contents(GAP_PUBLIC . '/data/marble.json'), true);
check(($raw['attribution']['databaseLicence'] ?? '') === 'ODbL 1.0', 'built data keeps the ODbL credit');
check(($raw['attribution']['contentLicence'] ?? '') === 'CC BY-SA 4.0', 'built data keeps the CC BY-SA credit');
check(($raw['v'] ?? '') === 'v1' && count($raw['topics']) === 1930, 'the full Marble v1 topic set is in the index');

try {
    gap_plan('mt_0123456789', [], $task, []);
    check(false, 'unknown skill ids are rejected');
} catch (InvalidArgumentException) {
    check(true, 'unknown skill ids are rejected');
}

$dropped = gap_filter_picks([
    ['id' => 'mt_not-a-real', 'title' => 'Invented skill'],
    ['id' => 'mt_gxCIASSezX', 'title' => 'Square numbers'],
], ['mt_gxCIASSezX']);
check(count($dropped) === 1 && $dropped[0]['id'] === 'mt_gxCIASSezX', 'model picks outside the candidate list are dropped');
check(gap_ai_keeps_numbers('9', 'nine little squares') === false, 'a rewritten answer must keep the number');
check(gap_redact_task("Name: Sam Student\nSchool: Example College\nWhat is 3 x 4?") === 'What is 3 x 4?', 'name and school lines are removed before matching');

if ($failed) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
fwrite(STDOUT, "marble checks passed\n");
