<?php
// Retired 2026-10-17: merged into Robot Cards as robots.php?view=table. Kept as a redirect so bookmarks and old links still work.
$query=(string)($_SERVER['QUERY_STRING']??'');
header('Location: robots.php?view=table'.($query!==''?'&'.$query:''),true,302);
exit;
