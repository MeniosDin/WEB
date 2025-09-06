<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_role('teacher');
$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM topics WHERE supervisor_id=? ORDER BY created_at DESC');
$stmt->execute([$u['id']]);
ok($stmt->fetchAll());
?>