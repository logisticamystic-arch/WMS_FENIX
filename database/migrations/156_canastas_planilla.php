<?php
/**
 * Migration 156 — Canastas por Ambiente: total de canastas capturado por
 * sucursal y ambiente, identificado por "planilla" (planilla_numero real, o
 * etiqueta sintética DOC-<id> para pedidos manuales sin CSV — MISMO criterio
 * que ya usa _agruparPedidosCarguePorPlanilla()/certRemisionMultiple).
 *
 * A pedido explícito de Camilo (2026-09-25): capturado apenas termina la
 * certificación (escritorio: pestaña "Pedidos Pendientes" de Despacho; móvil:
 * nuevo módulo 'mobile.cn'), normalmente ANTES de que exista una Planilla de
 * Cargue/Despacho — por eso NO se referencia por despacho_id. Cuando esa
 * planilla se convierte en un despacho, tanto Remisión como Planilla de
 * Cargue resuelven el mismo identificador desde las órdenes ya asociadas.
 *
 * Sin FK dura a ambientes — mismo criterio que ya usa despachos.ruta_id:
 * evita romper el registro si el ambiente se borra/desactiva.
 *
 * El permiso móvil sigue el mismo patrón que la migración 138
 * (permisos_modulos_y_mobile): se concede por defecto a los mismos roles
 * amplios para no cambiar el comportamiento de nadie — el admin restringe
 * después puntualmente desde "Permisos por Usuario" si lo necesita. En
 * escritorio no se agrega ningún permiso nuevo: el botón vive dentro de la
 * pantalla Despacho ya existente, que hereda su gate actual.
 */
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();
        if (!$schema->hasTable('canastas_planilla')) {
            $schema->create('canastas_planilla', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('empresa_id');
                $t->string('planilla');
                $t->string('sucursal_entrega');
                $t->unsignedBigInteger('ambiente_id');
                $t->integer('cantidad')->default(0);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['empresa_id', 'planilla', 'sucursal_entrega', 'ambiente_id'], 'canastas_planilla_unica');
            });
            echo "  [OK] tabla canastas_planilla creada.\n";
        } elseif ($schema->hasColumn('canastas_planilla', 'despacho_id') && !$schema->hasColumn('canastas_planilla', 'planilla')) {
            // Migración en caliente desde el diseño anterior (keyed por despacho_id,
            // descartado el mismo día porque las canastas se capturan ANTES de que
            // exista un despacho) — la tabla estaba vacía en producción, no hay datos
            // que migrar.
            $schema->table('canastas_planilla', function (Blueprint $t) use ($schema) {
                $t->string('planilla')->after('empresa_id');
            });
            $schema->table('canastas_planilla', function (Blueprint $t) {
                $t->dropColumn('despacho_id');
            });
            echo "  [OK] canastas_planilla migrada de despacho_id a planilla.\n";
        }

        $pdo = Capsule::connection()->getPdo();
        $empresaId = (int)($pdo->query("SELECT id FROM empresas ORDER BY id LIMIT 1")->fetchColumn() ?: 1);
        $roles = ['Admin', 'Supervisor', 'Auxiliar', 'Montacarguista', 'Analista'];

        $id = $pdo->query("SELECT id FROM permisos WHERE modulo = 'mobile' AND accion = 'cn'")->fetchColumn();
        if (!$id) {
            $stmt = $pdo->prepare("INSERT INTO permisos (modulo, accion, descripcion) VALUES ('mobile', 'cn', 'Móvil: Canastas por Ambiente') RETURNING id");
            $stmt->execute();
            $id = $stmt->fetchColumn();
            echo "  [OK] Permiso creado: mobile.cn\n";
        }
        foreach ($roles as $rol) {
            $existe = $pdo->query("SELECT 1 FROM rol_permisos WHERE empresa_id = {$empresaId} AND rol = " . $pdo->quote($rol) . " AND permiso_id = {$id}")->fetchColumn();
            if (!$existe) {
                $stmt2 = $pdo->prepare("INSERT INTO rol_permisos (empresa_id, rol, permiso_id, concedido, created_at, updated_at) VALUES (?, ?, ?, 1, now(), now())");
                $stmt2->execute([$empresaId, $rol, $id]);
            }
        }
    },
    'down' => function () {
        Capsule::schema()->dropIfExists('canastas_planilla');
        $pdo = Capsule::connection()->getPdo();
        $pdo->exec("DELETE FROM rol_permisos WHERE permiso_id IN (SELECT id FROM permisos WHERE modulo = 'mobile' AND accion = 'cn')");
        $pdo->exec("DELETE FROM personal_permisos WHERE modulo = 'mobile' AND accion = 'cn'");
        $pdo->exec("DELETE FROM permisos WHERE modulo = 'mobile' AND accion = 'cn'");
    },
];
