<?php
/** Redirect old account activity URL to the secured Platform Administration tab. */
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/platform-security.php';
$u=require_role(['owner']);
$platformOrgId=max(1,(int)($config['app']['platform_organization_id']??1));
if((int)$u['organization_id']!==$platformOrgId)access_denied('Platform owner access required.');
neptune_require_platform_owner_google($u);
header('Cache-Control: no-store, private');
header('Location: platform-administration.php?tab=accounts',true,302);
exit;
