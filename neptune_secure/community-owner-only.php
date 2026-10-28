<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/google-auth.php';
require_once __DIR__.'/platform-security.php';
$communityOwner=require_role(['owner','admin']);
if((int)$communityOwner['organization_id']!==(int)($config['app']['platform_organization_id']??1))access_denied('Auto Scout is for admins of the owner organization.');

header('Cache-Control: private, no-store');
