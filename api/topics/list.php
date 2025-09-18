<?php
// api/presentation/list.php
require_once __DIR__ . '/../bootstrap.php';

// επιτρέπουμε: student, teacher, secretariat
$u = require_role('student','teacher','secretariat');

$thesis_id = trim((string)($_GET['thesis_id'] ?? '')); // προαιρετικό
$upcoming  = trim((string)($_GET['upcoming']  ?? '')); // '1' => μελλοντικές μόνο

$params = [];
$where  = [];
$join   = "
  JOIN theses t   ON t.id = p.thesis_id
  JOIN topics tp  ON tp.id = t.topic_id
  JOIN users  stu ON stu.id = t.student_id
  JOIN users  sup ON sup.id = t.supervisor_id
";

// φίλτρα ανά ρόλο
if ($u['role'] === 'student') {
  $where[] = "t.student_id = ?";
  $params[] = $u['id'];
} elseif ($u['role'] === 'teacher') {
  // επιβλέπων ή μέλος τριμελούς
  $join .= "
    LEFT JOIN committee_members cm ON cm.thesis_id = t.id
    LEFT JOIN persons          pr ON pr.id = cm.person_id
  ";
  $where[] = "(t.supervisor_id = ? OR pr.user_id = ?)";
  $params[] = $u['id'];
  $params[] = $u['id'];
} else {
  // secretariat: χωρίς φίλτρο χρήστη
}

if ($thesis_id !== '') {
  $where[] = "p.thesis_id = ?";
  $params[] = $thesis_id;
}
if ($upcoming === '1') {
  $where[] = "p.when_dt >= NOW()";
}

$sql = "
  SELECT
    p.id, p.thesis_id, p.when_dt, p.mode, p.room_or_link, p.published_at,
    tp.title AS topic_title,
    stu.name AS student_name,
    sup.name AS supervisor_name
  FROM presentation p
  $join
  " . (count($where) ? "WHERE " . implode(" AND ", $where) : "") . "
  ORDER BY p.when_dt DESC
  LIMIT 200
";

$st = $pdo->prepare($sql);
$st->execute($params);
ok(['items' => $st->fetchAll()]);
