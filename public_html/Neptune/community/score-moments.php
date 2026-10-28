<?php
declare(strict_types=1);
// Scoreboard score-change moments for one match video (Watch & tag): a hint where to look, not evidence.
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-owner-only.php';
require_once dirname(__DIR__,3).'/neptune_secure/score-moments.php';
header('Content-Type: application/json');header('Cache-Control: private, no-store');
try{echo json_encode(['moments'=>(new ScoreMoments(dirname(__DIR__,3).'/neptune_secure',(int)$communityOwner['organization_id']))->get((string)($_GET['video']??''))],JSON_THROW_ON_ERROR);}
catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}
