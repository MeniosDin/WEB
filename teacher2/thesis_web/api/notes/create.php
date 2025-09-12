<?php
require_once __DIR__.'/../bootstrap.php'; require_login();
$in = body_json(); must($in,['thesis_id','text']);
if(mb_strlen($in['text'])>300) bad('Μέγιστο 300 χαρακτήρες');
$s = db()->prepare("INSERT INTO notes(id, thesis_id, author_id, text) VALUES(UUID(),?,?,?)");
$s->execute([$in['thesis_id'], $_SESSION['uid'], $in['text']]); ok();