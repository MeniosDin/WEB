<?php
declare(strict_types=1);

// === SAFE JSON OUTPUT ===
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Αν κάποιο include έβγαλε BOM/whitespace, κρατάμε buffer για καθάρισμα
if (ob_get_level() === 0) { ob_start(); }

try {
  require_once __DIR__ . '/../bootstrap.php';
  require_once __DIR__ . '/../db.php';

  // μόνο γραμματεία
  $me  = require_role('secretariat');
  $pdo = db(); // PDO με utf8mb4 & ERRMODE_EXCEPTION

  // προαιρετικά φίλτρα
  $q     = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
  $page  = max(1, (int)($_GET['page']  ?? 1));
  $limit = (int)($_GET['limit'] ?? 25);
  if ($limit < 1)   $limit = 1;
  if ($limit > 100) $limit = 100;
  $offset = ($page - 1) * $limit;

  // WHERE
  $where   = ["t.status IN ('active','under_review')"];
  $params  = [];
  if ($q !== '') {
    $where[] = "(tp.title LIKE ? OR tp.summary LIKE ? OR stu.name LIKE ? OR sup.name LIKE ?)";
    $like = '%'.$q.'%';
    array_push($params, $like, $like, $like, $like);
  }
  $whereSql = 'WHERE ' . implode(' AND ', $where);

  // COUNT
  $sqlCount = <<<SQL
    SELECT COUNT(*) AS cnt
      FROM theses t
      JOIN topics tp  ON tp.id = t.topic_id
      JOIN users  stu ON stu.id = t.student_id
      JOIN users  sup ON sup.id = t.supervisor_id
      $whereSql
  SQL;
  $st = $pdo->prepare($sqlCount);
  $st->execute($params);
  $total = (int)$st->fetchColumn();

  // DATA
  $sql = <<<SQL
    SELECT
      t.id,
      t.status,
      t.created_at,
      t.assigned_at,
      CASE WHEN t.assigned_at IS NULL THEN NULL
           ELSE TIMESTAMPDIFF(DAY, t.assigned_at, NOW()) END AS days_since_assigned,
      tp.id          AS topic_id,
      tp.title       AS topic_title,
      tp.summary     AS topic_summary,
      tp.pdf_path    AS topic_pdf_path,
      stu.name       AS student_name,
      stu.student_number AS student_number,
      sup.name       AS supervisor_name
    FROM theses t
    JOIN topics tp  ON tp.id = t.topic_id
    JOIN users  stu ON stu.id = t.student_id
    JOIN users  sup ON sup.id = t.supervisor_id
    $whereSql
    ORDER BY t.created_at DESC
    LIMIT ? OFFSET ?
  SQL;

  $paramsData = $params;
  $paramsData[] = $limit;
  $paramsData[] = $offset;

  $st = $pdo->prepare($sql);
  $st->execute($paramsData);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // === JSON RESPONSE ===
  header('Content-Type: application/json; charset=utf-8');
  if (ob_get_length()) { ob_clean(); }

  echo json_encode([
    'ok'   => true,
    'data' => $rows,
    'meta' => [
      'page'        => $page,
      'limit'       => $limit,
      'total'       => $total,
      'total_pages' => $limit ? (int)ceil($total / $limit) : 1,
      'q'           => $q,
    ],
  ], JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  header('Content-Type: application/json; charset=utf-8');
  if (ob_get_length()) { ob_clean(); }
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'internal_error',
    'message' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
