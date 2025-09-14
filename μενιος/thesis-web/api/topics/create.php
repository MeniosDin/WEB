<?php
require_once __DIR__.'/../bootstrap.php'; require_role('teacher');
$in = body_json(); must($in,['title']);
$s = db()->prepare("INSERT INTO topics(id,supervisor_id,title,summary) VALUES(UUID(),?,?,?)");
$s->execute([$_SESSION['uid'],$in['title'],$in['summary']??null]); ok();