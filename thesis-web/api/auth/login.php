<?php
require_once __DIR__.'/../bootstrap.php';
$in = body_json(); must($in,['email','password']);
$s = db()->prepare("SELECT id,role,name,password_hash FROM users WHERE email=?");
$s->execute([$in['email']]); $u = $s->fetch();
if(!$u || !password_verify($in['password'], $u['password_hash'])) bad('Λάθος στοιχεία', 401);
$_SESSION['uid']=$u['id']; $_SESSION['role']=$u['role'];
ok(['user'=>['id'=>$u['id'],'role'=>$u['role'],'name'=>$u['name']]]);