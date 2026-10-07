<?php
declare(strict_types=1);

/**
 * Robot Recall pixel-art logo helper.
 *
 * Creates a large nearest-neighbour PNG from Neptune's persistent local
 * team-logo cache. The generated file is cached under uploads/team-logos/pixel
 * so it remains available offline after its first generation.
 */

function rr_pixel_app_root(): string
{
    return dirname(__DIR__);
}

function rr_pixel_safe_team(mixed $value): int
{
    $team = (int)$value;
    return ($team >= 1 && $team <= 99999) ? $team : 0;
}

function rr_pixel_safe_org(mixed $value): int
{
    $org = (int)$value;
    return ($org >= 1 && $org <= 999999999) ? $org : 0;
}

function rr_pixel_is_source_file(string $path, int $team): bool
{
    if (!is_file($path)) {
        return false;
    }
    $base = strtolower(basename($path));
    if (!preg_match('/^frc' . preg_quote((string)$team, '/') . '\.(png|jpe?g|webp|avif)$/i', $base)) {
        return false;
    }
    $normalized = str_replace('\\', '/', $path);
    return !str_contains($normalized, '/pixel/') && !str_contains($normalized, '/1000/');
}

/** @return array{path:string,org:int}|null */
function rr_pixel_find_source(int $team, int $org = 0): ?array
{
    $root = rr_pixel_app_root();
    $base = $root . '/uploads/team-logos';
    if (!is_dir($base)) {
        return null;
    }

    $extensions = ['png', 'jpg', 'jpeg', 'webp', 'avif'];
    $candidates = [];

    $add = static function (string $path, int $candidateOrg) use (&$candidates, $team): void {
        if (rr_pixel_is_source_file($path, $team)) {
            $candidates[] = [
                'path' => $path,
                'org' => $candidateOrg,
                'mtime' => (int)@filemtime($path),
                'persistent' => preg_match('#/org-\d+/frc\d+\.[a-z0-9]+$#i', str_replace('\\', '/', $path)) ? 1 : 0,
            ];
        }
    };

    // Preferred persistent cache: uploads/team-logos/org-N/frcTEAM.ext
    if ($org > 0) {
        foreach ($extensions as $ext) {
            $add($base . '/org-' . $org . '/frc' . $team . '.' . $ext, $org);
        }
    } else {
        foreach (glob($base . '/org-*', GLOB_ONLYDIR) ?: [] as $orgDir) {
            if (!preg_match('/org-(\d+)$/', $orgDir, $m)) {
                continue;
            }
            $candidateOrg = (int)$m[1];
            foreach ($extensions as $ext) {
                $add($orgDir . '/frc' . $team . '.' . $ext, $candidateOrg);
            }
        }
    }

    // Legacy season cache fallback: org-N/YYYY/frcTEAM.ext
    $orgDirs = $org > 0 ? [$base . '/org-' . $org] : (glob($base . '/org-*', GLOB_ONLYDIR) ?: []);
    foreach ($orgDirs as $orgDir) {
        if (!is_dir($orgDir) || !preg_match('/org-(\d+)$/', $orgDir, $m)) {
            continue;
        }
        $candidateOrg = (int)$m[1];
        foreach (glob($orgDir . '/[12][0-9][0-9][0-9]', GLOB_ONLYDIR) ?: [] as $yearDir) {
            foreach ($extensions as $ext) {
                $add($yearDir . '/frc' . $team . '.' . $ext, $candidateOrg);
            }
        }
    }

    // Very old/global cache fallback.
    foreach ($extensions as $ext) {
        $add($base . '/frc' . $team . '.' . $ext, 0);
    }

    if (!$candidates) {
        return null;
    }

    usort($candidates, static function (array $a, array $b): int {
        if ($a['persistent'] !== $b['persistent']) {
            return $b['persistent'] <=> $a['persistent'];
        }
        return $b['mtime'] <=> $a['mtime'];
    });

    return ['path' => $candidates[0]['path'], 'org' => (int)$candidates[0]['org']];
}

function rr_pixel_load_image(string $path): ?GdImage
{
    $info = @getimagesize($path);
    if (!$info || empty($info['mime'])) {
        return null;
    }

    try {
        return match (strtolower((string)$info['mime'])) {
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : null,
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : null,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : null,
            default => null,
        };
    } catch (Throwable $e) {
        return null;
    }
}

function rr_pixel_transparent_canvas(int $w, int $h): GdImage
{
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
    imagefilledrectangle($im, 0, 0, $w, $h, $transparent);
    return $im;
}

/**
 * Generate a deliberately blocky logo.
 *
 * The logo is first reduced to a small logical grid with nearest-neighbour,
 * then enlarged to the output size using nearest-neighbour again. For tiny
 * FIRST/TBA avatars (typically 40x40), the native grid is preserved so no
 * detail is thrown away.
 */
function rr_pixel_q(int $v, int $step = 32): int
{
    $v = max(0, min(255, $v));
    return max(0, min(255, (int)(round($v / $step) * $step)));
}

function rr_pixel_adjust(int $v, int $delta): int
{
    return max(0, min(255, $v + $delta));
}

/**
 * Generate a deliberately coarse Minecraft-style block mosaic.
 *
 * This is intentionally NOT a normal nearest-neighbour enlargement. The source
 * is collapsed to a small logical grid, colors are quantized, and every logical
 * pixel becomes a visible square tile with a very small light/dark bevel.
 */
function rr_pixel_generate(string $source, string $dest, int $size = 768, int $grid = 18): bool
{
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        return false;
    }

    $src = rr_pixel_load_image($source);
    if (!$src) return false;

    $sw = imagesx($src);
    $sh = imagesy($src);
    if ($sw < 1 || $sh < 1) {
        imagedestroy($src);
        return false;
    }

    $size = max(320, min(1200, $size));
    $grid = max(12, min(28, $grid));

    // Fit the logo into a coarse square logical grid while preserving aspect ratio.
    $scale = $grid / max($sw, $sh);
    $lw = max(1, (int)round($sw * $scale));
    $lh = max(1, (int)round($sh * $scale));

    // Resampling here intentionally averages the original anti-aliased source into
    // a small set of logical cells. The next stage removes gradients entirely.
    $logical = rr_pixel_transparent_canvas($lw, $lh);
    imagealphablending($logical, false);
    imagesavealpha($logical, true);
    imagecopyresampled($logical, $src, 0, 0, 0, 0, $lw, $lh, $sw, $sh);

    $inner = (int)round($size * 0.92);
    $cell = max(4, (int)floor($inner / max($lw, $lh)));
    $fw = $lw * $cell;
    $fh = $lh * $cell;
    $x0 = (int)floor(($size - $fw) / 2);
    $y0 = (int)floor(($size - $fh) / 2);

    $out = rr_pixel_transparent_canvas($size, $size);
    imagealphablending($out, false);
    imagesavealpha($out, true);

    $bevel = max(1, (int)round($cell * 0.055));

    for ($y = 0; $y < $lh; $y++) {
        for ($x = 0; $x < $lw; $x++) {
            $rgba = imagecolorat($logical, $x, $y);
            $a = ($rgba >> 24) & 0x7F;
            // Treat mostly-transparent edge samples as empty so the silhouette is
            // made of hard square blocks instead of a fuzzy halo.
            if ($a >= 82) continue;

            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;

            // Quantize to remove smooth gradients and make colors read like tiles.
            $r = rr_pixel_q($r, 32);
            $g = rr_pixel_q($g, 32);
            $b = rr_pixel_q($b, 32);
            $alpha = $a <= 24 ? 0 : min(70, $a);

            $left = $x0 + ($x * $cell);
            $top = $y0 + ($y * $cell);
            $right = $left + $cell - 1;
            $bottom = $top + $cell - 1;

            $base = imagecolorallocatealpha($out, $r, $g, $b, $alpha);
            imagefilledrectangle($out, $left, $top, $right, $bottom, $base);

            // Tiny Minecraft-like tile bevel. It keeps the logo recognizable while
            // making each coarse square visibly independent at TV/tablet sizes.
            if ($cell >= 14) {
                $hi = imagecolorallocatealpha(
                    $out,
                    rr_pixel_adjust($r, 18), rr_pixel_adjust($g, 18), rr_pixel_adjust($b, 18), $alpha
                );
                $lo = imagecolorallocatealpha(
                    $out,
                    rr_pixel_adjust($r, -22), rr_pixel_adjust($g, -22), rr_pixel_adjust($b, -22), $alpha
                );
                imagefilledrectangle($out, $left, $top, $right, $top + $bevel - 1, $hi);
                imagefilledrectangle($out, $left, $top, $left + $bevel - 1, $bottom, $hi);
                imagefilledrectangle($out, $left, $bottom - $bevel + 1, $right, $bottom, $lo);
                imagefilledrectangle($out, $right - $bevel + 1, $top, $right, $bottom, $lo);
            }
        }
    }

    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        imagedestroy($src); imagedestroy($logical); imagedestroy($out);
        return false;
    }

    $ok = @imagepng($out, $dest, 6);
    if ($ok) @chmod($dest, 0664);

    imagedestroy($src);
    imagedestroy($logical);
    imagedestroy($out);
    return $ok;
}

/** @return array{path:string,source:string,org:int,generated:bool}|null */
function rr_pixel_cached_logo(int $team, int $org = 0, int $size = 768, int $grid = 18): ?array
{
    $found = rr_pixel_find_source($team, $org);
    if (!$found) {
        return null;
    }

    $source = $found['path'];
    $resolvedOrg = (int)$found['org'];
    $root = rr_pixel_app_root();
    $bucket = $resolvedOrg > 0 ? 'org-' . $resolvedOrg : 'global';
    $dir = $root . '/uploads/team-logos/pixel/' . $bucket;
    $dest = $dir . '/frc' . $team . '.png';
    $metaPath = $dir . '/frc' . $team . '.meta.json';

    $fingerprint = [
        'source' => str_replace($root, '', realpath($source) ?: $source),
        'source_mtime' => (int)@filemtime($source),
        'source_size' => (int)@filesize($source),
        'size' => $size,
        'grid' => $grid,
        'version' => 3,
    ];

    $fresh = false;
    if (is_file($dest) && is_file($metaPath)) {
        $meta = json_decode((string)@file_get_contents($metaPath), true);
        $fresh = is_array($meta) && $meta === $fingerprint;
    }

    $generated = false;
    if (!$fresh) {
        $generated = rr_pixel_generate($source, $dest, $size, $grid);
        if ($generated) {
            @file_put_contents($metaPath, json_encode($fingerprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            @chmod($metaPath, 0664);
        }
    }

    if (!is_file($dest)) {
        return ['path' => $source, 'source' => $source, 'org' => $resolvedOrg, 'generated' => false];
    }

    return ['path' => $dest, 'source' => $source, 'org' => $resolvedOrg, 'generated' => $generated];
}
