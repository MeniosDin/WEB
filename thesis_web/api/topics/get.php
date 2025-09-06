<?php
require_once __DIR__.'/../bootstrap.php'; require_login();
$id = $_GET['id'] ?? ''; if(!$id) bad('Λείπει id');
$s = db()->prepare("SELECT * FROM topics WHERE id=?");
$s->execute([$id]); ok(['item'=>$s->fetch()]);