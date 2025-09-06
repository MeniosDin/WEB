<?php
require_once __DIR__.'/../bootstrap.php';
if(!isset($_SESSION['uid'])) ok(['user'=>null]);
$s = db()->prepare("SELECT id,role,name,email FROM users WHERE id=?");
$s->execute([$_SESSION['uid']]); ok(['user'=>$s->fetch()]);