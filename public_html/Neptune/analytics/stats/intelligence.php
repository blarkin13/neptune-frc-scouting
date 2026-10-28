<?php
// Public Statistics moved to /epa/ (package v18/v19, October 17, 2026). This permanent redirect
// keeps old links, bookmarks and search results working, including their query strings.
// The prefix keeps installs served from a subfolder (for example /Neptune/) inside that folder.
$prefix = preg_replace('#/analytics/stats(?:/[^/]*)?$#', '', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$query = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: ' . $prefix . '/epa/intelligence.php' . ($query !== '' ? '?' . $query : ''), true, 301);
exit;
