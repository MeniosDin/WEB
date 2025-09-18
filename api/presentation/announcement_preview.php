<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../bootstrap.php';

$me  = require_role('teacher');
$pdo = db();

$thesis_id = $_POST['thesis_id'] ?? $_GET['thesis_id'] ?? '';
if (!$thesis_id) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Λείπει thesis_id']); exit; }

/** true αν υπάρχει η στήλη $col στον πίνακα $table */
$hasCol = function(string $table, string $col) use ($pdo): bool {
  $sql = "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([':t'=>$table, ':c'=>$col]);
  return (bool)$st->fetchColumn();
};

/* Δόμηση εκφράσεων για όνομα φοιτητή & ΑΜ χωρίς να αναφερθούμε σε ανύπαρκτες στήλες */
$nameParts = [];
if ($hasCol('users','full_name')) $nameParts[] = "NULLIF(TRIM(u.full_name), '')";
if ($hasCol('users','name'))      $nameParts[] = "NULLIF(TRIM(u.name), '')";
if ($hasCol('users','first_name') || $hasCol('users','last_name')) {
  $fn = $hasCol('users','first_name') ? 'u.first_name' : "''";
  $ln = $hasCol('users','last_name')  ? 'u.last_name'  : "''";
  $nameParts[] = "NULLIF(TRIM(CONCAT(IFNULL($fn,''),' ',IFNULL($ln,''))), '')";
}
$nameExpr = $nameParts ? 'COALESCE('.implode(',', $nameParts).')' : "''";

$amParts = [];
foreach (['student_number','am','student_no','aem','studentid'] as $c) {
  if ($hasCol('users',$c)) $amParts[] = "NULLIF(TRIM(u.$c), '')";
}
$amExpr = $amParts ? 'COALESCE('.implode(',', $amParts).')' : "''";

/* Κυρίως SELECT */
$sql = "
SELECT
  t.id,
  tp.title AS topic_title,
  $nameExpr  AS student_name,
  $amExpr    AS student_number,
  p.when_dt, p.mode, p.room_or_link
FROM theses t
JOIN topics tp       ON tp.id = t.topic_id
JOIN users  u        ON u.id  = t.student_id
JOIN presentation p  ON p.thesis_id = t.id
WHERE t.id = :tid
LIMIT 1";
$st = $pdo->prepare($sql);
$st->execute([':tid'=>$thesis_id]);
$r = $st->fetch(PDO::FETCH_ASSOC);

if (!$r) { echo json_encode(['ok'=>false,'error'=>'Δεν βρέθηκαν στοιχεία παρουσίασης']); exit; }

$whn  = $r['when_dt'] ? date('d/m/Y H:i', strtotime($r['when_dt'])) : '—';
$mode = ($r['mode'] === 'online') ? 'Διαδικτυακά' : 'Δια ζώσης';
$loc  = ($r['mode'] === 'online') ? 'Σύνδεσμος' : 'Αίθουσα';

$html = "<!doctype html><meta charset='utf-8'>
<title>Ανακοίνωση Παρουσίασης</title>
<body style='font:16px/1.5 system-ui;padding:2rem'>
  <h2 style='margin:0 0 .5rem'>Ανακοίνωση Παρουσίασης ΔΕ</h2>
  <p><strong>Θέμα:</strong> ".htmlspecialchars($r['topic_title'] ?? '—')."</p>
  <p><strong>Φοιτητής/τρια:</strong> ".htmlspecialchars($r['student_name'] ?? '—')." (".htmlspecialchars($r['student_number'] ?? '—').")</p>
  <p><strong>Ημ/νία:</strong> ".htmlspecialchars($whn)."</p>
  <p><strong>Τρόπος:</strong> ".htmlspecialchars($mode)."</p>
  <p><strong>".$loc.":</strong> ".htmlspecialchars($r['room_or_link'] ?? '—')."</p>
</body>";

echo json_encode(['ok'=>true,'html'=>$html], JSON_UNESCAPED_UNICODE);
