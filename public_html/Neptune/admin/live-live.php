<?php
// Retired 2026-10-17: older copy of the Live Monitor; live.php adds match control. Kept as a redirect so bookmarks and old links still work.
$query=(string)($_SERVER['QUERY_STRING']??'');
header('Location: live.php'.($query!==''?'?'.$query:''),true,302);
exit;
