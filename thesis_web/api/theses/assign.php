<?php
require_once __DIR__.'/../bootstrap.php'; require_role('teacher');
$in = body_json(); must($in,['student_id','topic_id']);
$s = db()->prepare("INSERT INTO theses(id,student_id,topic_id,supervisor_id)
VALUES(UUID(),?,?,?)");
$s->execute([$in['student_id'],$in['topic_id'],$_SESSION['uid']]);
ok(['message'=>'Ανατέθηκε']);