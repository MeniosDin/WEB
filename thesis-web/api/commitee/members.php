<?php
require_once __DIR__.'/../bootstrap.php'; require_login();
$tid = $_GET['thesis_id'] ?? ''; if(!$tid) bad('Λείπει thesis_id');
$s = db()->prepare("SELECT cm.*, p.first_name, p.last_name, p.email, cm.role_in_committee
FROM committee_members cm JOIN persons p ON p.id=cm.person_id
WHERE cm.thesis_id=?");
$s->execute([$tid]); ok(['items'=>$s->fetchAll()]);