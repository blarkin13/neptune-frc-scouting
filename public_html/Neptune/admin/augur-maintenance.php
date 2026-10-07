<?php
if($_SERVER['REQUEST_METHOD']==='POST'||isset($_GET['status'])){
    $_GET['tab']='overview';
    require __DIR__.'/augur-operations.php';
    exit;
}
$params=$_GET;
$params['tab']='overview';
header('Location: augur-operations.php?'.http_build_query($params),true,302);
exit;
