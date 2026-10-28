<?php
declare(strict_types=1);

/** Uploads Auto Scout review videos to the owner's YouTube channel (YouTube Data API v3, OAuth refresh token).
 *
 * Files under neptune_secure/youtube/ (0600, never served):
 *   client.json  {client_id, client_secret} of a Google Cloud "Web application" OAuth client
 *   token.json   {refresh_token, channel_title, connected_at} saved by autoscout/youtube.php
 * Videos are requested as unlisted. YouTube keeps uploads from unaudited API projects private until the project
 * passes its audit; the returned privacy status is recorded as-is.
 */
final class YouTubeUploader {
 private string $dir;
 public function __construct(string $secure){$this->dir=$secure.'/youtube';}
 private function read(string $name):?array{$f=$this->dir.'/'.$name;return is_file($f)?json_decode((string)file_get_contents($f),true):null;}
 private function write(string $name,array $data):void{
  if(!is_dir($this->dir)&&!mkdir($this->dir,0700,true))throw new RuntimeException('YouTube settings storage unavailable');
  $tmp=tempnam($this->dir,'pending-');if($tmp===false||file_put_contents($tmp,json_encode($data,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Could not save');
  chmod($tmp,0600);if(!rename($tmp,$this->dir.'/'.$name))throw new RuntimeException('Could not commit save');
 }
 public function status():array{$c=$this->read('client.json');$t=$this->read('token.json');return ['client'=>(bool)($c['client_id']??null),'connected'=>(bool)($t['refresh_token']??null),'channel'=>$t['channel_title']??null,'connected_at'=>$t['connected_at']??null];}
 public function saveClient(string $id,string $secret):void{
  if(!preg_match('/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/D',$id)||strlen($secret)<10||strlen($secret)>200)throw new InvalidArgumentException('That does not look like a Google OAuth client ID and secret.');
  $this->write('client.json',['client_id'=>$id,'client_secret'=>$secret]);
 }
 public function disconnect():void{$f=$this->dir.'/token.json';if(is_file($f))unlink($f);}
 public function authUrl(string $redirect,string $state):string{
  $c=$this->read('client.json')??throw new RuntimeException('Save the OAuth client first.');
  return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id'=>$c['client_id'],'redirect_uri'=>$redirect,'response_type'=>'code',
   'scope'=>'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly','access_type'=>'offline','prompt'=>'consent','state'=>$state]);
 }
 private static function http(string $method,string $url,array $headers,?string $body=null,bool $wantHeaders=false):array{
  $h=curl_init($url);$out=[];
  curl_setopt_array($h,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>600,CURLOPT_CONNECTTIMEOUT=>15,
   CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$out){if(str_contains($line,':')){[$k,$v]=explode(':',$line,2);$out[strtolower(trim($k))]=trim($v);}return strlen($line);}]);
  if($body!==null)curl_setopt($h,CURLOPT_POSTFIELDS,$body);
  $raw=curl_exec($h);$code=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);$err=curl_error($h);curl_close($h);
  if($raw===false)throw new RuntimeException('YouTube request failed: '.$err);
  $json=json_decode((string)$raw,true);
  if($code>=400)throw new RuntimeException('YouTube said '.$code.': '.mb_substr((string)($json['error']['message']??$json['error_description']??$json['error']??$raw),0,200));
  return ['json'=>is_array($json)?$json:[],'headers'=>$out];
 }
 public function connect(string $code,string $redirect):array{
  $c=$this->read('client.json')??throw new RuntimeException('Save the OAuth client first.');
  $r=self::http('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['code'=>$code,'client_id'=>$c['client_id'],'client_secret'=>$c['client_secret'],'redirect_uri'=>$redirect,'grant_type'=>'authorization_code']))['json'];
  if(empty($r['refresh_token']))throw new RuntimeException('Google did not return a refresh token. Remove Neptune from your Google account’s third-party access and connect again.');
  $channel=self::http('GET','https://www.googleapis.com/youtube/v3/channels?part=snippet&mine=true',['Authorization: Bearer '.$r['access_token']])['json']['items'][0]['snippet']['title']??null;
  $this->write('token.json',['refresh_token'=>$r['refresh_token'],'channel_title'=>$channel,'connected_at'=>time()]);
  return $this->status();
 }
 private function accessToken():string{
  $c=$this->read('client.json');$t=$this->read('token.json');if(!$c||!$t)throw new RuntimeException('YouTube is not connected.');
  return (string)self::http('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['client_id'=>$c['client_id'],'client_secret'=>$c['client_secret'],'refresh_token'=>$t['refresh_token'],'grant_type'=>'refresh_token']))['json']['access_token'];
 }
 /** Resumable upload. Returns {youtube_id, privacy}. */
 public function upload(string $path,string $title,string $description):array{
  $token=$this->accessToken();$size=filesize($path);
  $meta=json_encode(['snippet'=>['title'=>$title,'description'=>$description,'categoryId'=>'28','tags'=>['FRC','Neptune','Auto Scout']],'status'=>['privacyStatus'=>'unlisted','selfDeclaredMadeForKids'=>false]],JSON_THROW_ON_ERROR);
  $start=self::http('POST','https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status',
   ['Authorization: Bearer '.$token,'Content-Type: application/json; charset=UTF-8','X-Upload-Content-Type: video/mp4','X-Upload-Content-Length: '.$size],$meta);
  $session=$start['headers']['location']??throw new RuntimeException('YouTube did not start the upload.');
  $done=self::http('PUT',$session,['Content-Type: video/mp4','Content-Length: '.$size],(string)file_get_contents($path))['json'];
  return ['youtube_id'=>(string)($done['id']??''),'privacy'=>(string)($done['status']['privacyStatus']??'unknown')];
 }
}
