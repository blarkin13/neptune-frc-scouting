<?php
declare(strict_types=1);
require_once dirname(__DIR__,4).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,4).'/neptune_secure/mobile-google.php';
mobile_google_browser($pdo);
