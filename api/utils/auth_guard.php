<?php
function ensure_logged_in() {
  session_start();
  if (empty($_SESSION['user'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'not_authenticated']);
    exit;
  }
  return $_SESSION['user'];
}
