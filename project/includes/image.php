<?php
// Center-crop to a square, resample down to $size x $size, save as JPEG.
function resize_square_image(string $srcPath, string $destPath, int $size = 200): bool {
    $info = @getimagesize($srcPath);
    if (!$info) return false;

    $src = match ($info['mime']) {
        'image/jpeg' => imagecreatefromjpeg($srcPath),
        'image/png'  => imagecreatefrompng($srcPath),
        default      => null,
    };
    if (!$src) return false;

    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $cropSize = min($srcW, $srcH);
    $cropX = (int) (($srcW - $cropSize) / 2);
    $cropY = (int) (($srcH - $cropSize) / 2);

    $dst = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $size, $size, $cropSize, $cropSize);

    $ok = imagejpeg($dst, $destPath, 85);

    imagedestroy($src);
    imagedestroy($dst);

    return $ok;
}
