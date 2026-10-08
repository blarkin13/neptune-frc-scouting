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
<title><?=e($cfg['title'])?> QR Flyer</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#e9edf1;color:#07111c;font-family:Arial,Helvetica,sans-serif}.sheet{width:8.5in;min-height:11in;margin:20px auto;background:#fff;padding:.55in;display:flex;flex-direction:column;align-items:center;text-align:center;box-shadow:0 16px 50px rgba(0,0,0,.15)}.brand{display:flex;align-items:center;gap:14px;align-self:flex-start;text-align:left}.brand img{width:72px;height:72px;object-fit:contain}.brand strong{font-size:26px;letter-spacing:.06em}.brand span{display:block;color:#536274;font-weight:700;margin-top:3px}.kicker{margin-top:.45in;color:#007ec1;font-weight:900;letter-spacing:.15em;font-size:16px}.sheet h1{font-size:54px;line-height:.98;letter-spacing:-.045em;margin:16px 0 18px;max-width:6.8in}.lead{font-size:25px;line-height:1.35;color:#39495a;margin:0 auto 18px;max-width:6.9in}.facts{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;margin:12px 0 22px}.fact{border:2px solid #d9e1e8;border-radius:18px;padding:12px 18px;font-size:20px;font-weight:800}.qr{width:4.35in;height:4.35in;object-fit:contain;margin:4px 0 10px}.scan{font-size:29px;font-weight:900;margin:0}.url{font-size:16px;color:#526274;margin-top:9px}.address{margin-top:24px;font-size:20px;font-weight:800}.print{margin-top:auto;color:#758392;font-size:12px}@media print{body{background:#fff}.sheet{margin:0;box-shadow:none;width:8.5in;height:11in;min-height:11in}.print{display:none}}@media(max-width:850px){.sheet{width:100%;min-height:100vh;margin:0;padding:28px 20px}.sheet h1{font-size:44px}.qr{width:min(82vw,440px);height:auto}}
</style>
</head>
<body>
<main class="sheet">
  <div class="brand"><img src="<?=e(base_url('images/logo.png'))?>" alt="Neptune"><div><strong>NEPTUNE</strong><span>McKinney STEM Academy</span></div></div>
  <div class="kicker">NORTH TEXAS · OPEN FIELD PRACTICE</div>
  <h1>Sunday Night Field Practice</h1>
  <p class="lead">Bring your robot and drive team. Scan the code to register so we can plan field time and attendance.</p>
  <div class="facts"><div class="fact"><?=e($cfg['date_label'])?></div><div class="fact"><?=e($cfg['start_label'])?>–<?=e($cfg['end_label'])?></div></div>
  <img class="qr" src="<?=e(base_url($cfg['qr_image']))?>" alt="QR code to register for field practice">
  <p class="scan">SCAN TO SIGN UP</p>
  <div class="url">neptune.mckinneysteamacademy.org/field-practice/</div>
  <div class="address"><?=e($cfg['address'])?></div>
  <div class="print">Print this page at 100% / Actual Size.</div>
</main>
</body>
</html>
