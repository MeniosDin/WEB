<?php
require_once __DIR__ . '/../bootstrap.php';
$user = require_role('student');

$in = $_POST ?: body_json();
$thesis_id = $in['thesis_id'] ?? '';
$person_id = $in['person_id'] ?? '';
if (!$thesis_id || !$person_id) bad('thesis_id & person_id required', 422);

$own = $pdo->prepare("SELECT 1 FROM theses WHERE id=? AND student_id=? AND status='under_assignment'");
$own->execute([$thesis_id, $user['id']]);
if (!$own->fetchColumn()) bad('Not allowed', 403);

$pdo->prepare("
  INSERT INTO committee_invitations(thesis_id, person_id) VALUES(?,?)
  ON DUPLICATE KEY UPDATE status=status
")->execute([$thesis_id, $person_id]);

ok(['ok'=>true]);
