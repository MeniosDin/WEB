<?php
// /api/presentation/schedule.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../bootstrap.php';     // <-- ΜΟΝΟ ΑΝ το bootstrap.php είναι στο /api/
require_once __DIR__ . '/../utils/auth_guard.php';

try {
  // Επιτρεπτοί ρόλοι: student (και προαιρετικά supervisor, admin)
  ensure_logged_in(); // αν έχεις ensure_role([...]) βάλε το εδώ

  // Δεχόμαστε είτε form-data είτε JSON
  $isMultipart = !empty($_POST);
  $in = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?? []);

  $thesis_id    = trim((string)($in['thesis_id'] ?? ''));
$when_raw     = trim((string)($in['when_dt']   ?? ''));
$mode_raw     = trim((string)($in['mode']      ?? 'in_person')); // 👈 default σωστό
$room_or_link = trim((string)($in['room_or_link'] ?? ''));

if ($thesis_id === '' || $when_raw === '' || $room_or_link === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'thesis_id, when_dt και room_or_link είναι υποχρεωτικά.']);
  exit;
}

// Έλεγχος mode
$allowedModes = ['in_person','online'];
if (!in_array($mode_raw, $allowedModes, true)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Μη έγκυρος τρόπος παρουσίασης.']);
  exit;
}
$mode = $mode_raw;


  // Normalization του τρόπου
  $mode = in_array($mode_raw, $allowedModes, true) ? $mode_raw : 'in_person';

  // Parse της ημερομηνίας/ώρας σε DATETIME (δοκίμασε 2 φορμάτ)
  $dt = DateTime::createFromFormat('Y-m-d\TH:i', $when_raw);
  if (!$dt) { $dt = DateTime::createFromFormat('m/d/Y h:i A', $when_raw); }
  if (!$dt) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Μη έγκυρη μορφή ημερομηνίας/ώρας.']);
    exit;
  }
  // Αν θέλεις UTC:
  // $dt->setTimezone(new DateTimeZone('UTC'));
  $when_sql = $dt->format('Y-m-d H:i:00');

  // Αποθήκευση (UPSERT): αν υπάρχει ήδη εγγραφή για τη διπλωματική → ενημέρωση
  $sql = "INSERT INTO presentations (thesis_id, when_dt, mode, room_or_link)
          VALUES (?, ?, ?, ?)
          ON DUPLICATE KEY UPDATE when_dt = VALUES(when_dt),
                                  mode = VALUES(mode),
                                  room_or_link = VALUES(room_or_link)";
  $st = $pdo->prepare($sql);
  $st->execute([$thesis_id, $when_sql, $mode, $room_or_link]);

  echo json_encode([
    'ok' => true,
    'data' => [
      'thesis_id'    => $thesis_id,
      'when_dt'      => $when_sql,
      'mode'         => $mode,
      'room_or_link' => $room_or_link,
    ]
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server error']);
}
