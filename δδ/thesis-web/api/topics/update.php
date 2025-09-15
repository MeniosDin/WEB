<?php
require_once __DIR__.'/../bootstrap.php'; require_role('teacher');
$in = body_json(); must($in,['id','title']);
$s = db()->prepare("UPDATE topics SET title=?, summary=?, updated_at=NOW() WHERE id=? AND supervisor_id=?");
$s->execute([$in['title'],$in['summary']??null,$in['id'],$_SESSION['uid']]);
if(!$s->rowCount()) bad('Δεν ενημερώθηκε (ιδιοκτησία/ύπαρξη)');
ok();