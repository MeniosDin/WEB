<?php
// db.php
// Δημιουργεί και επιστρέφει ένα PDO connection σε Singleton pattern.

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $cfg = require __DIR__ . '/config.php';
  $db  = $cfg['db'];

  $dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    $db['port'],
    $db['database'],
    $db['charset']
  );

  $options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Exceptions για λάθη
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // fetch ως associative arrays
    PDO::ATTR_EMULATE_PREPARES   => false,                  // native prepared statements
  ];

  try {
    $pdo = new PDO($dsn, $db['user'], $db['password'], $options);
  } catch (PDOException $e) {
    // Απλό error page (μην εκθέτεις stacktrace σε production)
    http_response_code(500);
    exit('Database connection failed.');
  }
  return $pdo;
}