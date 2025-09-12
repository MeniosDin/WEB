<?php
require_once __DIR__ . '/_bootstrap.php';
// Typically committee members are teachers (internal). We map users->persons to find their invitation.
require_any_role(['teacher','secretariat']);

$data = read_json_body();
$invitation_id = (string)($data['invitation_id'] ?? '');
$thesis_id     = (string)($data['thesis_id'] ?? '');

if ($invitation_id === '' && $thesis_id === '') json_err('Provide invitation_id or thesis_id', 422);

try {
  if ($invitation_id !== '') {
    $call = $pdo->prepare("CALL sp_accept_invitation(:id)");
    $call->execute([':id'=>$invitation_id]);
    json_ok(['message'=>'accepted']);
  } else {
    // find person_id for this user
    $p = $pdo->prepare("SELECT id FROM persons WHERE user_id=:u LIMIT 1");
    $p->execute([':u'=>$user['id']]);
    $person_id = $p->fetchColumn();
    if (!$person_id) json_err('No person record for user', 404);

    $stm = $pdo->prepare("SELECT id FROM committee_invitations WHERE thesis_id=:t AND person_id=:p AND status='pending'");
    $stm->execute([':t'=>$thesis_id, ':p'=>$person_id]);
    $iid = $stm->fetchColumn();
    if (!$iid) json_err('No pending invitation found', 404);

    $call = $pdo->prepare("CALL sp_accept_invitation(:id)");
    $call->execute([':id'=>$iid]);
    json_ok(['message'=>'accepted','invitation_id'=>$iid]);
  }
} catch (Throwable $e) {
  json_err('Accept failed: '.$e->getMessage(), 400);
}
