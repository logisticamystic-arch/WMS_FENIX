<?php
require 'bootstrap.php';
use App\Models\Personal;
use Illuminate\Database\Capsule\Manager as Capsule;

try {
    $count = Personal::count();
    echo "Total de usuarios: " . $count . "\n";
    
    $users = Personal::select('id', 'documento', 'nombre', 'rol', 'activo', 'empresa_id')->get();
    foreach ($users as $u) {
        echo "ID: {$u->id} | Doc: {$u->documento} | Nombre: {$u->nombre} | Rol: {$u->rol} | Activo: " . ($u->activo ? 'SI' : 'NO') . " | Empresa: {$u->empresa_id}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
