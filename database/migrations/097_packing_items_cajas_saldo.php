<?php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

Capsule::schema()->table('packing_items', function (Blueprint $t) {
    $t->decimal('cantidad_cajas', 10, 3)->default(0)->after('cantidad');
    $t->decimal('saldo',          10, 3)->default(0)->after('cantidad_cajas');
});
