<?php
require_once __DIR__.'/../bootstrap.php'; require_login();
$p = db()->prepare("SELECT p.id FROM persons p WHERE p.user_id=?");
$p->execute([$_SESSION['uid']]); $pid = ($p->fetch()['id'] ?? null); if(!$pid) ok(['items'=>[]]);
$s = db()->prepare("SELECT * FROM grades WHERE person_id=? ORDER BY created_at DESC");
$s->execute([$pid]); ok(['items'=>$s->fetchAll()]);