<?php
// config.php
// ---- Βάλε εδώ τα credentials σου. Στο XAMPP συνήθως: user=root, password="" (κενό).
return [
  'db' => [
    'host'     => '127.0.0.1',
    'port'     => 3307,
    'database' => 'thesis_db',
    'user'     => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
  ],
  // προαιρετικά για production:
  'debug' => true
];