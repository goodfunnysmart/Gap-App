<?php
declare(strict_types=1);

define('GAP_PUBLIC', dirname(__DIR__) . '/public');
require dirname(__DIR__) . '/private/image.php';
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

$image = imagecreatetruecolor(16, 10);
$color = imagecolorallocate($image, 20, 40, 60);
imagefilledrectangle($image, 0, 0, 15, 9, $color);
ob_start();
imagejpeg($image, null, 90);
$jpeg = (string) ob_get_clean();
imagedestroy($image);
$secret = 'EXIFSECRET Student Alex School Code 123';
$comment = "\xFF\xFE" . pack('n', 2 + strlen($secret)) . $secret;
$jpeg = substr($jpeg, 0, 2) . $comment . substr($jpeg, 2);
check(str_contains($jpeg, 'EXIFSECRET'), 'the fixture photo contains a comment');
$clean = gap_prepare_image($jpeg);
check(!str_contains($clean, 'EXIFSECRET') && !str_contains($clean, 'Alex'), 're-encoding strips the comment and the name');
check(str_starts_with($clean, "\xFF\xD8"), 'the cleaned photo is still a JPEG');

$sample = (string) file_get_contents(GAP_PUBLIC . '/assets/sample-task.png');
check(hash('sha256', $sample) === gap_sample_hash(), 'the sample task photo hash matches the shipped file');

$sources = '';
foreach (['public/identify.php', 'public/plan.php', 'public/wording.php', 'private/gemini.php', 'private/image.php', 'private/marble.php'] as $path) {
    $sources .= (string) file_get_contents(dirname(__DIR__) . '/' . $path);
}
check(!str_contains($sources, 'file_put_contents') && !str_contains($sources, 'move_uploaded_file'), 'task photos and text are not written to disk');
check(!str_contains($sources, 'temperature') && !str_contains($sources, 'topP') && !str_contains($sources, 'topK'), 'the Gemini call omits sampling parameters');
check(str_contains((string) file_get_contents(dirname(__DIR__) . '/private/gemini.php'), 'gemini'), 'wording and reading go through the Gemini helper');
check(!preg_match('/AIza[0-9A-Za-z_\-]{20,}/', $sources), 'no live API key is baked into the source');

$example = (string) file_get_contents(dirname(__DIR__) . '/private/config.example.php');
check(str_contains($example, "'GEMINI_MODEL' => 'gemini-3.5-flash-lite'"), 'the example model is gemini-3.5-flash-lite');
check(str_contains($example, 'REPLACE_WITH_YOUR_KEY'), 'the example key is only a placeholder');
check(!is_file(dirname(__DIR__) . '/private/config.php'), 'config.php is not in the working tree');

if ($failed) {
    fwrite(STDERR, "$failed failed\n");
    exit(1);
}
fwrite(STDOUT, "privacy checks passed\n");
