<?php
// database/migrations/072_devoluciones_upgrade.php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();
        $isPg   = Capsule::connection()->getDriverName() === 'pgsql';

        // ── 1. Extender devoluciones.tipo ────────────────────────────────────
        if ($isPg) {
            Capsule::statement("ALTER TABLE devoluciones ALTER COLUMN tipo TYPE VARCHAR(30)");
            Capsule::statement("ALTER TABLE devoluciones DROP CONSTRAINT IF EXISTS chk_dev_tipo");
            Capsule::statement("ALTER TABLE devoluciones ADD CONSTRAINT chk_dev_tipo
                CHECK (tipo IN ('AProveedorAveria','AProveedorVencido','ReingresoBuenEstado','cliente','proveedor','interna'))");
        } else {
            Capsule::statement("
                ALTER TABLE devoluciones
                MODIFY COLUMN tipo ENUM('AProveedorAveria','AProveedorVencido','ReingresoBuenEstado','cliente','proveedor','interna')
                NOT NULL
            ");
        }

        // ── 2. Extender devoluciones.estado ──────────────────────────────────
        if ($isPg) {
            Capsule::statement("ALTER TABLE devoluciones ALTER COLUMN estado TYPE VARCHAR(30)");
            Capsule::statement("ALTER TABLE devoluciones DROP CONSTRAINT IF EXISTS chk_dev_estado");
            Capsule::statement("ALTER TABLE devoluciones ADD CONSTRAINT chk_dev_estado
                CHECK (estado IN ('Borrador','Aprobada','Procesada','PendienteAprobacion','Rechazada','Anulada'))");
            Capsule::statement("ALTER TABLE devoluciones ALTER COLUMN estado SET DEFAULT 'PendienteAprobacion'");
        } else {
            Capsule::statement("
                ALTER TABLE devoluciones
                MODIFY COLUMN estado ENUM('Borrador','Aprobada','Procesada','PendienteAprobacion','Rechazada','Anulada')
                NOT NULL DEFAULT 'PendienteAprobacion'
            ");
        }

        // ── 3. Nuevas columnas en devoluciones ───────────────────────────────
        $schema->table('devoluciones', function (Blueprint $t) use ($schema) {
            if (!$schema->hasColumn('devoluciones', 'referencia_externa')) {
                $t->string('referencia_externa', 100)->nullable()->after('proveedor');
            }
            if (!$schema->hasColumn('devoluciones', 'solicitado_por')) {
                $t->unsignedBigInteger('solicitado_por')->nullable()->after('auxiliar_id');
            }
            if (!$schema->hasColumn('devoluciones', 'aprobado_por')) {
                $t->unsignedBigInteger('aprobado_por')->nullable()->after('odc_id');
            }
            if (!$schema->hasColumn('devoluciones', 'procesado_por')) {
                $t->unsignedBigInteger('procesado_por')->nullable()->after('aprobado_por');
            }
            if (!$schema->hasColumn('devoluciones', 'aprobado_at')) {
                $t->timestamp('aprobado_at')->nullable()->after('procesado_por');
            }
            if (!$schema->hasColumn('devoluciones', 'procesado_at')) {
                $t->timestamp('procesado_at')->nullable()->after('aprobado_at');
            }
        });

        // ── 4. Extender devolucion_detalles.destino ──────────────────────────
        if ($isPg) {
            Capsule::statement("ALTER TABLE devolucion_detalles ALTER COLUMN destino TYPE VARCHAR(30)");
            Capsule::statement("ALTER TABLE devolucion_detalles DROP CONSTRAINT IF EXISTS chk_devdet_destino");
            Capsule::statement("ALTER TABLE devolucion_detalles ADD CONSTRAINT chk_devdet_destino
                CHECK (destino IN ('InventarioObsoleto','Reingreso','DevolucionProveedor','restock','descarte','proveedor'))");
        } else {
            Capsule::statement("
                ALTER TABLE devolucion_detalles
                MODIFY COLUMN destino ENUM('InventarioObsoleto','Reingreso','DevolucionProveedor','restock','descarte','proveedor')
                NULL
            ");
        }

        // ── 5. Nuevas columnas en devolucion_detalles ────────────────────────
        $schema->table('devolucion_detalles', function (Blueprint $t) use ($schema, $isPg) {
            if (!$schema->hasColumn('devolucion_detalles', 'condicion')) {
                if ($isPg) {
                    $t->string('condicion', 20)->nullable()->after('motivo');
                } else {
                    $t->enum('condicion', ['bueno','dañado','vencido','otro'])->nullable()->after('motivo');
                }
            }
        });

        // ── 6. Índice en devoluciones (empresa_id, sucursal_id, estado) ──────
        if (!$isPg) {
            try {
                Capsule::statement('CREATE INDEX idx_dev_scope ON devoluciones (empresa_id, sucursal_id, estado)');
            } catch (\Exception $e) {} // ignore if exists
        } else {
            try {
                Capsule::statement('CREATE INDEX IF NOT EXISTS idx_dev_scope ON devoluciones (empresa_id, sucursal_id, estado)');
            } catch (\Exception $e) {}
        }
    },

    'down' => function () {
        // Enum rollback is intentionally omitted — data integrity risk.
        // Drop added columns only.
        $schema = Capsule::schema();
        $cols = ['referencia_externa', 'solicitado_por', 'aprobado_por', 'procesado_por', 'aprobado_at', 'procesado_at'];
        $schema->table('devoluciones', function (Blueprint $t) use ($schema, $cols) {
            foreach ($cols as $c) {
                if ($schema->hasColumn('devoluciones', $c)) $t->dropColumn($c);
            }
        });
        if ($schema->hasColumn('devolucion_detalles', 'condicion')) {
            $schema->table('devolucion_detalles', fn(Blueprint $t) => $t->dropColumn('condicion'));
        }
    },
];
