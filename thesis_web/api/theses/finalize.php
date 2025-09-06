<?php
require_once __DIR__ . '/_bootstrap.php';
require_any_role(['teacher','secretariat']);

$data = read_json_body();
$thesis_id = (string)($data['thesis_id'] ?? '');
$gs_no     = (string)($data['gs_no'] ?? '');
$gs_year   = (int)($data['gs_year'] ?? 0);
$nim_url   = (string)($data['nimeritis_url'] ?? '');
$nim_date  = (string)($data['nimeritis_date'] ?? '');

if ($thesis_id==='' || $gs_no==='' || !$gs_year || $nim_url==='' || $nim_date==='') {
  json_err('All fields required: thesis_id, gs_no, gs_year, nimeritis_url, nimeritis_date', 422);
}

// If teacher, ensure supervisor
if ($user['role']==='teacher') {
  $stm = $pdo->prepare("SELECT COUNT(*) c FROM theses WHERE id=:t AND supervisor_id=:s");
  $stm->execute([':t'=>$thesis_id, ':s'=>$user['id']]);
  if ((int)$stm->fetch()['c'] === 0) json_err('Only supervisor can finalize', 403);
}

try {
  $call = $pdo->prepare("CALL sp_finalize_thesis(:t, :n, :y, :u, :d)");
  $call->execute([':t'=>$thesis_id, ':n'=>$gs_no, ':y'=>$gs_year, ':u'=>$nim_url, ':d'=>$nim_date]);
  json_ok(['message'=>'finalized']);
} catch (Throwable $e) {
  json_err('Finalize failed: '.$e->getMessage(), 400);
}
