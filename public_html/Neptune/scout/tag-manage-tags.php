<?php
/**
 * Compatibility endpoint for pre-v71 Tag Editor URLs, including existing POST forms.
 * New interface and implementation: admin/tag-manager.php.
 */
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
$u=require_role(['owner','admin','strategy']);
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    require dirname(__DIR__).'/admin/tag-manager.php';
    exit;
}
$query=(string)($_SERVER['QUERY_STRING']??'');
$target=base_url('admin/tag-manager.php');
if($query!=='')$target.='?'.$query;
header('Location: '.$target,true,302);
exit;
