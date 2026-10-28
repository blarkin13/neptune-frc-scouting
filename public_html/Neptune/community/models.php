<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-analysis-install.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-learning.php';
header('Content-Type: application/json');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try {
 $settings=community_analysis_settings($pdo,$config);if(empty($settings['enabled'])){http_response_code(404);echo json_encode(['error'=>'Community analysis is not enabled.']);exit;}
 $season=filter_var($_GET['season']??gmdate('Y'),FILTER_VALIDATE_INT);if(!$season||$season<2025||$season>(int)gmdate('Y')+1)throw new InvalidArgumentException('Choose a valid season.');
 community_video_tags_schema($pdo);community_learning_schema($pdo);if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
 echo json_encode(community_learning_public($pdo,$season),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
}catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['error'=>$e->getMessage()]);}catch(Throwable $e){error_log('Shared model: '.$e->getMessage());http_response_code(503);echo json_encode(['error'=>'Shared models are temporarily unavailable.']);}
