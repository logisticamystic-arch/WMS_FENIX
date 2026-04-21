<?php
require 'src/bootstrap.php';
use App\Models\Personal;
use App\Models\SesionLinea;

$user = Personal::where('documento', '123456')->first(); // A known admin if possible
echo "Roles in DB:\n";
$roles = Personal::select('rol')->distinct()->get();
foreach($roles as $r) {
    echo "- " . $r->rol . "\n";
}

echo "\nColumns in sesion_lineas:\n";
$res = \Illuminate\Database\Capsule\Manager::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'sesion_lineas'");
foreach($res as $c) {
    echo "- " . $c->column_name . "\n";
}
