<?php
/** Compatibility route: existing bookmarks and Command Center cards keep working. */
declare(strict_types=1);
$query=(string)($_SERVER['QUERY_STRING']??'');
$target='assignment-center.php'.($query!==''?'?'.$query:'');
header('Cache-Control: no-store');
header('Location: '.$target,true,($_SERVER['REQUEST_METHOD']??'GET')==='POST'?307:302);
exit;
