<?php
require 'bootstrap.php';
$res = \Illuminate\Database\Capsule\Manager::select('SHOW CREATE TABLE producto_eans');
foreach ($res as $row) {
    foreach ($row as $k => $v) {
        echo "$k: $v\n";
    }
}
