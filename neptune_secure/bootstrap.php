<?php
$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');
session_name($config['app']['session_name'] ?? 'NEPTUNESESSID');
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/connection.php';

function base_url(string $path=''): string {
    global $config;
    return rtrim($config['app']['base_url'] ?? '/Neptune','/') . '/' . ltrim($path,'/');
}
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function json_response(array $data, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
function uuidv4(): string {
    $d=random_bytes(16); $d[6]=chr((ord($d[6])&0x0f)|0x40); $d[8]=chr((ord($d[8])&0x3f)|0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d),4));
}
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): array {
    $u=current_user();
    if(!$u){ header('Location: '.base_url('index.php')); exit; }
    if(!empty($u['must_change_password'])){
        $script=basename((string)($_SERVER['SCRIPT_NAME']??''));
        if(!in_array($script,['change-password.php','logout.php'],true)){
            header('Location: '.base_url('change-password.php'));
            exit;
        }
    }
    return $u;
}
function require_role(array $roles): array {
    $u=require_login();
    if(!in_array($u['role'],$roles,true)){ http_response_code(403); exit('Forbidden'); }
    return $u;
}
function csrf_token(): string {
    if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    if(!hash_equals($_SESSION['csrf']??'', $_POST['csrf']??'')) { http_response_code(419); exit('Invalid CSRF token'); }
}
function game_timing(array|string|null $config): array {
    if(is_string($config)) $config=json_decode($config,true)?:[];
    if(!is_array($config)) $config=[];
    $t=is_array($config['timing']??null)?$config['timing']:[];
    $auton=max(1,(int)($t['autonSeconds']??15));
    $teleop=max(1,(int)($t['teleopSeconds']??135));
    $transition=max(0,(int)($t['transitionPauseSeconds']??0));
    $endgame=max(0,min($teleop,(int)($t['endgameSeconds']??15)));
    return ['auton'=>$auton,'teleop'=>$teleop,'transition'=>$transition,'endgame'=>$endgame,'duration'=>$auton+$teleop];
}
function phase_for_game_time(?int $sec, array|string|null $config=null): string {
    if($sec===null) return 'unknown';
    $t=game_timing($config);
    if($sec<$t['auton']) return 'auton';
    if($sec>=($t['duration']-$t['endgame'])) return 'endgame';
    return 'teleop';
}
function phase_for_time(?int $sec): string { return phase_for_game_time($sec,null); }
function neptune_match_label(array $m): string {
    $level=['qm'=>'Qual','ef'=>'Eighth','qf'=>'Quarter','sf'=>'Semi','f'=>'Final','legacy'=>'Legacy'][$m['comp_level']??'']??strtoupper((string)($m['comp_level']??''));
    if(($m['comp_level']??'')==='qm' || ($m['comp_level']??'')==='legacy') return trim($level.' '.($m['match_number']??''));
    return trim($level.' '.($m['set_number']??1).'-'.($m['match_number']??''));
}
function primary_team_id(PDO $pdo, int $userId, int $organizationId): ?int {
    $s=$pdo->prepare('SELECT ut.team_id FROM user_teams ut JOIN teams t ON t.id=ut.team_id WHERE ut.user_id=? AND t.organization_id=? ORDER BY ut.is_primary DESC,ut.team_id LIMIT 1');
    $s->execute([$userId,$organizationId]);
    $id=(int)$s->fetchColumn();
    if($id>0) return $id;
    $s=$pdo->prepare('SELECT id FROM teams WHERE organization_id=? AND active=1 ORDER BY frc_team_number LIMIT 1');
    $s->execute([$organizationId]);
    $id=(int)$s->fetchColumn();
    return $id>0?$id:null;
}
