<?php
require_once __DIR__ . '/../bootstrap.php';
$user = require_login();

$thesis_id = $_GET['thesis_id'] ?? '';
if ($thesis_id === '') bad('thesis_id required', 422);

if ($user['role'] === 'student') {
  $own = $pdo->prepare("SELECT 1 FROM theses WHERE id=? AND student_id=?");
  $own->execute([$thesis_id, $user['id']]);
  if (!$own->fetchColumn()) bad('Forbidden', 403);
}

$st = $pdo->prepare("
  SELECT cm.role_in_committee, p.first_name, p.last_name, p.email
  FROM committee_members cm
  JOIN persons p ON p.id=cm.person_id
  WHERE cm.thesis_id=?
  ORDER BY FIELD(cm.role_in_committee,'supervisor','member'), p.last_name, p.first_name
");
$st->execute([$thesis_id]);
ok(['items'=>$st->fetchAll()]);
