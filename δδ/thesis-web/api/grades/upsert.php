<?php
require_once __DIR__.'/../bootstrap.php'; require_login();
$in = body_json(); must($in,['thesis_id','criteria']);
$crit = $in['criteria'];
$p = db()->prepare("SELECT p.id FROM persons p WHERE p.user_id=?");
$p->execute([$_SESSION['uid']]); $person_id = ($p->fetch()['id'] ?? null);
if(!$person_id) bad('Δεν υπάρχει person για το χρήστη.', 400);
$c = db()->prepare("SELECT 1 FROM committee_members WHERE thesis_id=? AND person_id=?");
$c->execute([$in['thesis_id'],$person_id]); if(!$c->fetch()) forbid();
$r = db()->query("SELECT id FROM grading_rubrics WHERE effective_from<=CURRENT_DATE AND (effective_to IS NULL OR effective_to>=CURRENT_DATE) ORDER BY effective_from DESC LIMIT 1")->fetch();
if(!$r) bad('Δεν υπάρχει ενεργό rubric.');
$s = db()->prepare("INSERT INTO grades(id, thesis_id, person_id, rubric_id, criteria_scores_json)
VALUES(UUID(),?,?,?,JSON_OBJECT('goals',?,'duration',?,'text',?,'presentation',?))
ON DUPLICATE KEY UPDATE rubric_id=VALUES(rubric_id), criteria_scores_json=VALUES(criteria_scores_json)");
$s->execute([$in['thesis_id'],$person_id,$r['id'],$crit['goals']??0,$crit['duration']??0,$crit['text']??0,$crit['presentation']??0]);
ok(['message'=>'Αποθηκεύτηκε ο βαθμός']);