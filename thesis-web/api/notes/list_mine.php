<?php
require_once __DIR__.'/../bootstrap.php'; require_login();
$s = db()->prepare("SELECT * FROM notes WHERE author_id=? ORDER BY created_at DESC LIMIT 200");
$s->execute([$_SESSION['uid']]); ok(['items'=>$s->fetchAll()]);