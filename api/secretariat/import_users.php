<?php
declare(strict_types=1);

ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../db.php';

// επιτρέπουμε μόνο Γραμματεία
require_role('secretariat');
$pdo = db(); // PDO με utf8mb4 + ERRMODE_EXCEPTION

try {
  // Περιμένουμε raw JSON body: { items: [ ... ] }
  $raw = file_get_contents('php://input');
  if (!$raw) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'empty_body']);
    exit;
  }
  $body = json_decode($raw, true);
  if (!is_array($body) || !isset($body['items']) || !is_array($body['items'])) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_payload','hint'=>'expected {items:[...]}']);
    exit;
  }

  $items = $body['items'];
  $inserted = 0; $updated = 0; $failed = 0;
  $errors = [];
  $new_credentials = []; // για καινούριους users: [{email, password}]

  // Προετοιμασία statement (INSERT ... ON DUPLICATE KEY UPDATE)
  // Πεδία πίνακα users: id (uuid default), role, student_number, name, email, password_hash, address, phone_mobile, phone_landline
  $sql = "
    INSERT INTO users
      (role, student_number, name, email, password_hash, address, phone_mobile, phone_landline)
    VALUES
      (:role, :student_number, :name, :email, :password_hash, :address, :phone_mobile, :phone_landline)
    ON DUPLICATE KEY UPDATE
      name = VALUES(name),
      address = VALUES(address),
      phone_mobile = VALUES(phone_mobile),
      phone_landline = VALUES(phone_landline),
      -- επιτρέπουμε update student_number μόνο για ρόλο student αν δίνεται
      student_number = IF(VALUES(student_number) IS NULL OR VALUES(role) <> 'student', student_number, VALUES(student_number))
  ";
  $stmt = $pdo->prepare($sql);

  // Έλεγχος αν υπάρχει ήδη user για να ξεχωρίσουμε inserted/updated
  $checkByEmail = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");

  foreach ($items as $idx => $it) {
    try {
      if (!is_array($it)) throw new Exception('item_not_object');

      $role = strtolower(trim((string)($it['role'] ?? '')));
      if (!in_array($role, ['student','teacher'], true)) throw new Exception('invalid_role');

      $name  = trim((string)($it['name'] ?? ''));
      $email = trim((string)($it['email'] ?? ''));
      if ($name === '' || $email === '') throw new Exception('name_or_email_missing');
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('invalid_email');

      $student_number = null;
      if ($role === 'student') {
        $student_number = trim((string)($it['student_number'] ?? ''));
        if ($student_number === '') throw new Exception('student_number_required');
      }

      $address       = isset($it['address']) ? trim((string)$it['address']) : null;
      $phone_mobile  = isset($it['phone_mobile']) ? trim((string)$it['phone_mobile']) : null;
      $phone_land    = isset($it['phone_landline']) ? trim((string)$it['phone_landline']) : null;

      // password: αν δώσεις 'password' στο JSON το κάνουμε hash, αλλιώς φτιάχνουμε προσωρινό
      $plain = isset($it['password']) && $it['password'] !== '' ? (string)$it['password'] : null;
      if ($plain === null) {
        // προσωρινός 12-χαρακτήρων
        $plain = substr(bin2hex(random_bytes(8)), 0, 12);
        $new_credentials[] = ['email'=>$email, 'password'=>$plain];
      }
      $hash = password_hash($plain, PASSWORD_DEFAULT);

      // Υπάρχει ήδη χρήστης με αυτό το email;
      $checkByEmail->execute([$email]);
      $exists = (bool)$checkByEmail->fetchColumn();

      // Εκτέλεση upsert
      $stmt->execute([
        ':role'           => $role,
        ':student_number' => $student_number ?: null,
        ':name'           => $name,
        ':email'          => $email,
        ':password_hash'  => $hash,
        ':address'        => $address,
        ':phone_mobile'   => $phone_mobile,
        ':phone_landline' => $phone_land,
      ]);

      if ($exists) $updated++; else $inserted++;
    } catch (Throwable $e) {
      $failed++;
      $errors[] = ['index'=>$idx, 'item'=>$it, 'error'=>$e->getMessage()];
      continue;
    }
  }

  echo json_encode([
    'ok' => true,
    'result' => [
      'inserted' => $inserted,
      'updated'  => $updated,
      'failed'   => $failed
    ],
    'errors' => $errors,
    // στέλνουμε μόνο για τους ΝΕΟΥΣ χρήστες ό,τι προσωρινούς κωδικούς δημιουργήσαμε
    'new_credentials' => $new_credentials
  ], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'internal_error','message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}
