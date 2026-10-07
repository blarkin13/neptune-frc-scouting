<?php
if($_SERVER['REQUEST_METHOD']==='POST'){
    $_GET['tab']='phase-maps';
    $oldAction=(string)($_POST['action']??'save');
    if($oldAction==='delete') $_POST['action']='phase_delete';
    elseif($oldAction==='save'||$oldAction==='') $_POST['action']='phase_save';
    require __DIR__.'/augur-operations.php';
    exit;
}
$params=$_GET;
$params['tab']='phase-maps';
header('Location: augur-operations.php?'.http_build_query($params),true,302);
exit;
