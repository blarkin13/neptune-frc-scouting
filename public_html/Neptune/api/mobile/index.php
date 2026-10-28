<?php
declare(strict_types=1);
// Deployment layout matches the existing Neptune /api directory.
require_once dirname(__DIR__,4).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,4).'/neptune_secure/mobile-api.php';
mobile_dispatch($pdo);
