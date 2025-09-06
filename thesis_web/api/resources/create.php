<?php
require_once __DIR__.'/../bootstrap.php'; require_login();
$in = body_json(); must($in,['thesis_id','kind','url_or_path']);
assert_enum($in['kind'], ['draft','code','video','image','other'],'kind');
$s = db()->prepare("INSERT INTO resources(id, thesis_id, kind, url_or_path) VALUES(UUID(),?,?,?)");
$s->execute([$in['thesis_id'],$in['kind'],$in['url_or_path']]); ok();