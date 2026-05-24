# Picking Profesional — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Profesionalizar el módulo de Picking con asignación multi-auxiliar inteligente por ambiente/pasillo, filtros avanzados, y reporte exportable a Excel.

**Architecture:** Extender tablas existentes (`orden_pickings`, `picking_detalles`) con 3 columnas nuevas + tabla de auditoría. Nuevo endpoint `POST /picking/asignar-ambiente` con locking pesimista y distribución inteligente de líneas. Frontend rediseñado con drawer instantáneo de asignación y conteos por ambiente.

**Tech Stack:** PHP 8.2 + Slim 4 + Eloquent ORM + MySQL · Vanilla JS · SheetJS CDN (Excel)

---

## File Map

| Archivo | Acción |
|---|---|
| `database/migrations/065_picking_profesional.php` | CREAR — migración DB |
| `src/Controllers/PickingController.php` | MODIFICAR — 4 métodos nuevos/extendidos |
| `public/index.php` | MODIFICAR — 2 rutas nuevas (líneas ~595–596) |
| `public/assets/js/desktop/picking.js` | MODIFICAR — 3 submodulos + helpers |

---

## Task 1: Migración 065 — Schema DB

**Files:**
- Create: `database/migrations/065_picking_profesional.php`

- [ ] **Step 1: Crear el archivo de migración**

```php
<?php
// database/migrations/065_picking_profesional.php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();

        // 1. Columnas nuevas en orden_pickings
        if ($schema->hasTable('orden_pickings')) {
            $schema->table('orden_pickings', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('orden_pickings', 'sucursal_entrega')) {
                    $table->string('sucursal_entrega', 200)->nullable()->after('cliente');
                }
                if (!$schema->hasColumn('orden_pickings', 'ruta')) {
                    $table->string('ruta', 100)->nullable()->after('sucursal_entrega');
                }
                if (!$schema->hasColumn('orden_pickings', 'orden_logico')) {
                    $table->integer('orden_logico')->nullable()->after('ruta');
                }
            });
            // Índices via raw para evitar conflictos con Blueprint
            try { Capsule::statement('ALTER TABLE orden_pickings ADD INDEX idx_pick_ruta (empresa_id,sucursal_id,ruta(50))'); } catch (\Exception $e) {}
            try { Capsule::statement('ALTER TABLE orden_pickings ADD INDEX idx_pick_suc (empresa_id,sucursal_id,sucursal_entrega(100))'); } catch (\Exception $e) {}
            try { Capsule::statement('ALTER TABLE orden_pickings ADD INDEX idx_pick_fecha_est (empresa_id,sucursal_id,fecha_movimiento,estado)'); } catch (\Exception $e) {}
        }

        // 2. Columna ambiente en picking_detalles
        if ($schema->hasTable('picking_detalles')) {
            $schema->table('picking_detalles', function (Blueprint $table) use ($schema) {
                if (!$schema->hasColumn('picking_detalles', 'ambiente')) {
                    $table->string('ambiente', 30)->nullable()->after('auxiliar_id');
                }
            });
        }

        // 3. Tabla de auditoría de asignaciones
        if (!$schema->hasTable('picking_asignaciones_log')) {
            $schema->create('picking_asignaciones_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');
                $table->text('ordenes_json');
                $table->enum('modo', ['ambiente', 'pasillo']);
                $table->text('config_json');
                $table->integer('lineas_total');
                $table->string('ruta', 100)->nullable();
                $table->unsignedBigInteger('asignado_por');
                $table->timestamp('created_at')->useCurrent();
                $table->index(['empresa_id', 'sucursal_id', 'created_at'], 'idx_log_empresa');
            });
        }
    },

    'down' => function () {
        $schema = Capsule::schema();
        if ($schema->hasTable('picking_asignaciones_log')) {
            $schema->drop('picking_asignaciones_log');
        }
        if ($schema->hasTable('picking_detalles') && $schema->hasColumn('picking_detalles', 'ambiente')) {
            $schema->table('picking_detalles', fn(Blueprint $t) => $t->dropColumn('ambiente'));
        }
        if ($schema->hasTable('orden_pickings')) {
            $schema->table('orden_pickings', function (Blueprint $table) use ($schema) {
                foreach (['orden_logico', 'ruta', 'sucursal_entrega'] as $col) {
                    if ($schema->hasColumn('orden_pickings', $col)) $table->dropColumn($col);
                }
            });
        }
    },
];
```

- [ ] **Step 2: Ejecutar la migración**

```bash
php C:/xampp/htdocs/WMS_FENIX/scratch/run_migrations.php 065
```

Salida esperada: `✓ Migration 065_picking_profesional executed successfully`

- [ ] **Step 3: Verificar columnas en MySQL**

```sql
SHOW COLUMNS FROM orden_pickings LIKE 'sucursal_entrega';
SHOW COLUMNS FROM orden_pickings LIKE 'ruta';
SHOW COLUMNS FROM picking_detalles LIKE 'ambiente';
SHOW TABLES LIKE 'picking_asignaciones_log';
```

Cada query debe retornar 1 fila (no vacío).

- [ ] **Step 4: Commit**

```bash
git add database/migrations/065_picking_profesional.php
git commit -m "feat: migration 065 - picking profesional schema (sucursal_entrega, ruta, ambiente, log)"
```

---

## Task 2: Backend — Registro de Rutas Nuevas

**Files:**
- Modify: `public/index.php:595` (después de la línea `asignar-ruta`)

- [ ] **Step 1: Agregar las 2 rutas nuevas en public/index.php**

Busca la línea (≈595):
```php
$group->post('/picking/asignar-ruta', [\App\Controllers\PickingController::class, 'asignarRuta']);
```

Insertar DESPUÉS de esa línea:
```php
$group->post('/picking/asignar-ambiente', [\App\Controllers\PickingController::class, 'asignarPorAmbiente']);
$group->put('/picking/{id}/ruta',         [\App\Controllers\PickingController::class, 'asignarRutaOrden']);
```

- [ ] **Step 2: Verificar que no haya conflicto de rutas**

```bash
php -l "C:/xampp/htdocs/WMS_FENIX/public/index.php"
```

Salida esperada: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add public/index.php
git commit -m "feat: register routes POST /picking/asignar-ambiente and PUT /picking/{id}/ruta"
```

---

## Task 3: Backend — asignarPorAmbiente() + asignarRutaOrden()

**Files:**
- Modify: `src/Controllers/PickingController.php` (agregar antes del cierre `}` de la clase)

- [ ] **Step 1: Agregar método privado _clasificarAmbiente()**

Agregar ANTES del cierre `}` de la clase `PickingController`:

```php
    private function _clasificarAmbiente(string $zona, string $categoria): string
    {
        $z = strtolower($zona);
        $c = strtolower($categoria);
        if (str_contains($z, 'congel') || str_contains($c, 'congel')) return 'Congelado';
        if (str_contains($z, 'refrig') || str_contains($z, 'frio') || str_contains($z, 'frío') ||
            str_contains($c, 'refrig') || str_contains($c, 'frio') || str_contains($c, 'lácteo') ||
            str_contains($c, 'lacteo')) return 'Refrigerado';
        return 'Seco';
    }

    private function _reservarInventarioBatch(array $ordenIds, object $user): void
    {
        $now     = date('Y-m-d H:i:s');
        $detalles = PickingDetalle::whereIn('orden_picking_id', $ordenIds)
            ->where('estado', 'EnProceso')
            ->whereNotNull('auxiliar_id')
            ->get();

        if ($detalles->isEmpty()) return;

        $productoIds     = $detalles->pluck('producto_id')->unique()->toArray();
        $stockDisponible = Inventario::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->whereIn('producto_id', $productoIds)
            ->where('estado', 'Disponible')
            ->whereRaw('(cantidad - cantidad_reservada) > 0')
            ->lockForUpdate()
            ->orderByRaw('fecha_vencimiento IS NULL ASC')
            ->orderBy('fecha_vencimiento', 'ASC')
            ->get();

        $stockPorProducto = $stockDisponible->groupBy('producto_id');
        foreach ($detalles as $linea) {
            $restante = (float)$linea->cantidad_solicitada;
            foreach ($stockPorProducto->get($linea->producto_id, collect()) as $inv) {
                if ($restante <= 0) break;
                $disponible = max(0, $inv->cantidad - $inv->cantidad_reservada);
                if ($disponible <= 0) continue;
                $reservar              = min($disponible, $restante);
                $inv->cantidad_reservada += $reservar;
                $inv->save();
                $restante -= $reservar;
            }
        }
    }
```

- [ ] **Step 2: Agregar método asignarPorAmbiente()**

Agregar a continuación (aún antes del cierre `}` de la clase):

```php
    public function asignarPorAmbiente(Request $r, Response $res): Response
    {
        $user     = $r->getAttribute('user');
        $data     = $r->getParsedBody() ?? [];
        $ordenIds = array_map('intval', $data['orden_ids'] ?? []);
        $modo     = $data['modo'] ?? 'ambiente';
        $config   = $data['config'] ?? [];
        $ruta     = trim($data['ruta'] ?? '');

        if (empty($ordenIds))           return $this->error($res, 'Se requieren orden_ids');
        if (!in_array($modo, ['ambiente','pasillo']))
                                        return $this->error($res, 'Modo inválido: use "ambiente" o "pasillo"');

        try {
            $resultado = Capsule::transaction(function () use ($ordenIds, $modo, $config, $ruta, $user) {
                $now = date('Y-m-d H:i:s');

                // 1. Cargar líneas pendientes sin auxiliar con lock
                $lineas = Capsule::table('picking_detalles as pd')
                    ->join('orden_pickings as op', 'pd.orden_picking_id', '=', 'op.id')
                    ->leftJoin('ubicaciones as u', 'pd.ubicacion_id', '=', 'u.id')
                    ->leftJoin('productos as pr', 'pd.producto_id', '=', 'pr.id')
                    ->where('op.empresa_id', $user->empresa_id)
                    ->where('op.sucursal_id', $user->sucursal_id)
                    ->whereIn('pd.orden_picking_id', $ordenIds)
                    ->where('pd.estado', 'Pendiente')
                    ->whereNull('pd.auxiliar_id')
                    ->select(['pd.id','pd.orden_picking_id','u.zona','u.pasillo','pr.categoria'])
                    ->lockForUpdate()
                    ->get();

                // 2. Detectar colisiones (líneas ya asignadas en estas órdenes)
                $colision = Capsule::table('picking_detalles')
                    ->whereIn('orden_picking_id', $ordenIds)
                    ->whereNotNull('auxiliar_id')
                    ->pluck('orden_picking_id')->unique()->values();

                if ($colision->isNotEmpty()) {
                    throw new \RuntimeException(json_encode([
                        'tipo'      => 'colision',
                        'orden_ids' => $colision->toArray(),
                    ]));
                }

                // 3. Clasificar cada línea por ambiente
                foreach ($lineas as $linea) {
                    $linea->amb = $this->_clasificarAmbiente($linea->zona ?? '', $linea->categoria ?? '');
                }

                // 4. Determinar auxiliar por línea
                $porAuxiliar  = [];  // [auxId => [lineaId,...]]
                $porAmbiente  = ['Seco' => 0, 'Refrigerado' => 0, 'Congelado' => 0];
                $sinAuxiliar  = 0;

                foreach ($lineas as $linea) {
                    if ($modo === 'ambiente') {
                        $auxId = $config[$linea->amb]['auxiliar_id'] ?? null;
                    } else {
                        $auxId = null;
                        foreach (($config['rangos'] ?? []) as $rng) {
                            if (($linea->pasillo ?? '') >= ($rng['pasillo_desde'] ?? '') &&
                                ($linea->pasillo ?? '') <= ($rng['pasillo_hasta'] ?? '')) {
                                $auxId = $rng['auxiliar_id'] ?? null;
                                break;
                            }
                        }
                    }
                    if ($auxId) {
                        $porAuxiliar[$auxId][] = $linea->id;
                        $porAmbiente[$linea->amb] = ($porAmbiente[$linea->amb] ?? 0) + 1;
                    } else {
                        // Sin auxiliar: actualizar solo el campo ambiente
                        Capsule::table('picking_detalles')
                            ->where('id', $linea->id)
                            ->update(['ambiente' => $linea->amb, 'updated_at' => $now]);
                        $sinAuxiliar++;
                    }
                }

                // 5. UPDATE picking_detalles por auxiliar
                $totalAsignadas = 0;
                foreach ($porAuxiliar as $auxId => $ids) {
                    // Necesitamos el ambiente de cada línea en este lote
                    foreach ($lineas as $linea) {
                        if (!in_array($linea->id, $ids)) continue;
                        Capsule::table('picking_detalles')
                            ->where('id', $linea->id)
                            ->update([
                                'auxiliar_id' => $auxId,
                                'ambiente'    => $linea->amb,
                                'estado'      => 'EnProceso',
                                'updated_at'  => $now,
                            ]);
                    }
                    $totalAsignadas += count($ids);
                }

                // 6. Actualizar orden_pickings: estado + ruta + orden_logico
                $logico = 1;
                foreach (Capsule::table('orden_pickings')->whereIn('id', $ordenIds)->get(['id']) as $ord) {
                    $upd = ['estado' => 'EnProceso', 'updated_at' => $now, 'orden_logico' => $logico++];
                    if ($ruta) $upd['ruta'] = $ruta;
                    Capsule::table('orden_pickings')->where('id', $ord->id)->update($upd);
                }

                // 7. Reservar inventario
                $this->_reservarInventarioBatch($ordenIds, $user);

                // 8. Log de auditoría
                Capsule::table('picking_asignaciones_log')->insert([
                    'empresa_id'  => $user->empresa_id,
                    'sucursal_id' => $user->sucursal_id,
                    'ordenes_json' => json_encode($ordenIds),
                    'modo'        => $modo,
                    'config_json' => json_encode($config),
                    'lineas_total' => $totalAsignadas,
                    'ruta'        => $ruta ?: null,
                    'asignado_por' => $user->id,
                    'created_at'  => $now,
                ]);

                return [
                    'asignadas'    => $totalAsignadas,
                    'por_ambiente' => $porAmbiente,
                    'sin_auxiliar' => $sinAuxiliar,
                    'ordenes'      => count($ordenIds),
                ];
            });

            return $this->ok($res, $resultado, 'Asignación completada');

        } catch (\RuntimeException $e) {
            $decoded = json_decode($e->getMessage(), true);
            if (($decoded['tipo'] ?? '') === 'colision') {
                return $this->error($res, 'Algunos pedidos ya tienen líneas asignadas.', 409,
                    ['orden_ids_en_conflicto' => $decoded['orden_ids']]);
            }
            return $this->error($res, $e->getMessage(), 500);
        } catch (\Exception $e) {
            error_log('asignarPorAmbiente error: ' . $e->getMessage());
            return $this->error($res, 'Error en asignación: ' . $e->getMessage(), 500);
        }
    }
```

- [ ] **Step 3: Agregar método asignarRutaOrden()**

```php
    public function asignarRutaOrden(Request $r, Response $res, array $a): Response
    {
        $user  = $r->getAttribute('user');
        $data  = $r->getParsedBody() ?? [];
        $ruta  = trim($data['ruta'] ?? '');
        $orden = OrdenPicking::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);
        if (!$orden) return $this->notFound($res);
        $orden->ruta = $ruta ?: null;
        $orden->save();
        return $this->ok($res, ['id' => $orden->id, 'ruta' => $orden->ruta], 'Ruta actualizada');
    }
```

- [ ] **Step 4: Verificar sintaxis PHP**

```bash
php -l "C:/xampp/htdocs/WMS_FENIX/src/Controllers/PickingController.php"
```

Salida esperada: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/PickingController.php
git commit -m "feat: asignarPorAmbiente() + asignarRutaOrden() + private helpers"
```

---

## Task 4: Backend — Extender listar(), importarPedidos(), reporte()

**Files:**
- Modify: `src/Controllers/PickingController.php`

- [ ] **Step 1: Extender listar() con nuevos filtros**

En el método `listar()`, REEMPLAZAR el bloque de filtros existente (líneas ~37–97) con la versión extendida. Busca `$q = OrdenPicking::where('orden_pickings.empresa_id'` y reemplaza hasta `->when($params['planilla']` inclusive:

```php
        $soloHoy           = !empty($params['solo_hoy']);
        $incluirFinalizados = !empty($params['incluir_finalizados']);

        $q = OrdenPicking::where('orden_pickings.empresa_id', $user->empresa_id)
            ->where('orden_pickings.sucursal_id', $user->sucursal_id)
            ->when($soloHoy, fn($q) =>
                $q->whereDate('orden_pickings.fecha_movimiento', date('Y-m-d'))
            )
            ->when(!$soloHoy, fn($q) => (function() use ($q, $params, $ini, $fin) {
                $q->whereBetween('orden_pickings.created_at', [$ini, $fin]);
            })())
            ->when(!$incluirFinalizados && !($params['estado'] ?? null),
                fn($q) => $q->whereIn('estado', ['Pendiente','EnProceso'])
            )
            ->when($params['estado'] ?? null, function($q, $e) {
                if (strpos($e, ',') !== false) $q->whereIn('estado', explode(',', $e));
                else $q->where('estado', $e);
            })
            ->when($params['sucursal_entrega'] ?? null,
                fn($q, $v) => $q->where('orden_pickings.sucursal_entrega', 'like', "%$v%"))
            ->when($params['ruta'] ?? null,
                fn($q, $v) => $q->where('orden_pickings.ruta', 'like', "%$v%"))
            ->when($params['auxiliar_id']  ?? null, fn($q, $v) => $q->where('auxiliar_id', (int)$v))
            ->when($params['sin_auxiliar'] ?? null, fn($q)     => $q->whereNull('auxiliar_id'))
            ->when($params['cliente']      ?? null, fn($q, $v) => $q->where('cliente', 'like', "%$v%"))
            ->when($params['q'] ?? null, function($q, $v) {
                $q->where(fn($sq) => $sq
                    ->where('orden_pickings.numero_pedido', 'like', "%$v%")
                    ->orWhere('orden_pickings.cliente', 'like', "%$v%")
                    ->orWhere('orden_pickings.sucursal_entrega', 'like', "%$v%")
                    ->orWhere('orden_pickings.ruta', 'like', "%$v%")
                );
            });

        if (!empty($params['tiene_asignadas'])) {
            $q->where(fn($sq) => $sq
                ->where('orden_pickings.auxiliar_id', $user->id)
                ->orWhereHas('detalles', fn($dq) => $dq->where('auxiliar_id', $user->id))
            );
        }
        if (!empty($params['pasillo'])) {
            $pasillo = $params['pasillo'];
            $q->whereHas('detalles', fn($dq) => $dq
                ->join('ubicaciones', 'picking_detalles.ubicacion_id', '=', 'ubicaciones.id')
                ->where(fn($sq) => $sq
                    ->where('ubicaciones.pasillo', $pasillo)
                    ->orWhere('ubicaciones.codigo', 'like', "$pasillo%")
                )
            );
        }
```

Y REEMPLAZAR la parte del `->with([...])->orderBy` con:

```php
        $ordenes = $q
            ->withCount([
                'detalles as seco_count'        => fn($q) => $q->where('ambiente', 'Seco'),
                'detalles as refrigerado_count'  => fn($q) => $q->where('ambiente', 'Refrigerado'),
                'detalles as congelado_count'    => fn($q) => $q->where('ambiente', 'Congelado'),
                'detalles as total_count',
            ])
            ->with(['auxiliar:id,nombre', 'detalles.producto:id,nombre,codigo_interno,unidades_caja', 'detalles.auxiliar:id,nombre'])
            ->orderBy('orden_pickings.sucursal_entrega')
            ->orderBy('orden_pickings.prioridad')
            ->orderBy('orden_pickings.created_at', 'desc')
            ->limit($limit)
            ->get();
```

- [ ] **Step 2: Extender importarPedidos() — agregar sucursal_entrega al ALIASES**

En el método `importarPedidos()`, busca el array `$ALIASES` (línea ~1730). Hacer dos cambios:

**2a.** En el alias de `cliente`, ELIMINAR `'sucursal entrega', 'sucursal', 'punto entrega', 'destino'`:
```php
'cliente' => ['cliente', 'nombre cliente', 'razon social'],
```

**2b.** Agregar ANTES del alias `'documento'` el nuevo campo:
```php
'sucursal_entrega' => ['sucursal entrega', 'sucursal_entrega', 'sucursal', 'punto entrega', 'destino', 'cliente entrega'],
```

**2c.** En la sección donde se crea `$ordenData` para insertar en `orden_pickings` (busca `'cliente'` en el array de creación), agregar:
```php
'sucursal_entrega' => $row[$colMap['sucursal_entrega']] ?? null,
```

**2d.** Al crear `picking_detalles` en el batch, agregar clasificación de ambiente. Busca donde se hace el `PickingDetalle::create([...])` o el batch insert de detalles y agregar el campo:
```php
'ambiente' => $this->_clasificarAmbiente('', $producto->categoria ?? ''),
```

- [ ] **Step 3: Reemplazar reporte() completo**

Busca el método `public function reporte(...)` y reemplaza todo el método:

```php
    public function reporte(Request $r, Response $res): Response
    {
        $user   = $r->getAttribute('user');
        $params = $r->getQueryParams();

        $fechaDesde = $params['fecha_desde'] ?? null;
        $fechaHasta = $params['fecha_hasta'] ?? null;

        if (!$fechaDesde || !$fechaHasta) {
            return $this->ok($res, [
                'ordenes'     => [],
                'resumen'     => ['total'=>0,'completadas'=>0,'faltantes'=>0,'duracion_prom_min'=>0],
                'fecha_desde' => $fechaDesde,
                'fecha_hasta' => $fechaHasta,
            ]);
        }

        try {
            $q = OrdenPicking::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->whereBetween('fecha_movimiento', [$fechaDesde, $fechaHasta])
                ->when($params['ruta'] ?? null,
                    fn($q, $v) => $q->where('ruta', 'like', "%$v%"))
                ->when($params['sucursal_entrega'] ?? null,
                    fn($q, $v) => $q->where('sucursal_entrega', 'like', "%$v%"))
                ->withCount([
                    'detalles as completadas_count' => fn($q) => $q->where('estado', 'Completado'),
                    'detalles as faltantes_count'   => fn($q) => $q->where('estado', 'Faltante'),
                    'detalles as total_lineas_count',
                ])
                ->with([
                    'detalles.auxiliar:id,nombre',
                    'auxiliar:id,nombre',
                ])
                ->orderBy('fecha_movimiento', 'DESC')
                ->orderBy('created_at', 'DESC');

            $ordenes = $q->get();

            // Calcular auxiliares únicos por orden y duración
            $rows = $ordenes->map(function($o) {
                $auxNombres = $o->detalles->pluck('auxiliar.nombre')
                    ->filter()->unique()->values()->join(', ');
                if (!$auxNombres && $o->auxiliar) $auxNombres = $o->auxiliar->nombre;

                $durMin = null;
                if ($o->hora_inicio && $o->hora_fin) {
                    $ini = strtotime($o->fecha_movimiento . ' ' . $o->hora_inicio);
                    $fin = strtotime($o->fecha_movimiento . ' ' . $o->hora_fin);
                    if ($fin > $ini) $durMin = round(($fin - $ini) / 60);
                }

                $total = $o->total_lineas_count ?: 0;
                $comp  = $o->completadas_count  ?: 0;
                return [
                    'id'               => $o->id,
                    'fecha'            => $o->fecha_movimiento,
                    'numero_orden'     => $o->numero_orden,
                    'numero_pedido'    => $o->numero_pedido,
                    'cliente'          => $o->cliente,
                    'sucursal_entrega' => $o->sucursal_entrega,
                    'ruta'             => $o->ruta,
                    'estado'           => $o->estado,
                    'total_lineas'     => $total,
                    'completadas'      => $comp,
                    'faltantes'        => $o->faltantes_count ?: 0,
                    'pct_cumplimiento' => $total > 0 ? round($comp / $total * 100, 1) : 0,
                    'auxiliares'       => $auxNombres ?: '—',
                    'hora_inicio'      => $o->hora_inicio,
                    'hora_fin'         => $o->hora_fin,
                    'duracion_min'     => $durMin,
                ];
            });

            $duraciones     = $rows->pluck('duracion_min')->filter();
            $durPromedio    = $duraciones->isNotEmpty() ? round($duraciones->avg()) : 0;
            $totalFaltantes = $rows->sum('faltantes');

            return $this->ok($res, [
                'ordenes'     => $rows->values(),
                'resumen'     => [
                    'total'            => $rows->count(),
                    'completadas'      => $rows->where('estado','Completada')->count(),
                    'faltantes'        => $totalFaltantes,
                    'duracion_prom_min'=> $durPromedio,
                ],
                'fecha_desde' => $fechaDesde,
                'fecha_hasta' => $fechaHasta,
            ]);
        } catch (\Exception $e) {
            error_log('reporte error: ' . $e->getMessage());
            return $this->error($res, 'Error generando reporte.', 500);
        }
    }
```

- [ ] **Step 4: Verificar sintaxis PHP**

```bash
php -l "C:/xampp/htdocs/WMS_FENIX/src/Controllers/PickingController.php"
```

Salida esperada: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/PickingController.php
git commit -m "feat: extend listar() filters, importarPedidos() sucursal_entrega, reporte() rediseñado"
```

---

## Task 5: Frontend — show_pedidos() Redesign

**Files:**
- Modify: `public/assets/js/desktop/picking.js`

Localizar la función `show_pedidos()` y reemplazarla COMPLETA con la siguiente implementación. También agregar los helpers `_buildFiltrosDefault`, `_todayStr`, `_asignarRutaInline`, `_eliminarOrden`, `_toggleExpandRow`.

- [ ] **Step 1: Reemplazar show_pedidos() y agregar helpers**

Buscar `show_pedidos()` en picking.js y reemplazar la función completa:

```javascript
  async show_pedidos() {
    WMS.setBreadcrumb('picking', 'Pedidos');
    WMS.spinner();
    this._pedidosFiltros = this._pedidosFiltros || {
      q: '', solo_hoy: 1, estado: '', ruta: '', sucursal_entrega: '', fecha_desde: '', fecha_hasta: ''
    };
    await this._cargarPedidos();
  },

  _todayStr() {
    return new Date().toISOString().split('T')[0];
  },

  async _cargarPedidos() {
    const f = this._pedidosFiltros || {};
    const params = new URLSearchParams();
    if (f.solo_hoy)            params.set('solo_hoy', '1');
    if (f.incluir_finalizados) params.set('incluir_finalizados', '1');
    if (f.q)                   params.set('q', f.q);
    if (f.estado)              params.set('estado', f.estado);
    if (f.ruta)                params.set('ruta', f.ruta);
    if (f.sucursal_entrega)    params.set('sucursal_entrega', f.sucursal_entrega);
    if (f.fecha_desde)         params.set('fecha_desde', f.fecha_desde);
    if (f.fecha_hasta)         params.set('fecha_hasta', f.fecha_hasta);
    params.set('limit', '200');

    try {
      const r = await API.get('/picking?' + params.toString());
      const ordenes = r.data || r || [];
      this._renderPedidosTabla(ordenes);
    } catch(e) {
      WMS.toast('error', 'Error cargando pedidos');
    }
  },

  _renderPedidosTabla(ordenes) {
    const hoy = this._todayStr();
    const f   = this._pedidosFiltros || {};

    const estadoBadge = (e) => {
      const map = {
        'Pendiente': 'background:#fef9c3;color:#854d0e',
        'EnProceso': 'background:#dbeafe;color:#1e40af',
        'Completada':'background:#dcfce7;color:#166534',
        'Cancelada': 'background:#fee2e2;color:#991b1b',
        'Anulado':   'background:#f1f5f9;color:#64748b',
      };
      const s = map[e] || 'background:#f1f5f9;color:#64748b';
      return `<span style="${s};padding:2px 8px;border-radius:3px;font-size:.72rem;font-weight:600;">${WMS.esc(e)}</span>`;
    };

    const rows = ordenes.map(o => {
      const seco = o.seco_count || 0;
      const frio = o.refrigerado_count || 0;
      const cong = o.congelado_count || 0;
      const total = o.total_count || o.detalles?.length || 0;
      const auxNombres = [...new Set((o.detalles||[]).map(d=>d.auxiliar?.nombre).filter(Boolean))].join(', ') || (o.auxiliar?.nombre || '—');
      return `
        <tr class="erp-table-row clickable main-row" onclick="WMS_MODULES.picking._toggleExpandRow(this, ${o.id})" style="cursor:pointer;">
          <td style="padding:8px 12px;">
            <div style="font-weight:700;color:#0F4C81;">${WMS.esc(o.numero_pedido||o.numero_orden||'—')}</div>
            <div style="font-size:.7rem;color:#64748b;">${WMS.esc(o.planilla_lote||'')}</div>
          </td>
          <td style="padding:8px 12px;">
            <div style="font-weight:600;">${WMS.esc(o.sucursal_entrega||o.cliente||'—')}</div>
            <div style="font-size:.7rem;color:#64748b;">${WMS.esc(o.cliente||'')}</div>
          </td>
          <td style="padding:8px 12px;">
            ${o.ruta
              ? `<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:3px;font-size:.72rem;">${WMS.esc(o.ruta)}</span>`
              : `<button class="btn btn-outline-primary btn-sm" style="font-size:.7rem;padding:2px 8px;" onclick="event.stopPropagation();WMS_MODULES.picking._asignarRutaInline(${o.id}, this)">+ Ruta</button>`
            }
          </td>
          <td style="padding:8px 12px;text-align:center;font-weight:700;color:#92400e;">${seco||'—'}</td>
          <td style="padding:8px 12px;text-align:center;font-weight:700;color:#0369a1;">${frio||'—'}</td>
          <td style="padding:8px 12px;text-align:center;font-weight:700;color:#7c3aed;">${cong||'—'}</td>
          <td style="padding:8px 12px;text-align:center;font-weight:700;">${total}</td>
          <td style="padding:8px 12px;">${estadoBadge(o.estado)}</td>
          <td style="padding:8px 12px;text-align:center;">
            <div style="display:flex;gap:4px;justify-content:center;">
              <button title="Eliminar" onclick="event.stopPropagation();WMS_MODULES.picking._eliminarOrden(${o.id})"
                style="background:#fee2e2;border:none;border-radius:3px;padding:4px 8px;cursor:pointer;color:#991b1b;font-size:.75rem;">
                <i class="fa-solid fa-trash"></i>
              </button>
            </div>
          </td>
        </tr>
        <tr id="expand-${o.id}" style="display:none;">
          <td colspan="9" style="padding:0;background:#f8fafc;">
            <div id="expand-content-${o.id}" style="padding:12px 24px;border-top:1px solid #e2e8f0;">
              <div style="color:#64748b;font-size:.78rem;">Cargando detalle...</div>
            </div>
          </td>
        </tr>`;
    }).join('');

    const rutasUnicas = [...new Set(ordenes.map(o=>o.ruta).filter(Boolean))];
    const sucursalesUnicas = [...new Set(ordenes.map(o=>o.sucursal_entrega||o.cliente).filter(Boolean))];

    WMS.setContent(`
      <div class="card animate-fade-in">
        <div class="card-header">
          <h5 class="card-title"><i class="fa-solid fa-boxes-stacked"></i> Pedidos de Picking</h5>
        </div>
        <div class="card-body" style="padding:0;">

          <!-- Filtros -->
          <div style="padding:12px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:1;min-width:200px;">
              <input id="pick-q" type="text" class="form-control" placeholder="🔍 Buscar ruta, sucursal, N° pedido..."
                     value="${WMS.esc(f.q||'')}"
                     oninput="WMS_MODULES.picking._pedidosFiltros.q=this.value;clearTimeout(WMS_MODULES.picking._qt);WMS_MODULES.picking._qt=setTimeout(()=>WMS_MODULES.picking._cargarPedidos(),350)">
            </div>
            <div>
              <select id="pick-ruta" class="form-control" onchange="WMS_MODULES.picking._pedidosFiltros.ruta=this.value;WMS_MODULES.picking._cargarPedidos()">
                <option value="">Ruta: Todas</option>
                ${rutasUnicas.map(r=>`<option value="${WMS.esc(r)}" ${f.ruta===r?'selected':''}>${WMS.esc(r)}</option>`).join('')}
              </select>
            </div>
            <div>
              <select id="pick-suc" class="form-control" onchange="WMS_MODULES.picking._pedidosFiltros.sucursal_entrega=this.value;WMS_MODULES.picking._cargarPedidos()">
                <option value="">Sucursal: Todas</option>
                ${sucursalesUnicas.map(s=>`<option value="${WMS.esc(s)}" ${f.sucursal_entrega===s?'selected':''}>${WMS.esc(s)}</option>`).join('')}
              </select>
            </div>
            <div>
              <select id="pick-est" class="form-control" onchange="WMS_MODULES.picking._pedidosFiltros.estado=this.value;WMS_MODULES.picking._cargarPedidos()">
                <option value="">Estado: Activos</option>
                <option value="Pendiente" ${f.estado==='Pendiente'?'selected':''}>Pendiente</option>
                <option value="EnProceso" ${f.estado==='EnProceso'?'selected':''}>En Proceso</option>
                <option value="Completada,Cancelada" ${f.estado==='Completada,Cancelada'?'selected':''}>Finalizados</option>
              </select>
            </div>
            <div style="display:flex;gap:4px;align-items:center;">
              <input id="pick-desde" type="date" class="form-control" style="width:140px;" value="${f.fecha_desde||''}"
                     onchange="WMS_MODULES.picking._pedidosFiltros.fecha_desde=this.value;WMS_MODULES.picking._pedidosFiltros.solo_hoy=0;WMS_MODULES.picking._cargarPedidos()">
              <span style="color:#64748b;font-size:.78rem;">—</span>
              <input id="pick-hasta" type="date" class="form-control" style="width:140px;" value="${f.fecha_hasta||''}"
                     onchange="WMS_MODULES.picking._pedidosFiltros.fecha_hasta=this.value;WMS_MODULES.picking._pedidosFiltros.solo_hoy=0;WMS_MODULES.picking._cargarPedidos()">
            </div>
            <button class="btn btn-outline-primary btn-sm" onclick="WMS_MODULES.picking._pedidosFiltros={solo_hoy:1,q:'',estado:'',ruta:'',sucursal_entrega:'',fecha_desde:'',fecha_hasta:''};WMS_MODULES.picking._cargarPedidos()">
              <i class="fa-solid fa-rotate-left"></i> Hoy
            </button>
          </div>

          <!-- Tabla -->
          <div style="overflow-x:auto;">
            <table class="erp-table">
              <thead>
                <tr>
                  <th style="padding:10px 12px;">N° Pedido</th>
                  <th style="padding:10px 12px;">Sucursal Entrega</th>
                  <th style="padding:10px 12px;">Ruta</th>
                  <th style="padding:10px 12px;text-align:center;" title="Líneas Seco">🌡️ Seco</th>
                  <th style="padding:10px 12px;text-align:center;" title="Líneas Refrigerado">❄️ Frío</th>
                  <th style="padding:10px 12px;text-align:center;" title="Líneas Congelado">🧊 Cong.</th>
                  <th style="padding:10px 12px;text-align:center;">Total</th>
                  <th style="padding:10px 12px;">Estado</th>
                  <th style="padding:10px 12px;text-align:center;">Acciones</th>
                </tr>
              </thead>
              <tbody>
                ${rows || '<tr><td colspan="9" style="text-align:center;padding:32px;color:#94a3b8;">Sin pedidos activos hoy. Use los filtros para buscar.</td></tr>'}
              </tbody>
            </table>
          </div>
        </div>
      </div>`);
  },

  async _toggleExpandRow(tr, ordenId) {
    const expandRow = document.getElementById('expand-' + ordenId);
    const content   = document.getElementById('expand-content-' + ordenId);
    if (!expandRow) return;
    if (expandRow.style.display !== 'none') {
      expandRow.style.display = 'none';
      tr.classList.remove('selected');
      return;
    }
    expandRow.style.display = '';
    tr.classList.add('selected');
    try {
      const r = await API.get('/picking/' + ordenId);
      const o = r.data || r;
      const lineas = (o.detalles || []).map(d => `
        <tr>
          <td style="padding:5px 10px;font-size:.78rem;">${WMS.esc(d.producto?.nombre||d.producto?.codigo_interno||'—')}</td>
          <td style="padding:5px 10px;font-size:.78rem;text-align:center;">${d.cantidad_solicitada}</td>
          <td style="padding:5px 10px;font-size:.78rem;text-align:center;">${d.cantidad_pickeada||0}</td>
          <td style="padding:5px 10px;font-size:.78rem;">${WMS.esc(d.ambiente||'—')}</td>
          <td style="padding:5px 10px;font-size:.78rem;">${WMS.esc(d.auxiliar?.nombre||'—')}</td>
          <td style="padding:5px 10px;font-size:.78rem;">${WMS.esc(d.estado||'')}</td>
        </tr>`).join('');
      content.innerHTML = `
        <div style="font-size:.78rem;font-weight:700;color:#0F4C81;margin-bottom:8px;">
          Pedido: <strong>${WMS.esc(o.numero_pedido||o.numero_orden||'—')}</strong>
          · Asesor: ${WMS.esc(o.asesor_comercial||'—')}
          · Área: ${WMS.esc(o.area_comercial||'—')}
        </div>
        <table class="erp-table" style="margin:0;">
          <thead>
            <tr>
              <th style="padding:5px 10px;font-size:.7rem;">Producto</th>
              <th style="padding:5px 10px;font-size:.7rem;text-align:center;">Solicitado</th>
              <th style="padding:5px 10px;font-size:.7rem;text-align:center;">Pickeado</th>
              <th style="padding:5px 10px;font-size:.7rem;">Ambiente</th>
              <th style="padding:5px 10px;font-size:.7rem;">Auxiliar</th>
              <th style="padding:5px 10px;font-size:.7rem;">Estado</th>
            </tr>
          </thead>
          <tbody>${lineas||'<tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:12px;">Sin líneas</td></tr>'}</tbody>
        </table>`;
    } catch(e) {
      content.innerHTML = '<div style="color:#ef4444;font-size:.78rem;padding:8px;">Error cargando detalle</div>';
    }
  },

  async _asignarRutaInline(ordenId, btn) {
    const ruta = prompt('Nombre de la ruta para este pedido:');
    if (ruta === null) return;
    try {
      await API.put('/picking/' + ordenId + '/ruta', { ruta });
      WMS.toast('success', 'Ruta asignada');
      this._cargarPedidos();
    } catch(e) {
      WMS.toast('error', 'Error asignando ruta');
    }
  },

  async _eliminarOrden(ordenId) {
    if (!confirm('¿Eliminar este pedido? Se revertirán las reservas de inventario.')) return;
    try {
      await API.delete('/picking/' + ordenId);
      WMS.toast('success', 'Pedido eliminado');
      this._cargarPedidos();
    } catch(e) {
      WMS.toast('error', e.message || 'Error eliminando pedido');
    }
  },
```

- [ ] **Step 2: Verificar en browser**

Abrir `http://localhost/WMS_FENIX/public` → ir a módulo Picking → submodulo Pedidos.  
Verificar: tabla carga con pedidos de hoy, columnas Seco/Frío/Cong visibles, clic en fila expande el detalle.

- [ ] **Step 3: Commit**

```bash
git add public/assets/js/desktop/picking.js
git commit -m "feat: show_pedidos() rediseñado con filtros avanzados y fila expandible"
```

---

## Task 6: Frontend — show_asignacion() Redesign con Drawer

**Files:**
- Modify: `public/assets/js/desktop/picking.js`

- [ ] **Step 1: Reemplazar show_asignacion() y helpers del drawer**

Localizar `show_asignacion()` en picking.js. Reemplazar la función completa y agregar los helpers:

```javascript
  async show_asignacion() {
    WMS.setBreadcrumb('picking', 'Asignación');
    WMS.spinner();
    this._asigFiltros = { solo_hoy: 1, q: '', ruta: '', sucursal_entrega: '' };
    this._asigSeleccionados = new Set();
    this._asigOrdenes = [];
    this._asigAuxiliares = [];
    await this._cargarAuxiliares();
    await this._cargarAsignacion();
  },

  async _cargarAuxiliares() {
    try {
      const r = await API.get('/personal?rol=auxiliar&limit=100');
      this._asigAuxiliares = r.data || r || [];
    } catch(e) { this._asigAuxiliares = []; }
  },

  async _cargarAsignacion() {
    const f = this._asigFiltros || {};
    const params = new URLSearchParams({ limit: 300 });
    if (f.solo_hoy) { params.set('solo_hoy','1'); params.set('estado','Pendiente'); }
    if (f.q)                params.set('q', f.q);
    if (f.ruta)             params.set('ruta', f.ruta);
    if (f.sucursal_entrega) params.set('sucursal_entrega', f.sucursal_entrega);
    params.set('sin_auxiliar', '1');
    try {
      const r = await API.get('/picking?' + params.toString());
      this._asigOrdenes = r.data || r || [];
      this._asigSeleccionados.clear();
      this._renderAsignacion();
    } catch(e) { WMS.toast('error', 'Error cargando pedidos'); }
  },

  _renderAsignacion() {
    const ordenes = this._asigOrdenes;
    const f = this._asigFiltros || {};
    const sucursales = [...new Set(ordenes.map(o=>o.sucursal_entrega||o.cliente).filter(Boolean))];
    const rutas      = [...new Set(ordenes.map(o=>o.ruta).filter(Boolean))];

    const auxOpts = this._asigAuxiliares.map(a =>
      `<option value="${a.id}">${WMS.esc(a.nombre)}</option>`).join('');

    const rows = ordenes.map(o => {
      const seco  = o.seco_count || 0;
      const frio  = o.refrigerado_count || 0;
      const cong  = o.congelado_count || 0;
      const total = o.total_count || 0;
      const sel   = this._asigSeleccionados.has(o.id);
      return `
        <tr style="${sel?'background:#eff6ff;':''}" id="asig-row-${o.id}">
          <td style="padding:8px 12px;text-align:center;">
            <input type="checkbox" ${sel?'checked':''} onchange="WMS_MODULES.picking._toggleAsig(${o.id},this.checked)">
          </td>
          <td style="padding:8px 12px;">
            <div style="font-weight:700;color:#0F4C81;">${WMS.esc(o.numero_pedido||o.numero_orden||'—')}</div>
            <div style="font-size:.7rem;color:#64748b;">${WMS.esc(o.cliente||'')}</div>
          </td>
          <td style="padding:8px 12px;">${WMS.esc(o.sucursal_entrega||o.cliente||'—')}</td>
          <td style="padding:8px 12px;">
            ${o.ruta?`<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:3px;font-size:.72rem;">${WMS.esc(o.ruta)}</span>`:'—'}
          </td>
          <td style="padding:8px 12px;text-align:center;font-weight:700;color:#92400e;">${seco||'—'}</td>
          <td style="padding:8px 12px;text-align:center;font-weight:700;color:#0369a1;">${frio||'—'}</td>
          <td style="padding:8px 12px;text-align:center;font-weight:700;color:#7c3aed;">${cong||'—'}</td>
          <td style="padding:8px 12px;text-align:center;font-weight:700;">${total}</td>
        </tr>`;
    }).join('');

    WMS.setContent(`
      <div style="display:flex;min-height:calc(100vh - 140px);position:relative;">

        <!-- Tabla principal -->
        <div style="flex:1;overflow:hidden;display:flex;flex-direction:column;">
          <div class="card" style="margin:0;border-radius:0;flex:1;overflow:hidden;display:flex;flex-direction:column;">
            <div class="card-header" style="flex-shrink:0;">
              <h5 class="card-title"><i class="fa-solid fa-user-check"></i> Asignación de Separación</h5>
              <span style="font-size:.78rem;color:#64748b;">Solo pedidos pendientes de hoy — marque para asignar</span>
            </div>

            <!-- Barra filtros -->
            <div style="padding:10px 16px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;flex-shrink:0;">
              <input type="text" class="form-control" style="flex:1;min-width:180px;" placeholder="🔍 Buscar..."
                     value="${WMS.esc(f.q||'')}"
                     oninput="WMS_MODULES.picking._asigFiltros.q=this.value;clearTimeout(WMS_MODULES.picking._aqt);WMS_MODULES.picking._aqt=setTimeout(()=>WMS_MODULES.picking._cargarAsignacion(),350)">
              <select class="form-control" style="width:160px;" onchange="WMS_MODULES.picking._asigFiltros.sucursal_entrega=this.value;WMS_MODULES.picking._cargarAsignacion()">
                <option value="">Sucursal: Todas</option>
                ${sucursales.map(s=>`<option value="${WMS.esc(s)}" ${f.sucursal_entrega===s?'selected':''}>${WMS.esc(s)}</option>`).join('')}
              </select>
              <select class="form-control" style="width:140px;" onchange="WMS_MODULES.picking._asigFiltros.ruta=this.value;WMS_MODULES.picking._cargarAsignacion()">
                <option value="">Ruta: Todas</option>
                ${rutas.map(r=>`<option value="${WMS.esc(r)}" ${f.ruta===r?'selected':''}>${WMS.esc(r)}</option>`).join('')}
              </select>
              ${this._asigSeleccionados.size > 0 ?
                `<span style="background:#0F4C81;color:#fff;padding:4px 12px;border-radius:4px;font-weight:600;font-size:.8rem;">${this._asigSeleccionados.size} sel.</span>` : ''}
            </div>

            <!-- Tabla con scroll -->
            <div style="overflow:auto;flex:1;">
              <table class="erp-table">
                <thead>
                  <tr>
                    <th style="padding:10px 12px;width:40px;">
                      <input type="checkbox" onchange="WMS_MODULES.picking._toggleAsigTodos(this.checked)">
                    </th>
                    <th style="padding:10px 12px;">N° Pedido</th>
                    <th style="padding:10px 12px;">Sucursal</th>
                    <th style="padding:10px 12px;">Ruta</th>
                    <th style="padding:10px 12px;text-align:center;">🌡️ Seco</th>
                    <th style="padding:10px 12px;text-align:center;">❄️ Frío</th>
                    <th style="padding:10px 12px;text-align:center;">🧊 Cong.</th>
                    <th style="padding:10px 12px;text-align:center;">Total</th>
                  </tr>
                </thead>
                <tbody>
                  ${rows || '<tr><td colspan="8" style="text-align:center;padding:32px;color:#94a3b8;">No hay pedidos pendientes hoy.</td></tr>'}
                </tbody>
                ${this._asigSeleccionados.size > 0 ? `
                <tfoot>
                  <tr style="background:#f0fdf4;border-top:2px solid #86efac;">
                    <td colspan="4" style="padding:8px 12px;font-weight:700;font-size:.78rem;color:#166534;">
                      ∑ ${this._asigSeleccionados.size} pedidos seleccionados
                    </td>
                    <td style="padding:8px 12px;text-align:center;font-weight:900;color:#92400e;font-size:.9rem;" id="asig-tot-seco">—</td>
                    <td style="padding:8px 12px;text-align:center;font-weight:900;color:#0369a1;font-size:.9rem;" id="asig-tot-frio">—</td>
                    <td style="padding:8px 12px;text-align:center;font-weight:900;color:#7c3aed;font-size:.9rem;" id="asig-tot-cong">—</td>
                    <td style="padding:8px 12px;text-align:center;font-weight:900;font-size:.9rem;" id="asig-tot-total">—</td>
                  </tr>
                </tfoot>` : ''}
              </table>
            </div>
          </div>
        </div>

        <!-- Drawer lateral (visible solo si hay seleccionados) -->
        ${this._asigSeleccionados.size > 0 ? this._buildDrawerAsignacion(auxOpts) : ''}
      </div>`);

    if (this._asigSeleccionados.size > 0) this._actualizarTotalesAsig();
  },

  _buildDrawerAsignacion(auxOpts) {
    const totales = this._calcularTotalesAmbiente();
    return `
      <div id="asig-drawer" style="width:260px;flex-shrink:0;border-left:2px solid #0F4C81;background:#fff;display:flex;flex-direction:column;max-height:calc(100vh - 140px);overflow-y:auto;">
        <!-- Header -->
        <div style="background:#0F4C81;color:#fff;padding:12px 14px;flex-shrink:0;">
          <div style="font-weight:700;font-size:.85rem;">⚡ Asignar Separación</div>
          <div style="font-size:.75rem;opacity:.85;">${this._asigSeleccionados.size} pedidos · ${totales.total} líneas</div>
        </div>

        <div style="padding:14px;display:flex;flex-direction:column;gap:14px;flex:1;">
          <!-- Modo -->
          <div>
            <div style="font-size:.7rem;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:6px;">Modo Asignación</div>
            <div style="display:flex;gap:4px;">
              <button id="modo-amb" onclick="WMS_MODULES.picking._setModoAsig('ambiente')"
                style="flex:1;padding:6px;border-radius:4px;border:none;cursor:pointer;font-size:.75rem;background:#0F4C81;color:#fff;">
                🌡️ Ambiente
              </button>
              <button id="modo-pas" onclick="WMS_MODULES.picking._setModoAsig('pasillo')"
                style="flex:1;padding:6px;border-radius:4px;border:1px solid #e2e8f0;cursor:pointer;font-size:.75rem;background:#f8fafc;color:#64748b;">
                🛒 Pasillo
              </button>
            </div>
          </div>

          <!-- KPI chips por ambiente -->
          <div style="display:flex;gap:6px;">
            <div style="flex:1;background:#fef3c7;border:1px solid #fde68a;border-radius:4px;padding:8px;text-align:center;">
              <div style="font-size:.65rem;color:#92400e;font-weight:600;">🌡️ SECO</div>
              <div style="font-size:1.3rem;font-weight:900;color:#92400e;" id="kpi-seco">${totales.seco}</div>
              <div style="font-size:.6rem;color:#92400e;">líneas</div>
            </div>
            <div style="flex:1;background:#e0f2fe;border:1px solid #bae6fd;border-radius:4px;padding:8px;text-align:center;">
              <div style="font-size:.65rem;color:#0369a1;font-weight:600;">❄️ FRÍO</div>
              <div style="font-size:1.3rem;font-weight:900;color:#0369a1;" id="kpi-frio">${totales.frio}</div>
              <div style="font-size:.6rem;color:#0369a1;">líneas</div>
            </div>
            <div style="flex:1;background:#ede9fe;border:1px solid #ddd6fe;border-radius:4px;padding:8px;text-align:center;">
              <div style="font-size:.65rem;color:#7c3aed;font-weight:600;">🧊 CONG.</div>
              <div style="font-size:1.3rem;font-weight:900;color:#7c3aed;" id="kpi-cong">${totales.cong}</div>
              <div style="font-size:.6rem;color:#7c3aed;">líneas</div>
            </div>
          </div>

          <!-- Selectores por ambiente (modo ambiente) -->
          <div id="config-ambiente">
            ${[
              {key:'Seco',    label:'🌡️ Seco',      count: totales.seco, borderColor:'#fde68a', bg:'#fffbeb', color:'#92400e'},
              {key:'Refrigerado', label:'❄️ Refrigerado', count: totales.frio, borderColor:'#bae6fd', bg:'#f0f9ff', color:'#0369a1'},
              {key:'Congelado',   label:'🧊 Congelado',   count: totales.cong, borderColor:'#ddd6fe', bg:'#faf5ff', color:'#7c3aed'},
            ].map(env => `
              <div style="margin-bottom:10px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                  <span style="font-size:.75rem;font-weight:700;color:${env.color};">${env.label}</span>
                  <span style="font-size:.68rem;background:${env.bg};color:${env.color};border:1px solid ${env.borderColor};padding:1px 6px;border-radius:10px;">${env.count} lín.</span>
                </div>
                <select class="form-control" id="aux-${env.key.toLowerCase()}"
                  style="border-color:${env.borderColor};background:${env.bg};font-size:.78rem;"
                  data-ambiente="${env.key}">
                  <option value="">— Sin asignar —</option>
                  ${auxOpts}
                </select>
              </div>`).join('')}
          </div>

          <!-- Selectores por pasillo (modo pasillo - oculto por defecto) -->
          <div id="config-pasillo" style="display:none;">
            <div style="font-size:.7rem;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:8px;">Rangos de Pasillo</div>
            <div id="rangos-pasillo">
              ${this._buildRangoPasillo(0, auxOpts)}
            </div>
            <button onclick="WMS_MODULES.picking._agregarRangoPasillo()" class="btn btn-outline-primary btn-sm" style="width:100%;margin-top:4px;font-size:.75rem;">
              + Agregar rango
            </button>
          </div>

          <!-- Nombre de ruta -->
          <div>
            <div style="font-size:.7rem;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:4px;">Nombre de Ruta</div>
            <input type="text" id="asig-ruta-nombre" class="form-control" placeholder="Ej: Ruta 01" style="font-size:.82rem;">
          </div>
        </div>

        <!-- Botones footer -->
        <div style="padding:12px 14px;border-top:1px solid #e2e8f0;display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
          <button class="btn btn-primary" id="btn-confirmar-asig" onclick="WMS_MODULES.picking._confirmarAsignacion()"
            style="width:100%;background:#059669;border-color:#059669;font-weight:700;">
            <i class="fa-solid fa-check"></i> Confirmar Asignación
          </button>
          <button class="btn btn-outline-secondary btn-sm" onclick="WMS_MODULES.picking._asigSeleccionados.clear();WMS_MODULES.picking._renderAsignacion()"
            style="width:100%;font-size:.78rem;">
            ✕ Cancelar
          </button>
        </div>
      </div>`;
  },

  _buildRangoPasillo(idx, auxOpts) {
    return `
      <div style="display:flex;gap:4px;margin-bottom:8px;align-items:center;" id="rango-${idx}">
        <input type="text" placeholder="P01" class="form-control" style="width:60px;font-size:.75rem;" data-rango-desde="${idx}">
        <span style="color:#64748b;font-size:.8rem;">—</span>
        <input type="text" placeholder="P10" class="form-control" style="width:60px;font-size:.75rem;" data-rango-hasta="${idx}">
        <select class="form-control" style="flex:1;font-size:.75rem;" data-rango-aux="${idx}">
          <option value="">Auxiliar</option>${auxOpts}
        </select>
      </div>`;
  },

  _rangoIdx: 1,
  _agregarRangoPasillo() {
    const cont = document.getElementById('rangos-pasillo');
    if (!cont) return;
    const auxOpts = this._asigAuxiliares.map(a=>`<option value="${a.id}">${WMS.esc(a.nombre)}</option>`).join('');
    cont.insertAdjacentHTML('beforeend', this._buildRangoPasillo(this._rangoIdx++, auxOpts));
  },

  _setModoAsig(modo) {
    document.getElementById('config-ambiente').style.display = modo === 'ambiente' ? '' : 'none';
    document.getElementById('config-pasillo').style.display  = modo === 'pasillo'  ? '' : 'none';
    document.getElementById('modo-amb').style.cssText = modo==='ambiente'
      ? 'flex:1;padding:6px;border-radius:4px;border:none;cursor:pointer;font-size:.75rem;background:#0F4C81;color:#fff;'
      : 'flex:1;padding:6px;border-radius:4px;border:1px solid #e2e8f0;cursor:pointer;font-size:.75rem;background:#f8fafc;color:#64748b;';
    document.getElementById('modo-pas').style.cssText = modo==='pasillo'
      ? 'flex:1;padding:6px;border-radius:4px;border:none;cursor:pointer;font-size:.75rem;background:#0F4C81;color:#fff;'
      : 'flex:1;padding:6px;border-radius:4px;border:1px solid #e2e8f0;cursor:pointer;font-size:.75rem;background:#f8fafc;color:#64748b;';
  },

  _toggleAsig(ordenId, checked) {
    if (checked) this._asigSeleccionados.add(ordenId);
    else         this._asigSeleccionados.delete(ordenId);
    this._renderAsignacion();
  },

  _toggleAsigTodos(checked) {
    if (checked) this._asigOrdenes.forEach(o => this._asigSeleccionados.add(o.id));
    else         this._asigSeleccionados.clear();
    this._renderAsignacion();
  },

  _calcularTotalesAmbiente() {
    let seco = 0, frio = 0, cong = 0, total = 0;
    this._asigOrdenes
      .filter(o => this._asigSeleccionados.has(o.id))
      .forEach(o => {
        seco  += o.seco_count || 0;
        frio  += o.refrigerado_count || 0;
        cong  += o.congelado_count || 0;
        total += o.total_count || 0;
      });
    return { seco, frio, cong, total };
  },

  _actualizarTotalesAsig() {
    const t = this._calcularTotalesAmbiente();
    const set = (id, v) => { const el = document.getElementById(id); if(el) el.textContent = v||'—'; };
    set('asig-tot-seco', t.seco); set('asig-tot-frio', t.frio);
    set('asig-tot-cong', t.cong); set('asig-tot-total', t.total);
    set('kpi-seco', t.seco); set('kpi-frio', t.frio); set('kpi-cong', t.cong);
  },

  async _confirmarAsignacion() {
    const btn = document.getElementById('btn-confirmar-asig');
    if (btn) btn.disabled = true;

    const ordenIds = [...this._asigSeleccionados];
    const ruta     = document.getElementById('asig-ruta-nombre')?.value.trim() || '';
    const modoPasEl = document.getElementById('config-pasillo');
    const modo     = (modoPasEl && modoPasEl.style.display !== 'none') ? 'pasillo' : 'ambiente';

    let config = {};
    if (modo === 'ambiente') {
      ['Seco','Refrigerado','Congelado'].forEach(amb => {
        const sel = document.getElementById('aux-' + amb.toLowerCase());
        const auxId = sel?.value ? parseInt(sel.value) : null;
        config[amb] = { auxiliar_id: auxId };
      });
    } else {
      const rangos = [];
      document.querySelectorAll('[data-rango-desde]').forEach(el => {
        const idx  = el.dataset.rangoDesde;
        const desde = el.value.trim();
        const hasta = document.querySelector(`[data-rango-hasta="${idx}"]`)?.value.trim();
        const auxId = parseInt(document.querySelector(`[data-rango-aux="${idx}"]`)?.value);
        if (desde && hasta && auxId) rangos.push({ pasillo_desde: desde, pasillo_hasta: hasta, auxiliar_id: auxId });
      });
      config = { rangos };
    }

    try {
      const r = await API.post('/picking/asignar-ambiente', { orden_ids: ordenIds, modo, config, ruta });
      const d = r.data || r;
      WMS.toast('success', `✓ ${d.asignadas} líneas asignadas a ${d.ordenes} pedidos`);
      this._asigSeleccionados.clear();
      await this._cargarAsignacion();
    } catch(e) {
      if (e.status === 409) {
        WMS.toast('error', 'Conflicto: algunos pedidos ya tienen líneas asignadas. Recargue la lista.');
      } else {
        WMS.toast('error', e.message || 'Error en asignación');
      }
      if (btn) btn.disabled = false;
    }
  },
```

- [ ] **Step 2: Verificar en browser**

Ir a módulo Picking → Asignación.  
Verificar: tabla muestra pedidos pendientes de hoy, al marcar checkbox aparece el drawer lateral con KPI chips y selectores de auxiliar.

- [ ] **Step 3: Commit**

```bash
git add public/assets/js/desktop/picking.js
git commit -m "feat: show_asignacion() con drawer multi-auxiliar por ambiente/pasillo + anti-duplicado"
```

---

## Task 7: Frontend — show_reporte() + Exportar Excel

**Files:**
- Modify: `public/assets/js/desktop/picking.js`

- [ ] **Step 1: Reemplazar show_reporte() y agregar _exportarExcel()**

```javascript
  async show_reporte() {
    WMS.setBreadcrumb('picking', 'Reporte');
    this._reporteFiltros = { fecha_desde: '', fecha_hasta: '', ruta: '', sucursal_entrega: '' };
    this._reporteData = [];
    WMS.setContent(`
      <div class="card animate-fade-in">
        <div class="card-header">
          <h5 class="card-title"><i class="fa-solid fa-chart-bar"></i> Historial de Separaciones</h5>
        </div>
        <div class="card-body">
          <!-- Filtros -->
          <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;">
            <div class="form-group" style="margin:0;">
              <label class="form-label">Desde <span class="required">*</span></label>
              <input type="date" id="rep-desde" class="form-control">
            </div>
            <div class="form-group" style="margin:0;">
              <label class="form-label">Hasta <span class="required">*</span></label>
              <input type="date" id="rep-hasta" class="form-control" value="${new Date().toISOString().split('T')[0]}">
            </div>
            <div class="form-group" style="margin:0;min-width:160px;">
              <label class="form-label">Ruta</label>
              <input type="text" id="rep-ruta" class="form-control" placeholder="Ej: Ruta 01">
            </div>
            <div class="form-group" style="margin:0;min-width:180px;">
              <label class="form-label">Sucursal Entrega</label>
              <input type="text" id="rep-suc" class="form-control" placeholder="TiendaXYZ...">
            </div>
            <div style="display:flex;gap:6px;align-items:flex-end;padding-bottom:2px;">
              <button class="btn btn-primary" onclick="WMS_MODULES.picking._buscarReporte()">
                <i class="fa-solid fa-search"></i> Buscar
              </button>
              <button class="btn btn-outline-success" id="btn-export-excel" onclick="WMS_MODULES.picking._exportarExcel()" style="display:none;">
                <i class="fa-solid fa-file-excel"></i> Excel
              </button>
            </div>
          </div>

          <!-- KPIs (ocultos hasta buscar) -->
          <div id="rep-kpis" style="display:none;display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
            <div class="erp-card" style="flex:1;min-width:120px;text-align:center;padding:14px;">
              <div style="font-size:.72rem;color:#64748b;text-transform:uppercase;">Pedidos</div>
              <div id="rep-k-total" style="font-size:1.5rem;font-weight:900;color:#0F4C81;">—</div>
            </div>
            <div class="erp-card" style="flex:1;min-width:120px;text-align:center;padding:14px;">
              <div style="font-size:.72rem;color:#64748b;text-transform:uppercase;">Completadas</div>
              <div id="rep-k-comp" style="font-size:1.5rem;font-weight:900;color:#059669;">—</div>
            </div>
            <div class="erp-card" style="flex:1;min-width:120px;text-align:center;padding:14px;">
              <div style="font-size:.72rem;color:#64748b;text-transform:uppercase;">Faltantes</div>
              <div id="rep-k-falt" style="font-size:1.5rem;font-weight:900;color:#dc2626;">—</div>
            </div>
            <div class="erp-card" style="flex:1;min-width:120px;text-align:center;padding:14px;">
              <div style="font-size:.72rem;color:#64748b;text-transform:uppercase;">Dur. Prom.</div>
              <div id="rep-k-dur" style="font-size:1.5rem;font-weight:900;color:#7c3aed;">—</div>
            </div>
          </div>

          <!-- Tabla resultados -->
          <div id="rep-tabla" style="display:none;overflow-x:auto;">
            <table class="erp-table" id="rep-table-el">
              <thead>
                <tr>
                  <th style="padding:10px 12px;">Fecha</th>
                  <th style="padding:10px 12px;">N° Pedido</th>
                  <th style="padding:10px 12px;">Sucursal Entrega</th>
                  <th style="padding:10px 12px;">Ruta</th>
                  <th style="padding:10px 12px;text-align:center;">Total Lín.</th>
                  <th style="padding:10px 12px;text-align:center;">Completadas</th>
                  <th style="padding:10px 12px;text-align:center;">Faltantes</th>
                  <th style="padding:10px 12px;text-align:center;">% Cumpl.</th>
                  <th style="padding:10px 12px;">Auxiliar(es)</th>
                  <th style="padding:10px 12px;">Inicio</th>
                  <th style="padding:10px 12px;">Fin</th>
                  <th style="padding:10px 12px;text-align:center;">Dur.(min)</th>
                </tr>
              </thead>
              <tbody id="rep-tbody"></tbody>
            </table>
          </div>

          <div id="rep-empty" style="text-align:center;padding:40px;color:#94a3b8;">
            <i class="fa-solid fa-magnifying-glass" style="font-size:2rem;margin-bottom:10px;display:block;"></i>
            Seleccione un rango de fechas y haga clic en Buscar para ver el historial.
          </div>
        </div>
      </div>`);
  },

  async _buscarReporte() {
    const desde = document.getElementById('rep-desde')?.value;
    const hasta = document.getElementById('rep-hasta')?.value;
    if (!desde || !hasta) { WMS.toast('warning', 'Seleccione fecha desde y hasta'); return; }

    const ruta = document.getElementById('rep-ruta')?.value.trim();
    const suc  = document.getElementById('rep-suc')?.value.trim();

    const params = new URLSearchParams({ fecha_desde: desde, fecha_hasta: hasta });
    if (ruta) params.set('ruta', ruta);
    if (suc)  params.set('sucursal_entrega', suc);

    try {
      const r = await API.get('/picking/reporte?' + params.toString());
      const d = r.data || r;
      this._reporteData = d.ordenes || [];
      const res = d.resumen || {};

      // KPIs
      const kpis = document.getElementById('rep-kpis');
      if (kpis) kpis.style.display = 'flex';
      const set = (id, v) => { const el = document.getElementById(id); if(el) el.textContent = v; };
      set('rep-k-total', res.total || 0);
      set('rep-k-comp',  res.completadas || 0);
      set('rep-k-falt',  res.faltantes || 0);
      set('rep-k-dur',   res.duracion_prom_min ? res.duracion_prom_min + ' min' : '—');

      // Tabla
      const tbody = document.getElementById('rep-tbody');
      const empty = document.getElementById('rep-empty');
      const tabla = document.getElementById('rep-tabla');
      const exportBtn = document.getElementById('btn-export-excel');

      if (!this._reporteData.length) {
        if (tabla) tabla.style.display = 'none';
        if (empty) { empty.style.display = 'block'; empty.textContent = 'Sin resultados para los filtros seleccionados.'; }
        if (exportBtn) exportBtn.style.display = 'none';
        return;
      }

      if (empty) empty.style.display = 'none';
      if (tabla) tabla.style.display = 'block';
      if (exportBtn) exportBtn.style.display = '';

      tbody.innerHTML = this._reporteData.map(o => `
        <tr>
          <td style="padding:8px 12px;white-space:nowrap;">${WMS.esc(o.fecha||'—')}</td>
          <td style="padding:8px 12px;font-weight:700;color:#0F4C81;">${WMS.esc(o.numero_pedido||o.numero_orden||'—')}</td>
          <td style="padding:8px 12px;">${WMS.esc(o.sucursal_entrega||o.cliente||'—')}</td>
          <td style="padding:8px 12px;">${o.ruta?`<span style="background:#dbeafe;color:#1e40af;padding:2px 7px;border-radius:3px;font-size:.72rem;">${WMS.esc(o.ruta)}</span>`:'—'}</td>
          <td style="padding:8px 12px;text-align:center;">${o.total_lineas||0}</td>
          <td style="padding:8px 12px;text-align:center;color:#059669;font-weight:700;">${o.completadas||0}</td>
          <td style="padding:8px 12px;text-align:center;color:#dc2626;font-weight:700;">${o.faltantes||0}</td>
          <td style="padding:8px 12px;text-align:center;">
            <span style="font-weight:700;${(o.pct_cumplimiento||0)>=90?'color:#059669;':(o.pct_cumplimiento||0)>=70?'color:#d97706;':'color:#dc2626;'}">${o.pct_cumplimiento||0}%</span>
          </td>
          <td style="padding:8px 12px;font-size:.78rem;">${WMS.esc(o.auxiliares||'—')}</td>
          <td style="padding:8px 12px;white-space:nowrap;">${WMS.esc(o.hora_inicio||'—')}</td>
          <td style="padding:8px 12px;white-space:nowrap;">${WMS.esc(o.hora_fin||'—')}</td>
          <td style="padding:8px 12px;text-align:center;">${o.duracion_min||'—'}</td>
        </tr>`).join('');

    } catch(e) {
      WMS.toast('error', 'Error generando reporte');
    }
  },

  async _exportarExcel(rows) {
    if (!this._reporteData?.length) { WMS.toast('warning', 'Sin datos para exportar'); return; }
    const btn = document.getElementById('btn-export-excel');
    if (btn) { btn.disabled = true; btn.textContent = 'Generando...'; }
    try {
      // Cargar SheetJS dinámicamente
      if (typeof XLSX === 'undefined') {
        await new Promise((res, rej) => {
          const s = document.createElement('script');
          s.src = 'https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js';
          s.onload = res; s.onerror = rej;
          document.head.appendChild(s);
        });
      }
      const headers = ['Fecha','N° Pedido','Sucursal Entrega','Ruta','Total Líneas','Completadas','Faltantes','% Cumplimiento','Auxiliar(es)','Hora Inicio','Hora Fin','Duración (min)'];
      const data    = this._reporteData.map(o => [
        o.fecha, o.numero_pedido||o.numero_orden, o.sucursal_entrega||o.cliente, o.ruta,
        o.total_lineas, o.completadas, o.faltantes, o.pct_cumplimiento,
        o.auxiliares, o.hora_inicio, o.hora_fin, o.duracion_min,
      ]);
      const ws = XLSX.utils.aoa_to_sheet([headers, ...data]);
      ws['!cols'] = headers.map(() => ({ wch: 18 }));
      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, 'Picking');
      const hoy = new Date().toISOString().split('T')[0];
      XLSX.writeFile(wb, `Picking_Reporte_${hoy}.xlsx`);
    } catch(e) {
      WMS.toast('error', 'Error generando Excel: ' + e.message);
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-file-excel"></i> Excel'; }
    }
  },
```

- [ ] **Step 2: Verificar en browser**

Ir a módulo Picking → Reporte.  
Verificar: pantalla inicia vacía, con fecha desde/hasta y clic en Buscar aparecen la tabla y KPIs, botón Excel descarga el `.xlsx`.

- [ ] **Step 3: Commit final**

```bash
git add public/assets/js/desktop/picking.js
git commit -m "feat: show_reporte() con filtros, KPIs y exportación Excel via SheetJS CDN"
```

---

## Verificación Final

- [ ] Abrir `http://localhost/WMS_FENIX/public` → módulo Picking

**Checklist de smoke-tests:**

| Test | Esperado |
|---|---|
| Pedidos → tabla carga hoy | Solo estado Pendiente/EnProceso |
| Pedidos → filtro Ruta | Filtra correctamente |
| Pedidos → botón `+ Ruta` en fila sin ruta | Prompt → guarda la ruta |
| Pedidos → papelera | Confirm → elimina → fila desaparece |
| Pedidos → clic en fila | Expande con detalles de productos y ambiente |
| Asignación → tabla carga | Solo pendientes de hoy sin auxiliar |
| Asignación → marcar 2+ pedidos | Drawer aparece con KPIs por ambiente |
| Asignación → Confirmar sin auxiliar Seco | Líneas sin aux permanecen pendientes |
| Asignación → Confirmar con aux completo | Toast éxito, pedidos desaparecen de lista |
| Asignación → Modo Pasillo toggle | Cambia a inputs de rangos de pasillo |
| Reporte → buscar sin fechas | Toast warning |
| Reporte → buscar con fechas | KPIs + tabla aparecen |
| Reporte → Exportar Excel | Descarga `Picking_Reporte_YYYY-MM-DD.xlsx` |
| CSV import con col SUCURSAL ENTREGA | Campo `sucursal_entrega` poblado en DB |
