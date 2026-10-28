<?php
declare(strict_types=1);
// Auto Scout moved to /autoscout/. Older helpers and saved links still open this address.
$query=(string)($_SERVER['QUERY_STRING']??'');
header('Location: ../autoscout/train.php'.($query!==''?'?'.$query:''),true,308);
