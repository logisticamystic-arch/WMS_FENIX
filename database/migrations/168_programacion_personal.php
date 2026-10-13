<?php
/**
 * Migración 168 — Programación de personal (horarios mensuales).
 *
 * Pedido por Camilo 2026-10-12: módulo de horarios como el Excel semanal del
 * CDP (ver imagen fuente). 3 tablas:
 *   - prog_filas         (ítems: TURNO-1..n, RUTA-1..n, APOYO) — editables
 *   - prog_tareas        (ROL: REC, DVO, INV, ASE, LAV, ORG…) — editables
 *   - prog_asignaciones  (celda por fila × día × slot × mes)
 *
 * Festivos Colombia se calculan en runtime (no se guardan): usan easter_days()
 * + Ley Emiliani (traslado al lunes). Ver ProgramacionController::festivos().
 */

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        $schema = Capsule::schema();

        if (!$schema->hasTable('prog_filas')) {
            $schema->create('prog_filas', function ($t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id');
                $t->string('codigo', 30);               // TURNO-1, RUTA-1, APOYO
                $t->string('nombre', 100)->nullable();  // Etiqueta larga opcional
                $t->time('hora_inicio')->nullable();
                $t->time('hora_fin')->nullable();
                $t->smallInteger('slots')->default(1);  // cuántas personas caben por día
                $t->integer('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->timestamps();
                $t->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $t->unique(['empresa_id', 'codigo']);
            });
        }

        if (!$schema->hasTable('prog_tareas')) {
            $schema->create('prog_tareas', function ($t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id');
                $t->string('codigo', 20);               // REC, DVO, INV…
                $t->string('nombre', 100);              // Recepción, Devoluciones…
                $t->string('color', 10)->default('#f8d7da'); // hex para resaltar en la grilla
                $t->integer('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->timestamps();
                $t->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $t->unique(['empresa_id', 'codigo']);
            });
        }

        if (!$schema->hasTable('prog_asignaciones')) {
            $schema->create('prog_asignaciones', function ($t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('empresa_id');
                $t->smallInteger('anio');
                $t->smallInteger('mes');
                $t->smallInteger('dia');
                $t->unsignedBigInteger('fila_id');
                $t->smallInteger('slot')->default(0);
                $t->unsignedBigInteger('personal_id')->nullable();
                $t->unsignedBigInteger('tarea_id')->nullable();
                $t->boolean('descansa')->default(false);
                $t->text('nota')->nullable();
                $t->timestamps();
                $t->foreign('empresa_id')->references('id')->on('empresas')->onDelete('cascade');
                $t->foreign('fila_id')->references('id')->on('prog_filas')->onDelete('cascade');
                $t->foreign('personal_id')->references('id')->on('personal')->onDelete('set null');
                $t->foreign('tarea_id')->references('id')->on('prog_tareas')->onDelete('set null');
                $t->unique(['empresa_id', 'anio', 'mes', 'dia', 'fila_id', 'slot'], 'ux_prog_asig_celda');
                $t->index(['empresa_id', 'anio', 'mes'], 'ix_prog_asig_mes');
            });
        }

        // ── Seed por empresa (filas y tareas del Excel original) ────────────
        $pdo = Capsule::connection()->getPdo();
        $empresas = $pdo->query("SELECT id FROM empresas")->fetchAll(\PDO::FETCH_COLUMN);
        $filasSeed = [
            ['RUTA-1',  'Ruta 1',   null,       null,       1,  10],
            ['RUTA-2',  'Ruta 2',   null,       null,       1,  20],
            ['RUTA-3',  'Ruta 3',   null,       null,       1,  30],
            ['TURNO-1', 'Turno 1',  '07:00:00', '14:30:00', 3,  40],
            ['TURNO-2', 'Turno 2',  '12:00:00', '19:30:00', 5,  50],
            ['TURNO-3', 'Turno 3',  '05:00:00', '12:30:00', 5,  60],
            ['TURNO-4', 'Turno 4',  '10:00:00', '17:30:00', 2,  70],
            ['APOYO',   'Apoyo',    null,       null,       3,  80],
        ];
        $tareasSeed = [
            ['REC', 'Recepción',       '#fde2e4', 10],
            ['DVO', 'Devoluciones',    '#fde2e4', 20],
            ['INV', 'Inventario',      '#fde2e4', 30],
            ['ASE', 'Aseo',            '#fde2e4', 40],
            ['LAV', 'Lavada canasta',  '#fde2e4', 50],
            ['ORG', 'Organizar bodega','#fde2e4', 60],
            ['DES', 'Descansa',        '#e2e8f0', 99],
        ];
        foreach ($empresas as $eid) {
            foreach ($filasSeed as [$cod, $nom, $hi, $hf, $slots, $ord]) {
                $existe = $pdo->query("SELECT 1 FROM prog_filas WHERE empresa_id={$eid} AND codigo=" . $pdo->quote($cod))->fetchColumn();
                if (!$existe) {
                    $pdo->prepare("INSERT INTO prog_filas (empresa_id,codigo,nombre,hora_inicio,hora_fin,slots,orden,activo,created_at,updated_at) VALUES (?,?,?,?,?,?,?,TRUE,now(),now())")
                        ->execute([$eid, $cod, $nom, $hi, $hf, $slots, $ord]);
                }
            }
            foreach ($tareasSeed as [$cod, $nom, $col, $ord]) {
                $existe = $pdo->query("SELECT 1 FROM prog_tareas WHERE empresa_id={$eid} AND codigo=" . $pdo->quote($cod))->fetchColumn();
                if (!$existe) {
                    $pdo->prepare("INSERT INTO prog_tareas (empresa_id,codigo,nombre,color,orden,activo,created_at,updated_at) VALUES (?,?,?,?,?,TRUE,now(),now())")
                        ->execute([$eid, $cod, $nom, $col, $ord]);
                }
            }
        }

        // ── Permiso de menú (sigue el patrón de la migración 159) ───────────
        $permId = $pdo->query("SELECT id FROM permisos WHERE modulo='programacion' AND accion='ver'")->fetchColumn();
        if (!$permId) {
            $stmt = $pdo->prepare("INSERT INTO permisos (modulo, accion, descripcion) VALUES ('programacion','ver','Programación de personal: Horarios') RETURNING id");
            $stmt->execute();
            $permId = $stmt->fetchColumn();
        }
        $roles = ['Admin', 'Supervisor'];
        foreach ($empresas as $eid) {
            foreach ($roles as $rol) {
                $existe = $pdo->query("SELECT 1 FROM rol_permisos WHERE empresa_id={$eid} AND rol=" . $pdo->quote($rol) . " AND permiso_id={$permId}")->fetchColumn();
                if (!$existe) {
                    $pdo->prepare("INSERT INTO rol_permisos (empresa_id,rol,permiso_id,concedido,created_at,updated_at) VALUES (?,?,?,1,now(),now())")
                        ->execute([$eid, $rol, $permId]);
                }
            }
        }
        echo "  [OK] prog_filas/prog_tareas/prog_asignaciones + semilla + permiso programacion.ver\n";
    },
    'down' => function () {
        $pdo = Capsule::connection()->getPdo();
        $pdo->exec("DELETE FROM rol_permisos WHERE permiso_id IN (SELECT id FROM permisos WHERE modulo='programacion')");
        $pdo->exec("DELETE FROM personal_permisos WHERE modulo='programacion'");
        $pdo->exec("DELETE FROM permisos WHERE modulo='programacion'");
        Capsule::schema()->dropIfExists('prog_asignaciones');
        Capsule::schema()->dropIfExists('prog_tareas');
        Capsule::schema()->dropIfExists('prog_filas');
    },
];
