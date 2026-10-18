<?php
declare(strict_types=1);
// Legacy training page retired; keep existing bookmarks useful.
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
if($_SERVER['REQUEST_METHOD']==='POST'){http_response_code(410);exit('Legacy training has been retired. Open the event hub.');}
header('Location: '.base_url('event/'),true,302);
exit;
