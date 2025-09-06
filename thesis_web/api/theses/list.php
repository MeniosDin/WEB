<?php
require_once __DIR__.'/../bootstrap.php'; require_login();
$role = $_SESSION['role']; $uid = $_SESSION['uid'];
if($role==='teacher'){
$s = db()->prepare("SELECT * FROM theses WHERE supervisor_id=? ORDER BY created_at DESC");
$s->execute([$uid]); ok(['items'=>$s->fetchAll()]);
}else if($role==='student'){
$s = db()->prepare("SELECT * FROM theses WHERE student_id=? ORDER BY created_at DESC");
$s->execute([$uid]); ok(['items'=>$s->fetchAll()]);
}else{
$s = db()->query("SELECT * FROM theses ORDER BY created_at DESC LIMIT 500");
ok(['items'=>$s->fetchAll()]);
}