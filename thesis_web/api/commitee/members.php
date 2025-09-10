<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_login();

$thesis_id = $_GET['thesis_id'] ?? '';
if ($thesis_id === '') bad('thesis_id required', 422);

if ($u['role'] === 'student') { assert_student_owns_thesis($pdo, $thesis_id, $u['id']); }

$st = $pdo->prepare("
  SELECT cm.role_in_committee, p.first_name, p.last_name, p.email
  FROM committee_members cm
  JOIN persons p ON p.id = cm.person_id
  WHERE cm.thesis_id = ?
  ORDER BY FIELD(cm.role_in_committee,'supervisor','member'), p.last_name, p.first_name
");
$st->execute([$thesis_id]);
ok(['items'=>$st->fetchAll()]);
