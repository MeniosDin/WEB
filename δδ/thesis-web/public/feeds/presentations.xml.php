<?php
require_once __DIR__.'/../../api/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');
$items = db()->query("SELECT * FROM vw_public_presentations WHERE published_at IS NOT NULL ORDER BY when_dt DESC LIMIT 100")->fetchAll();
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<presentations>";
foreach($items as $it){
echo "<item><title>".htmlspecialchars($it['topic_title'])."</title>"
. "<student>".htmlspecialchars($it['student_name'])."</student>"
. "<when>".htmlspecialchars($it['when_dt'])."</when>"
. "<mode>".htmlspecialchars($it['mode'])."</mode>"
. "<room>".htmlspecialchars($it['room_or_link'])."</room></item>";
}
echo "</presentations>";