<?php
require_once __DIR__ . '/_bootstrap.php';
require_any_role(['teacher','secretariat']);

$data = read_json_body();
$thesis_id = (string)($data['thesis_id'] ?? '');
$rubric    = (string)($data['rubric_code'] ?? 'TMIYP-4CRIT-2024');
$scores    = $data['scores'] ?? null; // array with goals,duration,text,presentation

if ($thesis_id==='' || !is_array($scores)) json_err('thesis_id and scores required', 422);

// find person_id for current user
$p = $pdo->prepare("SELECT id FROM persons WHERE user_id=:u LIMIT 1");
$p->execute([':u'=>$user['id']]);
$person_id = $p->fetchColumn();
if (!$person_id) json_err('No person record for user', 404);

// ensure is committee member for this thesis
$chk = $pdo->prepare("SELECT COUNT(*) c FROM committee_members WHERE thesis_id=:t AND person_id=:p");
$chk->execute([':t'=>$thesis_id, ':p'=>$person_id]);
if ((int)$chk->fetch()['c'] === 0) json_err('Not a committee member', 403);

try {
  $json = json_encode($scores, JSON_UNESCAPED_UNICODE);
  $call = $pdo->prepare("CALL sp_record_grade(:t, :p, :r, :s)");
  $call->execute([':t'=>$thesis_id, ':p'=>$person_id, ':r'=>$rubric, ':s'=>$json]);
  json_ok(['message'=>'grade saved']);
} catch (Throwable $e) {
  json_err('Grade failed: '.$e->getMessage(), 400);
}
