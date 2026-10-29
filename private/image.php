<?php
declare(strict_types=1);

function gap_prepare_image(string $bytes): string
{
    if (strlen($bytes) > 6000000) {
        throw new InvalidArgumentException('That photo is too large. Try a closer crop of the question.');
    }
    $info = @getimagesizefromstring($bytes);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        throw new InvalidArgumentException('Use a JPEG, PNG, or WEBP photo of the question.');
    }
    $image = @imagecreatefromstring($bytes);
    if ($image === false) {
        throw new InvalidArgumentException('That photo could not be read. Paste the task instead.');
    }
    if ($mime === 'image/jpeg') {
        $image = gap_apply_orientation($image, $bytes);
    }
    $width = imagesx($image);
    $height = imagesy($image);
    $max = 1600;
    if ($width > $max || $height > $max) {
        $scale = $max / max($width, $height);
        $nw = max(1, (int) round($width * $scale));
        $nh = max(1, (int) round($height * $scale));
        $scaled = imagecreatetruecolor($nw, $nh);
        if ($scaled === false) {
            imagedestroy($image);
            throw new InvalidArgumentException('That photo could not be read. Paste the task instead.');
        }
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $nw, $nh, $width, $height);
        imagedestroy($image);
        $image = $scaled;
    }
    ob_start();
    $wrote = imagejpeg($image, null, 82);
    $jpeg = ob_get_clean();
    imagedestroy($image);
    if (!$wrote || !is_string($jpeg) || $jpeg === '') {
        throw new InvalidArgumentException('That photo could not be read. Paste the task instead.');
    }
    return $jpeg;
}

function gap_apply_orientation($image, string $bytes)
{
    $orientation = 1;
    $uri = 'data://image/jpeg;base64,' . base64_encode($bytes);
    $exif = @exif_read_data($uri);
    if (is_array($exif) && isset($exif['Orientation'])) {
        $orientation = (int) $exif['Orientation'];
    }
    $rotated = match ($orientation) {
        2 => gap_flip($image, IMG_FLIP_HORIZONTAL),
        3 => imagerotate($image, 180, 0),
        4 => gap_flip($image, IMG_FLIP_VERTICAL),
        5 => gap_flip(imagerotate($image, 270, 0) ?: $image, IMG_FLIP_HORIZONTAL),
        6 => imagerotate($image, -90, 0),
        7 => gap_flip(imagerotate($image, 90, 0) ?: $image, IMG_FLIP_HORIZONTAL),
        8 => imagerotate($image, 90, 0),
        default => $image,
    };
    if ($rotated !== $image && $rotated !== false) {
        imagedestroy($image);
        return $rotated;
    }
    return $image;
}

function gap_flip($image, int $mode)
{
    if (function_exists('imageflip')) {
        imageflip($image, $mode);
    }
    return $image;
}
