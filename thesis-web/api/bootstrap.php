<?php
declare(strict_types=1);
session_start([
'cookie_httponly'=>true,
'cookie_secure'=>isset($_SERVER['HTTPS']),
'cookie_samesite'=>'Lax',
]);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: '.($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTPS']? 'https://'.$_SERVER['HTTP_HOST'] : 'http://'.$_SERVER['HTTP_HOST'])));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD']==='OPTIONS') { http_response_code(204); exit; }
require_once __DIR__.'/utils/responses.php';
require_once __DIR__.'/db.php';
require_once __DIR__.'/utils/auth_guard.php';
require_once __DIR__.'/utils/validators.php';
require_once __DIR__.'/utils/files.php';