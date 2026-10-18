<?php
if (!isset($config) || !is_array($config)) {
    $config = require __DIR__ . '/config.php';
}
$db = $config['db'];
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    $db['port'],
    $db['name'],
    $db['charset']
);

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

$sslCa = trim((string)($db['ssl_ca'] ?? ''));
if ($sslCa !== '') {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = !empty($db['ssl_verify']);
    }
}

$pdo = new PDO($dsn, $db['user'], $db['pass'], $options);

// Time standard: the database stores UTC. Every Neptune connection runs in UTC so DEFAULT/ON UPDATE
// CURRENT_TIMESTAMP, NOW() and TIMESTAMP columns all agree with UTC_TIMESTAMP() and gmdate().
$pdo->exec("SET time_zone = '+00:00'");
require_once __DIR__ . '/time-standard.php';
