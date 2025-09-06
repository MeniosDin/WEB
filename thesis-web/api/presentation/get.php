<?php
require_once __DIR__.'/../bootstrap.php';
require_login();

$tid = $_GET['thesis_id'] ?? '';
if(!$tid) bad('Λείπει thesis_id');

$s = db()->prepare("SELECT * FROM presentation WHERE thesis_id=?");
$s->execute([$tid]);

$item = $s->fetch();
if(!$item) bad('Δεν βρέθηκε παρουσίαση', 404);

ok(['item'=>$item]);
