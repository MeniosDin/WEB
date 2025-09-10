<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_login();

$thesis_id = $_GET['thesis_id'] ?? '';
if ($thesis_id === '') bad('thesis_id required', 422);
if ($u['role'] === 'student') { assert_student_owns_thesis($pdo, $thesis_id, $u['id']); }

$st = $pdo->prepare("SELECT COUNT(*) cnt, AVG(total) avg_total FROM grades WHERE thesis_id=?");
$st->execute([$thesis_id]);
ok(['summary' => $st->fetch() ?: ['cnt'=>0,'avg_total'=>null]]);
