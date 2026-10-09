<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/neptune_secure/bootstrap.php';
require_once __DIR__.'/_helpers.php';
$cfg = neptune_field_practice_config();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MSA Sunday Field Practice · 2027 QR Flyer</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#e8edf1;color:#0b1218;font-family:Arial,Helvetica,sans-serif}.sheet{position:relative;width:8.5in;min-height:11in;margin:20px auto;background:#fbfaf6;padding:.46in .55in .5in;display:flex;flex-direction:column;align-items:center;text-align:center;box-shadow:0 16px 50px rgba(0,0,0,.15);overflow:hidden}.sheet:before{content:"";position:absolute;inset:0 0 auto;height:10px;background:linear-gradient(90deg,#149fe4 0 56%,#bd1d24 56% 100%)}.brand-lockup{width:100%;display:grid;grid-template-columns:110px 1fr 104px;align-items:center;gap:18px;margin-top:8px}.brand-logo{height:100px;border:1px solid #d7dde2;border-radius:18px;background:#fff;padding:8px;display:grid;place-items:center}.brand-logo img{max-width:100%;max-height:100%;object-fit:contain}.brand-center{display:flex;flex-direction:column;align-items:center}.neptune{display:flex;align-items:center;gap:8px;color:#067db7;font-weight:900;letter-spacing:.13em;font-size:13px}.neptune img{width:38px;height:38px;object-fit:contain}.host{margin-top:5px;font-size:25px;font-weight:900;line-height:1.05}.host-sub{margin-top:4px;color:#667482;font-weight:800;font-size:13px}.kicker{margin-top:.32in;color:#bd1d24;font-weight:900;letter-spacing:.15em;font-size:15px}.sheet h1{font-size:52px;line-height:.98;letter-spacing:-.045em;margin:12px 0 15px;max-width:7in}.sheet h1 span{color:#087fb8}.lead{font-size:21px;line-height:1.35;color:#3f4d59;margin:0 auto 16px;max-width:6.9in}.facts{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin:8px 0 15px}.fact{border:2px solid #d6dde3;border-radius:999px;padding:9px 16px;font-size:18px;font-weight:900;background:#fff}.fact.red{border-color:#e4b8ba}.qr-wrap{border:5px solid #087fb8;border-bottom-color:#bd1d24;border-radius:22px;padding:12px;background:#fff}.qr{width:3.65in;height:3.65in;object-fit:contain;display:block}.scan{font-size:27px;font-weight:900;margin:13px 0 0}.url{font-size:15px;color:#526274;margin-top:7px}.address{margin-top:16px;font-size:19px;font-weight:900}.sub{margin-top:7px;color:#59697a;font-size:15px;font-weight:700}.powered{margin-top:auto;padding-top:12px;border-top:1px solid #dce2e7;width:100%;display:flex;justify-content:space-between;gap:12px;color:#758392;font-size:11px}.print{white-space:nowrap}@media print{body{background:#fff}.sheet{margin:0;box-shadow:none;width:8.5in;height:11in;min-height:11in}}@media(max-width:850px){.sheet{width:100%;min-height:100vh;margin:0;padding:26px 18px}.brand-lockup{grid-template-columns:72px 1fr 72px;gap:10px}.brand-logo{height:70px;border-radius:13px}.host{font-size:18px}.sheet h1{font-size:42px}.qr{width:min(76vw,400px);height:auto}.powered{flex-direction:column}}
</style>
</head>
<body>
<main class="sheet">
  <div class="brand-lockup">
    <div class="brand-logo"><img src="<?=e(base_url('assets/images/msa-field-practice-logo.png'))?>" alt="McKinney STEAM Academy"></div>
    <div class="brand-center">
      <div class="neptune"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"> NEPTUNE</div>
      <div class="host">McKinney STEAM Academy</div>
      <div class="host-sub">Home of FRC 6369 Mercenary Robotics</div>
    </div>
    <div class="brand-logo"><img src="<?=e(base_url('assets/images/mercenary-field-practice-logo.png'))?>" alt="Mercenary Robotics 6369"></div>
  </div>
  <div class="kicker">NORTH TEXAS · 2027 FRC SEASON</div>
  <h1>Sunday Night <span>Field Practice</span></h1>
  <p class="lead">MSA is opening its practice field to North Texas FRC teams on Sunday evenings during the 2027 season. Scan to choose a Sunday and register your team.</p>
  <div class="facts"><div class="fact">SUNDAYS</div><div class="fact red"><?=e($cfg['start_label'])?>–<?=e($cfg['end_label'])?></div></div>
  <div class="qr-wrap"><img class="qr" src="<?=e(base_url($cfg['qr_image']))?>" alt="QR code to register for MSA Sunday field practice"></div>
  <p class="scan">SCAN TO REGISTER</p>
  <div class="url">neptune.mckinneysteamacademy.org/field-practice/</div>
  <div class="address"><?=e($cfg['address'])?></div>
  <div class="sub">Bring your robot, drive team, batteries, and whatever you want to work on.</div>
  <div class="powered"><span>Hosted by McKinney STEAM Academy · Registration powered by Neptune</span><span class="print">Print at 100% / Actual Size</span></div>
</main>
</body>
</html>
