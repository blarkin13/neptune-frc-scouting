<?php
declare(strict_types=1);
/** Preserve model numeric types and its empty heads map across PHP/JSON transport. */
function community_model_document(array $model):string {
 foreach(['heads','action_definitions'] as $key)if(isset($model[$key])&&$model[$key]===[])$model[$key]=new stdClass();
 return json_encode($model,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION|JSON_UNESCAPED_SLASHES);
}
function community_training_json_response(array $data,int $status=200):never {
 if(isset($data['job']['model'])&&is_array($data['job']['model']))$data['job']['model']=json_decode(community_model_document($data['job']['model']));
 http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);exit;
}
