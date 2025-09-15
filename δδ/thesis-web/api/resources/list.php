<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=UTF-8');

try {
  @require_once __DIR__ . '/../utils/bootstrap.php';   // αναμένεται να ορίζει $pdo
  @require_once __DIR__ . '/../utils/auth_guard.php';  // ensure_logged_in()

  if (!function_exists('ensure_logged_in')) { function ensure_logged_in(){} }
  if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('DB not initialized ($pdo missing)');
  }

  // ---- params
  $thesis_id = isset($_GET['thesis_id']) ? trim((string)$_GET['thesis_id']) : '';
  $kind      = isset($_GET['kind'])      ? trim((string)$_GET['kind'])      : ''; // optional
  if ($thesis_id === '') {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'thesis_id required']); exit;
  }
  ensure_logged_in();

  // ---- helpers
  $hasCol = function(string $table, string $col) use ($pdo): bool {
    $q = "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1";
    $st = $pdo->prepare($q);
    $st->execute([':t'=>$table, ':c'=>$col]);
    return (bool)$st->fetchColumn();
  };
  $table = (function() use ($pdo){
    // resources ή thesis_resources;
    foreach (['resources','thesis_resources'] as $t) {
      $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
      $st->execute([':t'=>$t]);
      if ($st->fetchColumn()) return $t;
    }
    return 'resources'; // default
  })();

  // map πεδίων ανάλογα με το schema
  $col_kind   = $hasCol($table,'kind')         ? 'kind'      : ($hasCol($table,'type') ? 'type' : null);
  $col_path   = $hasCol($table,'url_or_path')  ? 'url_or_path' : ($hasCol($table,'path') ? 'path' : ($hasCol($table,'url') ? 'url' : null));
  $col_name   = $hasCol($table,'original_name')? 'original_name' : ($hasCol($table,'filename') ? 'filename' : ($hasCol($table,'title') ? 'title' : ($hasCol($table,'name') ? 'name' : null)));
  $col_mime   = $hasCol($table,'mime')         ? 'mime'      : ($hasCol($table,'mimetype') ? 'mimetype' : ($hasCol($table,'content_type') ? 'content_type' : null));
  $col_size   = $hasCol($table,'size')         ? 'size'      : ($hasCol($table,'file_size') ? 'file_size' : null);
  $col_created= $hasCol($table,'created_at')   ? 'created_at': ($hasCol($table,'uploaded_at') ? 'uploaded_at' : ($hasCol($table,'createdAt') ? 'createdAt' : null));

  // φτιάχνουμε SELECT μόνο με ό,τι υπάρχει
  $cols = ['id','thesis_id'];
  if ($col_kind)    $cols[] = $col_kind.' AS _kind';
  if ($col_path)    $cols[] = $col_path.' AS _path';
  if ($col_name)    $cols[] = $col_name.' AS _name';
  if ($col_mime)    $cols[] = $col_mime.' AS _mime';
  if ($col_size)    $cols[] = $col_size.' AS _size';
  if ($col_created) $cols[] = $col_created.' AS _created';

  $where  = "WHERE thesis_id = ?";
  $params = [$thesis_id];
  if ($kind !== '' && $col_kind) { $where .= " AND $col_kind = ?"; $params[] = $kind; }

  $sql = "SELECT ".implode(',', $cols)." FROM $table $where ORDER BY ".($col_created ?: 'id')." DESC";
  $st  = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $BASE_WEB = '/thesis-web'; // αν το app σερβίρεται αλλού, άλλαξέ το

  $norm = array_map(function(array $r) use ($BASE_WEB){
    $url = $r['_path'] ?? '';
    if ($url && strncmp($url, '/uploads/', 9) === 0) {
      $url = $BASE_WEB . $url;
    }
    return [
      'id'         => $r['id'] ?? null,
      'thesis_id'  => $r['thesis_id'] ?? null,
      'kind'       => $r['_kind'] ?? null,
      'url'        => $url ?: null,
      'filename'   => $r['_name'] ?? null,   // για draft
      'title'      => $r['_name'] ?? null,   // για link (fallback)
      'mime'       => $r['_mime'] ?? null,
      'size'       => isset($r['_size']) ? (int)$r['_size'] : null,
      'created_at' => $r['_created'] ?? null,
    ];
  }, $rows);

  echo json_encode(['ok'=>true,'items'=>$norm], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server error','extra'=>['msg'=>$e->getMessage()]], JSON_UNESCAPED_UNICODE);
}
