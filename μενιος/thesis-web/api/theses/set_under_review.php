<?php
require_once __DIR__.'/../bootstrap.php'; require_role('teacher');
$in = body_json(); must($in,['thesis_id']);
$s = db()->prepare("UPDATE theses SET status='under_review', committee_submission_at=COALESCE(committee_submission_at,NOW())
WHERE id=? AND supervisor_id=? AND status='active'");
$s->execute([$in['thesis_id'], $_SESSION['uid']]);
if(!$s->rowCount()) bad('Δεν ενημερώθηκε (έλεγχος κατάστασης/ιδιοκτησίας).', 400);
ok();