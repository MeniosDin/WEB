<?php
require_once __DIR__ . '/../bootstrap.php';
if (!isset($_SESSION['uid'])) ok(['user'=>null]);
$stm = $pdo->prepare("SELECT id, role, name, email FROM users WHERE id=?");
$stm->execute([$_SESSION['uid']]);
ok(['user'=>$stm->fetch() ?: null]);
