<?php
require_once __DIR__.'/../bootstrap.php'; require_role('student');
$in = body_json(); must($in,['thesis_id','person_id']);
$s = db()->prepare("INSERT INTO committee_invitations(id, thesis_id, person_id) VALUES(UUID(),?,?)");
$s->execute([$in['thesis_id'],$in['person_id']]); ok();