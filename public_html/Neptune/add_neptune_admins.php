#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

fwrite(
    STDERR,
    "This historical bulk-admin bootstrap utility was retired for Neptune Public 1.0.\n"
    . "Use Command Center -> Organization -> Teams & Users to create or manage accounts.\n"
);
exit(2);
