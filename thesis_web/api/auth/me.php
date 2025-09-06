<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_login();
ok(['user'=>$u]);
?>