<?php
require_once __DIR__.'/../bootstrap.php'; require_login();
$tid = $_GET['thesis_id'] ?? ''; if(!$tid) bad('Λείπει thesis_id');
$s = db()->prepare("SELECT * FROM committee_invitations WHERE thesis_id=? ORDER BY invited_at DESC");
$s->execute([$tid]); ok(['items'=>$s->fetchAll()]);