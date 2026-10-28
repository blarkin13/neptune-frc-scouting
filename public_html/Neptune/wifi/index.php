<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once dirname(__DIR__,3).'/neptune_secure/event-wifi.php';
require_once dirname(__DIR__,3).'/neptune_secure/platform-mfa.php';
$u=require_login();
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
$error='';$network=null;$ready=false;
$entry=['ssid'=>'','password'=>'','security_type'=>'WPA'];
try {
    neptune_organization_wifi_schema($pdo);
    $key=hash_hkdf('sha256',neptune_mfa_crypto_key(),32,'Neptune event Wi-Fi password storage v1');
    $wifi=new NeptuneEventWifi($pdo,$key);
    if($_SERVER['REQUEST_METHOD']==='POST') {
        if(!is_string($_POST['csrf']??null)||!hash_equals(csrf_token(),$_POST['csrf'])){http_response_code(419);exit('Your page session expired. Reload and try again.');}
        // Organization ownership comes exclusively from the authenticated account.
        if(($_POST['action']??'')==='save')$wifi->saveOrganization($u,$_POST);
        elseif(($_POST['action']??'')==='clear')$wifi->clearOrganization($u);
        else throw new NeptuneEventWifiError('Unknown network action.');
        header('Location: '.base_url('wifi/'),true,303);exit;
    }
    $network=$wifi->organizationNetwork($u);$entry=$network??$entry;$ready=true;
} catch(NeptuneEventWifiError $ex) {
    http_response_code(422);$error=$ex->getMessage();$ready=true;
    try{$network=isset($wifi)?$wifi->organizationNetwork($u):null;}catch(Throwable $ignored){}
    foreach(['ssid','password','security_type'] as $field)if(is_string($_POST[$field]??null))$entry[$field]=$_POST[$field];
} catch(Throwable $ex) {
    http_response_code(503);error_log('Organization Wi-Fi: '.get_class($ex));$error='Wi-Fi Access is temporarily unavailable. Please try again shortly.';
}
$pageTitle='Wi-Fi Access';$moduleName='NEPTUNE';$bodyClass='neptune-display-page wifi-access-page has-ocean';
include dirname(__DIR__).'/partials_header.php';
?>
<link rel="stylesheet" href="<?=e(base_url('assets/css/neptune-ocean.css?v=1'))?>">
<link rel="stylesheet" href="<?=e(base_url('assets/css/event-hub.css?v=5'))?>">
<div class="neptune-display-wrap">
<?php if($error):?><div class="notice bad" role="alert"><?=e($error)?></div><?php endif ?>
<section class="wifi-sign nd-mission-header" aria-labelledby="wifi-heading">
  <div class="wifi-sign-copy">
    <div class="display-kicker">STAY CONNECTED · <?=e($u['organization_name']??'YOUR ORGANIZATION')?></div>
    <h1 id="wifi-heading">Wi-Fi <em>Access</em></h1>
    <p class="wifi-lead"><?=$network?'Scan the QR code with your phone’s camera to connect to the network.':'Save your organization’s network name and password to create a Wi-Fi connection sign.'?></p>
    <div class="wifi-details" aria-label="Wi-Fi credentials">
      <div class="wifi-detail"><span class="wifi-detail-icon"><i class="fa-solid fa-wifi" aria-hidden="true"></i></span><div><span class="wifi-label">NETWORK</span><strong id="saved-network-name"><?=e($network['ssid']??'No network saved')?></strong></div></div>
      <div class="wifi-detail"><span class="wifi-detail-icon"><i class="fa-solid fa-lock" aria-hidden="true"></i></span><div><span class="wifi-label">PASSWORD</span><strong id="saved-network-password"><?=e($network?($network['security_type']==='open'?'No password needed':$network['password']):'—')?></strong></div></div>
    </div>
    <p class="wifi-sign-note"><?=$network?'Can’t scan? Select the network in your Wi-Fi settings and enter the password above.':'Use Change network below to enter the connection details.'?></p>
    <p class="wifi-org-note">Saved for <?=e($u['organization_name']??'your organization')?> until someone in your organization changes it. Other organizations have their own network settings.</p>
  </div>
  <aside class="wifi-sign-qr">
    <?php if($network):?>
    <div class="display-qr-frame"><canvas id="wifiConnectionQr" role="img" aria-label="Scan to connect to the saved Wi-Fi network" data-ssid="<?=e($network['ssid'])?>" data-password="<?=e($network['password'])?>" data-security="<?=e($network['security_type'])?>"></canvas></div>
    <p class="display-qr-caption">SCAN TO CONNECT</p>
    <p class="wifi-qr-error" id="wifiQrError" role="status" hidden>QR generation is unavailable. Use the network details shown.</p>
    <?php else:?><div class="wifi-qr-placeholder"><i class="fa-solid fa-qrcode" aria-hidden="true"></i><span>Your connection QR code<br>will appear here</span></div><?php endif ?>
  </aside>
</section>
<?php if($ready):?>
<details class="wifi-editor card ocean-glass" id="wifi-editor" <?=(!$network||$error)?'open':''?>>
  <summary><span><i class="fa-solid fa-pen" aria-hidden="true"></i> Change network</span><small>Updates the saved network and QR code for your organization</small></summary>
  <form method="post" autocomplete="off" class="wifi-edit-form">
    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
    <div><label for="network-name">Network name (SSID)</label><input id="network-name" name="ssid" required maxlength="32" value="<?=e($entry['ssid'])?>" autocapitalize="none" spellcheck="false"></div>
    <div><label for="network-password">Network password</label><input id="network-password" name="password" type="password" maxlength="128" value="<?=e($entry['password'])?>" autocomplete="new-password" autocapitalize="none"><label class="wifi-check"><input type="checkbox" id="show-entry-password"> Show password</label></div>
    <div><label for="network-security">Security</label><select id="network-security" name="security_type"><?php foreach(['WPA'=>'WPA / WPA2 / WPA3 Personal','WEP'=>'WEP','open'=>'Open — no password'] as $type=>$label):?><option value="<?=$type?>" <?=$entry['security_type']===$type?'selected':''?>><?=$label?></option><?php endforeach ?></select></div>
    <div class="wifi-save"><button type="submit" name="action" value="save" class="btn">Save network &amp; generate QR</button></div>
  </form>
  <?php if($network):?><form method="post" class="wifi-clear"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><button type="submit" name="action" value="clear" class="btn secondary">Clear saved network</button></form><?php endif ?>
</details>
<?php endif ?>
</div>
<script src="<?=e(base_url('assets/js/neptune-ocean.js?v=1'))?>" defer></script>
<script src="<?=e(base_url('assets/js/qrcodegen.js'))?>" defer></script>
<script src="<?=e(base_url('assets/js/event-wifi.js?v=4'))?>" defer></script>
<?php include dirname(__DIR__).'/partials_footer.php'; ?>
