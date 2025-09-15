<?php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../bootstrap.php';

$me  = require_login();
$pdo = db();

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$thesis_id = trim((string)($in['thesis_id'] ?? ''));
$criteria  = $in['criteria'] ?? null;
if ($thesis_id === '' || !is_array($criteria)) {
  http_response_code(422);
  echo json_encode(['ok'=>false,'error'=>'thesis_id και criteria απαιτούνται']); exit;
}

/* εύρεση person_id από users */
$st = $pdo->prepare("SELECT COALESCE(p.id, :uid) AS person_id FROM persons p WHERE p.user_id=:uid LIMIT 1");
$st->execute([':uid'=>$me['id']]);
$person_id = (string)($st->fetchColumn() ?: $me['id']);

/* έλεγχος ότι ο χρήστης είναι στην τριμελή */
$chk = $pdo->prepare("SELECT 1 FROM committee_members WHERE thesis_id=:tid AND person_id=:pid LIMIT 1");
$chk->execute([':tid'=>$thesis_id, ':pid'=>$person_id]);
if (!$chk->fetchColumn()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Δεν είστε μέλος τριμελούς']); exit; }

/* rubric (προαιρετικά) */
$rub = $pdo->query("SELECT id FROM grading_rubrics WHERE effective_from <= CURRENT_DATE ORDER BY effective_from DESC LIMIT 1")->fetch();
$rubric_id = $rub['id'] ?? null;

$payload = [
  'goals'        => (float)($criteria['goals']        ?? 0),
  'duration'     => (float)($criteria['duration']     ?? 0),
  'text'         => (float)($criteria['text']         ?? 0),
  'presentation' => (float)($criteria['presentation'] ?? 0),
];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE);

$sql = "
INSERT INTO grades (id, thesis_id, person_id, rubric_id, criteria_scores_json, created_at, updated_at)
VALUES (UUID(), :tid, :pid, :rid, :json, NOW(), NOW())
ON DUPLICATE KEY UPDATE rubric_id=VALUES(rubric_id), criteria_scores_json=VALUES(criteria_scores_json), updated_at=NOW()
";
$st = $pdo->prepare($sql);
$st->execute([':tid'=>$thesis_id, ':pid'=>$person_id, ':rid'=>$rubric_id, ':json'=>$json]);

echo json_encode(['ok'=>true,'data'=>['saved'=>true]], JSON_UNESCAPED_UNICODE);
