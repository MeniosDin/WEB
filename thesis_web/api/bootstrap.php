<?php
// Headers, CORS, JSON-only API, session start
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');


$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Vary: Origin');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }


require_once __DIR__ . '/db.php';
require_once __DIR__ . '/utils/responses.php';
require_once __DIR__ . '/utils/auth_guard.php';
require_once __DIR__ . '/utils/validators.php';
require_once __DIR__ . '/utils/files.php';


session_set_cookie_params([
'httponly' => true,
'samesite' => 'Lax',
'secure' => isset($_SERVER['HTTPS'])
]);
session_start();
?>