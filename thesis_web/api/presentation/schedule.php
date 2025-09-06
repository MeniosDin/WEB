<?php
require_once __DIR__.'/../bootstrap.php'; require_role('teacher');
$in = body_json(); must($in,['thesis_id','when_dt','mode','room_or_link']);
assert_enum($in['mode'], ['in_person','online'],'mode');
$when = parse_datetime($in['when_dt'],'when_dt');
$s = db()->prepare("INSERT INTO presentation(id, thesis_id, when_dt, mode, room_or_link)
VALUES(UUID(),?,?,?,?)
ON DUPLICATE KEY UPDATE when_dt=VALUES(when_dt), mode=VALUES(mode), room_or_link=VALUES(room_or_link)");
$s->execute([$in['thesis_id'],$when,$in['mode'],$in['room_or_link']]);
ok(['message'=>'Προγραμματίστηκε/ενημερώθηκε']);