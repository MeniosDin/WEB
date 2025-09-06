<?php
require_once __DIR__.'/../bootstrap.php'; require_login();
$tid = $_GET['thesis_id'] ?? ''; if(!$tid) bad('Λείπει thesis_id');
$s = db()->prepare("SELECT AVG(total) avg_total, COUNT(*) cnt FROM grades WHERE thesis_id=?");
$s->execute([$tid]); ok(['summary'=>$s->fetch()]);