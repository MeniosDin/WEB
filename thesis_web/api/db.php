<?php
declare(strict_types=1);
function db(): PDO {
  static $pdo; if ($pdo) return $pdo;

  $cfg = require dirname(__DIR__) . '/config.php';
  $db  = $cfg['db'];

  $dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'], (int)$db['port'], $db['database'], $db['charset'] ?? 'utf8mb4'
  );

  $options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ];

  try {
    $pdo = new PDO($dsn, $db['user'], $db['password'], $options);
  } catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed: '.$e->getMessage());
  }
  return $pdo;
}
