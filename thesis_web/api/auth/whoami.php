<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

$u = current_user(); // διαβάζει $_SESSION['uid'], $_SESSION['role']
if (!$u) { bad('Unauthorized', 401); }
ok(['user' => $u]);
