<?php
/**
 * Neptune image processing helpers.
 *
 * Uploaded pit photos are normalized to a web-friendly size. Neptune prefers
 * AVIF, then WebP, then JPEG, depending on the capabilities of the host.
 */

function neptune_ini_bytes(string $value): int {
    $value = trim($value);
    if ($value === '') return 0;
    $last = strtolower(substr($value, -1));
    $number = (float)$value;
    return match ($last) {
        'g' => (int)round($number * 1024 * 1024 * 1024),
        'm' => (int)round($number * 1024 * 1024),
        'k' => (int)round($number * 1024),
        default => (int)$number,
    };
}

function neptune_format_bytes(int $bytes): string {
    if ($bytes <= 0) return 'Unknown';
    if ($bytes >= 1024 * 1024 * 1024) return rtrim(rtrim(number_format($bytes / (1024 * 1024 * 1024), 1), '0'), '.') . ' GB';
    if ($bytes >= 1024 * 1024) return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1), '0'), '.') . ' MB';
    if ($bytes >= 1024) return rtrim(rtrim(number_format($bytes / 1024, 1), '0'), '.') . ' KB';
    return $bytes . ' B';
}

function neptune_imagick_has_format(string $format): bool {
    if (!extension_loaded('imagick') || !class_exists('Imagick')) return false;
    try {
        return count(Imagick::queryFormats(strtoupper($format))) > 0;
    } catch (Throwable) {
        return false;
    }
}

function neptune_image_capabilities(): array {
    $imagick = extension_loaded('imagick') && class_exists('Imagick');
    $gd = extension_loaded('gd');

    $encodeAvif = $imagick ? neptune_imagick_has_format('AVIF') : false;
    $encodeWebp = $imagick ? neptune_imagick_has_format('WEBP') : false;
    $encodeJpeg = $imagick ? neptune_imagick_has_format('JPEG') : false;

    if ($gd) {
        $encodeAvif = $encodeAvif || function_exists('imageavif');
        $encodeWebp = $encodeWebp || function_exists('imagewebp');
        $encodeJpeg = $encodeJpeg || function_exists('imagejpeg');
    }

    $preferred = $encodeAvif ? 'avif' : ($encodeWebp ? 'webp' : ($encodeJpeg ? 'jpg' : 'original'));
    $preferredLabel = match ($preferred) {
        'avif' => 'AVIF',
        'webp' => 'WebP',
        'jpg' => 'JPEG',
        default => 'Original image',
    };

    $uploadMax = neptune_ini_bytes((string)ini_get('upload_max_filesize'));
    $postMax = neptune_ini_bytes((string)ini_get('post_max_size'));
    $effectiveMax = $uploadMax;
    if ($postMax > 0 && ($effectiveMax <= 0 || $postMax < $effectiveMax)) $effectiveMax = $postMax;

    return [
        'imagick' => $imagick,
        'gd' => $gd,
        'avif' => $encodeAvif,
        'webp' => $encodeWebp,
        'jpeg' => $encodeJpeg,
        'heic' => $imagick && (neptune_imagick_has_format('HEIC') || neptune_imagick_has_format('HEIF')),
        'preferred' => $preferred,
        'preferred_label' => $preferredLabel,
        'upload_max_bytes' => $uploadMax,
        'post_max_bytes' => $postMax,
        'effective_upload_max_bytes' => $effectiveMax,
    ];
}

function neptune_upload_error_message(int $error): string {
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The photo is larger than this server allows. Try a smaller photo or increase the PHP upload limit.',
        UPLOAD_ERR_PARTIAL => 'The photo upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server is missing its temporary upload directory.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded photo.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the photo upload.',
        default => 'The pit photo failed to upload.',
    };
}

function neptune_detect_image_mime(string $path): ?string {
    try {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string)$finfo->file($path));
    } catch (Throwable) {
        $mime = '';
    }

    $aliases = [
        'image/jpg' => 'image/jpeg',
        'image/x-png' => 'image/png',
        'image/x-webp' => 'image/webp',
        'image/x-avif' => 'image/avif',
        'image/heif-sequence' => 'image/heif',
        'image/heic-sequence' => 'image/heic',
        'image/x-heic' => 'image/heic',
        'image/x-heif' => 'image/heif',
    ];
    return $aliases[$mime] ?? ($mime ?: null);
}

function neptune_gd_apply_orientation(GdImage $image, string $path): GdImage {
    if (!function_exists('exif_read_data')) return $image;
    try {
        $exif = @exif_read_data($path);
        $orientation = (int)($exif['Orientation'] ?? 1);
    } catch (Throwable) {
        $orientation = 1;
    }

    return match ($orientation) {
        2 => (imageflip($image, IMG_FLIP_HORIZONTAL) ? $image : $image),
        3 => imagerotate($image, 180, 0),
        4 => (imageflip($image, IMG_FLIP_VERTICAL) ? $image : $image),
        5 => (function () use ($image) { imageflip($image, IMG_FLIP_HORIZONTAL); return imagerotate($image, 270, 0); })(),
        6 => imagerotate($image, 270, 0),
        7 => (function () use ($image) { imageflip($image, IMG_FLIP_HORIZONTAL); return imagerotate($image, 90, 0); })(),
        8 => imagerotate($image, 90, 0),
        default => $image,
    };
}

function neptune_gd_decode(string $path, string $mime): ?GdImage {
    try {
        return match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : null,
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : null,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : null,
            default => null,
        };
    } catch (Throwable) {
        return null;
    }
}

function neptune_gd_resize(GdImage $source, int $maxDimension): GdImage {
    $width = imagesx($source);
    $height = imagesy($source);
    $largest = max($width, $height);
    if ($largest <= $maxDimension) return $source;

    $scale = $maxDimension / $largest;
    $newWidth = max(1, (int)round($width * $scale));
    $newHeight = max(1, (int)round($height * $scale));
    $target = imagecreatetruecolor($newWidth, $newHeight);
    imagealphablending($target, false);
    imagesavealpha($target, true);
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefilledrectangle($target, 0, 0, $newWidth, $newHeight, $transparent);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    imagedestroy($source);
    return $target;
}

function neptune_flatten_gd_for_jpeg(GdImage $source): GdImage {
    $width = imagesx($source);
    $height = imagesy($source);
    $target = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($target, 255, 255, 255);
    imagefilledrectangle($target, 0, 0, $width, $height, $white);
    imagealphablending($target, true);
    imagecopy($target, $source, 0, 0, 0, 0, $width, $height);
    imagedestroy($source);
    return $target;
}

/**
 * Normalize a verified uploaded image into the destination directory.
 * Returns filename, mime, width, height, bytes, and processor.
 */
function neptune_store_uploaded_image(array $file, string $destDir, string $namePrefix, int $maxDimension = 1800, int $appMaxBytes = 20_971_520): array {
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) throw new RuntimeException('No photo was selected.');
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException(neptune_upload_error_message($error));

    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('The uploaded photo could not be verified.');
    if ($size <= 0) throw new RuntimeException('The selected photo is empty.');
    if ($size > $appMaxBytes) throw new RuntimeException('Pit photos must be 20 MB or smaller before optimization.');

    $mime = neptune_detect_image_mime($tmp);
    $allowed = ['image/jpeg','image/png','image/webp','image/avif','image/heic','image/heif'];
    if (!$mime || !in_array($mime, $allowed, true)) throw new RuntimeException('Pit photos must be a supported image file (JPEG, PNG, WebP, AVIF, or a phone HEIC/HEIF image when the server supports it).');

    if (!is_dir($destDir) && !mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        throw new RuntimeException('Could not create the pit photo directory.');
    }

    $caps = neptune_image_capabilities();
    $random = bin2hex(random_bytes(8));
    $base = preg_replace('/[^a-z0-9_-]+/i', '-', $namePrefix) . '-' . $random;

    // Prefer Imagick because it can handle more phone formats (including HEIC on capable hosts).
    if ($caps['imagick']) {
        try {
            $image = new Imagick();
            $image->readImage($tmp);
            if ($image->getNumberImages() > 1) $image->setIteratorIndex(0);
            if (method_exists($image, 'autoOrientImage')) $image->autoOrientImage();
            if (method_exists($image, 'setImageColorspace') && defined('Imagick::COLORSPACE_SRGB')) {
                try { $image->setImageColorspace(Imagick::COLORSPACE_SRGB); } catch (Throwable) {}
            }
            $width = (int)$image->getImageWidth();
            $height = (int)$image->getImageHeight();
            if ($width <= 0 || $height <= 0) throw new RuntimeException('The uploaded image has invalid dimensions.');
            if (max($width, $height) > $maxDimension) {
                $image->thumbnailImage($maxDimension, $maxDimension, true, true);
            }
            $image->stripImage();

            if ($caps['avif']) {
                $ext = 'avif'; $outMime = 'image/avif'; $format = 'AVIF'; $quality = 55;
            } elseif ($caps['webp']) {
                $ext = 'webp'; $outMime = 'image/webp'; $format = 'WEBP'; $quality = 78;
            } else {
                $ext = 'jpg'; $outMime = 'image/jpeg'; $format = 'JPEG'; $quality = 84;
                $image->setImageBackgroundColor('white');
                if (method_exists($image, 'setImageAlphaChannel') && defined('Imagick::ALPHACHANNEL_REMOVE')) {
                    try { $image->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE); } catch (Throwable) {}
                }
            }

            $image->setImageFormat($format);
            $image->setImageCompressionQuality($quality);
            $dest = $destDir . '/' . $base . '.' . $ext;
            if (!$image->writeImage($dest)) throw new RuntimeException('The optimized image could not be written.');
            $finalWidth = (int)$image->getImageWidth();
            $finalHeight = (int)$image->getImageHeight();
            $image->clear();
            $image->destroy();

            if (!is_file($dest) || filesize($dest) <= 0) throw new RuntimeException('The optimized image is empty.');
            return [
                'filename' => basename($dest),
                'mime' => $outMime,
                'width' => $finalWidth,
                'height' => $finalHeight,
                'bytes' => (int)filesize($dest),
                'processor' => 'Imagick',
                'format' => strtoupper($ext),
            ];
        } catch (Throwable $e) {
            // Continue to GD fallback where possible. HEIC/HEIF generally requires Imagick.
            if (in_array($mime, ['image/heic','image/heif'], true)) {
                throw new RuntimeException('This server cannot decode the phone HEIC/HEIF photo. Change the phone camera format to Most Compatible/JPEG or enable HEIC support in ImageMagick.');
            }
        }
    }

    if ($caps['gd']) {
        $image = neptune_gd_decode($tmp, $mime);
        if ($image instanceof GdImage) {
            if ($mime === 'image/jpeg') $image = neptune_gd_apply_orientation($image, $tmp);
            $image = neptune_gd_resize($image, $maxDimension);
            $width = imagesx($image); $height = imagesy($image);

            if ($caps['avif'] && function_exists('imageavif')) {
                $ext = 'avif'; $outMime = 'image/avif'; $dest = $destDir . '/' . $base . '.avif';
                $ok = @imageavif($image, $dest, 55);
            } elseif ($caps['webp'] && function_exists('imagewebp')) {
                $ext = 'webp'; $outMime = 'image/webp'; $dest = $destDir . '/' . $base . '.webp';
                $ok = @imagewebp($image, $dest, 78);
            } else {
                $image = neptune_flatten_gd_for_jpeg($image);
                $ext = 'jpg'; $outMime = 'image/jpeg'; $dest = $destDir . '/' . $base . '.jpg';
                $ok = function_exists('imagejpeg') ? @imagejpeg($image, $dest, 84) : false;
            }
            imagedestroy($image);

            if ($ok && is_file($dest) && filesize($dest) > 0) {
                return [
                    'filename' => basename($dest),
                    'mime' => $outMime,
                    'width' => $width,
                    'height' => $height,
                    'bytes' => (int)filesize($dest),
                    'processor' => 'GD',
                    'format' => strtoupper($ext),
                ];
            }
        }
    }

    // Last-resort pass-through for hosts without an image processor. Only allow
    // formats PHP can independently validate as images.
    if (!in_array($mime, ['image/jpeg','image/png','image/webp','image/avif'], true) || @getimagesize($tmp) === false) {
        throw new RuntimeException('This server cannot process that photo format. Enable Imagick or GD with AVIF/WebP/JPEG support.');
    }
    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/avif'=>'avif'][$mime];
    $dest = $destDir . '/' . $base . '.' . $ext;
    if (!move_uploaded_file($tmp, $dest)) throw new RuntimeException('Could not save the pit photo.');
    $info = @getimagesize($dest) ?: [0,0];
    return [
        'filename' => basename($dest),
        'mime' => $mime,
        'width' => (int)($info[0] ?? 0),
        'height' => (int)($info[1] ?? 0),
        'bytes' => (int)filesize($dest),
        'processor' => 'Pass-through',
        'format' => strtoupper($ext),
    ];
}


/**
 * Delete a stored pit photo only when it resolves inside Neptune/uploads/pit.
 */
function neptune_delete_pit_image_file(string $publicRoot, string $relativePath): bool {
    $relative = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
    if ($relative === '' || !str_starts_with($relative, 'uploads/pit/') || str_contains($relative, '../')) return false;

    $publicRoot = rtrim($publicRoot, '/\\');
    $pitRoot = realpath($publicRoot . '/uploads/pit');
    $candidatePath = $publicRoot . '/' . $relative;

    if (!is_file($candidatePath)) return true;
    $candidate = realpath($candidatePath);
    if ($pitRoot === false || $candidate === false) return false;

    $prefix = rtrim($pitRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($candidate, $prefix)) return false;
    return @unlink($candidate);
}
