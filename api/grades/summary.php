<?php
// /thesis-web/api/grades/summary.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
  /* === Σύνδεση DB (βάλε το δικό σου bootstrap αν έχεις) === */
  // require __DIR__ . '/../_bootstrap.php'; // πρέπει να ορίζει $pdo (PDO)
  $DB_HOST='127.0.0.1'; $DB_PORT='3307'; $DB_NAME='thesis_db'; $DB_USER='root'; $DB_PASS='';
  $pdo = new PDO("mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);

  /* === Είσοδος === */
  $thesis_id = $_GET['thesis_id'] ?? '';
  if (!$thesis_id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Λείπει thesis_id']); exit; }

  /* === Βρες τη διπλωματική & αν είναι ενεργοποιημένη η βαθμολόγηση === */
  $st = $pdo->prepare('SELECT id, status, grading_enabled_at FROM theses WHERE id = :id');
  $st->execute([':id'=>$thesis_id]);
  $thesis = $st->fetch();
  if (!$thesis) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Δεν βρέθηκε διπλωματική.']); exit; }

  // Αν ΔΕΝ έχει ενεργοποιηθεί, γύρνα ok:true αλλά κενή σύνοψη
  if (empty($thesis['grading_enabled_at'])) {
    echo json_encode([
      'ok' => true,
      'summary' => (object)[], // student.js θα δείξει «Δεν έχουν καταχωρηθεί βαθμοί ακόμη.»
      'message' => 'Δεν έχει ενεργοποιηθεί η βαθμολόγηση.'
    ]);
    exit;
  }

  /* === Υπολόγισε σύνοψη βαθμών ===
     Υποθέτουμε πίνακα grades(thesis_id, member_user_id, total, created_at ...)
     Αν το δικό σου schema δεν έχει στήλη total, άλλαξε το SELECT ανάλογα. */
  $g = $pdo->prepare('
    SELECT COUNT(*) AS cnt, AVG(total) AS avg_total
    FROM grades
    WHERE thesis_id = :id
  ');
  $g->execute([':id'=>$thesis_id]);
  $sum = $g->fetch() ?: ['cnt'=>0,'avg_total'=>null];

  // Ομαλοποίηση τιμών
  $cnt = (int)($sum['cnt'] ?? 0);
  $avg = $sum['avg_total'] !== null ? round((float)$sum['avg_total'], 2) : null;

  echo json_encode([
    'ok' => true,
    'summary' => [
      'cnt'       => $cnt,
      'avg_total' => $avg,
    ],
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Σφάλμα: '.$e->getMessage()]);
}
