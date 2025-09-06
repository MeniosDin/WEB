<?php
require_once __DIR__.'/../bootstrap.php'; require_login();
$in = body_json(); must($in,['invitation_id','status']); assert_enum($in['status'], ['accepted','declined'],'status');
$s = db()->prepare("UPDATE committee_invitations SET status=?, responded_at=NOW() WHERE id=?");
$s->execute([$in['status'],$in['invitation_id']]); ok();