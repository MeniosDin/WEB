<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_role('student'); // μόνο φοιτητής

$st = $pdo->prepare("
  SELECT id, role, student_number, name, email, address, phone_mobile, phone_landline
  FROM users WHERE id = ? LIMIT 1
");
$st->execute([$u['id']]);
$p = $st->fetch();
if (!$p) bad('Not found', 404);

ok(['profile' => $p]);
