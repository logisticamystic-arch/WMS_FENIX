# ExpiryGuard — Control de Vencimientos Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement `ExpiryGuard` service that blocks picking/packing of expired products, requires supervisor approval for products expiring within 5 days, and auto-quarantines expired inventory.

**Architecture:** New `ExpiryGuard` helper in `src/Helpers/` calls from `InventoryGuard::canPick()` (adding rules R10/R11) and directly from `PackingController::agregarItem()`. A new `AprobacionController` handles the 4 approval endpoints. Frontend handles the 202 response with a waiting-modal + 10-second polling loop in both mobile and desktop.

**Tech Stack:** PHP 8.2, Slim 4, Eloquent ORM, MySQL/PostgreSQL dual-driver, Vanilla JS (no framework)

---

## File Map

### New files
| File | Responsibility |
|---|---|
| `src/Helpers/ExpiryGuard.php` | `ExpiryResult` VO + `ExpiryGuard` service — single source of expiry logic |
| `src/Models/AprobacionVencimiento.php` | Eloquent model for `aprobaciones_vencimiento` |
| `src/Controllers/AprobacionController.php` | 4 endpoints: pendientes, resolver, estado, cancelar |
| `database/migrations/2026_05_30_create_aprobaciones_vencimiento.sql` | New table DDL |
| `database/migrations/2026_05_30_add_fecha_vencimiento_picking_detalles.sql` | ALTER TABLE DDL |

### Modified files
| File | What changes |
|---|---|
| `src/Helpers/InventoryGuard.php` | Add R10/R11 at end of `canPick()` |
| `src/Models/PickingDetalle.php` | Add `fecha_vencimiento` to `$fillable` |
| `src/Helpers/FefoEngine.php` | `getSuggestedLots()` calls `autoQuarantine()` + filters `Cuarentena` |
| `src/Controllers/PickingController.php` | `confirmLine()` handles `pending_approval` → 202 + stores `fecha_vencimiento` |
| `src/Controllers/PackingController.php` | `agregarItem()` calls `ExpiryGuard::check()` before creating item |
| `public/index.php` | Register 4 new routes under `/api` group |
| `public/index.html` | `loadBadge()` adds expiry count; `loadNotifications()` adds approval cards |
| `public/mobile/index.html` | `confirmar-linea` handler handles 202 + waiting modal |
| `public/assets/js/desktop/despacho.js` | `agregarItemPacking()` handles 202 + waiting modal |

---

## Task 1: Database migrations

**Files:**
- Create: `database/migrations/2026_05_30_create_aprobaciones_vencimiento.sql`
- Create: `database/migrations/2026_05_30_add_fecha_vencimiento_picking_detalles.sql`

- [ ] **Step 1: Write migration for new table**

```sql
-- database/migrations/2026_05_30_create_aprobaciones_vencimiento.sql
CREATE TABLE IF NOT EXISTS aprobaciones_vencimiento (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id       INT UNSIGNED NOT NULL,
    sucursal_id      INT UNSIGNED NOT NULL,
    producto_id      INT UNSIGNED NOT NULL,
    lote             VARCHAR(100) NOT NULL,
    dias_restantes   INT NOT NULL,
    solicitado_por   INT UNSIGNED NOT NULL,
    aprobado_por     INT UNSIGNED NULL,
    estado           ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
    valid_until      DATE NULL,
    created_at       TIMESTAMP NULL,
    updated_at       TIMESTAMP NULL,
    INDEX idx_aprobaciones_empresa (empresa_id, sucursal_id, estado),
    INDEX idx_aprobaciones_producto (producto_id, lote, valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Write migration for picking_detalles**

```sql
-- database/migrations/2026_05_30_add_fecha_vencimiento_picking_detalles.sql
ALTER TABLE picking_detalles
    ADD COLUMN IF NOT EXISTS fecha_vencimiento DATE NULL AFTER lote;
```

- [ ] **Step 3: Execute both migrations**

Open phpMyAdmin (http://localhost/phpmyadmin), select the WMS database, go to SQL tab, and run each file's contents. Expected: 0 rows affected, no errors.

- [ ] **Step 4: Verify tables**

```sql
DESCRIBE aprobaciones_vencimiento;
SHOW COLUMNS FROM picking_detalles LIKE 'fecha_vencimiento';
```

Expected: `aprobaciones_vencimiento` has 12 columns; `picking_detalles` has `fecha_vencimiento DATE NULL`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_05_30_create_aprobaciones_vencimiento.sql
git add database/migrations/2026_05_30_add_fecha_vencimiento_picking_detalles.sql
git commit -m "feat: migrations — aprobaciones_vencimiento + picking_detalles.fecha_vencimiento"
```

---

## Task 2: ExpiryGuard helper

**Files:**
- Create: `src/Helpers/ExpiryGuard.php`

- [ ] **Step 1: Create the file**

```php
<?php

namespace App\Helpers;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * ExpiryResult — value object returned by ExpiryGuard::check().
 * status: OK | BLOCKED | PENDING
 */
class ExpiryResult
{
    public const OK      = 'OK';
    public const BLOCKED = 'BLOCKED';
    public const PENDING = 'PENDING';

    public function __construct(
        public readonly string  $status,
        public readonly ?int    $aprobacionId  = null,
        public readonly ?string $message       = null,
        public readonly ?string $productName   = null,
        public readonly ?string $lote          = null,
        public readonly ?int    $diasRestantes = null
    ) {}
}

/**
 * ExpiryGuard — Central expiry enforcement service.
 *
 * R10: fecha_vencimiento < today  → BLOCKED (no exceptions)
 * R11: 1 ≤ dias_restantes ≤ 5    → PENDING (supervisor approval required)
 *
 * autoQuarantine() marks all expired inventory rows as Cuarentena.
 * Called lazy from FefoEngine and on each BLOCKED result.
 */
class ExpiryGuard
{
    public function __construct(
        private readonly int $empresaId,
        private readonly int $sucursalId
    ) {}

    /**
     * Check expiry status for a specific product+lote at time of picking/packing.
     * If lote is null or no fecha_vencimiento exists in inventory, returns OK.
     */
    public function check(int $productoId, ?string $lote, int $solicitadoPor): ExpiryResult
    {
        if ($lote === null) {
            return new ExpiryResult(ExpiryResult::OK);
        }

        $inv = Capsule::table('inventarios')
            ->where('empresa_id',  $this->empresaId)
            ->where('sucursal_id', $this->sucursalId)
            ->where('producto_id', $productoId)
            ->where('lote',        $lote)
            ->whereNotNull('fecha_vencimiento')
            ->orderBy('fecha_vencimiento', 'asc')
            ->first();

        if (!$inv || !$inv->fecha_vencimiento) {
            return new ExpiryResult(ExpiryResult::OK);
        }

        $today         = strtotime(date('Y-m-d'));
        $fechaVencTs   = strtotime($inv->fecha_vencimiento);
        $diasRestantes = (int)floor(($fechaVencTs - $today) / 86400);
        $nombre        = Capsule::table('productos')->where('id', $productoId)->value('nombre')
                         ?? "Producto #{$productoId}";

        if ($diasRestantes <= 0) {
            $this->_quarantineLote($productoId, $lote);
            return new ExpiryResult(
                ExpiryResult::BLOCKED,
                message: "El producto {$nombre} (Lote {$lote}) está vencido ({$inv->fecha_vencimiento}). No puede ser despachado.",
                productName: $nombre,
                lote: $lote,
                diasRestantes: $diasRestantes
            );
        }

        if ($diasRestantes <= 5) {
            // Check for existing valid approval today
            $existing = Capsule::table('aprobaciones_vencimiento')
                ->where('empresa_id',  $this->empresaId)
                ->where('sucursal_id', $this->sucursalId)
                ->where('producto_id', $productoId)
                ->where('lote',        $lote)
                ->where('estado',      'aprobada')
                ->where('valid_until', date('Y-m-d'))
                ->first();

            if ($existing) {
                return new ExpiryResult(ExpiryResult::OK);
            }

            $aprobacionId = Capsule::table('aprobaciones_vencimiento')->insertGetId([
                'empresa_id'    => $this->empresaId,
                'sucursal_id'   => $this->sucursalId,
                'producto_id'   => $productoId,
                'lote'          => $lote,
                'dias_restantes'=> $diasRestantes,
                'solicitado_por'=> $solicitadoPor,
                'estado'        => 'pendiente',
                'created_at'    => date('Y-m-d H:i:s'),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

            return new ExpiryResult(
                ExpiryResult::PENDING,
                aprobacionId: $aprobacionId,
                message: "Producto próximo a vencer ({$diasRestantes} días). Esperando autorización del supervisor.",
                productName: $nombre,
                lote: $lote,
                diasRestantes: $diasRestantes
            );
        }

        return new ExpiryResult(ExpiryResult::OK);
    }

    /**
     * Marks all expired inventory (across entire empresa/sucursal) as Cuarentena.
     * Returns count of rows updated.
     */
    public function autoQuarantine(): int
    {
        return Capsule::table('inventarios')
            ->where('empresa_id',  $this->empresaId)
            ->where('sucursal_id', $this->sucursalId)
            ->where('estado', '!=', 'Cuarentena')
            ->whereNotNull('fecha_vencimiento')
            ->where('fecha_vencimiento', '<', date('Y-m-d'))
            ->update([
                'estado'     => 'Cuarentena',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    private function _quarantineLote(int $productoId, string $lote): void
    {
        Capsule::table('inventarios')
            ->where('empresa_id',  $this->empresaId)
            ->where('sucursal_id', $this->sucursalId)
            ->where('producto_id', $productoId)
            ->where('lote',        $lote)
            ->where('estado', '!=', 'Cuarentena')
            ->update([
                'estado'     => 'Cuarentena',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
}
```

- [ ] **Step 2: Verify PHP syntax**

```bash
php -l src/Helpers/ExpiryGuard.php
```

Expected: `No syntax errors detected in src/Helpers/ExpiryGuard.php`

- [ ] **Step 3: Commit**

```bash
git add src/Helpers/ExpiryGuard.php
git commit -m "feat: ExpiryGuard helper — R10 block expired, R11 pending approval"
```

---

## Task 3: AprobacionVencimiento model

**Files:**
- Create: `src/Models/AprobacionVencimiento.php`

- [ ] **Step 1: Create the model**

```php
<?php

namespace App\Models;

class AprobacionVencimiento extends BaseModel
{
    protected $table = 'aprobaciones_vencimiento';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'producto_id', 'lote',
        'dias_restantes', 'solicitado_por', 'aprobado_por',
        'estado', 'valid_until',
    ];

    protected $casts = [
        'valid_until' => 'date',
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function solicitante()
    {
        return $this->belongsTo(Personal::class, 'solicitado_por');
    }

    public function aprobador()
    {
        return $this->belongsTo(Personal::class, 'aprobado_por');
    }
}
```

- [ ] **Step 2: Verify syntax**

```bash
php -l src/Models/AprobacionVencimiento.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Models/AprobacionVencimiento.php
git commit -m "feat: AprobacionVencimiento model"
```

---

## Task 4: AprobacionController

**Files:**
- Create: `src/Controllers/AprobacionController.php`

- [ ] **Step 1: Create the controller**

```php
<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\AprobacionVencimiento;
use Illuminate\Database\Capsule\Manager as Capsule;

class AprobacionController extends BaseController
{
    // ── GET /api/aprobaciones/vencimiento/pendientes ──────────────────────────
    // Admin/Supervisor: lista solicitudes pendientes de la empresa/sucursal
    public function pendientes(Request $r, Response $res): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);

        $rows = Capsule::table('aprobaciones_vencimiento as av')
            ->join('productos as p',        'p.id',  '=', 'av.producto_id')
            ->join('personal as sol',        'sol.id', '=', 'av.solicitado_por')
            ->where('av.empresa_id',  $empresaId)
            ->where('av.sucursal_id', $sucursalId)
            ->where('av.estado', 'pendiente')
            ->orderBy('av.created_at', 'asc')
            ->select([
                'av.id',
                'av.producto_id',
                'p.nombre as producto_nombre',
                'p.codigo_interno as producto_codigo',
                'av.lote',
                'av.dias_restantes',
                'av.solicitado_por',
                Capsule::raw("CONCAT(sol.nombre) as auxiliar_nombre"),
                'av.created_at',
            ])
            ->get();

        return $this->ok($res, $rows);
    }

    // ── POST /api/aprobaciones/{id}/resolver ──────────────────────────────────
    // Admin/Supervisor: aprobar o rechazar
    public function resolver(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $data = $r->getParsedBody() ?? [];
        if (empty($data['decision']) || !in_array($data['decision'], ['aprobada', 'rechazada'])) {
            return $this->error($res, 'decision debe ser "aprobada" o "rechazada"');
        }

        $empresaId  = $this->getEffectiveEmpresaId($user, $r);
        $sucursalId = $this->getEffectiveSucursalId($user, $r);

        $aprobacion = AprobacionVencimiento::where('empresa_id',  $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->where('estado', 'pendiente')
            ->find((int)$a['id']);

        if (!$aprobacion) {
            return $this->notFound($res, 'Solicitud no encontrada o ya resuelta');
        }

        $aprobacion->estado      = $data['decision'];
        $aprobacion->aprobado_por = $user->id;
        if ($data['decision'] === 'aprobada') {
            $aprobacion->valid_until = date('Y-m-d');
        }
        $aprobacion->save();

        $this->audit($user, 'aprobacion_vencimiento', $data['decision'],
            'aprobaciones_vencimiento', $aprobacion->id, null,
            ['decision' => $data['decision']],
            "Solicitud de vencimiento #{$aprobacion->id} {$data['decision']}");

        return $this->ok($res, $aprobacion, 'Solicitud ' . $data['decision']);
    }

    // ── GET /api/aprobaciones/{id}/estado ─────────────────────────────────────
    // Auxiliar: polling — retorna estado actual de su solicitud
    public function estado(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');

        $aprobacion = AprobacionVencimiento::find((int)$a['id']);
        if (!$aprobacion) return $this->notFound($res);

        // Solo el solicitante o un supervisor puede consultar
        $isSupervisor = in_array($user->rol ?? '', ['Admin', 'Supervisor', 'SuperAdmin']);
        if (!$isSupervisor && $aprobacion->solicitado_por !== $user->id) {
            return $this->forbidden($res);
        }

        return $this->ok($res, [
            'id'             => $aprobacion->id,
            'estado'         => $aprobacion->estado,
            'dias_restantes' => $aprobacion->dias_restantes,
        ]);
    }

    // ── DELETE /api/aprobaciones/{id} ─────────────────────────────────────────
    // Auxiliar: cancela su propia solicitud pendiente
    public function cancelar(Request $r, Response $res, array $a): Response
    {
        $user = $r->getAttribute('user');

        $aprobacion = AprobacionVencimiento::where('solicitado_por', $user->id)
            ->where('estado', 'pendiente')
            ->find((int)$a['id']);

        if (!$aprobacion) {
            return $this->notFound($res, 'Solicitud no encontrada o ya resuelta');
        }

        $aprobacion->estado = 'rechazada';
        $aprobacion->save();

        return $this->ok($res, null, 'Solicitud cancelada');
    }
}
```

- [ ] **Step 2: Verify syntax**

```bash
php -l src/Controllers/AprobacionController.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add src/Controllers/AprobacionController.php
git commit -m "feat: AprobacionController — 4 endpoints for expiry approval flow"
```

---

## Task 5: Register routes

**Files:**
- Modify: `public/index.php`

- [ ] **Step 1: Add 4 routes after the packing group (around line 654)**

Find the block ending `}); // packing group` and add after it:

```php
    // Módulo: Aprobaciones de Vencimiento
    $group->get('/aprobaciones/vencimiento/pendientes', [\App\Controllers\AprobacionController::class, 'pendientes']);
    $group->post('/aprobaciones/{id}/resolver',          [\App\Controllers\AprobacionController::class, 'resolver']);
    $group->get('/aprobaciones/{id}/estado',             [\App\Controllers\AprobacionController::class, 'estado']);
    $group->delete('/aprobaciones/{id}',                 [\App\Controllers\AprobacionController::class, 'cancelar']);
```

- [ ] **Step 2: Verify with curl (requires Apache running)**

```bash
curl -s http://localhost/WMS_FENIX/public/api/aprobaciones/vencimiento/pendientes \
  -H "Authorization: Bearer INVALID" | php -r "echo json_decode(file_get_contents('php://stdin'))->message;"
```

Expected output: `Token inválido` or `Unauthorized` (401 — proves route exists and middleware fires)

- [ ] **Step 3: Commit**

```bash
git add public/index.php
git commit -m "feat: routes — register 4 aprobaciones endpoints"
```

---

## Task 6: InventoryGuard R10/R11

**Files:**
- Modify: `src/Helpers/InventoryGuard.php`

- [ ] **Step 1: Add `use` statement at top of file**

After line 5 (`use Illuminate\Database\Capsule\Manager as Capsule;`) add:

```php
use App\Helpers\ExpiryGuard;
use App\Helpers\ExpiryResult;
```

- [ ] **Step 2: Add R10/R11 at end of `canPick()`, just before the return `['ok' => true, ...]`**

Replace lines 111–115 (the `return ['ok' => true, ...]` block):

```php
        // R10/R11 — Expiry check (after stock validation)
        if (!empty($rows) && $lote !== null && $this->usuarioId !== null) {
            $guard  = new ExpiryGuard($this->empresaId, $this->sucursalId);
            $result = $guard->check($productoId, $lote, $this->usuarioId);

            if ($result->status === ExpiryResult::BLOCKED) {
                return $this->deny('R10', $result->message, [
                    'producto_id'    => $productoId,
                    'lote'           => $lote,
                    'dias_restantes' => $result->diasRestantes,
                    'code'           => 'PRODUCT_EXPIRED',
                ]);
            }

            if ($result->status === ExpiryResult::PENDING) {
                return [
                    'ok'              => false,
                    'regla'           => 'R11',
                    'pending_approval'=> true,
                    'aprobacion_id'   => $result->aprobacionId,
                    'message'         => $result->message,
                    'dias_restantes'  => $result->diasRestantes,
                ];
            }
        }

        return [
            'ok'               => true,
            'stock_disponible' => $stockDisponible,
            'fefo_warning'     => $fefoWarning,
        ];
```

- [ ] **Step 3: Verify syntax**

```bash
php -l src/Helpers/InventoryGuard.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add src/Helpers/InventoryGuard.php
git commit -m "feat: InventoryGuard — R10 expired block, R11 proximity pending via ExpiryGuard"
```

---

## Task 7: PickingController — handle 202 and store fecha_vencimiento

**Files:**
- Modify: `src/Controllers/PickingController.php`
- Modify: `src/Models/PickingDetalle.php`

- [ ] **Step 1: Add `fecha_vencimiento` to PickingDetalle `$fillable`**

In `src/Models/PickingDetalle.php`, replace:

```php
        'orden_picking_id', 'producto_id', 'ubicacion_id', 'auxiliar_id', 'lote',
        'cantidad_solicitada', 'cantidad_pickeada', 'pasillo_lock', 'estado',
        'costo_unitario', 'descuento_porc', 'iva_porc', 'valor_iva', 'total_linea', 'devolucion_qty',
        'ambiente', 'numero_pedido_ref',
```

With:

```php
        'orden_picking_id', 'producto_id', 'ubicacion_id', 'auxiliar_id', 'lote',
        'fecha_vencimiento',
        'cantidad_solicitada', 'cantidad_pickeada', 'pasillo_lock', 'estado',
        'costo_unitario', 'descuento_porc', 'iva_porc', 'valor_iva', 'total_linea', 'devolucion_qty',
        'ambiente', 'numero_pedido_ref',
```

- [ ] **Step 2: Modify `confirmLine()` to return 202 on pending approval**

In `src/Controllers/PickingController.php`, around line 1064, replace:

```php
        if (!$check['ok']) {
            return $this->error($res, $check['message'], 422);
        }
```

With:

```php
        if (!$check['ok']) {
            if (!empty($check['pending_approval'])) {
                $body = $res->getBody();
                $body->write(json_encode([
                    'error'         => false,
                    'status'        => 'pending_approval',
                    'aprobacion_id' => $check['aprobacion_id'],
                    'message'       => $check['message'],
                    'dias_restantes'=> $check['dias_restantes'],
                ], JSON_UNESCAPED_UNICODE));
                return $res->withHeader('Content-Type', 'application/json')->withStatus(202);
            }
            return $this->error($res, $check['message'], 422);
        }
```

- [ ] **Step 3: Store `fecha_vencimiento` on linea when confirming**

In the same method, find the line `$linea->cantidad_pickeada = $cantidadTomada;` (around line 1133) and add before it inside the transaction:

```php
                // Store fecha_vencimiento from inventory for traceability
                if (!$linea->fecha_vencimiento && $linea->lote) {
                    $fv = \Illuminate\Database\Capsule\Manager::table('inventarios')
                        ->where('empresa_id',  $user->empresa_id)
                        ->where('sucursal_id', $user->sucursal_id)
                        ->where('producto_id', $linea->producto_id)
                        ->where('lote',        $linea->lote)
                        ->whereNotNull('fecha_vencimiento')
                        ->value('fecha_vencimiento');
                    if ($fv) $linea->fecha_vencimiento = $fv;
                }
```

- [ ] **Step 4: Verify syntax**

```bash
php -l src/Controllers/PickingController.php && php -l src/Models/PickingDetalle.php
```

Expected: No syntax errors in both files.

- [ ] **Step 5: Commit**

```bash
git add src/Controllers/PickingController.php src/Models/PickingDetalle.php
git commit -m "feat: PickingController — 202 pending approval + store fecha_vencimiento on confirm"
```

---

## Task 8: PackingController — ExpiryGuard check in agregarItem

**Files:**
- Modify: `src/Controllers/PackingController.php`

- [ ] **Step 1: Add `use` for ExpiryGuard at top of PackingController**

After the existing `use` statements, add:

```php
use App\Helpers\ExpiryGuard;
use App\Helpers\ExpiryResult;
```

- [ ] **Step 2: Add ExpiryGuard check in `agregarItem()`, after `$fechaVenc` is resolved**

In `agregarItem()`, the `[$lote, $fechaVenc, $separadorId, $detalleId]` resolution happens around line 183. After that line and before `$item = PackingItem::create(...)`, add:

```php
        // R10/R11 — Expiry check before adding item to packing
        if ($lote !== null) {
            $expiryGuard = new ExpiryGuard($user->empresa_id, $user->sucursal_id);
            $expiryResult = $expiryGuard->check((int)$productoId, $lote, $user->id);

            if ($expiryResult->status === ExpiryResult::BLOCKED) {
                return $this->error($res, $expiryResult->message, 422);
            }

            if ($expiryResult->status === ExpiryResult::PENDING) {
                $body = $res->getBody();
                $body->write(json_encode([
                    'error'         => false,
                    'status'        => 'pending_approval',
                    'aprobacion_id' => $expiryResult->aprobacionId,
                    'message'       => $expiryResult->message,
                    'dias_restantes'=> $expiryResult->diasRestantes,
                ], JSON_UNESCAPED_UNICODE));
                return $res->withHeader('Content-Type', 'application/json')->withStatus(202);
            }
        }
```

- [ ] **Step 3: Verify syntax**

```bash
php -l src/Controllers/PackingController.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add src/Controllers/PackingController.php
git commit -m "feat: PackingController agregarItem — ExpiryGuard check before packing item creation"
```

---

## Task 9: FefoEngine — autoQuarantine and filter Cuarentena

**Files:**
- Modify: `src/Helpers/FefoEngine.php`

- [ ] **Step 1: Add `use` for ExpiryGuard**

After `use Illuminate\Database\Capsule\Manager as Capsule;`, add:

```php
use App\Helpers\ExpiryGuard;
```

- [ ] **Step 2: Add `autoQuarantine()` call at start of `getSuggestedLots()` and filter Cuarentena**

In `getSuggestedLots()`, replace the opening lines (from `$rows = Capsule::table('inventarios as i')` through `->where('i.estado', 'Disponible')`) with:

```php
        // Lazy auto-quarantine: marks expired inventory before suggesting lots
        (new ExpiryGuard($this->empresaId, $this->sucursalId))->autoQuarantine();

        $rows = Capsule::table('inventarios as i')
            ->leftJoin('ubicaciones as u', 'u.id', '=', 'i.ubicacion_id')
            ->where('i.empresa_id',  $this->empresaId)
            ->where('i.sucursal_id', $this->sucursalId)
            ->where('i.producto_id', $productoId)
            ->where('i.estado', 'Disponible')  // Cuarentena rows excluded by state filter
```

- [ ] **Step 3: Verify syntax**

```bash
php -l src/Helpers/FefoEngine.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add src/Helpers/FefoEngine.php
git commit -m "feat: FefoEngine — autoQuarantine on getSuggestedLots, expired lots never suggested"
```

---

## Task 10: Frontend badge — expiry count for Admin/Supervisor

**Files:**
- Modify: `public/index.html`

- [ ] **Step 1: Extend `loadBadge()` to include expiry approval count**

In `public/index.html`, find `async loadBadge()` (around line 1944). Replace the entire method with:

```js
      async loadBadge() {
        if (!_wmsToken) return;
        try {
          const r = await API.get('/notificaciones/badge');
          if (r.error) return;
          let totalBadge = r.badge;

          // Expiry approvals count — only visible to Admin/Supervisor
          const rol = _wmsUser?.rol ?? '';
          if (rol === 'Admin' || rol === 'Supervisor' || rol === 'SuperAdmin') {
            try {
              const ea = await API.get('/aprobaciones/vencimiento/pendientes');
              if (!ea.error && Array.isArray(ea.data)) {
                totalBadge += ea.data.length;
                this._expiryPendingCount = ea.data.length;
              }
            } catch(_) {}
          }

          this.updateBadge(totalBadge);
          this._lastNotifCnt = r.badge;

          // ML intelligence alerts (existing logic)
          if (r.badge > (this._lastNotifCnt ?? 0)) {
            try {
              const rn = await API.get('/notificaciones', 'pagina=1&por_pagina=5');
              const urgentes = (rn.data || []).filter(n =>
                n.modulo === 'inteligencia' && !n.leida
              );
              if (urgentes.length > 0) {
                const n = urgentes[0];
                const toastId = 'ml-alert-' + Date.now();
                const html = `
                  <div id="${toastId}" style="
                    position:fixed;bottom:24px;right:24px;z-index:9999;
                    background:#1e293b;color:#f8fafc;padding:14px 18px;
                    border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,.35);
                    max-width:340px;border-left:4px solid #dc2626;
                    animation:slideInRight .3s ease;cursor:pointer;"
                    onclick="WMS.nav('inteligencia','vencimientos');document.getElementById('${toastId}')?.remove();">
                    <div style="font-weight:700;font-size:.85rem;margin-bottom:4px;">
                      <i class="fa-solid fa-brain me-2" style="color:#f87171;"></i>${WMS.esc(n.titulo)}
                    </div>
                    <div style="font-size:.75rem;color:#cbd5e1;white-space:pre-line;max-height:80px;overflow:hidden;">
                      ${WMS.esc((n.mensaje || '').substring(0, 160))}
                    </div>
                    <div style="margin-top:8px;font-size:.72rem;color:#94a3b8;">
                      Clic para ir al módulo Inteligencia →
                    </div>
                  </div>`;
                document.body.insertAdjacentHTML('beforeend', html);
                setTimeout(() => document.getElementById(toastId)?.remove(), 10000);
              }
            } catch(_) {}
          }
        } catch (e) { }
      },
```

Note: also add `_expiryPendingCount: 0,` to the WMS object properties at the top of the WMS object (find `_notifOpen: false,` around line 1013 and add after it):

```js
      _expiryPendingCount: 0,
```

- [ ] **Step 2: Verify the page still loads without JS errors**

Open http://localhost/WMS_FENIX/public/ in browser, open DevTools console. Expected: No errors about `_expiryPendingCount` or `loadBadge`.

- [ ] **Step 3: Commit**

```bash
git add public/index.html
git commit -m "feat: frontend badge — includes pending expiry approvals for Admin/Supervisor"
```

---

## Task 11: Frontend notification panel — expiry approval cards

**Files:**
- Modify: `public/index.html`

- [ ] **Step 1: Extend `loadNotifications()` to show expiry cards for Admin/Supervisor**

In `public/index.html`, find `async loadNotifications(forceRefresh = false)` (around line 2005). At the end of the method (after the `catch` block, before the closing `},`), add a new section that renders expiry approval cards:

Find the closing of `loadNotifications`:

```js
        } catch (e) { list.innerHTML = '<div class="search-no-results">Error de conexión</div>'; }
      },
```

Replace with:

```js
        } catch (e) { list.innerHTML = '<div class="search-no-results">Error de conexión</div>'; }

        // Append expiry approval cards for Admin/Supervisor
        const rol = _wmsUser?.rol ?? '';
        if (rol === 'Admin' || rol === 'Supervisor' || rol === 'SuperAdmin') {
          try {
            const ea = await API.get('/aprobaciones/vencimiento/pendientes');
            if (!ea.error && ea.data && ea.data.length > 0) {
              const expiryHtml = ea.data.map(ap => `
                <div class="notif-item unread" id="expiry-card-${ap.id}">
                  <div class="notif-item-icon alerta"><i class="fa-solid fa-clock"></i></div>
                  <div class="notif-body">
                    <div class="notif-titulo">⚠ Producto próximo a vencer (${ap.dias_restantes} días)</div>
                    <div class="notif-mensaje">${this.esc(ap.producto_nombre)} — Lote: ${this.esc(ap.lote)}</div>
                    <div class="notif-mensaje" style="color:#64748b;font-size:.72rem;">Solicitado por: ${this.esc(ap.auxiliar_nombre)} · ${this.tiempoRelativo(ap.created_at)}</div>
                    <div class="notif-actions" style="margin-top:6px;display:flex;gap:6px;">
                      <button class="btn btn-sm btn-success" onclick="WMS._resolverVencimiento(${ap.id},'aprobada',this)">
                        <i class="fa-solid fa-check"></i> Aprobar
                      </button>
                      <button class="btn btn-sm btn-danger" onclick="WMS._resolverVencimiento(${ap.id},'rechazada',this)">
                        <i class="fa-solid fa-times"></i> Rechazar
                      </button>
                    </div>
                  </div>
                </div>`).join('');
              list.insertAdjacentHTML('afterbegin', expiryHtml);
            }
          } catch(_) {}
        }
      },
```

- [ ] **Step 2: Add `_resolverVencimiento()` method to WMS object**

After the `completarNotif` method (around line 2060), add:

```js
      async _resolverVencimiento(id, decision, btn) {
        btn.disabled = true;
        try {
          const r = await API.post('/aprobaciones/' + id + '/resolver', { decision });
          if (r.error) { this.toast('error', r.message); btn.disabled = false; return; }
          document.getElementById('expiry-card-' + id)?.remove();
          this.toast('success', decision === 'aprobada' ? 'Solicitud aprobada' : 'Solicitud rechazada');
          this._expiryPendingCount = Math.max(0, this._expiryPendingCount - 1);
          this.loadBadge();
        } catch(e) { this.toast('error', 'Error al resolver'); btn.disabled = false; }
      },
```

- [ ] **Step 3: Test in browser**

Login as Admin, open notifications panel. If there are pending approvals in the DB table, they should appear at the top with Aprobar/Rechazar buttons.

- [ ] **Step 4: Commit**

```bash
git add public/index.html
git commit -m "feat: frontend notif panel — expiry approval cards with Aprobar/Rechazar for Admin/Supervisor"
```

---

## Task 12: Mobile frontend — waiting modal for confirmar-linea

**Files:**
- Modify: `public/mobile/index.html`

- [ ] **Step 1: Add waiting modal HTML**

In `public/mobile/index.html`, find the section with other modal definitions. Add a new modal (before the closing `</body>` tag):

```html
<!-- Expiry waiting modal -->
<div id="m-expiry-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.6);
  display:none;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;padding:24px;max-width:320px;width:90%;text-align:center;">
    <div style="font-size:2rem;margin-bottom:12px;">⏳</div>
    <div style="font-weight:700;font-size:1rem;margin-bottom:6px;">Esperando aprobación del supervisor</div>
    <div id="m-expiry-msg" style="font-size:.85rem;color:#64748b;margin-bottom:16px;"></div>
    <button onclick="MWMS._cancelarExpiry()" style="background:#ef4444;color:#fff;border:none;
      border-radius:6px;padding:8px 16px;cursor:pointer;font-size:.85rem;">
      Cancelar solicitud
    </button>
  </div>
</div>
```

- [ ] **Step 2: Add `_expiryPollTimer`, `_expiryAprobacionId` properties and helper methods to MWMS object**

Find the MWMS object definition and add these properties near the top:

```js
    _expiryPollTimer: null,
    _expiryAprobacionId: null,
    _expiryOnApproved: null,
```

Then add these methods to MWMS (after the existing methods, before the closing `};`):

```js
    _showExpiryModal(aprobacionId, message, onApproved) {
      this._expiryAprobacionId = aprobacionId;
      this._expiryOnApproved   = onApproved;
      document.getElementById('m-expiry-msg').textContent = message;
      const modal = document.getElementById('m-expiry-modal');
      modal.style.display = 'flex';
      this._expiryPollTimer = setInterval(() => this._pollExpiry(), 10000);
    },

    async _pollExpiry() {
      if (!this._expiryAprobacionId) return;
      try {
        const r = await mApi('GET', '/aprobaciones/' + this._expiryAprobacionId + '/estado');
        if (r.data?.estado === 'aprobada') {
          this._closeExpiryModal();
          this.toast('Solicitud aprobada. Continuando...', 'success');
          if (this._expiryOnApproved) await this._expiryOnApproved();
        } else if (r.data?.estado === 'rechazada') {
          this._closeExpiryModal();
          this.toast('Solicitud rechazada por el supervisor.', 'error');
        }
      } catch(_) {}
    },

    _closeExpiryModal() {
      clearInterval(this._expiryPollTimer);
      this._expiryPollTimer    = null;
      this._expiryAprobacionId = null;
      this._expiryOnApproved   = null;
      const modal = document.getElementById('m-expiry-modal');
      if (modal) modal.style.display = 'none';
    },

    async _cancelarExpiry() {
      if (!this._expiryAprobacionId) return;
      try {
        await mApi('DELETE', '/aprobaciones/' + this._expiryAprobacionId);
      } catch(_) {}
      this._closeExpiryModal();
      this.toast('Solicitud cancelada', 'info');
    },
```

- [ ] **Step 3: Handle 202 in the `confirmar-linea` call (around line 2851)**

Replace the existing `confirmar-linea` block:

```js
    this.loading(true);
    try {
      await mApi('POST', `/picking/${d.orden.id}/confirmar-linea`, { 
        linea_id: d.linea.id, 
        cantidad_tomada: nQty 
      });
      this.toast("Línea confirmada", "success");
      this.goPK(d.orden.id);
    } catch(e) { 
        this.toast(e.message, "error"); 
    } finally {
        this.loading(false);
    }
```

With:

```js
    this.loading(true);
    try {
      const r = await mApi('POST', `/picking/${d.orden.id}/confirmar-linea`, { 
        linea_id: d.linea.id, 
        cantidad_tomada: nQty 
      });
      if (r.status === 'pending_approval') {
        this.loading(false);
        this._showExpiryModal(r.aprobacion_id, r.message, async () => {
          // Retry after approval — supervisor approved, proceed
          const r2 = await mApi('POST', `/picking/${d.orden.id}/confirmar-linea`, {
            linea_id: d.linea.id,
            cantidad_tomada: nQty,
          });
          if (!r2.error) { this.toast("Línea confirmada", "success"); this.goPK(d.orden.id); }
          else this.toast(r2.message, "error");
        });
        return;
      }
      this.toast("Línea confirmada", "success");
      this.goPK(d.orden.id);
    } catch(e) { 
        this.toast(e.message, "error"); 
    } finally {
        this.loading(false);
    }
```

- [ ] **Step 4: Verify mobile page loads without errors**

Open http://localhost/WMS_FENIX/public/mobile/ in browser, check DevTools console. Expected: No JS errors on page load.

- [ ] **Step 5: Commit**

```bash
git add public/mobile/index.html
git commit -m "feat: mobile — expiry waiting modal + polling on confirmar-linea 202 response"
```

---

## Task 13: Desktop packing — waiting modal for agregarItemPacking

**Files:**
- Modify: `public/assets/js/desktop/despacho.js`

- [ ] **Step 1: Add `_expiryPollTimer`, `_expiryAprobacionId`, `_expiryOnApproved` to the module object**

Find the module object in `despacho.js` (begins with `WMS_MODULES.despacho = {` or similar). Add near the top:

```js
  _expiryPollTimer: null,
  _expiryAprobacionId: null,
  _expiryOnApproved: null,
```

- [ ] **Step 2: Add expiry waiting modal HTML insertion method and polling helpers**

Add these methods to the module object (before the closing `};` of the module):

```js
  _showExpiryWaitModal(aprobacionId, message, onApproved) {
    this._expiryAprobacionId = aprobacionId;
    this._expiryOnApproved   = onApproved;
    const modalId = 'expiry-wait-modal';
    document.getElementById(modalId)?.remove();
    document.body.insertAdjacentHTML('beforeend', `
      <div id="${modalId}" style="position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.6);
        display:flex;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:12px;padding:28px;max-width:360px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3);">
          <div style="font-size:2.5rem;margin-bottom:12px;">⏳</div>
          <div style="font-weight:700;font-size:1rem;margin-bottom:8px;">Esperando aprobación del supervisor</div>
          <div style="font-size:.85rem;color:#64748b;margin-bottom:20px;">${WMS.esc(message)}</div>
          <button onclick="WMS_MODULES.despacho._cancelarExpiryWait()" style="background:#ef4444;color:#fff;
            border:none;border-radius:6px;padding:8px 20px;cursor:pointer;font-size:.85rem;">
            Cancelar solicitud
          </button>
        </div>
      </div>`);
    this._expiryPollTimer = setInterval(() => this._pollExpiryWait(), 10000);
  },

  async _pollExpiryWait() {
    if (!this._expiryAprobacionId) return;
    try {
      const r = await API.get('/aprobaciones/' + this._expiryAprobacionId + '/estado');
      if (r.data?.estado === 'aprobada') {
        this._closeExpiryWaitModal();
        WMS.toast('success', 'Solicitud aprobada. Continuando...');
        if (this._expiryOnApproved) await this._expiryOnApproved();
      } else if (r.data?.estado === 'rechazada') {
        this._closeExpiryWaitModal();
        WMS.toast('error', 'Solicitud rechazada por el supervisor.');
      }
    } catch(_) {}
  },

  _closeExpiryWaitModal() {
    clearInterval(this._expiryPollTimer);
    this._expiryPollTimer    = null;
    this._expiryAprobacionId = null;
    this._expiryOnApproved   = null;
    document.getElementById('expiry-wait-modal')?.remove();
  },

  async _cancelarExpiryWait() {
    if (this._expiryAprobacionId) {
      try { await API.delete('/aprobaciones/' + this._expiryAprobacionId); } catch(_) {}
    }
    this._closeExpiryWaitModal();
    WMS.toast('warning', 'Solicitud de vencimiento cancelada');
  },
```

- [ ] **Step 3: Handle 202 in `agregarItemPacking()` (around line 1395)**

Replace the existing method:

```js
  async agregarItemPacking(sesionId, productoId) {
    const qty = parseFloat(document.getElementById('pk-qty-' + productoId)?.value || 0);
    if (!qty || qty <= 0) { WMS.toast('error', 'Ingrese una cantidad válida'); return; }
    try {
      const r = await API.post('/packing/sesion/' + sesionId + '/item', {
        producto_id: productoId,
        cantidad:    qty,
      });
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Ítem agregado');
      await this.show_packing(sesionId);
    } catch(e) { WMS.toast('error', 'Error al agregar'); }
  },
```

With:

```js
  async agregarItemPacking(sesionId, productoId) {
    const qty = parseFloat(document.getElementById('pk-qty-' + productoId)?.value || 0);
    if (!qty || qty <= 0) { WMS.toast('error', 'Ingrese una cantidad válida'); return; }
    try {
      const r = await API.post('/packing/sesion/' + sesionId + '/item', {
        producto_id: productoId,
        cantidad:    qty,
      });
      if (r.error) { WMS.toast('error', r.message); return; }
      if (r.status === 'pending_approval') {
        this._showExpiryWaitModal(r.aprobacion_id, r.message, async () => {
          const r2 = await API.post('/packing/sesion/' + sesionId + '/item', {
            producto_id: productoId,
            cantidad:    qty,
          });
          if (!r2.error) { WMS.toast('success', 'Ítem agregado'); await this.show_packing(sesionId); }
          else WMS.toast('error', r2.message);
        });
        return;
      }
      WMS.toast('success', 'Ítem agregado');
      await this.show_packing(sesionId);
    } catch(e) { WMS.toast('error', 'Error al agregar'); }
  },
```

- [ ] **Step 4: Verify syntax by opening the module in browser**

Navigate to Despacho module in the WMS, open DevTools console. Expected: No JS errors during module load.

- [ ] **Step 5: Commit**

```bash
git add public/assets/js/desktop/despacho.js
git commit -m "feat: despacho.js — expiry waiting modal + polling on agregarItemPacking 202 response"
```

---

## Self-Review Checklist

After all tasks are implemented, run these acceptance checks:

**Backend verification:**
```bash
# 1. Syntax check all modified PHP files
php -l src/Helpers/ExpiryGuard.php && \
php -l src/Helpers/InventoryGuard.php && \
php -l src/Helpers/FefoEngine.php && \
php -l src/Controllers/AprobacionController.php && \
php -l src/Controllers/PickingController.php && \
php -l src/Controllers/PackingController.php && \
php -l src/Models/AprobacionVencimiento.php && \
php -l src/Models/PickingDetalle.php
```

**DB verify:**
```sql
-- Confirm table and column exist
SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'aprobaciones_vencimiento';
SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'picking_detalles' AND column_name = 'fecha_vencimiento';
```

**Acceptance tests via API:**
1. `POST /api/picking/{id}/confirmar-linea` with a product whose inventory `fecha_vencimiento < today` → 422 with message containing "vencido"
2. `POST /api/picking/{id}/confirmar-linea` with a product `fecha_vencimiento = today + 3 days` → 202 with `aprobacion_id`
3. `GET /api/aprobaciones/{aprobacion_id}/estado` → `{"estado": "pendiente"}`
4. `POST /api/aprobaciones/{aprobacion_id}/resolver` with `{"decision": "aprobada"}` as Admin → 200
5. Retry the same `confirmar-linea` → 200 OK (approval found for today)
6. `GET /api/aprobaciones/vencimiento/pendientes` as Auxiliar → 403
