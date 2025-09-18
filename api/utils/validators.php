<?php
function must($arr, $keys){ foreach($keys as $k) if(!isset($arr[$k]) || $arr[$k]==='') bad("Λείπει: $k"); }
function assert_enum($v,$allowed,$name){ if(!in_array($v,$allowed,true)) bad("Μη αποδεκτή τιμή: $name"); }
function parse_datetime($s,$name){ $dt = date_create($s); if(!$dt) bad("Λάθος ημ/νία: $name"); return $dt->format('Y-m-d H:i:s'); }