<?php
$params=$_GET;
$params['tab']='archive';
header('Location: augur-operations.php?'.http_build_query($params),true,302);
exit;
