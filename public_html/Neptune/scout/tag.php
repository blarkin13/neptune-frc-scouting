<?php
declare(strict_types=1);
$tab=(string)($_GET['tab']??'match');
if(!in_array($tab,['match','pit','team'],true))$tab='match';
$_GET['tag_tab']=$tab;
if($tab==='match')require __DIR__.'/impact.php';
else require __DIR__.'/tag-context.php';
