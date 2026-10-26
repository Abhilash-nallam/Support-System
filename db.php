<?php
declare(strict_types=1);
$config = require __DIR__ . '/config.php';
function db(): PDO {
 static $pdo = null; global $config;
 if ($pdo instanceof PDO) return $pdo;
 $dsn = 'mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4';
 $pdo = new PDO($dsn,$config['db_user'],$config['db_password'],[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES=>false
 ]);
 return $pdo;
}
