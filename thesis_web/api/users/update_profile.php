<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_role('student');

$in   = $_POST ?: body_json();
$addr = trim((string)($in['address'] ?? ''));
$mob  = trim((string)($in['phone_mobile'] ?? ''));
$lan  = trim((string)($in['phone_landline'] ?? ''));
$mail = trim((string)($in['email'] ?? ''));  // αν θες να επιτρέπεις αλλαγή email

if ($mail === '') bad('Το email είναι υποχρεωτικό', 422);

$st = $pdo->prepare("
  UPDATE users
     SET address=?, phone_mobile=?, phone_landline=?, email=?, updated_at=NOW()
   WHERE id=? AND role='student'
");
$st->execute([$addr, $mob, $lan, $mail, $u['id']]);

ok(['message' => 'Το προφίλ ενημερώθηκε.']);
