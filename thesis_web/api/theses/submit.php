<?php
require_once __DIR__ . '/_bootstrap.php';
require_any_role(['student','teacher']);

$data = read_json_body();
$thesis_id = (string)($data['thesis_id'] ?? '');
if ($thesis_id === '') json_err('thesis_id is required', 422);

// Permission: student must own, or teacher must be supervisor
if ($user['role'] === 'student') {
  $stm = $pdo->prepare("SELECT COUNT(*) c FROM theses WHERE id=:t AND student_id=:s");
  $stm->execute([':t'=>$thesis_id, ':s'=>$user['id']]);
  if ((int)$stm->fetch()['c'] === 0) json_err('Not your thesis', 403);
} else if ($user['role'] === 'teacher') {
  $stm = $pdo->prepare("SELECT COUNT(*) c FROM theses WHERE id=:t AND supervisor_id=:s");
  $stm->execute([':t'=>$thesis_id, ':s'=>$user['id']]);
  if ((int)$stm->fetch()['c'] === 0) json_err('Only supervisor can submit on behalf', 403);
}

try {
  $call = $pdo->prepare("CALL sp_submit_to_committee(:t)");
  $call->execute([':t'=>$thesis_id]);
  json_ok(['message'=>'submitted']);
} catch (Throwable $e) {
  json_err('Submit failed: '.$e->getMessage(), 400);
}
