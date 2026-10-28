<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-analysis-install.php';
require_once dirname(__DIR__,3).'/neptune_secure/community-training-pairing.php';
header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');
$settings=community_analysis_settings($pdo,$config);$settings['platform_organization_id']=(int)($config['app']['platform_organization_id']??1);
require_once dirname(__DIR__,3).'/neptune_secure/google-auth.php';
require_once dirname(__DIR__,3).'/neptune_secure/platform-security.php';
if(empty($settings['enabled'])){http_response_code(404);exit('Community analysis is not enabled.');}
$id=(string)($_GET['request']??$_POST['request']??'');$message='';$request=null;$user=null;
try {
    community_training_pairing_schema($pdo);
    $pairing=new CommunityTrainingPairing($pdo,$settings);$request=$pairing->inspect($id);
    // Leave the request page in place if this browser has no Neptune session.
    // After signing in in the separate tab, Continue revalidates that session.
    if(current_user())$user=require_login();
    if($_SERVER['REQUEST_METHOD']==='POST') {
        if(!$user)throw new CommunityAnalysisError(401,'Sign in to Neptune first, then return to this page.');
        verify_csrf();
        if(!in_array($user['role'],['owner','admin'],true)||(int)$user['organization_id']!==$settings['platform_organization_id'])access_denied('Auto Scout is for admins of the owner organization.');
        $decision=(string)($_POST['decision']??'');
        if(!in_array($decision,['approve','deny'],true))throw new CommunityAnalysisError(422,'Choose Approve or Cancel.');
        $redirect=$pairing->authorize($id,(int)$user['id'],(string)$user['credential_revision'],$decision==='approve');
        header('Location: '.$redirect,true,303);exit;
    }
} catch(CommunityAnalysisError $e){
    if($_SERVER['REQUEST_METHOD']==='POST'&&$request&&in_array($e->httpStatus,[401,403],true)) {
        header('Location: http://127.0.0.1:'.(int)$request['port'].'/neptune-connect?'.http_build_query(['state'=>$request['state'],'error'=>$e->httpStatus===403?'contributor_access_denied':'account_unavailable']),true,303);exit;
    }
    http_response_code($e->httpStatus);$message=$e->getMessage();
}
catch(Throwable $e){error_log('Community helper connection: '.get_class($e));http_response_code(503);$message='The helper connection service is unavailable. Try again shortly.';}
require_once __DIR__.'/layout.php';
community_page_start('Connect Neptune Training Helper','Approve this computer’s connection using your Neptune account.','connect','NEPTUNE HELPER · ACCOUNT CONNECTION');
?>
<section class="connection-panel"><h2>Connect Neptune Training Helper</h2><p>This connects your computer to the training queue. Choose matches, follow progress and see results on the Training &amp; Test page.</p>
<?php if($message):?><p role="alert"><?=e($message)?></p><?php elseif($request&&$request['status']!=='pending'):?><p>This request was already answered. Return to Neptune Helper.</p>
<?php elseif(!$user):?><p>Sign in to your Neptune account, then return here and click Continue.</p><a class="community-button" href="<?=e(base_url('index.php'))?>" target="_blank" rel="noopener">Sign in to Neptune</a><a class="community-button" href="?request=<?=e($id)?>">Continue</a>
<?php else:?><p>Connect this helper as <strong><?=e($user['display_name']?:$user['username'])?></strong>?</p><p>Check that this code matches the helper window:</p><p><code class="connection-code"><?=e($request['user_code'])?></code></p><p>Only approve if you clicked Connect to Neptune on this computer. This computer can prepare training data, fit candidates and run requested prediction tests under your platform owner account. Releasing a model is a separate owner action.</p><small>The connection lasts up to 30 days. You can revoke it on Training &amp; Test.</small><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="request" value="<?=e($id)?>"><button name="decision" value="approve">Approve connection</button><button name="decision" value="deny">Cancel</button></form><?php endif ?></section><?php community_page_end(); ?>
