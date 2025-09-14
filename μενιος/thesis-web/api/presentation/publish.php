<?php
require_once __DIR__.'/../bootstrap.php'; require_role('teacher');
$in = body_json(); must($in,['thesis_id','announcement_html']);
$s = db()->prepare("UPDATE presentation SET published_at=COALESCE(published_at,NOW()), announcement_html=? WHERE thesis_id=?");
$s->execute([$in['announcement_html'],$in['thesis_id']]); ok();