<?php
declare(strict_types=1);

/*
 * Load Neptune database credentials from the protected server-side
 * environment file. This keeps credentials outside public_html and
 * survives Apache restarts/reloads.
 */

$neptuneDbEnvFile = '/etc/scout/db.env';

if (!is_readable($neptuneDbEnvFile)) {
    throw new RuntimeException(
        'Neptune database environment file is not readable: ' .
        $neptuneDbEnvFile
    );
}

$lines = file(
    $neptuneDbEnvFile,
    FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
);

foreach ($lines ?: [] as $line) {
    $line = trim($line);

    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }

    $pos = strpos($line, '=');

    if ($pos === false) {
        continue;
    }

    $key = trim(substr($line, 0, $pos));
    $value = trim(substr($line, $pos + 1));

    if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
        continue;
    }

    $length = strlen($value);

    if (
        $length >= 2 &&
        (
            ($value[0] === '"' && $value[$length - 1] === '"') ||
            ($value[0] === "'" && $value[$length - 1] === "'")
        )
    ) {
        $value = substr($value, 1, -1);
    }

    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
