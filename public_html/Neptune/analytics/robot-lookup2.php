<?php
// Retired 2026-10-17: older copy of robot-lookup.php. Kept as a redirect so bookmarks and old links still work.
$query=(string)($_SERVER['QUERY_STRING']??'');
header('Location: robot-lookup.php'.($query!==''?'?'.$query:''),true,302);
exit;
