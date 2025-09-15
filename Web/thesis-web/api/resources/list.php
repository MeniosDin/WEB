<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

try {
  @require_once __DIR__ . '/../utils/bootstrap.php';   // ορίζει $pdo
  @require_once __DIR__ . '/../utils/auth_guard.php';  // ensure_logged_in()

  if (!function_exists('ensure_logged_in')) { function ensure_logged_in(){} }
  ensure_logged_in();

  if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo json_encode(['ok'=>false,'error'=>'DB not initialized']); exit;
  }

  $thesis_id = isset($_GET['thesis_id']) ? trim((string)$_GET['thesis_id']) : '';
  $kind      = isset($_GET['kind'])      ? trim((string)$_GET['kind'])      : ''; // optional
  if ($thesis_id === '') { echo json_encode(['ok'=>false,'error'=>'thesis_id required']); exit; }

  // --- helpers για schema introspection ---
  $tableExists = function(string $t) use ($pdo): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
    $st->execute([':t'=>$t]); return (bool)$st->fetchColumn();
  };
  $hasCol = function(string $t, string $c) use ($pdo): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
    $st->execute([':t'=>$t, ':c'=>$c]); return (bool)$st->fetchColumn();
  };

  // --- ποιος πίνακας;
  $table = $tableExists('resources') ? 'resources'
         : ($tableExists('thesis_resources') ? 'thesis_resources' : null);
  if (!$table) {
    echo json_encode(['ok'=>false,'error'=>'resources table not found']); exit;
  }

  // --- map ονομάτων στηλών (με προτεραιότητες)
  $pick = function(string $t, array $cands) use ($hasCol) {
    foreach ($cands as $c) if ($hasCol($t,$c)) return $c;
    return null;
  };

  $col_id        = $pick($table, ['id','resource_id','res_id']);
  $col_thesis_id = $pick($table, ['thesis_id','thesis','thesisId','thesis_uid']);
  $col_kind      = $pick($table, ['kind','type','category']);
  $col_path      = $pick($table, ['url_or_path','path','url','file_path','link']);
  $col_name      = $pick($table, ['original_name','filename','title','name','label']);
  $col_mime      = $pick($table, ['mime','mimetype','content_type','mime_type']);
  $col_size      = $pick($table, ['size','file_size','bytes','length']);
  $col_created   = $pick($table, ['created_at','uploaded_at','createdAt','created','ts']);

  if (!$col_id)        { echo json_encode(['ok'=>false,'error'=>"missing id column in $table"]); exit; }
  if (!$col_thesis_id) { echo json_encode(['ok'=>false,'error'=>"missing thesis_id column in $table"]); exit; }
  if (!$col_path && !$col_name) {
    echo json_encode(['ok'=>false,'error'=>"missing path/name columns in $table"]); exit;
  }

  // --- SELECT φτιάχνεται μόνο με ό,τι υπάρχει
  $cols = [];
  $cols[] = "$col_id AS _id";
  $cols[] = "$col_thesis_id AS _thesis";
  if ($col_kind)    $cols[] = "$col_kind AS _kind";
  if ($col_path)    $cols[] = "$col_path AS _path";
  if ($col_name)    $cols[] = "$col_name AS _name";
  if ($col_mime)    $cols[] = "$col_mime AS _mime";
  if ($col_size)    $cols[] = "$col_size AS _size";
  if ($col_created) $cols[] = "$col_created AS _created";

  $where  = "WHERE $col_thesis_id = :tid";
  $params = [':tid'=>$thesis_id];
  if ($kind !== '' && $col_kind) { $where .= " AND $col_kind = :kind"; $params[':kind'] = $kind; }

  $orderCol = $col_created ?: $col_id;

  $sql = "SELECT ".implode(',', $cols)." FROM $table $where ORDER BY $orderCol DESC";
  $st  = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // --- normalize προς το Frontend
  $BASE_WEB = '/thesis-web';
  $items = array_map(function(array $r) use ($BASE_WEB) {
    $url = (string)($r['_path'] ?? '');
    if ($url && strncmp($url, '/uploads/', 9) === 0) $url = $BASE_WEB . $url;
    return [
      'id'         => $r['_id']      ?? null,
      'thesis_id'  => $r['_thesis']  ?? null,
      'kind'       => $r['_kind']    ?? null,
      'url'        => $url ?: null,
      'filename'   => $r['_name']    ?? null,
      'title'      => $r['_name']    ?? null,
      'mime'       => $r['_mime']    ?? null,
      'size'       => isset($r['_size']) ? (int)$r['_size'] : null,
      'created_at' => $r['_created'] ?? null,
    ];
  }, $rows);

  echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(200); // γύρνα JSON με σφάλμα αντί για 500, ώστε να φαίνεται στο UI
  echo json_encode(['ok'=>false,'error'=>'server error','extra'=>['msg'=>$e->getMessage()]], JSON_UNESCAPED_UNICODE);
}
