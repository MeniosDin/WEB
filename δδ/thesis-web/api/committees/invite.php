<?php
require_once __DIR__ . '/_bootstrap.php';
require_any_role(['teacher','secretariat']);

$data = read_json_body();
$thesis_id = (string)($data['thesis_id'] ?? '');
$person_id = (string)($data['person_id'] ?? '');

if ($thesis_id === '' || $person_id === '') json_err('thesis_id and person_id are required', 422);

// If teacher, ensure they are supervisor of this thesis
if ($user['role'] === 'teacher') {
  $stm = $pdo->prepare("SELECT COUNT(*) c FROM theses WHERE id=:t AND supervisor_id=:s");
  $stm->execute([':t'=>$thesis_id, ':s'=>$user['id']]);
  if ((int)$stm->fetch()['c'] === 0) json_err('Only the supervisor can invite members', 403);
}

try {
  $pdo->query("SET SESSION sql_safe_updates=0");
  $call = $pdo->prepare("CALL sp_invite_committee_member(:t, :p)");
  $call->execute([':t'=>$thesis_id, ':p'=>$person_id]);
  json_ok(['message'=>'invited']);
} catch (Throwable $e) {
  json_err('Invite failed: '.$e->getMessage(), 400);
}
