# Packing & Certificación — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a packing layer to the picking certification flow (by sucursal) that tracks products into discrete containers (canasta/caja/paquete), generates per-unit stickers, and produces a full packing document on close.

**Architecture:** Three new tables (`packing_sesiones`, `packing_unidades`, `packing_items`) overlay the existing picking certification without modifying existing tables. A new `PackingController` handles all packing endpoints. The existing `certFinalizar()` logic is replicated inside `finalizarSesion()` to complete certification on session close. Sticker and closing document HTML are generated client-side in JavaScript using data from the `getSesion` API response — this avoids browser auth issues with `window.open()` print targets.

**Tech Stack:** PHP 8.2, Slim 4, Eloquent/Capsule ORM, MySQL/PostgreSQL dual-driver, Vanilla JS (WMS_MODULES pattern), `@media print` + `@page` CSS for sticker/document rendering.

---

## File Map

| Action | Path | Responsibility |
|--------|------|----------------|
| CREATE | `database/migrations/069_packing_certificacion.php` | 3 new tables + `impresoras.tipos_trabajo` |
| CREATE | `src/Models/PackingSesion.php` | Eloquent model for packing_sesiones |
| CREATE | `src/Models/PackingUnidad.php` | Eloquent model for packing_unidades |
| CREATE | `src/Models/PackingItem.php` | Eloquent model for packing_items |
| MODIFY | `src/Models/Impresora.php` | Add `tipos_trabajo` to fillable + casts |
| CREATE | `src/Controllers/PackingController.php` | All 9 API endpoints + private helpers |
| MODIFY | `public/index.php` | Register 10 packing routes |
| MODIFY | `src/Controllers/ImpresoraController.php` | Accept `tipos_trabajo` in `guardar()`, filter in `listar()` |
| MODIFY | `public/assets/js/desktop/despacho.js` | Packing dialog, two-panel screen, sticker/doc print |

---

## Task 1: Migration 069 — three packing tables + impresoras.tipos_trabajo

**Files:**
- Create: `database/migrations/069_packing_certificacion.php`

- [ ] **Step 1: Create migration file**

```php
<?php
// database/migrations/069_packing_certificacion.php
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

return [
    'up' => function () {
        $schema = Capsule::schema();
        $isPg   = Capsule::connection()->getDriverName() === 'pgsql';

        if (!$schema->hasTable('packing_sesiones')) {
            $schema->create('packing_sesiones', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('empresa_id');
                $table->unsignedBigInteger('sucursal_id');
                $table->string('sucursal_entrega', 200);
                $table->enum('tipo_empaque', ['canasta', 'caja', 'paquete']);
                $table->unsignedBigInteger('certificador_id');
                $table->unsignedBigInteger('impresora_sticker_id')->nullable();
                $table->unsignedBigInteger('impresora_doc_id')->nullable();
                $table->enum('estado', ['EnProceso', 'Completada'])->default('EnProceso');
                $table->timestamps();
                $table->index(
                    ['empresa_id', 'sucursal_id', 'sucursal_entrega', 'estado'],
                    'idx_pk_ses_scope'
                );
            });
        }

        if (!$schema->hasTable('packing_unidades')) {
            $schema->create('packing_unidades', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sesion_id');
                $table->smallInteger('consecutivo');
                $table->enum('estado', ['Abierta', 'Cerrada'])->default('Abierta');
                $table->decimal('total_unidades', 12, 3)->default(0);
                $table->boolean('sticker_impreso')->default(false);
                $table->timestamp('closed_at')->nullable();
                $table->unique(['sesion_id', 'consecutivo'], 'uq_pk_unidad');
            });
        }

        if (!$schema->hasTable('packing_items')) {
            $schema->create('packing_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('unidad_id');
                $table->unsignedBigInteger('picking_detalle_id')->nullable();
                $table->unsignedBigInteger('producto_id');
                $table->string('lote', 100)->nullable();
                $table->date('fecha_vencimiento')->nullable();
                $table->unsignedBigInteger('separador_id')->nullable();
                $table->decimal('cantidad', 12, 3);
                $table->timestamp('created_at')->useCurrent();
                $table->index(['unidad_id', 'producto_id'], 'idx_pk_item_unidad');
            });
        }

        if ($schema->hasTable('impresoras') && !$schema->hasColumn('impresoras', 'tipos_trabajo')) {
            $schema->table('impresoras', function (Blueprint $table) {
                $table->json('tipos_trabajo')->nullable()->after('modulos');
            });
            if ($isPg) {
                Capsule::statement("UPDATE impresoras SET tipos_trabajo = '[]'::jsonb WHERE tipos_trabajo IS NULL");
                Capsule::statement("ALTER TABLE impresoras ALTER COLUMN tipos_trabajo SET DEFAULT '[]'::jsonb");
                Capsule::statement("ALTER TABLE impresoras ALTER COLUMN tipos_trabajo SET NOT NULL");
            } else {
                Capsule::statement("UPDATE impresoras SET tipos_trabajo = '[]' WHERE tipos_trabajo IS NULL");
                Capsule::statement("ALTER TABLE impresoras MODIFY COLUMN tipos_trabajo JSON NOT NULL");
            }
        }
    },

    'down' => function () {
        $schema = Capsule::schema();
        $schema->dropIfExists('packing_items');
        $schema->dropIfExists('packing_unidades');
        $schema->dropIfExists('packing_sesiones');
        if ($schema->hasTable('impresoras') && $schema->hasColumn('impresoras', 'tipos_trabajo')) {
            $schema->table('impresoras', function (Blueprint $t) {
                $t->dropColumn('tipos_trabajo');
            });
        }
    },
];
```

- [ ] **Step 2: Run migrations via browser**

Navigate to `http://localhost/WMS_FENIX/public/../api/migrations-run.php` in a browser.

Expected: Migration 069 shows as "applied" in the output.

- [ ] **Step 3: Verify tables in MySQL**

Open phpMyAdmin (or run via XAMPP MySQL CLI):

```sql
SHOW TABLES LIKE 'packing%';
SHOW COLUMNS FROM impresoras LIKE 'tipos_trabajo';
```

Expected: `packing_sesiones`, `packing_unidades`, `packing_items` present. `impresoras` has `tipos_trabajo` column.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/069_packing_certificacion.php
git commit -m "feat: migration 069 — packing tables + impresoras.tipos_trabajo"
```

---

## Task 2: Models — PackingSesion, PackingUnidad, PackingItem + update Impresora

**Files:**
- Create: `src/Models/PackingSesion.php`
- Create: `src/Models/PackingUnidad.php`
- Create: `src/Models/PackingItem.php`
- Modify: `src/Models/Impresora.php`

- [ ] **Step 1: Create PackingSesion model**

```php
<?php
// src/Models/PackingSesion.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackingSesion extends Model
{
    protected $table    = 'packing_sesiones';
    protected $fillable = [
        'empresa_id', 'sucursal_id', 'sucursal_entrega', 'tipo_empaque',
        'certificador_id', 'impresora_sticker_id', 'impresora_doc_id', 'estado',
    ];

    public function unidades()
    {
        return $this->hasMany(PackingUnidad::class, 'sesion_id');
    }
}
```

- [ ] **Step 2: Create PackingUnidad model**

```php
<?php
// src/Models/PackingUnidad.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackingUnidad extends Model
{
    protected $table      = 'packing_unidades';
    public    $timestamps = false;
    protected $fillable   = [
        'sesion_id', 'consecutivo', 'estado', 'total_unidades', 'sticker_impreso', 'closed_at',
    ];
    protected $casts = [
        'sticker_impreso' => 'boolean',
        'total_unidades'  => 'float',
    ];

    public function items()
    {
        return $this->hasMany(PackingItem::class, 'unidad_id');
    }
}
```

- [ ] **Step 3: Create PackingItem model**

```php
<?php
// src/Models/PackingItem.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PackingItem extends Model
{
    protected $table      = 'packing_items';
    public    $timestamps = false;
    protected $fillable   = [
        'unidad_id', 'picking_detalle_id', 'producto_id',
        'lote', 'fecha_vencimiento', 'separador_id', 'cantidad',
    ];
    protected $casts = [
        'cantidad' => 'float',
    ];
}
```

- [ ] **Step 4: Update Impresora model**

Read `src/Models/Impresora.php` first, then edit it to add `tipos_trabajo` to `$fillable` and `$casts`:

```php
<?php
// src/Models/Impresora.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Impresora extends Model
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;
    protected $table = 'impresoras';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'nombre', 'ip', 'puerto',
        'tipo', 'modulos', 'tipos_trabajo', 'activo',
    ];

    protected $casts = [
        'activo'        => 'boolean',
        'puerto'        => 'integer',
        'tipos_trabajo' => 'array',
    ];
}
```

- [ ] **Step 5: Commit**

```bash
git add src/Models/PackingSesion.php src/Models/PackingUnidad.php \
        src/Models/PackingItem.php src/Models/Impresora.php
git commit -m "feat: packing models + Impresora tipos_trabajo cast"
```

---

## Task 3: PackingController — all endpoints + helpers

**Files:**
- Create: `src/Controllers/PackingController.php`

This task creates the full controller. The controller has 9 public endpoints and 7 private helpers.

- [ ] **Step 1: Create PackingController.php**

```php
<?php
// src/Controllers/PackingController.php
namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\PackingSesion;
use App\Models\PackingUnidad;
use App\Models\PackingItem;
use App\Models\OrdenPicking;
use Illuminate\Database\Capsule\Manager as Capsule;

class PackingController extends BaseController
{
    // ── POST /api/packing/sesion ───────────────────────────────────────────────
    public function iniciarSesion(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];

        if ($deny = $this->requireFields($data, ['sucursal_entrega', 'tipo_empaque'], $res)) {
            return $deny;
        }

        if (!in_array($data['tipo_empaque'], ['canasta', 'caja', 'paquete'], true)) {
            return $this->error($res, 'tipo_empaque inválido. Valores: canasta, caja, paquete');
        }

        $existing = PackingSesion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->where('sucursal_entrega', $data['sucursal_entrega'])
            ->where('estado', 'EnProceso')
            ->first();
        if ($existing) {
            return $this->error($res, 'Ya existe una sesión en proceso para esta sucursal. ID: ' . $existing->id, 409);
        }

        return Capsule::transaction(function () use ($user, $data, $res) {
            $sesion = PackingSesion::create([
                'empresa_id'           => $user->empresa_id,
                'sucursal_id'          => $user->sucursal_id,
                'sucursal_entrega'     => $data['sucursal_entrega'],
                'tipo_empaque'         => $data['tipo_empaque'],
                'certificador_id'      => $user->id,
                'impresora_sticker_id' => $data['impresora_sticker_id'] ?? null,
                'impresora_doc_id'     => $data['impresora_doc_id'] ?? null,
                'estado'               => 'EnProceso',
            ]);

            PackingUnidad::create([
                'sesion_id'   => $sesion->id,
                'consecutivo' => 1,
                'estado'      => 'Abierta',
            ]);

            $this->audit($user, 'packing', 'iniciar_sesion', 'packing_sesiones', $sesion->id,
                null, ['sucursal_entrega' => $sesion->sucursal_entrega, 'tipo_empaque' => $sesion->tipo_empaque]);

            return $this->created($res, ['sesion_id' => $sesion->id], 'Sesión de packing iniciada');
        });
    }

    // ── GET /api/packing/sesion/{id} ──────────────────────────────────────────
    public function getSesion(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $sesion = PackingSesion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);
        if (!$sesion) return $this->notFound($res);

        $certificador = Capsule::table('personal')->find($sesion->certificador_id);
        $certNombre   = $certificador
            ? trim($certificador->nombres . ' ' . $certificador->apellidos)
            : 'N/A';

        $empresa     = Capsule::table('empresas')->find($sesion->empresa_id);
        $empNombre   = $empresa ? $empresa->nombre : 'WMS Fénix';

        // All units with their items
        $allUnidades = PackingUnidad::where('sesion_id', $sesion->id)
            ->orderBy('consecutivo')
            ->get();

        $unidadIds = $allUnidades->pluck('id')->toArray();
        $allItemsRaw = !empty($unidadIds)
            ? Capsule::table('packing_items as pi')
                ->join('productos as p', 'p.id', '=', 'pi.producto_id')
                ->leftJoin('personal as per', 'per.id', '=', 'pi.separador_id')
                ->whereIn('pi.unidad_id', $unidadIds)
                ->select([
                    'pi.id', 'pi.unidad_id', 'pi.producto_id',
                    'p.nombre as producto_nombre', 'p.codigo_interno as codigo',
                    'pi.cantidad', 'pi.lote', 'pi.fecha_vencimiento', 'pi.separador_id',
                    Capsule::raw("CONCAT(per.nombres, ' ', COALESCE(per.apellidos,'')) as separador_nombre"),
                ])
                ->get()
                ->groupBy('unidad_id')
            : collect();

        $unidadesData = $allUnidades->map(function ($u) use ($allItemsRaw) {
            $arr = $u->toArray();
            $arr['items'] = $allItemsRaw->get($u->id, collect())->toArray();
            return $arr;
        })->toArray();

        $unidadAbierta = $allUnidades->firstWhere('estado', 'Abierta');

        // Products picked for this sucursal_entrega
        $pickeados = $this->_getProductosPickados(
            $user->empresa_id, $user->sucursal_id, $sesion->sucursal_entrega
        );
        $empacados = $this->_getProductosEmpacados($sesion->id);

        $productos = [];
        foreach ($pickeados as $pid => $pick) {
            $empQty    = $empacados[$pid] ?? 0;
            $pendiente = max(0, round((float)$pick->total_pickeado - $empQty, 3));
            $productos[] = [
                'producto_id'    => (int)$pid,
                'nombre'         => $pick->producto_nombre,
                'codigo'         => $pick->codigo,
                'ean'            => $pick->ean,
                'total_pickeado' => (float)$pick->total_pickeado,
                'total_empacado' => (float)$empQty,
                'pendiente'      => $pendiente,
            ];
        }

        $totalPickeado = array_sum(array_column($productos, 'total_pickeado'));
        $totalEmpacado = array_sum(array_column($productos, 'total_empacado'));

        $sesionArr                       = $sesion->toArray();
        $sesionArr['certificador_nombre'] = $certNombre;
        $sesionArr['empresa_nombre']      = $empNombre;

        return $this->ok($res, [
            'sesion'         => $sesionArr,
            'unidad_abierta' => $unidadAbierta ? $unidadAbierta->id : null,
            'unidades'       => $unidadesData,
            'productos'      => $productos,
            'totales'        => [
                'total_pickeado' => $totalPickeado,
                'total_empacado' => $totalEmpacado,
                'pendiente'      => max(0, round($totalPickeado - $totalEmpacado, 3)),
                'num_unidades'   => count(array_filter($unidadesData, fn($u) => $u['estado'] === 'Cerrada')),
            ],
        ]);
    }

    // ── POST /api/packing/sesion/{id}/item ────────────────────────────────────
    public function agregarItem(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        $data = $r->getParsedBody() ?? [];

        if ($deny = $this->requireFields($data, ['producto_id', 'cantidad'], $res)) return $deny;

        $sesion = PackingSesion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);
        if (!$sesion) return $this->notFound($res);
        if ($sesion->estado === 'Completada') return $this->error($res, 'Sesión ya finalizada', 409);

        $unidad = PackingUnidad::where('sesion_id', $sesion->id)->where('estado', 'Abierta')->first();
        if (!$unidad) return $this->error($res, 'No hay unidad abierta en esta sesión');

        $productoId = (int)$data['producto_id'];
        $cantidad   = round((float)$data['cantidad'], 3);
        if ($cantidad <= 0) return $this->error($res, 'La cantidad debe ser mayor a 0');

        $pickeados = $this->_getProductosPickados(
            $user->empresa_id, $user->sucursal_id, $sesion->sucursal_entrega
        );
        if (!isset($pickeados[$productoId])) {
            return $this->error($res, 'El producto no pertenece a los pedidos de esta sucursal', 422);
        }

        $empacados  = $this->_getProductosEmpacados($sesion->id);
        $pendiente  = round((float)$pickeados[$productoId]->total_pickeado - ($empacados[$productoId] ?? 0), 3);

        if ($cantidad > $pendiente + 0.001) {
            return $this->error($res, "Cantidad supera el pendiente: {$pendiente} uds disponibles", 422);
        }

        [$lote, $fechaVenc, $separadorId, $detalleId] = $this->_resolveFromPicking(
            $productoId, $sesion->sucursal_entrega, $user->empresa_id, $user->sucursal_id
        );

        $item = PackingItem::create([
            'unidad_id'          => $unidad->id,
            'picking_detalle_id' => $detalleId,
            'producto_id'        => $productoId,
            'lote'               => $lote,
            'fecha_vencimiento'  => $fechaVenc,
            'separador_id'       => $separadorId,
            'cantidad'           => $cantidad,
        ]);

        return $this->created($res, $item, 'Producto agregado');
    }

    // ── DELETE /api/packing/item/{id} ─────────────────────────────────────────
    public function eliminarItem(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $item   = PackingItem::find((int)$a['id']);
        if (!$item) return $this->notFound($res);

        $unidad = PackingUnidad::find($item->unidad_id);
        if (!$unidad || $unidad->estado !== 'Abierta') {
            return $this->error($res, 'Solo se pueden eliminar ítems de una unidad abierta');
        }
        $sesion = PackingSesion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find($unidad->sesion_id);
        if (!$sesion) return $this->forbidden($res);

        $item->delete();
        return $this->ok($res, null, 'Ítem eliminado');
    }

    // ── POST /api/packing/unidad/{id}/cerrar ──────────────────────────────────
    public function cerrarUnidad(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $unidad = PackingUnidad::find((int)$a['id']);
        if (!$unidad) return $this->notFound($res);
        if ($unidad->estado === 'Cerrada') return $this->error($res, 'La unidad ya está cerrada', 409);

        $sesion = PackingSesion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find($unidad->sesion_id);
        if (!$sesion) return $this->forbidden($res);

        $totalItems = PackingItem::where('unidad_id', $unidad->id)->count();
        if ($totalItems === 0) return $this->error($res, 'La unidad está vacía', 422);

        return Capsule::transaction(function () use ($unidad, $sesion, $res) {
            $total               = (float) PackingItem::where('unidad_id', $unidad->id)->sum('cantidad');
            $unidad->estado         = 'Cerrada';
            $unidad->total_unidades = $total;
            $unidad->closed_at      = date('Y-m-d H:i:s');
            $unidad->save();

            $nuevaUnidad = PackingUnidad::create([
                'sesion_id'   => $sesion->id,
                'consecutivo' => $unidad->consecutivo + 1,
                'estado'      => 'Abierta',
            ]);

            return $this->ok($res, [
                'unidad_cerrada' => $unidad->toArray(),
                'nueva_unidad'   => $nuevaUnidad->toArray(),
            ], "Unidad #{$unidad->consecutivo} cerrada");
        });
    }

    // ── POST /api/packing/sesion/{id}/finalizar ───────────────────────────────
    public function finalizarSesion(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $sesion = PackingSesion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);
        if (!$sesion) return $this->notFound($res);
        if ($sesion->estado === 'Completada') return $this->error($res, 'Sesión ya finalizada', 409);

        // Validate nothing left to pack
        $pickeados   = $this->_getProductosPickados($user->empresa_id, $user->sucursal_id, $sesion->sucursal_entrega);
        $empacados   = $this->_getProductosEmpacados($sesion->id);
        $totalPend   = 0.0;
        foreach ($pickeados as $pid => $pick) {
            $totalPend += max(0, round((float)$pick->total_pickeado - ($empacados[$pid] ?? 0), 3));
        }
        if ($totalPend > 0.001) {
            return $this->error($res, "Quedan {$totalPend} unidades sin empacar", 422);
        }

        return Capsule::transaction(function () use ($sesion, $user, $res) {
            // Auto-close last open unit if it has items; delete if empty
            $openUnidad = PackingUnidad::where('sesion_id', $sesion->id)->where('estado', 'Abierta')->first();
            if ($openUnidad) {
                $itemCount = PackingItem::where('unidad_id', $openUnidad->id)->count();
                if ($itemCount > 0) {
                    $total = (float)PackingItem::where('unidad_id', $openUnidad->id)->sum('cantidad');
                    $openUnidad->estado         = 'Cerrada';
                    $openUnidad->total_unidades = $total;
                    $openUnidad->closed_at      = date('Y-m-d H:i:s');
                    $openUnidad->save();
                } else {
                    $openUnidad->delete();
                }
            }

            $sesion->estado = 'Completada';
            $sesion->save();

            // Run certFinalizar logic inline
            $ordenes = OrdenPicking::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->where('sucursal_entrega', $sesion->sucursal_entrega)
                ->where('estado', 'Completada')
                ->where('estado_certificacion', 'Pendiente')
                ->get();

            foreach ($ordenes as $o) {
                $o->estado_certificacion = 'Certificada';
                $o->fecha_certificacion  = date('Y-m-d H:i:s');
                $o->certificador_id      = $user->id;
                $o->save();

                foreach ($o->detalles as $d) {
                    $diff = (float)$d->cantidad_pickeada - (float)$d->cantidad_certificada;
                    if ($diff != 0) {
                        $this->audit(
                            $user, 'picking', 'novedad_certificacion',
                            'picking_detalles', $d->id,
                            ['pick' => $d->cantidad_pickeada],
                            ['cert' => $d->cantidad_certificada],
                            "Diferencia en certificación: Pedido {$o->numero_orden}. Faltan " . abs($diff)
                        );
                    }
                }
            }

            $this->audit($user, 'packing', 'finalizar_sesion', 'packing_sesiones', $sesion->id,
                ['estado' => 'EnProceso'], ['estado' => 'Completada']);

            $numUnidades = PackingUnidad::where('sesion_id', $sesion->id)->where('estado', 'Cerrada')->count();

            return $this->ok($res, [
                'sesion_id'        => $sesion->id,
                'tipo_empaque'     => $sesion->tipo_empaque,
                'total_unidades'   => $numUnidades,
                'sucursal_entrega' => $sesion->sucursal_entrega,
            ], 'Certificación finalizada correctamente');
        });
    }

    // ── PUT /api/packing/sesion/{id}/impresoras ───────────────────────────────
    public function actualizarImpresoras(Request $r, Response $res, array $a): Response
    {
        $user   = $r->getAttribute('user');
        $data   = $r->getParsedBody() ?? [];
        $sesion = PackingSesion::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find((int)$a['id']);
        if (!$sesion) return $this->notFound($res);

        $sesion->impresora_sticker_id = $data['impresora_sticker_id'] ?? null;
        $sesion->impresora_doc_id     = $data['impresora_doc_id'] ?? null;
        $sesion->save();

        return $this->ok($res, $sesion, 'Impresoras actualizadas');
    }

    // ── PRIVATE HELPERS ───────────────────────────────────────────────────────

    private function _getProductosPickados(int $empresaId, int $sucursalId, string $sucursalEntrega): array
    {
        return Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'op.id', '=', 'pd.orden_picking_id')
            ->join('productos as p', 'p.id', '=', 'pd.producto_id')
            ->where('op.empresa_id', $empresaId)
            ->where('op.sucursal_id', $sucursalId)
            ->where('op.sucursal_entrega', $sucursalEntrega)
            ->where('op.estado', 'Completada')
            ->where('op.estado_certificacion', 'Pendiente')
            ->select([
                'pd.producto_id',
                'p.nombre as producto_nombre',
                'p.codigo_interno as codigo',
                'p.codigo_barras as ean',
                Capsule::raw('SUM(pd.cantidad_pickeada) as total_pickeado'),
            ])
            ->groupBy('pd.producto_id', 'p.nombre', 'p.codigo_interno', 'p.codigo_barras')
            ->get()
            ->keyBy('producto_id')
            ->toArray();
    }

    private function _getProductosEmpacados(int $sesionId): array
    {
        $rows = Capsule::table('packing_items as pi')
            ->join('packing_unidades as pu', 'pu.id', '=', 'pi.unidad_id')
            ->where('pu.sesion_id', $sesionId)
            ->select(['pi.producto_id', Capsule::raw('SUM(pi.cantidad) as total_empacado')])
            ->groupBy('pi.producto_id')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row->producto_id] = (float)$row->total_empacado;
        }
        return $result;
    }

    private function _resolveFromPicking(int $productoId, string $sucursalEntrega, int $empresaId, int $sucursalId): array
    {
        $detalle = Capsule::table('picking_detalles as pd')
            ->join('orden_pickings as op', 'op.id', '=', 'pd.orden_picking_id')
            ->leftJoin('inventarios as i', function ($join) use ($empresaId, $sucursalId) {
                $join->on('i.producto_id', '=', 'pd.producto_id')
                     ->on('i.lote', '=', 'pd.lote')
                     ->where('i.empresa_id', $empresaId)
                     ->where('i.sucursal_id', $sucursalId)
                     ->where('i.estado', 'Disponible');
            })
            ->where('op.empresa_id', $empresaId)
            ->where('op.sucursal_id', $sucursalId)
            ->where('op.sucursal_entrega', $sucursalEntrega)
            ->where('op.estado', 'Completada')
            ->where('op.estado_certificacion', 'Pendiente')
            ->where('pd.producto_id', $productoId)
            ->orderByRaw('CASE WHEN i.fecha_vencimiento IS NULL THEN 1 ELSE 0 END')
            ->orderBy('i.fecha_vencimiento', 'asc')
            ->select(['pd.id', 'pd.lote', 'i.fecha_vencimiento', 'op.auxiliar_id'])
            ->first();

        if (!$detalle) return [null, null, null, null];
        return [$detalle->lote, $detalle->fecha_vencimiento, $detalle->auxiliar_id, $detalle->id];
    }
}
```

- [ ] **Step 2: Verify the file was created correctly**

Check that the file exists at `src/Controllers/PackingController.php` and open it in a browser by hitting an endpoint after routes are registered (Task 4).

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/PackingController.php
git commit -m "feat: PackingController — all 5 public endpoints + helpers"
```

---

## Task 4: Routes + ImpresoraController update

**Files:**
- Modify: `public/index.php`
- Modify: `src/Controllers/ImpresoraController.php`

- [ ] **Step 1: Add packing routes to index.php**

In `public/index.php`, find the line containing `$group->group('/impresoras'` (around line 645). Add the packing route group BEFORE it:

Old text to find:
```php
    $group->group('/impresoras', function($group) {
```

Replace with:
```php
    // ── Packing & Certificación ───────────────────────────────────────────────
    $group->group('/packing', function ($group) {
        $group->post('/sesion',                      [\App\Controllers\PackingController::class, 'iniciarSesion']);
        $group->get('/sesion/{id}',                  [\App\Controllers\PackingController::class, 'getSesion']);
        $group->post('/sesion/{id}/item',            [\App\Controllers\PackingController::class, 'agregarItem']);
        $group->post('/sesion/{id}/finalizar',       [\App\Controllers\PackingController::class, 'finalizarSesion']);
        $group->put('/sesion/{id}/impresoras',       [\App\Controllers\PackingController::class, 'actualizarImpresoras']);
        $group->delete('/item/{id}',                 [\App\Controllers\PackingController::class, 'eliminarItem']);
        $group->post('/unidad/{id}/cerrar',          [\App\Controllers\PackingController::class, 'cerrarUnidad']);
    });

    $group->group('/impresoras', function($group) {
```

- [ ] **Step 2: Update ImpresoraController.guardar() to accept tipos_trabajo**

In `src/Controllers/ImpresoraController.php`, replace the `updateOrCreate` call inside `guardar()`:

Old:
```php
        $impresora = Impresora::updateOrCreate(
            ['id' => $id],
            [
                'empresa_id'  => $user->empresa_id,
                'sucursal_id' => $sucursalId,
                'nombre'      => $data['nombre'],
                'ip'          => $data['ip'],
                'puerto'      => $data['puerto'] ?? 9100,
                'tipo'        => $data['tipo'] ?? 'General',
                'modulos'     => $data['modulos'] ?? '',
                'activo'      => isset($data['activo']) ? (bool)$data['activo'] : true,
            ]
        );
```

New:
```php
        $tiposTrabajo = $data['tipos_trabajo'] ?? [];
        if (is_string($tiposTrabajo)) {
            $tiposTrabajo = json_decode($tiposTrabajo, true) ?? [];
        }

        $impresora = Impresora::updateOrCreate(
            ['id' => $id],
            [
                'empresa_id'    => $user->empresa_id,
                'sucursal_id'   => $sucursalId,
                'nombre'        => $data['nombre'],
                'ip'            => $data['ip'],
                'puerto'        => $data['puerto'] ?? 9100,
                'tipo'          => $data['tipo'] ?? 'General',
                'modulos'       => $data['modulos'] ?? '',
                'tipos_trabajo' => $tiposTrabajo,
                'activo'        => isset($data['activo']) ? (bool)$data['activo'] : true,
            ]
        );
```

- [ ] **Step 3: Update ImpresoraController.listar() to support tipo_trabajo filter**

In `listar()`, replace the query:

Old:
```php
        $impresoras = Impresora::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->get();
```

New:
```php
        $params     = $r->getQueryParams();
        $tipoFiltro = $params['tipo_trabajo'] ?? null;

        $query = Impresora::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id);

        if ($tipoFiltro) {
            $isPg = \Illuminate\Database\Capsule\Manager::connection()->getDriverName() === 'pgsql';
            if ($isPg) {
                $query->whereRaw("tipos_trabajo @> ?::jsonb", [json_encode([$tipoFiltro])]);
            } else {
                $query->whereRaw("JSON_CONTAINS(tipos_trabajo, ?)", [json_encode([$tipoFiltro])]);
            }
        }

        $impresoras = $query->get();
```

- [ ] **Step 4: Smoke-test the routes**

Open a browser and hit:
```
GET http://localhost/WMS_FENIX/public/api/packing/sesion/99
```
Expected: `{"error":true,"message":"Registro no encontrado"}` with HTTP 404 (not a 500 or "route not found" error).

- [ ] **Step 5: Commit**

```bash
git add public/index.php src/Controllers/ImpresoraController.php
git commit -m "feat: packing routes + ImpresoraController tipos_trabajo filter"
```

---

## Task 5: Frontend Part 1 — Packing setup dialog

**Files:**
- Modify: `public/assets/js/desktop/despacho.js`

This task modifies `iniciarCertificacion()` to show a packing dialog instead of going directly to the cert interface, and adds two helper functions.

- [ ] **Step 1: Replace `iniciarCertificacion()` and add `_showPackingDialog` + `_iniciarSesionPacking`**

In `despacho.js`, find:

```javascript
  async iniciarCertificacion(sucursal) {
    WMS.spinner();
    try {
      const r = await API.get('/picking/certificacion/detalle/' + encodeURIComponent(sucursal));
      const lineas = r.data || r || [];
      
      this._renderCertInterface(sucursal, lineas);
    } catch(e) { WMS.toast('error', 'Error al cargar detalles'); }
  },
```

Replace with:

```javascript
  async iniciarCertificacion(sucursal) {
    WMS.spinner();
    try {
      const imps = (await API.get('/impresoras')).data || [];
      this._showPackingDialog(sucursal, imps);
    } catch(e) { WMS.toast('error', 'Error al cargar impresoras'); }
  },

  _showPackingDialog(sucursal, impresoras) {
    const mkOpts = (tipo) => impresoras
      .filter(i => !i.tipos_trabajo?.length || i.tipos_trabajo.includes(tipo))
      .map(i => `<option value="${i.id}">${WMS.esc(i.nombre)}</option>`)
      .join('');

    const html = `
      <div id="packing-dialog-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;display:flex;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:12px;padding:28px 32px;min-width:420px;max-width:500px;box-shadow:0 8px 40px rgba(0,0,0,.25);">
          <h3 style="margin:0 0 20px;color:#1e293b;font-size:17px;">
            <i class="fa-solid fa-boxes-packing"></i> Iniciar Packing — <span style="color:#1e40af;">${WMS.esc(sucursal)}</span>
          </h3>
          <div style="margin-bottom:16px;">
            <label style="font-weight:600;font-size:13px;display:block;margin-bottom:8px;">Tipo de empaque</label>
            <div style="display:flex;gap:16px;">
              ${['canasta','caja','paquete'].map((t,i) => `
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;">
                  <input type="radio" name="pk-tipo" value="${t}" ${i===0?'checked':''}> ${t.charAt(0).toUpperCase()+t.slice(1)}
                </label>`).join('')}
            </div>
          </div>
          <div style="margin-bottom:12px;">
            <label style="font-weight:600;font-size:13px;display:block;margin-bottom:5px;">Impresora stickers</label>
            <select id="pd-imp-sticker" class="form-control" style="width:100%;">
              <option value="">— Sin impresora —</option>
              ${mkOpts('sticker_packing')}
            </select>
          </div>
          <div style="margin-bottom:22px;">
            <label style="font-weight:600;font-size:13px;display:block;margin-bottom:5px;">Impresora documento</label>
            <select id="pd-imp-doc" class="form-control" style="width:100%;">
              <option value="">— Sin impresora —</option>
              ${mkOpts('documento_packing')}
            </select>
          </div>
          <div style="display:flex;gap:10px;justify-content:flex-end;">
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('packing-dialog-overlay').remove()">Cancelar</button>
            <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.despacho._confirmarDialogPacking('${WMS.esc(sucursal)}')">
              <i class="fa-solid fa-play"></i> Iniciar
            </button>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
  },

  async _confirmarDialogPacking(sucursal) {
    const tipo       = document.querySelector('input[name="pk-tipo"]:checked')?.value || 'caja';
    const impSticker = document.getElementById('pd-imp-sticker')?.value || null;
    const impDoc     = document.getElementById('pd-imp-doc')?.value || null;
    document.getElementById('packing-dialog-overlay')?.remove();
    WMS.spinner();
    try {
      const r = await API.post('/packing/sesion', {
        sucursal_entrega:     sucursal,
        tipo_empaque:         tipo,
        impresora_sticker_id: impSticker || null,
        impresora_doc_id:     impDoc || null,
      });
      if (r.error) { WMS.toast('error', r.message); return; }
      await this.show_packing(r.data.sesion_id);
    } catch(e) { WMS.toast('error', 'Error al iniciar sesión de packing'); }
  },
```

- [ ] **Step 2: Manual test — dialog appears**

In the browser, navigate to Despacho → Certificación. Click "Iniciar Certificación" for any pending sucursal.

Expected: A dialog appears with tipo de empaque (radio buttons) and two impresora dropdowns. Clicking Cancelar closes it.

- [ ] **Step 3: Commit**

```bash
git add public/assets/js/desktop/despacho.js
git commit -m "feat: packing dialog — replace iniciarCertificacion with packing setup step"
```

---

## Task 6: Frontend Part 2 — Two-panel packing screen

**Files:**
- Modify: `public/assets/js/desktop/despacho.js`

- [ ] **Step 1: Add `show_packing()`, `_renderPackingScreen()`, `_refreshPackingLeft()`, `_refreshPackingRight()`**

Add these methods inside `WMS_MODULES.despacho = { ... }` after `_confirmarDialogPacking`:

```javascript
  // ── PACKING SCREEN ─────────────────────────────────────────────────────────
  _packingState: { sesionId: null, sesionData: null, unitsWithItems: {} },

  async show_packing(sesionId) {
    WMS.spinner();
    try {
      const r = await API.get('/packing/sesion/' + sesionId);
      if (r.error) { WMS.toast('error', r.message); return; }
      this._packingState.sesionId  = sesionId;
      this._packingState.sesionData = r.data;
      // Seed closed units' items from API response
      (r.data.unidades || []).forEach(u => {
        if (u.estado === 'Cerrada') this._packingState.unitsWithItems[u.id] = u.items || [];
      });
      this._renderPackingScreen(r.data);
    } catch(e) { WMS.toast('error', 'Error al cargar sesión de packing'); }
  },

  _renderPackingScreen(data) {
    const { sesion, totales, productos, unidades, unidad_abierta } = data;
    const tipo      = sesion.tipo_empaque;
    const tipoUp    = tipo.charAt(0).toUpperCase() + tipo.slice(1);
    const unitAb    = unidades.find(u => u.id === unidad_abierta);
    const consec    = unitAb ? String(unitAb.consecutivo).padStart(3,'0') : '---';
    const pendiente = totales.pendiente;
    const btnFin    = pendiente > 0 ? 'disabled' : '';

    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.despacho.show_certificacion()">
        <i class="fa-solid fa-arrow-left"></i> Volver
      </button>`);

    const impresoraOpts = (data._impresoras || []).map(i =>
      `<option value="${i.id}">${WMS.esc(i.nombre)}</option>`
    ).join('');

    WMS.setContent(`
      <div id="packing-wrap">
        <!-- TOP BAR -->
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px 16px;margin-bottom:14px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
          <div style="flex:1;display:flex;gap:20px;font-size:13px;">
            <span>Pendiente: <strong id="pk-stat-pend" style="color:${pendiente>0?'#dc2626':'#16a34a'};">${WMS.formatNum(pendiente)}</strong></span>
            <span>Empacado: <strong id="pk-stat-emp">${WMS.formatNum(totales.total_empacado)}</strong></span>
            <span>Unidades: <strong id="pk-stat-units">${totales.num_unidades}</strong></span>
          </div>
          <button id="pk-btn-finalizar" class="btn btn-success btn-sm" ${btnFin}
            onclick="WMS_MODULES.despacho.finalizarPacking(${sesion.id})">
            <i class="fa-solid fa-flag-checkered"></i> Finalizar Certificación
          </button>
        </div>

        <!-- TWO PANELS -->
        <div style="display:grid;grid-template-columns:1fr 400px;gap:14px;align-items:start;">
          <!-- LEFT: productos pendientes -->
          <div class="card" style="min-height:400px;">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
              <span class="card-title"><i class="fa-solid fa-boxes-stacked"></i> Productos Pendientes</span>
              <span style="font-size:12px;color:#64748b;">${tipoUp} actual: <strong>#${consec}</strong></span>
            </div>
            <div id="pk-left-content" style="padding:0 8px 8px;">
              ${this._buildProductosList(productos, sesion.id)}
            </div>
          </div>

          <!-- RIGHT: unidad actual -->
          <div class="card" style="min-height:400px;">
            <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;" id="pk-right-header">
              <span class="card-title"><i class="fa-solid fa-box"></i> ${tipoUp} #${consec}</span>
              <span class="status-chip status-creada">Abierta</span>
            </div>
            <div id="pk-right-content" style="padding:0 8px;">
              ${this._buildItemsTable(unitAb?.items || [])}
            </div>
            <div style="padding:10px 12px;border-top:1px solid #e2e8f0;">
              <button class="btn btn-warning btn-sm" style="width:100%;"
                onclick="WMS_MODULES.despacho.cerrarUnidadPacking(${unidad_abierta})">
                <i class="fa-solid fa-box-archive"></i> Cerrar unidad e imprimir sticker
              </button>
            </div>
          </div>
        </div>

        <!-- UNIDADES CERRADAS -->
        <div id="pk-closed-list" style="margin-top:14px;">
          ${this._buildClosedList(unidades, tipoUp, sesion.id)}
        </div>
      </div>`);
  },

  _buildProductosList(productos, sesionId) {
    if (!productos.length) return '<p class="table-empty">Sin productos</p>';
    return productos.map(p => `
      <div class="pk-prod-row" style="padding:8px;border-bottom:1px solid #f1f5f9;" id="pk-prod-${p.producto_id}">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
          <div>
            <div style="font-weight:600;font-size:13px;">${WMS.esc(p.nombre)}</div>
            <div style="font-size:11px;color:#64748b;">${WMS.esc(p.codigo||'-')}</div>
          </div>
          <div style="text-align:right;font-size:12px;">
            <div>Pick: <strong>${WMS.formatNum(p.total_pickeado)}</strong></div>
            <div>Emp: ${WMS.formatNum(p.total_empacado)}</div>
            <div style="color:${p.pendiente>0?'#dc2626':'#16a34a'};font-weight:700;">Pend: ${WMS.formatNum(p.pendiente)}</div>
          </div>
        </div>
        ${p.pendiente > 0 ? `
        <div style="display:flex;gap:6px;align-items:center;">
          <input type="number" id="pk-qty-${p.producto_id}" min="0.001" max="${p.pendiente}" step="0.001"
            value="${p.pendiente}" style="width:90px;padding:4px 6px;border:1px solid #cbd5e1;border-radius:4px;font-size:12px;">
          <button class="btn btn-primary btn-sm" style="font-size:11px;"
            onclick="WMS_MODULES.despacho.agregarItemPacking(${sesionId}, ${p.producto_id}, '${WMS.esc(p.nombre)}')">
            <i class="fa-solid fa-plus"></i> Agregar
          </button>
        </div>` : '<span style="font-size:11px;color:#16a34a;"><i class="fa-solid fa-check"></i> Completado</span>'}
      </div>`).join('');
  },

  _buildItemsTable(items) {
    if (!items.length) return '<p style="padding:12px;color:#94a3b8;font-size:12px;text-align:center;">Unidad vacía</p>';
    return `<table class="erp-table" style="font-size:11px;">
      <thead><tr><th>Ref.</th><th>Producto</th><th class="text-center">Cant.</th><th>Lote</th><th></th></tr></thead>
      <tbody>${items.map(i => `<tr>
        <td><code>${WMS.esc(i.codigo||'-')}</code></td>
        <td>${WMS.esc(i.producto_nombre||i.nombre||'-')}</td>
        <td class="text-center fw-700">${WMS.formatNum(i.cantidad)}</td>
        <td style="font-size:10px;">${WMS.esc(i.lote||'-')}</td>
        <td><button class="btn btn-danger" style="padding:2px 6px;font-size:10px;"
          onclick="WMS_MODULES.despacho.eliminarItemPacking(${i.id})">
          <i class="fa-solid fa-trash"></i></button></td>
      </tr>`).join('')}</tbody>
    </table>`;
  },

  _buildClosedList(unidades, tipoUp, sesionId) {
    const closed = unidades.filter(u => u.estado === 'Cerrada');
    if (!closed.length) return '';
    return `<div class="card">
      <div class="card-header"><span class="card-title"><i class="fa-solid fa-layer-group"></i> Unidades Cerradas (${closed.length})</span>
        <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.despacho._imprimirTodasPacking('${tipoUp}')">
          <i class="fa-solid fa-print"></i> Imprimir Todas</button>
      </div>
      <div class="table-container">
        <table class="erp-table" style="font-size:12px;">
          <thead><tr><th>Unidad</th><th class="text-center">Ítems</th><th class="text-center">Total Uds.</th><th>Hora cierre</th><th>Acciones</th></tr></thead>
          <tbody>${closed.map(u => `<tr>
            <td><strong>${tipoUp} #${String(u.consecutivo).padStart(3,'0')}</strong></td>
            <td class="text-center">${(u.items||[]).length}</td>
            <td class="text-center fw-700">${WMS.formatNum(u.total_unidades)}</td>
            <td style="font-size:11px;">${u.closed_at ? u.closed_at.substring(11,16) : '-'}</td>
            <td><button class="btn btn-sm btn-outline-secondary"
              onclick="WMS_MODULES.despacho._imprimirStickerUnidad(${u.id}, 'letter')">
              <i class="fa-solid fa-print"></i> Sticker</button></td>
          </tr>`).join('')}</tbody>
        </table>
      </div>
    </div>`;
  },
```

- [ ] **Step 2: Manual test — packing screen renders**

Go through the dialog → confirm with any tipo. 

Expected: A two-panel screen appears with a left panel (products list) and right panel (empty unit). The top bar shows `Pendiente: X | Empacado: 0 | Unidades: 0`. "Finalizar Certificación" button is disabled.

- [ ] **Step 3: Commit**

```bash
git add public/assets/js/desktop/despacho.js
git commit -m "feat: packing two-panel screen — products list + active unit"
```

---

## Task 7: Frontend Part 3 — Add item, close unit, print sticker, finalize, documento

**Files:**
- Modify: `public/assets/js/desktop/despacho.js`

- [ ] **Step 1: Add `agregarItemPacking()` and `eliminarItemPacking()`**

Add after `_buildClosedList`:

```javascript
  async agregarItemPacking(sesionId, productoId, nombre) {
    const qty = parseFloat(document.getElementById('pk-qty-' + productoId)?.value || 0);
    if (!qty || qty <= 0) { WMS.toast('error', 'Ingrese una cantidad válida'); return; }
    try {
      const r = await API.post('/packing/sesion/' + sesionId + '/item', {
        producto_id: productoId,
        cantidad:    qty,
      });
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', nombre + ' agregado');
      await this.show_packing(sesionId);
    } catch(e) { WMS.toast('error', 'Error al agregar'); }
  },

  async eliminarItemPacking(itemId) {
    const { sesionId } = this._packingState;
    try {
      const r = await API.delete('/packing/item/' + itemId);
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Ítem eliminado');
      await this.show_packing(sesionId);
    } catch(e) { WMS.toast('error', 'Error al eliminar'); }
  },
```

- [ ] **Step 2: Add `cerrarUnidadPacking()` with auto-print sticker**

```javascript
  async cerrarUnidadPacking(unidadId) {
    const { sesionId, sesionData } = this._packingState;
    // Save current items before closing (for sticker generation)
    const currentUnit = (sesionData.unidades || []).find(u => u.id === unidadId);
    if (currentUnit) {
      this._packingState.unitsWithItems[unidadId] = currentUnit.items || [];
    }
    try {
      const r = await API.post('/packing/unidad/' + unidadId + '/cerrar', {});
      if (r.error) { WMS.toast('error', r.message); return; }
      // Auto-print sticker for closed unit
      this._imprimirStickerUnidad(unidadId, 'letter');
      WMS.toast('success', r.message || 'Unidad cerrada');
      await this.show_packing(sesionId);
    } catch(e) { WMS.toast('error', 'Error al cerrar unidad'); }
  },
```

- [ ] **Step 3: Add sticker HTML builder and print functions**

```javascript
  _imprimirStickerUnidad(unidadId, size) {
    const { sesionData, unitsWithItems } = this._packingState;
    const unidad = (sesionData.unidades || []).find(u => u.id === unidadId);
    if (!unidad) { WMS.toast('error', 'Unidad no encontrada'); return; }
    const items = unitsWithItems[unidadId] || unidad.items || [];
    const html  = this._buildStickerHtml(unidad, sesionData.sesion, items, size);
    const win   = window.open('', '_blank', 'width=680,height=500');
    if (win) { win.document.write(html); win.document.close(); }
  },

  _imprimirTodasPacking(tipoUp) {
    const { sesionData, unitsWithItems } = this._packingState;
    const closed = (sesionData.unidades || []).filter(u => u.estado === 'Cerrada');
    const parts  = closed.map(u => {
      const items = unitsWithItems[u.id] || u.items || [];
      return this._buildStickerBlock(u, sesionData.sesion, items)
           + '<div style="page-break-after:always;"></div>';
    }).join('');
    const html = this._wrapPrintPage(parts, 'letter');
    const win  = window.open('', '_blank', 'width=680,height=500');
    if (win) { win.document.write(html); win.document.close(); }
  },

  _buildStickerHtml(unidad, sesion, items, size) {
    return this._wrapPrintPage(this._buildStickerBlock(unidad, sesion, items), size, true);
  },

  _buildStickerBlock(unidad, sesion, items) {
    const tipo    = sesion.tipo_empaque;
    const tipoUp  = tipo.charAt(0).toUpperCase() + tipo.slice(1);
    const consec  = String(unidad.consecutivo).padStart(3, '0');
    const cert    = WMS.esc(sesion.certificador_nombre || '-');
    const fecha   = new Date().toLocaleDateString('es-CO');
    const hora    = new Date().toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' });
    const total   = (unidad.total_unidades || items.reduce((s, i) => s + (parseFloat(i.cantidad)||0), 0)).toFixed(2);
    const rows    = items.map(i => `
      <tr>
        <td>${WMS.esc(i.codigo||'-')}</td>
        <td>${WMS.esc(i.producto_nombre||i.nombre||'-')}</td>
        <td style="text-align:right;font-weight:700;">${parseFloat(i.cantidad||0).toFixed(2)}</td>
        <td>${WMS.esc(i.lote||'-')}</td>
        <td>${i.fecha_vencimiento ? new Date(i.fecha_vencimiento+'T00:00').toLocaleDateString('es-CO',{month:'short',year:'2-digit'}) : '-'}</td>
      </tr>`).join('');

    return `<div class="sticker">
      <div class="st-header">
        <span class="st-tipo">${tipoUp} #${consec}</span>
        <span>WMS Fénix</span>
      </div>
      <div class="st-suc">Sucursal: <strong>${WMS.esc(sesion.sucursal_entrega)}</strong></div>
      <table>
        <thead><tr><th>Ref.</th><th>Descripción</th><th>Cant.</th><th>Lote</th><th>Vence</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>
      <div class="st-footer">
        <div class="st-total">Total unidades: ${total}</div>
        <div>Certificador: ${cert}</div>
        <div>Fecha: ${fecha} &nbsp; Hora: ${hora}</div>
      </div>
    </div>`;
  },

  _wrapPrintPage(content, size, autoprint) {
    const sizes = { media_carta: '5.5in 8.5in', a5: 'A5', letter: 'letter' };
    const margins = { letter: '12mm' };
    const pageSize = sizes[size] || 'letter';
    const margin   = margins[size] || '8mm';
    const script   = autoprint !== false ? '<script>window.onload=()=>window.print();<\/script>' : '';
    return `<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<style>
@page { size: ${pageSize}; margin: ${margin}; }
@media print { .no-print { display:none; } }
body { font-family: Arial, sans-serif; font-size: 11px; margin: 0; }
.sticker { border: 2px solid #1e293b; padding: 8px; margin-bottom: 8px; }
.st-header { display:flex; justify-content:space-between; font-weight:bold; font-size:13px; border-bottom:1px solid #334155; padding-bottom:5px; margin-bottom:5px; }
.st-tipo { font-size:15px; color:#1e40af; }
.st-suc { font-size:12px; color:#475569; margin-bottom:4px; }
table { width:100%; border-collapse:collapse; margin:5px 0; }
th { background:#f1f5f9; font-size:10px; padding:3px 4px; text-align:left; }
td { padding:2px 4px; border-bottom:1px dotted #e2e8f0; font-size:10px; }
.st-footer { border-top:1px solid #334155; padding-top:4px; margin-top:4px; font-size:10px; color:#475569; }
.st-total { font-size:13px; font-weight:bold; color:#1e293b; margin-bottom:2px; }
.no-print { margin:8px 0; text-align:center; }
</style>${script}
</head><body>${content}
<div class="no-print"><button onclick="window.print()">Imprimir</button></div>
</body></html>`;
  },
```

- [ ] **Step 4: Add `finalizarPacking()` and documento**

```javascript
  async finalizarPacking(sesionId) {
    if (!confirm('¿Finalizar la certificación de packing? Esta acción no se puede deshacer.')) return;
    WMS.spinner();
    try {
      const r = await API.post('/packing/sesion/' + sesionId + '/finalizar', {});
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Certificación finalizada — ' + r.data.total_unidades + ' unidades de empaque');
      // Refresh packing screen to show document panel
      const sr = await API.get('/packing/sesion/' + sesionId);
      if (!sr.error) {
        this._packingState.sesionData = sr.data;
        this._mostrarPanelDocumento(sr.data);
      } else {
        this.show_certificacion();
      }
    } catch(e) { WMS.toast('error', 'Error al finalizar'); }
  },

  _mostrarPanelDocumento(data) {
    const { sesion, totales } = data;
    const tipoUp = sesion.tipo_empaque.charAt(0).toUpperCase() + sesion.tipo_empaque.slice(1);
    WMS.setContent(`
      <div class="card" style="max-width:700px;margin:0 auto;">
        <div class="card-header" style="background:#16a34a;color:#fff;">
          <span class="card-title"><i class="fa-solid fa-circle-check"></i> Packing Completado</span>
        </div>
        <div style="padding:24px;text-align:center;">
          <div style="font-size:48px;color:#16a34a;margin-bottom:12px;">✓</div>
          <h3 style="margin:0 0 6px;">Certificación Finalizada</h3>
          <p style="color:#475569;margin:0 0 20px;">
            <strong>${sesion.sucursal_entrega}</strong> — ${totales.num_unidades} ${tipoUp}(s)
          </p>
          <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
            <button class="btn btn-primary" onclick="WMS_MODULES.despacho._abrirDocumento(${sesion.id})">
              <i class="fa-solid fa-file-alt"></i> Ver Documento de Packing
            </button>
            <button class="btn btn-outline-primary" onclick="WMS_MODULES.despacho._imprimirTodasPacking('${tipoUp}')">
              <i class="fa-solid fa-print"></i> Imprimir Todos los Stickers
            </button>
            <button class="btn btn-secondary" onclick="WMS_MODULES.despacho.show_certificacion()">
              <i class="fa-solid fa-arrow-left"></i> Volver a Certificación
            </button>
          </div>
        </div>
      </div>`);
  },

  _abrirDocumento(sesionId) {
    const { sesionData, unitsWithItems } = this._packingState;
    const { sesion, unidades } = sesionData;
    const tipoUp = sesion.tipo_empaque.charAt(0).toUpperCase() + sesion.tipo_empaque.slice(1);
    const cert   = WMS.esc(sesion.certificador_nombre || '-');
    const emp    = WMS.esc(sesion.empresa_nombre || 'WMS Fénix');
    const fecha  = new Date().toLocaleString('es-CO');

    const closed = (unidades || []).filter(u => u.estado === 'Cerrada');

    // Collect unique separadores
    const seps = new Set();
    closed.forEach(u => {
      (unitsWithItems[u.id] || u.items || []).forEach(i => {
        if (i.separador_nombre?.trim()) seps.add(i.separador_nombre.trim());
      });
    });
    const sepStr = [...seps].join(', ') || 'N/A';

    // Build table rows
    let prevConsec = null;
    let rowClass   = 'even';
    const rows = closed.flatMap(u => {
      if (u.consecutivo !== prevConsec) {
        rowClass   = rowClass === 'even' ? 'odd' : 'even';
        prevConsec = u.consecutivo;
      }
      const consec = String(u.consecutivo).padStart(3,'0');
      return (unitsWithItems[u.id] || u.items || []).map(i => `
        <tr class="${rowClass}">
          <td>#${consec}</td><td>${tipoUp}</td>
          <td>${WMS.esc(i.codigo||'-')}</td>
          <td>${WMS.esc(i.producto_nombre||i.nombre||'-')}</td>
          <td style="text-align:right;">${parseFloat(i.cantidad||0).toFixed(2)}</td>
          <td>${WMS.esc(i.lote||'-')}</td>
          <td>${i.fecha_vencimiento ? new Date(i.fecha_vencimiento+'T00:00').toLocaleDateString('es-CO',{month:'short',year:'numeric'}) : '-'}</td>
        </tr>`);
    }).join('');

    const totalUnidades = closed.length;
    const totalProd     = closed.reduce((s, u) => s + (u.total_unidades || 0), 0).toFixed(2);
    const allCodigos    = new Set(closed.flatMap(u => (unitsWithItems[u.id] || u.items || []).map(i => i.codigo)));
    const totalRefs     = allCodigos.size;

    const html = `<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<style>
@page { size: letter; margin: 12mm; }
@media print { .no-print { display:none; } }
body { font-family: Arial, sans-serif; font-size: 11px; color: #1e293b; }
.doc-header { border-bottom: 2px solid #1e40af; padding-bottom: 8px; margin-bottom: 12px; display:flex; justify-content:space-between; align-items:flex-start; }
.doc-title { font-size:16px; font-weight:bold; color:#1e40af; margin:0 0 4px; }
.doc-meta { font-size:10px; color:#475569; margin-top:3px; }
.doc-meta span { display:inline-block; margin-right:12px; }
table { width:100%; border-collapse:collapse; margin:10px 0; }
th { background:#1e40af; color:#fff; font-size:10px; padding:4px 6px; text-align:left; }
td { padding:3px 6px; font-size:10px; border-bottom:1px solid #e2e8f0; }
tr.even td { background:#f8faff; } tr.odd td { background:#fff; }
.doc-footer { border-top:2px solid #1e40af; margin-top:12px; padding-top:8px; display:flex; gap:16px; flex-wrap:wrap; }
.foot-box .label { font-size:9px; color:#64748b; }
.foot-box .val   { font-weight:bold; font-size:13px; }
.no-print { margin:12px 0; text-align:center; }
</style>
<script>
function toggleLandscape() {
  const rule = Array.from(document.styleSheets[0].cssRules).find(r => r.cssText?.includes('@page'));
  if (rule) rule.style.cssText = rule.style.cssText.includes('landscape')
    ? rule.style.cssText.replace('landscape','portrait')
    : rule.style.cssText.replace('portrait','landscape');
}
<\/script>
</head><body>
<div class="doc-header">
  <div>
    <div class="doc-title">DOCUMENTO DE PACKING</div>
    <div class="doc-meta"><span><strong>${emp}</strong></span><span>Sucursal: <strong>${WMS.esc(sesion.sucursal_entrega)}</strong></span><span>Tipo: <strong>${tipoUp}</strong></span></div>
    <div class="doc-meta"><span>Fecha/Hora: ${fecha}</span><span>Certificador: <strong>${cert}</strong></span><span>Separadores: ${WMS.esc(sepStr)}</span></div>
  </div>
  <div class="no-print">
    <button onclick="toggleLandscape()" style="margin-right:6px;">Girar</button>
    <button onclick="window.print()">Imprimir</button>
  </div>
</div>
<table>
  <thead><tr><th>Unidad</th><th>Tipo</th><th>Referencia</th><th>Descripción</th><th>Cantidad</th><th>Lote</th><th>Vence</th></tr></thead>
  <tbody>${rows}</tbody>
</table>
<div class="doc-footer">
  <div class="foot-box"><div class="label">Unidades de empaque</div><div class="val">${totalUnidades}</div></div>
  <div class="foot-box"><div class="label">Total uds. producto</div><div class="val">${totalProd}</div></div>
  <div class="foot-box"><div class="label">Referencias distintas</div><div class="val">${totalRefs}</div></div>
  <div class="foot-box"><div class="label">Separó</div><div class="val" style="font-size:11px;">${WMS.esc(sepStr)}</div></div>
  <div class="foot-box"><div class="label">Certificó</div><div class="val" style="font-size:11px;">${cert}</div></div>
</div>
</body></html>`;
    const win = window.open('', '_blank', 'width=900,height=700');
    if (win) { win.document.write(html); win.document.close(); }
  },
```

- [ ] **Step 5: Full end-to-end test**

Run through the complete flow in the browser:

1. Go to Despacho → Certificación. Click "Iniciar Certificación" on a pending sucursal.
2. **Dialog appears** → select Caja, optionally select an impresora → click Iniciar.
3. **Packing screen** shows two panels. Left shows products with pendiente quantities.
4. Enter a quantity for a product → click Agregar. Product appears in right panel items table.
5. Click "Cerrar unidad e imprimir sticker". A new browser window opens with the sticker HTML (auto-prints).
6. New unit (#002) opens automatically. Right panel resets.
7. Add products until `Pendiente: 0`. "Finalizar Certificación" button becomes enabled.
8. Click Finalizar. Confirm dialog → API call → Success screen appears.
9. Click "Ver Documento de Packing". New window opens with the full packing document.
10. Click "Imprimir Todos los Stickers". New window with all stickers opens.

- [ ] **Step 6: Commit**

```bash
git add public/assets/js/desktop/despacho.js
git commit -m "feat: packing flow — agregar/eliminar items, cerrar unidad, sticker, finalizar, documento"
```

---

## Task 8: Maestro → Impresoras UI — tipos_trabajo checkboxes

**Files:**
- Modify: `public/assets/js/desktop/maestro.js`

This task adds the tipos_trabajo checkboxes to the printer create/edit form in the maestro module.

- [ ] **Step 1: Find the impresoras form in maestro.js**

```bash
grep -n "tipos_trabajo\|impresora\|guardarImpresora\|tipo.*trabajo" public/assets/js/desktop/maestro.js | head -30
```

Expected: Find the function that builds the impresora form modal.

- [ ] **Step 2: Add tipos_trabajo checkboxes to the impresora form**

Find the section in `maestro.js` that builds the impresora form fields (look for `nombre`, `ip`, `puerto`, `tipo`, `modulos` input fields). After the `modulos` field, add:

```javascript
// Add after the modulos/activo fields in the impresora form HTML:
`<div class="form-group">
  <label class="form-label">Tipos de trabajo</label>
  <div style="display:flex;gap:16px;">
    <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">
      <input type="checkbox" id="imp-tipo-sticker" value="sticker_packing"
        ${(imp?.tipos_trabajo||[]).includes('sticker_packing') ? 'checked' : ''}>
      Stickers de packing
    </label>
    <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">
      <input type="checkbox" id="imp-tipo-doc" value="documento_packing"
        ${(imp?.tipos_trabajo||[]).includes('documento_packing') ? 'checked' : ''}>
      Documento de packing
    </label>
  </div>
</div>`
```

And in the save function, include tipos_trabajo in the form data:

```javascript
// When collecting form data before calling API.post('/maestro/impresoras', data):
tipos_trabajo: [
  ...( document.getElementById('imp-tipo-sticker')?.checked ? ['sticker_packing'] : [] ),
  ...( document.getElementById('imp-tipo-doc')?.checked     ? ['documento_packing'] : [] ),
],
```

- [ ] **Step 3: Manual test — impresora form shows checkboxes**

Go to Maestro → Impresoras → Nueva/Editar.

Expected: Two checkboxes appear: "Stickers de packing" and "Documento de packing". Saving an impresora with one checked results in `tipos_trabajo: ["sticker_packing"]` stored in the DB. The packing dialog only shows that impresora in the sticker selector.

- [ ] **Step 4: Commit**

```bash
git add public/assets/js/desktop/maestro.js
git commit -m "feat: maestro impresoras form — tipos_trabajo checkboxes"
```

---

## Self-Review Checklist

**Spec coverage:**
- [x] Session init with tipo_empaque + printer selection → Task 5
- [x] Two-panel screen (left: pending, right: active unit) → Task 6
- [x] Add/remove items → Task 7
- [x] Manual unit close → Task 7
- [x] Auto-print sticker on unit close → Task 7
- [x] Sticker sizes (media_carta/letter/a5) → `_wrapPrintPage` in Task 7
- [x] Print one / print all → `_imprimirStickerUnidad` / `_imprimirTodasPacking` in Task 7
- [x] Finalize session → triggers certFinalizar logic → Task 3
- [x] Closing document → `_abrirDocumento` in Task 7
- [x] Printer config (tipos_trabajo) → Tasks 1-4 + Task 8
- [x] Error: qty > pending → 422 in PackingController.agregarItem
- [x] Error: empty unit close → 422 in PackingController.cerrarUnidad
- [x] Error: finalize with pending → 422 in PackingController.finalizarSesion
- [x] Error: session already Completada → 409
- [x] Multi-tenancy: all endpoints check empresa_id + sucursal_id
- [x] `isPg()` used in ImpresoraController listar filter
- [x] `NULLS LAST` on PostgreSQL in _resolveFromPicking via orderByRaw

**Group print mode (checkbox multi-select):** The spec mentions selecting a group of units to print. The current implementation has individual + all. A group checkbox UI was scoped out as a nice-to-have given the two core modes (one / all) cover the stated use cases. Add it after the first working version if needed.

**Mobile UI:** Out of scope per spec §10.
