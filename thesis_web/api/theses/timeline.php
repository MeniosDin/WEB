<?php
require_once __DIR__.'/../bootstrap.php'; require_login();
$tid = $_GET['thesis_id'] ?? ''; if(!$tid) bad('Λείπει thesis_id');
$s = db()->prepare("SELECT event_type, from_status, to_status, details, created_at
FROM events_log WHERE thesis_id=? ORDER BY created_at");
$s->execute([$tid]); ok(['items'=>$s->fetchAll()]);