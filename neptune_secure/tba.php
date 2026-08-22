<?php
require_once __DIR__ . '/bootstrap.php';
function tba_get(string $path, int $ttl=300): array {
    global $pdo,$config;
    $key='tba:'.sha1($path); $now=gmdate('Y-m-d H:i:s');
    $s=$pdo->prepare('SELECT response_json FROM tba_cache WHERE cache_key=? AND expires_at>?'); $s->execute([$key,$now]);
    if($row=$s->fetch()) return json_decode($row['response_json'],true) ?: [];
    $url=rtrim($config['tba']['base_url'],'/').'/'.ltrim($path,'/');
    $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['X-TBA-Auth-Key: '.$config['tba']['auth_key'],'Accept: application/json'],CURLOPT_TIMEOUT=>15]);
    $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); if($body===false||$code>=400) throw new RuntimeException('TBA request failed (HTTP '.$code.')'); curl_close($ch);
    $exp=gmdate('Y-m-d H:i:s',time()+$ttl); $s=$pdo->prepare('INSERT INTO tba_cache(cache_key,response_json,fetched_at,expires_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE response_json=VALUES(response_json),fetched_at=VALUES(fetched_at),expires_at=VALUES(expires_at)'); $s->execute([$key,$body,$now,$exp]);
    return json_decode($body,true) ?: [];
}
