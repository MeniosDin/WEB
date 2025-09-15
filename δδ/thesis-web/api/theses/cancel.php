<?php
require_once __DIR__.'/../bootstrap.php'; require_role('teacher');
$in = body_json(); must($in,['thesis_id','reason']);
$s = db()->prepare("UPDATE theses SET status='canceled', canceled_reason=?, canceled_gs_number=?, canceled_gs_year=? WHERE id=? AND supervisor_id=?");
$s->execute([$in['reason'],$in['gs_number']??null,$in['gs_year']??null,$in['thesis_id'],$_SESSION['uid']]);
if(!$s->rowCount()) bad('Δεν ακυρώθηκε');
ok();