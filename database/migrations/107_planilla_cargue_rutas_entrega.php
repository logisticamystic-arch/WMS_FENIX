<?php
/**
 * Migration 107 — Planilla de Cargue: rutas, pedidos y estado Entregado.
 * - Agrega ruta_id FK a despachos
 * - Crea tabla pivot despacho_ordenes
 * - Agrega estado_despacho a orden_pickings
 * - Extiende constraint de despachos.estado con 'Entregado'
 */
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();

        // 1. Agregar ruta_id a despachos (sin FK — tabla rutas no tiene PK constraint)
        $schema->table('despachos', function (Blueprint $t) use ($schema) {
            if (!$schema->hasColumn('despachos', 'ruta_id')) {
                $t->unsignedBigInteger('ruta_id')->nullable()->after('ruta');
            }
            if (!$schema->hasColumn('despachos', 'observaciones')) {
                $t->text('observaciones')->nullable();
            }
        });
        echo "  despachos.ruta_id OK\n";

        // 2. Ampliar constraint estado de despachos para incluir 'Entregado'
        Capsule::statement('ALTER TABLE despachos DROP CONSTRAINT IF EXISTS despachos_estado_check');
        Capsule::statement("ALTER TABLE despachos ADD CONSTRAINT despachos_estado_check CHECK (estado IN ('Preparando','Certificado','Despachado','Entregado'))");
        echo "  despachos.estado constraint OK\n";

        // 3. Tabla pivot despacho_ordenes (sin FKs — tablas sin PK constraint)
        if (!$schema->hasTable('despacho_ordenes')) {
            $schema->create('despacho_ordenes', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('despacho_id');
                $t->unsignedBigInteger('orden_picking_id');
                $t->timestamps();
                $t->unique(['despacho_id', 'orden_picking_id']);
            });
        }
        echo "  despacho_ordenes OK\n";

        // 4. Agregar estado_despacho y despacho_id a orden_pickings
        $schema->table('orden_pickings', function (Blueprint $t) use ($schema) {
            if (!$schema->hasColumn('orden_pickings', 'estado_despacho')) {
                $t->string('estado_despacho', 20)->nullable()->after('estado_certificacion');
            }
            if (!$schema->hasColumn('orden_pickings', 'despacho_id')) {
                $t->unsignedBigInteger('despacho_id')->nullable()->after('estado_despacho');
            }
        });
        echo "  orden_pickings.estado_despacho OK\n";
    },
    'down' => function () {
        Capsule::schema()->table('orden_pickings', function (Blueprint $t) {
            $t->dropColumn(['estado_despacho', 'despacho_id']);
        });
        Capsule::schema()->dropIfExists('despacho_ordenes');
        Capsule::schema()->table('despachos', function (Blueprint $t) {
            $t->dropColumn(['ruta_id', 'observaciones']);
        });
        Capsule::statement('ALTER TABLE despachos DROP CONSTRAINT IF EXISTS despachos_estado_check');
        Capsule::statement("ALTER TABLE despachos ADD CONSTRAINT despachos_estado_check CHECK (estado IN ('Preparando','Certificado','Despachado'))");
    },
];
