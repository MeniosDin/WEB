<?php
declare(strict_types=1);

/* SESSION με σωστό cookie_path ώστε να περνάει παντού */
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_set_cookie_params([
    'path'     => '/',                                  // ΠΟΛΥ ΣΗΜΑΝΤΙΚΟ
    'httponly' => true,
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
  ]);
  session_start();
}

/* JSON */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

/* Helpers */
function ok(array $p = [], int $code = 200){ http_response_code($code); echo json_encode(['ok'=>true] + $p, JSON_UNESCAPED_UNICODE); exit; }
function bad(string $m='Σφάλμα', int $code = 400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE); exit; }
function body_json(): array { $raw = file_get_contents('php://input'); $j = json_decode($raw, true); return is_array($j) ? $j : []; }

/* PDO */
$pdo = db();

/* ===== AUTH GUARDS (διαβάζουν $_SESSION['uid'] / $_SESSION['role']) ===== */
function current_user(): ?array {
  if (empty($_SESSION['uid']) || empty($_SESSION['role'])) return null;
  return ['id' => $_SESSION['uid'], 'role' => $_SESSION['role']];
}
function require_login(): array {
  $u = current_user();
  if (!$u) bad('Unauthorized', 401);
  return $u;
}
function require_role(string ...$roles): array {
  $u = require_login();
  if (!in_array($u['role'], $roles, true)) bad('Forbidden', 403);
  return $u;
}
