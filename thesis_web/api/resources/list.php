<?php
require_once __DIR__.'/../bootstrap.php'; require_login();
$tid = $_GET['thesis_id'] ?? ''; if(!$tid) bad('Λείπει thesis_id');
$s = db()->prepare("SELECT * FROM resources WHERE thesis_id=? ORDER BY created_at DESC");
$s->execute([$tid]); ok(['items'=>$s->fetchAll()]);