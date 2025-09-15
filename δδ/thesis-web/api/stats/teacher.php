<?php
require_once __DIR__.'/../bootstrap.php'; require_role('teacher');
$uid = $_SESSION['uid'];
$s = db()->prepare("SELECT count_supervised, count_as_member, avg_grade_related FROM vw_teacher_stats WHERE teacher_id=?");
$s->execute([$uid]); ok($s->fetch() ?: ['count_supervised'=>0,'count_as_member'=>0,'avg_grade_related'=>null]);