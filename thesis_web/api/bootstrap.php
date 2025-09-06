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
