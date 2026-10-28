<?php
declare(strict_types=1);
// Connect the owner's YouTube channel: detector training runs upload their review videos there.
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/google-auth.php';
require_once dirname(__DIR__,3).'/neptune_secure/platform-security.php';
require_once dirname(__DIR__,3).'/neptune_secure/youtube-uploader.php';
$u=require_role(['owner','admin']);
if((int)$u['organization_id']!==(int)($config['app']['platform_organization_id']??1))access_denied('Auto Scout is for admins of the owner organization.');
header('Cache-Control: private, no-store');
$yt=new YouTubeUploader(dirname(__DIR__,3).'/neptune_secure');
$redirect='https://'.($_SERVER['HTTP_HOST']??'neptunefrc.com').'/autoscout/youtube.php';
$message='';$error='';
try{
 if(isset($_GET['code'],$_GET['state'])){
  if(!hash_equals((string)($_SESSION['youtube_state']??''),(string)$_GET['state']))throw new RuntimeException('This sign-in link expired. Start again.');
  unset($_SESSION['youtube_state']);$yt->connect((string)$_GET['code'],$redirect);$message='YouTube connected.';
 }elseif(isset($_GET['error']))$error='Google sign-in was cancelled.';
 if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();$action=(string)($_POST['action']??'');
  if($action==='client'){$yt->saveClient(trim((string)($_POST['client_id']??'')),trim((string)($_POST['client_secret']??'')));$message='OAuth client saved.';}
  elseif($action==='connect'){$_SESSION['youtube_state']=bin2hex(random_bytes(16));header('Location: '.$yt->authUrl($redirect,$_SESSION['youtube_state']));exit;}
  elseif($action==='disconnect'){$yt->disconnect();$message='YouTube disconnected. Review videos stay on Neptune.';}
 }
}catch(Throwable $e){$error=$e->getMessage();}
$s=$yt->status();
require_once dirname(__DIR__).'/community/layout.php';community_page_start('Review videos on YouTube','Each detector training run makes a short video of what the new model does on its held-out match and uploads it to your YouTube channel.','helpers','AUTO SCOUT · HELPERS');
?>
<?php if($message):?><p role="status"><strong><?=e($message)?></strong></p><?php endif?><?php if($error):?><p role="alert"><strong><?=e($error)?></strong></p><?php endif?>
<section><span class="stage-label">STATUS</span><h2><?=$s['connected']?'Connected to '.e($s['channel']??'your channel'):'Not connected'?></h2>
<p>Videos are uploaded as <strong>unlisted</strong>. Until your Google Cloud project passes YouTube’s API audit, YouTube keeps them <strong>private</strong>: you can watch them while signed in to this channel. Each run’s link appears under <em>Past runs</em> on 1 · Robots and 2 · Field. The last 10 videos are also kept on Neptune.</p>
<?php if($s['connected']):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="disconnect"><button class="button-secondary">Disconnect YouTube</button></form><?php endif?></section>
<section><span class="stage-label">STEP 1 · ONE TIME, IN GOOGLE CLOUD</span><h2>Create the OAuth client</h2><ol>
<li>At <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">console.cloud.google.com</a>, create a project (for example “Neptune Auto Scout”).</li>
<li>APIs &amp; Services → Library → enable <strong>YouTube Data API v3</strong>.</li>
<li>OAuth consent screen: External, add yourself (the channel’s Google account) as a test user.</li>
<li>Credentials → Create credentials → OAuth client ID → <strong>Web application</strong>. Authorized redirect URI: <code><?=e($redirect)?></code></li>
<li>Paste the client ID and secret below. They are stored only on this server.</li></ol>
<form method="post" class="training-field-grid"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="client">
<label>Client ID<input name="client_id" autocomplete="off" placeholder="…apps.googleusercontent.com"></label><label>Client secret<input name="client_secret" type="password" autocomplete="off"></label>
<button><?=$s['client']?'Replace OAuth client':'Save OAuth client'?></button></form></section>
<section><span class="stage-label">STEP 2</span><h2>Connect your channel</h2><p>Sign in with the Google account that owns the YouTube channel and allow uploads.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="connect"><button <?=$s['client']?'':'disabled'?>><?=$s['connected']?'Reconnect YouTube':'Connect YouTube'?></button></form></section>
<?php community_page_end(); ?>
