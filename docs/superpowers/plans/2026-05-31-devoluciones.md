# Devoluciones — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extender el módulo de devoluciones existente con flujo de aprobación por supervisor, tres tipos (cliente/proveedor/interna), destino por ítem al procesar, integración QR, badge/notificaciones, y pantalla mobile para devoluciones de cliente.

**Architecture:** Las tablas `devoluciones` y `devolucion_detalles` ya existen — la migración las extiende con columnas nuevas y nuevos valores de enum. `DevolucionController` ya existe — se le agregan 4 métodos nuevos (`aprobar`, `rechazar`, `procesar`, `anular`) y se actualiza `store()` y `index()`. El módulo desktop es un JS nuevo `devoluciones.js`. El badge y el panel de notificaciones en `index.html` se extienden con el mismo patrón que las aprobaciones de vencimiento.

**Tech Stack:** PHP 8.2, Slim 4, Eloquent/Capsule ORM, MySQL/PostgreSQL dual-driver, Vanilla JS (WMS_MODULES pattern), `GET /api/recepciones/buscar-qr` reutilizado para escaneo QR.

---

## File Map

| Acción | Archivo | Responsabilidad |
|--------|---------|-----------------|
| CREATE | `database/migrations/072_devoluciones_upgrade.php` | Extender enums + columnas nuevas en tablas existentes |
| MODIFY | `src/Models/Devolucion.php` | Añadir fillable + casts + constantes nuevas |
| MODIFY | `src/Models/DevolucionDetalle.php` | Añadir condicion, nuevos destinos a fillable/casts |
| MODIFY | `src/Controllers/DevolucionController.php` | Nuevos métodos: aprobar, rechazar, procesar, anular; actualizar store() e index() |
| MODIFY | `public/index.php` | Registrar 4 nuevas rutas + listar con filtros |
| CREATE | `public/assets/js/desktop/devoluciones.js` | Módulo desktop completo: lista, crear, detalle, aprobar, procesar |
| MODIFY | `public/index.html` | Registrar módulo en nav+scripts, badge, notificaciones, sidebar |
| MODIFY | `public/mobile/index.html` | Sección devoluciones: QR, ítems, submit, mis-devoluciones |

---

## Task 1: Migration 072 — extender tablas existentes

**Files:**
- Create: `database/migrations/072_devoluciones_upgrade.php`

- [ ] **Step 1: Crear el archivo de migración**

```php
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
                $t->unsignedBigInteger('aprobado_por')->nullable()->after('autorizado_por');
            }
            if (!$schema->hasColumn('devoluciones', 'procesado_por')) {
                $t->unsignedBigInteger('procesado_por')->nullable()->after('aprobado_por');
            }
            if (!$schema->hasColumn('devoluciones', 'aprobado_at')) {
                $t->timestamp('aprobado_at')->nullable()->after('fecha_autorizacion');
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
```

- [ ] **Step 2: Correr la migración**

Abrir en el navegador: `http://localhost/WMS_FENIX/public/run-migrations.php`

Expected: `072_devoluciones_upgrade` aparece como "applied".

- [ ] **Step 3: Verificar en MySQL**

```sql
SHOW COLUMNS FROM devoluciones LIKE 'tipo';
SHOW COLUMNS FROM devoluciones LIKE 'estado';
SHOW COLUMNS FROM devoluciones LIKE 'aprobado_por';
SHOW COLUMNS FROM devolucion_detalles LIKE 'condicion';
```

Expected: `tipo` y `estado` muestran los nuevos valores en el enum. `aprobado_por` y `condicion` existen.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/072_devoluciones_upgrade.php
git commit -m "feat: migration 072 — extender devoluciones y devolucion_detalles con flujo aprobación"
```

---

## Task 2: Modelos — Devolucion + DevolucionDetalle

**Files:**
- Modify: `src/Models/Devolucion.php`
- Modify: `src/Models/DevolucionDetalle.php`

- [ ] **Step 1: Actualizar `src/Models/Devolucion.php`**

Reemplazar el contenido completo del archivo:

```php
<?php
// src/Models/Devolucion.php
namespace App\Models;

use App\Models\Concerns\TenantScoped;

class Devolucion extends BaseModel
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;
    protected $table = 'devoluciones';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'recepcion_id', 'odc_id', 'numero_devolucion',
        'proveedor', 'referencia_externa', 'tipo', 'auxiliar_id', 'solicitado_por',
        'fecha_movimiento', 'hora_inicio', 'hora_fin',
        'estado', 'motivo_general', 'fotos_json', 'observaciones',
        'autorizado_por', 'fecha_autorizacion', 'fecha_devolucion',
        'aprobado_por', 'procesado_por', 'aprobado_at', 'procesado_at',
    ];

    protected $casts = [
        'fecha_movimiento'   => 'date',
        'fecha_autorizacion' => 'datetime',
        'aprobado_at'        => 'datetime',
        'procesado_at'       => 'datetime',
        'fotos_json'         => 'array',
    ];

    // Tipos legacy (proveedor)
    const TIPO_AVERIA    = 'AProveedorAveria';
    const TIPO_VENCIDO   = 'AProveedorVencido';
    const TIPO_REINGRESO = 'ReingresoBuenEstado';

    // Tipos nuevos
    const TIPO_CLIENTE   = 'cliente';
    const TIPO_PROVEEDOR = 'proveedor';
    const TIPO_INTERNA   = 'interna';

    // Estados
    const ESTADO_PENDIENTE   = 'PendienteAprobacion';
    const ESTADO_APROBADA    = 'Aprobada';
    const ESTADO_PROCESADA   = 'Procesada';
    const ESTADO_RECHAZADA   = 'Rechazada';
    const ESTADO_ANULADA     = 'Anulada';

    public function empresa()    { return $this->belongsTo(Empresa::class); }
    public function sucursal()   { return $this->belongsTo(Sucursal::class); }
    public function recepcion()  { return $this->belongsTo(Recepcion::class); }
    public function auxiliar()   { return $this->belongsTo(Personal::class, 'auxiliar_id'); }
    public function solicitante(){ return $this->belongsTo(Personal::class, 'solicitado_por'); }
    public function aprobador()  { return $this->belongsTo(Personal::class, 'aprobado_por'); }
    public function detalles()   { return $this->hasMany(DevolucionDetalle::class); }

    public static function generarNumero(int $empresaId): string
    {
        $year = date('Y');
        $last = self::where('empresa_id', $empresaId)
            ->where('numero_devolucion', 'like', "DEV-{$year}-%")
            ->count();
        return sprintf('DEV-%s-%04d', $year, $last + 1);
    }
}
```

- [ ] **Step 2: Actualizar `src/Models/DevolucionDetalle.php`**

Reemplazar el contenido completo del archivo:

```php
<?php
// src/Models/DevolucionDetalle.php
namespace App\Models;

class DevolucionDetalle extends BaseModel
{
    protected $table = 'devolucion_detalles';

    protected $fillable = [
        'devolucion_id', 'producto_id', 'lote', 'fecha_vencimiento',
        'cantidad', 'condicion', 'motivo', 'detalle_motivo',
        'destino', 'ubicacion_destino_id',
    ];

    protected $casts = [
        'fecha_vencimiento' => 'date',
        'cantidad'          => 'float',
    ];

    // Destinos legacy
    const DESTINO_OBSOLETO   = 'InventarioObsoleto';
    const DESTINO_REINGRESO  = 'Reingreso';
    const DESTINO_DEVOLUCION = 'DevolucionProveedor';

    // Destinos nuevos
    const DESTINO_RESTOCK    = 'restock';
    const DESTINO_DESCARTE   = 'descarte';
    const DESTINO_PROVEEDOR  = 'proveedor';

    // Condiciones
    const CONDICION_BUENO   = 'bueno';
    const CONDICION_DANADO  = 'dañado';
    const CONDICION_VENCIDO = 'vencido';
    const CONDICION_OTRO    = 'otro';

    public function devolucion() { return $this->belongsTo(Devolucion::class); }
    public function producto()   { return $this->belongsTo(Producto::class); }
}
```

- [ ] **Step 3: Verificar sintaxis**

```bash
php -l src/Models/Devolucion.php && php -l src/Models/DevolucionDetalle.php
```

Expected: `No syntax errors detected` en ambos.

- [ ] **Step 4: Commit**

```bash
git add src/Models/Devolucion.php src/Models/DevolucionDetalle.php
git commit -m "feat: Devolucion + DevolucionDetalle models — nuevos tipos, estados, relaciones"
```

---

## Task 3: DevolucionController — nuevos métodos + actualizar store() e index()

**Files:**
- Modify: `src/Controllers/DevolucionController.php`

- [ ] **Step 1: Actualizar `index()` para soportar filtros**

En `DevolucionController.php`, reemplazar el método `index()` completo (líneas ~18-34):

```php
    public function index(Request $request, Response $response): Response
    {
        $user       = $request->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $params     = $request->getQueryParams();

        $q = Devolucion::with('detalles')
            ->where('empresa_id',  $empresaId)
            ->where('sucursal_id', $sucursalId);

        if (!empty($params['tipo']))   $q->where('tipo',   $params['tipo']);
        if (!empty($params['estado'])) $q->where('estado', $params['estado']);
        if (!empty($params['desde']))  $q->where('created_at', '>=', $params['desde'] . ' 00:00:00');
        if (!empty($params['hasta']))  $q->where('created_at', '<=', $params['hasta'] . ' 23:59:59');
        if (!empty($params['q'])) {
            $sq = $params['q'];
            $q->where(fn($qb) => $qb->where('numero_devolucion', 'like', "%{$sq}%")
                                    ->orWhere('referencia_externa', 'like', "%{$sq}%")
                                    ->orWhere('motivo_general', 'like', "%{$sq}%"));
        }

        $devoluciones = $q->orderBy('created_at', 'desc')->limit(200)->get();
        return $this->ok($response, $devoluciones);
    }
```

- [ ] **Step 2: Actualizar `store()` para usar el nuevo flujo**

Reemplazar el método `store()` completo (empieza en línea ~92):

```php
    public function store(Request $request, Response $response): Response
    {
        $user       = $request->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $data       = $request->getParsedBody() ?? [];

        if ($deny = $this->requireFields($data, ['tipo', 'motivo_general', 'detalles'], $response)) {
            return $deny;
        }

        $tiposValidos = ['AProveedorAveria','AProveedorVencido','ReingresoBuenEstado','cliente','proveedor','interna'];
        if (!in_array($data['tipo'], $tiposValidos, true)) {
            return $this->error($response, 'tipo inválido');
        }

        $detalles = $data['detalles'] ?? [];
        if (empty($detalles) || !is_array($detalles)) {
            return $this->error($response, 'Debe incluir al menos un producto a devolver');
        }

        return \Illuminate\Database\Capsule\Manager::transaction(function () use (
            $user, $empresaId, $sucursalId, $data, $detalles, $response
        ) {
            $numero = Devolucion::generarNumero($empresaId);

            $dev = Devolucion::create([
                'empresa_id'         => $empresaId,
                'sucursal_id'        => $sucursalId,
                'numero_devolucion'  => $numero,
                'tipo'               => $data['tipo'],
                'estado'             => Devolucion::ESTADO_PENDIENTE,
                'motivo_general'     => $data['motivo_general'],
                'referencia_externa' => $data['referencia_externa'] ?? null,
                'auxiliar_id'        => $user->id,
                'solicitado_por'     => $user->id,
                'fecha_movimiento'   => date('Y-m-d'),
                'hora_inicio'        => date('H:i:s'),
                'recepcion_id'       => $data['recepcion_id'] ?? null,
                'proveedor'          => $data['proveedor'] ?? null,
            ]);

            foreach ($detalles as $d) {
                DevolucionDetalle::create([
                    'devolucion_id'   => $dev->id,
                    'producto_id'     => (int)$d['producto_id'],
                    'lote'            => $d['lote'] ?? null,
                    'fecha_vencimiento' => $d['fecha_vencimiento'] ?? null,
                    'cantidad'        => (float)($d['cantidad'] ?? 0),
                    'condicion'       => $d['condicion'] ?? null,
                    'motivo'          => $d['motivo'] ?? 'Otro',
                    'detalle_motivo'  => $d['motivo_item'] ?? null,
                    'destino'         => null,
                ]);
            }

            // Notificar a supervisores via anomaly_flags
            if (\Illuminate\Database\Capsule\Manager::schema()->hasTable('anomaly_flags')) {
                \Illuminate\Database\Capsule\Manager::table('anomaly_flags')->insert([
                    'empresa_id'     => $empresaId,
                    'sucursal_id'    => $sucursalId,
                    'tipo'           => 'devolucion',
                    'severidad'      => 'media',
                    'titulo'         => "Devolución {$numero} — aprobación requerida",
                    'descripcion'    => count($detalles) . ' ítem(s). Motivo: ' . mb_substr($data['motivo_general'], 0, 100),
                    'datos_anomalia' => json_encode(['devolucion_id' => $dev->id, 'tipo' => $data['tipo']], JSON_UNESCAPED_UNICODE),
                    'estado'         => 'pendiente',
                    'created_at'     => date('Y-m-d H:i:s'),
                    'updated_at'     => date('Y-m-d H:i:s'),
                ]);
            }

            $this->audit($user, 'devoluciones', 'crear', 'devoluciones', $dev->id,
                null, ['numero' => $numero, 'tipo' => $dev->tipo]);

            return $this->created($response, ['devolucion_id' => $dev->id, 'numero' => $numero], 'Devolución registrada');
        });
    }
```

- [ ] **Step 3: Agregar método `aprobar()`**

Agregar después del método `autorizar()` existente:

```php
    // ── POST /api/devoluciones/{id}/aprobar ───────────────────────────────────
    public function aprobar(Request $request, Response $response, array $args): Response
    {
        $user      = $request->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $response)) return $deny;
        $empresaId = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        $dev = Devolucion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find((int)($args['id'] ?? 0));
        if (!$dev) return $this->notFound($response);
        if ($dev->estado !== Devolucion::ESTADO_PENDIENTE) {
            return $this->error($response, 'La devolución no está en estado PendienteAprobacion', 409);
        }

        $dev->estado      = Devolucion::ESTADO_APROBADA;
        $dev->aprobado_por = $user->id;
        $dev->aprobado_at  = date('Y-m-d H:i:s');
        $dev->save();

        $this->audit($user, 'devoluciones', 'aprobar', 'devoluciones', $dev->id,
            ['estado' => Devolucion::ESTADO_PENDIENTE], ['estado' => Devolucion::ESTADO_APROBADA]);

        return $this->ok($response, null, 'Devolución aprobada');
    }

    // ── POST /api/devoluciones/{id}/rechazar ──────────────────────────────────
    public function rechazar(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $response)) return $deny;
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $data       = $request->getParsedBody() ?? [];

        $dev = Devolucion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find((int)($args['id'] ?? 0));
        if (!$dev) return $this->notFound($response);
        if ($dev->estado !== Devolucion::ESTADO_PENDIENTE) {
            return $this->error($response, 'La devolución no está en estado PendienteAprobacion', 409);
        }

        $dev->estado       = Devolucion::ESTADO_RECHAZADA;
        $dev->aprobado_por = $user->id;
        $dev->aprobado_at  = date('Y-m-d H:i:s');
        $dev->observaciones = $data['motivo_rechazo'] ?? null;
        $dev->save();

        $this->audit($user, 'devoluciones', 'rechazar', 'devoluciones', $dev->id,
            ['estado' => Devolucion::ESTADO_PENDIENTE], ['estado' => Devolucion::ESTADO_RECHAZADA]);

        return $this->ok($response, null, 'Devolución rechazada');
    }

    // ── POST /api/devoluciones/{id}/anular ────────────────────────────────────
    public function anular(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $response)) return $deny;
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);

        $dev = Devolucion::where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find((int)($args['id'] ?? 0));
        if (!$dev) return $this->notFound($response);
        if (in_array($dev->estado, [Devolucion::ESTADO_PROCESADA, Devolucion::ESTADO_ANULADA], true)) {
            return $this->error($response, 'No se puede anular una devolución ya procesada o anulada', 409);
        }

        $dev->estado = Devolucion::ESTADO_ANULADA;
        $dev->save();

        $this->audit($user, 'devoluciones', 'anular', 'devoluciones', $dev->id,
            ['estado' => $dev->getOriginal('estado')], ['estado' => Devolucion::ESTADO_ANULADA]);

        return $this->ok($response, null, 'Devolución anulada');
    }
```

- [ ] **Step 4: Agregar método `procesar()`**

Agregar después de `anular()`:

```php
    // ── POST /api/devoluciones/{id}/procesar ──────────────────────────────────
    public function procesar(Request $request, Response $response, array $args): Response
    {
        $user       = $request->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $request);
        $sucursalId = $this->getEffectiveSucursalId($user, $request);
        $data       = $request->getParsedBody() ?? [];

        $dev = Devolucion::with('detalles')
            ->where('empresa_id', $empresaId)
            ->where('sucursal_id', $sucursalId)
            ->find((int)($args['id'] ?? 0));
        if (!$dev) return $this->notFound($response);
        if ($dev->estado !== Devolucion::ESTADO_APROBADA) {
            return $this->error($response, 'La devolución debe estar en estado Aprobada', 409);
        }

        $itemDecisiones = [];
        foreach ($data['items'] ?? [] as $it) {
            $itemDecisiones[(int)$it['id']] = $it['destino'] ?? null;
        }

        $destinosValidos = [DevolucionDetalle::DESTINO_RESTOCK, DevolucionDetalle::DESTINO_DESCARTE, DevolucionDetalle::DESTINO_PROVEEDOR];

        // Validar que todos los ítems tienen destino
        foreach ($dev->detalles as $det) {
            $destino = $itemDecisiones[$det->id] ?? null;
            if (!in_array($destino, $destinosValidos, true)) {
                return $this->error($response, "Todos los ítems deben tener destino asignado (restock, descarte o proveedor)", 422);
            }
        }

        return \Illuminate\Database\Capsule\Manager::transaction(function () use (
            $dev, $user, $empresaId, $sucursalId, $itemDecisiones, $response
        ) {
            $devProveedorId = null;
            $devProveedorItems = [];

            foreach ($dev->detalles as $det) {
                $destino = $itemDecisiones[$det->id];
                $det->destino = $destino;
                $det->save();

                if ($destino === DevolucionDetalle::DESTINO_RESTOCK) {
                    // Buscar inventario existente para el lote
                    $inv = \Illuminate\Database\Capsule\Manager::table('inventarios')
                        ->where('empresa_id',  $empresaId)
                        ->where('sucursal_id', $sucursalId)
                        ->where('producto_id', $det->producto_id)
                        ->where('lote',        $det->lote)
                        ->where('estado',      'Disponible')
                        ->first();

                    if ($inv) {
                        \Illuminate\Database\Capsule\Manager::table('inventarios')
                            ->where('id', $inv->id)
                            ->update(['cantidad' => $inv->cantidad + $det->cantidad, 'updated_at' => date('Y-m-d H:i:s')]);
                    } else {
                        \Illuminate\Database\Capsule\Manager::table('inventarios')->insert([
                            'empresa_id'          => $empresaId,
                            'sucursal_id'         => $sucursalId,
                            'producto_id'         => $det->producto_id,
                            'lote'                => $det->lote,
                            'fecha_vencimiento'   => $det->fecha_vencimiento,
                            'cantidad'            => $det->cantidad,
                            'cantidad_reservada'  => 0,
                            'estado'              => 'Disponible',
                            'created_at'          => date('Y-m-d H:i:s'),
                            'updated_at'          => date('Y-m-d H:i:s'),
                        ]);
                    }

                    // Movimiento de entrada
                    \Illuminate\Database\Capsule\Manager::table('movimiento_inventarios')->insert([
                        'empresa_id'       => $empresaId,
                        'sucursal_id'      => $sucursalId,
                        'producto_id'      => $det->producto_id,
                        'tipo_movimiento'  => 'Devolucion',
                        'cantidad'         => $det->cantidad,
                        'lote'             => $det->lote,
                        'fecha_vencimiento'=> $det->fecha_vencimiento,
                        'referencia_tipo'  => 'devolucion',
                        'referencia_id'    => $dev->id,
                        'auxiliar_id'      => $user->id,
                        'fecha_movimiento' => date('Y-m-d'),
                        'hora_inicio'      => date('H:i:s'),
                        'observaciones'    => "Restock devolución {$dev->numero_devolucion}",
                        'created_at'       => date('Y-m-d H:i:s'),
                    ]);

                } elseif ($destino === DevolucionDetalle::DESTINO_DESCARTE) {
                    // Solo registro de movimiento — el producto nunca reingresó a inventario
                    \Illuminate\Database\Capsule\Manager::table('movimiento_inventarios')->insert([
                        'empresa_id'       => $empresaId,
                        'sucursal_id'      => $sucursalId,
                        'producto_id'      => $det->producto_id,
                        'tipo_movimiento'  => 'AjusteNegativo',
                        'cantidad'         => -abs($det->cantidad),
                        'lote'             => $det->lote,
                        'fecha_vencimiento'=> $det->fecha_vencimiento,
                        'referencia_tipo'  => 'devolucion',
                        'referencia_id'    => $dev->id,
                        'auxiliar_id'      => $user->id,
                        'fecha_movimiento' => date('Y-m-d'),
                        'hora_inicio'      => date('H:i:s'),
                        'observaciones'    => "Descarte devolución {$dev->numero_devolucion}",
                        'created_at'       => date('Y-m-d H:i:s'),
                    ]);

                } elseif ($destino === DevolucionDetalle::DESTINO_PROVEEDOR) {
                    $devProveedorItems[] = $det;
                }
            }

            // Crear devolución-proveedor automática si hay ítems con destino=proveedor
            if (!empty($devProveedorItems)) {
                $numProv = Devolucion::generarNumero($empresaId);
                $devProv = Devolucion::create([
                    'empresa_id'        => $empresaId,
                    'sucursal_id'       => $sucursalId,
                    'numero_devolucion' => $numProv,
                    'tipo'              => Devolucion::TIPO_PROVEEDOR,
                    'estado'            => Devolucion::ESTADO_PENDIENTE,
                    'motivo_general'    => "Generada automáticamente desde {$dev->numero_devolucion}",
                    'auxiliar_id'       => $user->id,
                    'solicitado_por'    => $user->id,
                    'fecha_movimiento'  => date('Y-m-d'),
                    'hora_inicio'       => date('H:i:s'),
                ]);
                foreach ($devProveedorItems as $det) {
                    DevolucionDetalle::create([
                        'devolucion_id'   => $devProv->id,
                        'producto_id'     => $det->producto_id,
                        'lote'            => $det->lote,
                        'fecha_vencimiento' => $det->fecha_vencimiento,
                        'cantidad'        => $det->cantidad,
                        'condicion'       => $det->condicion,
                        'motivo'          => $det->motivo ?? 'Otro',
                        'destino'         => null,
                    ]);
                }
                $devProveedorId = $devProv->id;
            }

            $dev->estado       = Devolucion::ESTADO_PROCESADA;
            $dev->procesado_por = $user->id;
            $dev->procesado_at  = date('Y-m-d H:i:s');
            $dev->save();

            $this->audit($user, 'devoluciones', 'procesar', 'devoluciones', $dev->id,
                ['estado' => Devolucion::ESTADO_APROBADA], ['estado' => Devolucion::ESTADO_PROCESADA]);

            return $this->ok($response, [
                'devolucion_proveedor_id' => $devProveedorId,
            ], 'Devolución procesada correctamente');
        });
    }
```

- [ ] **Step 5: Verificar sintaxis**

```bash
php -l src/Controllers/DevolucionController.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/DevolucionController.php
git commit -m "feat: DevolucionController — aprobar, rechazar, procesar, anular + store/index actualizados"
```

---

## Task 4: Rutas — registrar 4 nuevas rutas en index.php

**Files:**
- Modify: `public/index.php`

- [ ] **Step 1: Agregar las 4 rutas nuevas**

En `public/index.php`, encontrar el bloque existente de rutas de devoluciones (línea ~505):

```php
    $group->post('/devoluciones/{id}/autorizar', [\App\Controllers\DevolucionController::class, 'autorizar']);
    $group->post('/devoluciones/{id}/completar', [\App\Controllers\DevolucionController::class, 'completar']);
```

Agregar inmediatamente después de esas dos líneas:

```php
    $group->post('/devoluciones/{id}/aprobar',  [\App\Controllers\DevolucionController::class, 'aprobar']);
    $group->post('/devoluciones/{id}/rechazar', [\App\Controllers\DevolucionController::class, 'rechazar']);
    $group->post('/devoluciones/{id}/procesar', [\App\Controllers\DevolucionController::class, 'procesar']);
    $group->post('/devoluciones/{id}/anular',   [\App\Controllers\DevolucionController::class, 'anular']);
```

- [ ] **Step 2: Smoke-test rutas**

Abrir en el navegador (requiere token JWT — usar Postman o la app con sesión iniciada):

```
GET http://localhost/WMS_FENIX/public/api/devoluciones?estado=PendienteAprobacion
```

Expected: `{"error":false,"data":[],"message":"OK"}` (o lista de devoluciones si ya existen).

```
POST http://localhost/WMS_FENIX/public/api/devoluciones/99999/aprobar
```

Expected: `{"error":true,"message":"Registro no encontrado"}` con HTTP 404 (no 500).

- [ ] **Step 3: Commit**

```bash
git add public/index.php
git commit -m "feat: routes — devoluciones aprobar/rechazar/procesar/anular"
```

---

## Task 5: Frontend desktop — devoluciones.js (módulo completo)

**Files:**
- Create: `public/assets/js/desktop/devoluciones.js`

- [ ] **Step 1: Crear el archivo con estructura base, lista y filtros**

```javascript
// public/assets/js/desktop/devoluciones.js
'use strict';
WMS_MODULES.devoluciones = {

  _state: { lista: [], detalle: null, qrProd: null, items: [] },

  load(sub) {
    sub = sub || 'lista';
    if (sub === 'lista')  return this.showLista();
    if (sub === 'nueva')  return this.showNueva();
  },

  // ── LISTA ─────────────────────────────────────────────────────────────────

  async showLista(filtros = {}) {
    WMS.spinner();
    const qs = new URLSearchParams(filtros).toString();
    try {
      const r = await API.get('/devoluciones' + (qs ? '?' + qs : ''));
      this._state.lista = r.data || [];
      this._renderLista(this._state.lista);
    } catch(e) { WMS.toast('error', 'Error al cargar devoluciones'); }
  },

  _renderLista(rows) {
    const badgeColor = {
      PendienteAprobacion: '#f59e0b', Aprobada: '#3b82f6', Procesada: '#16a34a',
      Rechazada: '#dc2626', Anulada: '#94a3b8', Borrador: '#64748b',
    };
    const tipoLabel = {
      cliente: 'Cliente→WMS', proveedor: 'WMS→Proveedor', interna: 'Interna',
      AProveedorAveria: 'Proveedor (Avería)', AProveedorVencido: 'Proveedor (Vencido)',
      ReingresoBuenEstado: 'Reingreso', Borrador: 'Sin tipo',
    };

    WMS.setToolbar(`
      <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones.showNueva()">
        <i class="fa-solid fa-plus"></i> Nueva Devolución
      </button>`);

    WMS.setContent(`
      <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
          <span class="card-title"><i class="fa-solid fa-rotate-left"></i> Devoluciones</span>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <select id="dv-f-tipo" class="form-control form-control-sm" style="min-width:140px;" onchange="WMS_MODULES.devoluciones._aplicarFiltros()">
              <option value="">Todos los tipos</option>
              <option value="cliente">Cliente→WMS</option>
              <option value="proveedor">WMS→Proveedor</option>
              <option value="interna">Interna</option>
            </select>
            <select id="dv-f-estado" class="form-control form-control-sm" style="min-width:160px;" onchange="WMS_MODULES.devoluciones._aplicarFiltros()">
              <option value="">Todos los estados</option>
              <option value="PendienteAprobacion">Pendiente Aprobación</option>
              <option value="Aprobada">Aprobada</option>
              <option value="Procesada">Procesada</option>
              <option value="Rechazada">Rechazada</option>
              <option value="Anulada">Anulada</option>
            </select>
            <input type="date" id="dv-f-desde" class="form-control form-control-sm" onchange="WMS_MODULES.devoluciones._aplicarFiltros()">
            <input type="date" id="dv-f-hasta" class="form-control form-control-sm" onchange="WMS_MODULES.devoluciones._aplicarFiltros()">
            <input type="text" id="dv-f-q" class="form-control form-control-sm" placeholder="Buscar N°, referencia..." style="min-width:180px;"
              oninput="WMS_MODULES.devoluciones._aplicarFiltros()">
          </div>
        </div>
        <div class="table-container">
          <table class="erp-table">
            <thead><tr>
              <th>N°</th><th>Tipo</th><th>Estado</th><th>Referencia ERP</th>
              <th class="text-center">Ítems</th><th>Fecha</th><th>Solicitado por</th><th>Acciones</th>
            </tr></thead>
            <tbody id="dv-tbody">
              ${rows.length ? rows.map(d => `
                <tr>
                  <td><strong>${WMS.esc(d.numero_devolucion)}</strong></td>
                  <td><span class="badge" style="background:#e0f2fe;color:#0369a1;">${WMS.esc(tipoLabel[d.tipo]||d.tipo)}</span></td>
                  <td><span class="badge" style="background:${badgeColor[d.estado]||'#94a3b8'}20;color:${badgeColor[d.estado]||'#94a3b8'};border:1px solid ${badgeColor[d.estado]||'#94a3b8'};">${WMS.esc(d.estado)}</span></td>
                  <td>${WMS.esc(d.referencia_externa||'-')}</td>
                  <td class="text-center">${(d.detalles||[]).length}</td>
                  <td style="font-size:11px;">${d.created_at ? d.created_at.substring(0,10) : '-'}</td>
                  <td style="font-size:11px;">${WMS.esc(d.solicitado_por_nombre||'-')}</td>
                  <td>
                    <button class="btn btn-sm btn-outline-primary" onclick="WMS_MODULES.devoluciones.showDetalle(${d.id})">
                      <i class="fa-solid fa-eye"></i> Ver
                    </button>
                  </td>
                </tr>`).join('') : '<tr><td colspan="8" class="table-empty">Sin devoluciones registradas</td></tr>'}
            </tbody>
          </table>
        </div>
      </div>`);
  },

  _aplicarFiltros() {
    clearTimeout(this._filterTimer);
    this._filterTimer = setTimeout(() => {
      const f = {
        tipo:   document.getElementById('dv-f-tipo')?.value   || '',
        estado: document.getElementById('dv-f-estado')?.value || '',
        desde:  document.getElementById('dv-f-desde')?.value  || '',
        hasta:  document.getElementById('dv-f-hasta')?.value  || '',
        q:      document.getElementById('dv-f-q')?.value      || '',
      };
      Object.keys(f).forEach(k => { if (!f[k]) delete f[k]; });
      this.showLista(f);
    }, 400);
  },
```

- [ ] **Step 2: Agregar `showDetalle()` y botones de acción**

Continuar en el mismo archivo:

```javascript
  // ── DETALLE ───────────────────────────────────────────────────────────────

  async showDetalle(id) {
    WMS.spinner();
    try {
      const r = await API.get('/devoluciones/' + id);
      const d = r.data;
      this._state.detalle = d;
      const estado = d.estado;

      const canAprobar  = estado === 'PendienteAprobacion';
      const canProcesar = estado === 'Aprobada';
      const canAnular   = ['PendienteAprobacion','Borrador'].includes(estado);
      const rol = _wmsUser?.rol ?? '';
      const isSup = ['Admin','Supervisor','SuperAdmin','Jefe'].includes(rol);

      const badgeColor = {
        PendienteAprobacion:'#f59e0b',Aprobada:'#3b82f6',Procesada:'#16a34a',
        Rechazada:'#dc2626',Anulada:'#94a3b8',Borrador:'#64748b',
      };

      WMS.setToolbar(`
        <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.devoluciones.showLista()">
          <i class="fa-solid fa-arrow-left"></i> Volver
        </button>
        ${isSup && canAprobar  ? `<button class="btn btn-success btn-sm" onclick="WMS_MODULES.devoluciones.aprobar(${d.id})"><i class="fa-solid fa-check"></i> Aprobar</button>` : ''}
        ${isSup && canAprobar  ? `<button class="btn btn-danger btn-sm" onclick="WMS_MODULES.devoluciones.rechazar(${d.id})"><i class="fa-solid fa-times"></i> Rechazar</button>` : ''}
        ${canProcesar           ? `<button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones.abrirProcesar(${d.id})"><i class="fa-solid fa-gears"></i> Procesar</button>` : ''}
        ${isSup && canAnular    ? `<button class="btn btn-outline-danger btn-sm" onclick="WMS_MODULES.devoluciones.anular(${d.id})"><i class="fa-solid fa-ban"></i> Anular</button>` : ''}`);

      WMS.setContent(`
        <div class="card">
          <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span class="card-title"><i class="fa-solid fa-rotate-left"></i> ${WMS.esc(d.numero_devolucion)}</span>
            <span class="badge" style="background:${badgeColor[estado]||'#94a3b8'}20;color:${badgeColor[estado]||'#94a3b8'};border:1px solid ${badgeColor[estado]||'#94a3b8'};font-size:13px;">${WMS.esc(estado)}</span>
          </div>
          <div style="padding:16px;display:grid;grid-template-columns:repeat(3,1fr);gap:12px;font-size:13px;">
            <div><div style="color:#64748b;font-size:11px;">Tipo</div><strong>${WMS.esc(d.tipo)}</strong></div>
            <div><div style="color:#64748b;font-size:11px;">Referencia ERP</div>${WMS.esc(d.referencia_externa||'-')}</div>
            <div><div style="color:#64748b;font-size:11px;">Motivo</div>${WMS.esc(d.motivo_general)}</div>
            <div><div style="color:#64748b;font-size:11px;">Solicitado por</div>${WMS.esc(d.solicitado_por||d.auxiliar_id||'-')}</div>
            <div><div style="color:#64748b;font-size:11px;">Aprobado por</div>${WMS.esc(d.aprobado_por||'-')}</div>
            <div><div style="color:#64748b;font-size:11px;">Fecha</div>${d.created_at?d.created_at.substring(0,10):'-'}</div>
          </div>
          <div class="table-container">
            <table class="erp-table" style="font-size:12px;">
              <thead><tr><th>Producto</th><th>Lote</th><th>Vence</th><th class="text-center">Cant.</th><th>Condición</th><th>Destino</th><th>Nota</th></tr></thead>
              <tbody>
                ${(d.detalles||[]).map(det => `<tr>
                  <td>${WMS.esc(det.producto?.nombre||det.producto_id)}</td>
                  <td><code>${WMS.esc(det.lote||'-')}</code></td>
                  <td style="font-size:11px;">${det.fecha_vencimiento||'-'}</td>
                  <td class="text-center fw-700">${WMS.formatNum(det.cantidad)}</td>
                  <td>${WMS.esc(det.condicion||'-')}</td>
                  <td>${det.destino ? `<span class="badge" style="background:#f0fdf4;color:#16a34a;">${WMS.esc(det.destino)}</span>` : '<span style="color:#94a3b8;">—</span>'}</td>
                  <td style="font-size:11px;color:#64748b;">${WMS.esc(det.detalle_motivo||det.motivo_item||'-')}</td>
                </tr>`).join('')}
              </tbody>
            </table>
          </div>
        </div>`);
    } catch(e) { WMS.toast('error', 'Error al cargar detalle'); }
  },
```

- [ ] **Step 3: Agregar acciones — aprobar, rechazar, anular, procesar**

```javascript
  // ── ACCIONES ──────────────────────────────────────────────────────────────

  async aprobar(id) {
    if (!confirm('¿Aprobar esta devolución?')) return;
    try {
      const r = await API.post('/devoluciones/' + id + '/aprobar', {});
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Devolución aprobada');
      this.showDetalle(id);
    } catch(e) { WMS.toast('error', 'Error al aprobar'); }
  },

  async rechazar(id) {
    const motivo = prompt('Motivo del rechazo (opcional):') ?? '';
    try {
      const r = await API.post('/devoluciones/' + id + '/rechazar', { motivo_rechazo: motivo });
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Devolución rechazada');
      this.showDetalle(id);
    } catch(e) { WMS.toast('error', 'Error al rechazar'); }
  },

  async anular(id) {
    if (!confirm('¿Anular esta devolución? Esta acción no se puede deshacer.')) return;
    try {
      const r = await API.post('/devoluciones/' + id + '/anular', {});
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Devolución anulada');
      this.showLista();
    } catch(e) { WMS.toast('error', 'Error al anular'); }
  },

  abrirProcesar(id) {
    const d = this._state.detalle;
    if (!d) return;
    const destOpts = `<option value="">-- Seleccionar --</option><option value="restock">Restock al inventario</option><option value="descarte">Descarte</option><option value="proveedor">→ Proveedor</option>`;
    const rows = (d.detalles||[]).map(det => `
      <tr>
        <td>${WMS.esc(det.producto?.nombre||det.producto_id)}</td>
        <td><code>${WMS.esc(det.lote||'-')}</code></td>
        <td class="text-center">${WMS.formatNum(det.cantidad)}</td>
        <td>${WMS.esc(det.condicion||'-')}</td>
        <td>
          <select class="form-control form-control-sm proc-dest" data-id="${det.id}" style="min-width:160px;">
            ${destOpts}
          </select>
        </td>
      </tr>`).join('');
    const html = `
      <div id="proc-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;display:flex;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:12px;padding:24px 28px;min-width:600px;max-width:800px;max-height:80vh;overflow-y:auto;box-shadow:0 8px 40px rgba(0,0,0,.3);">
          <h3 style="margin:0 0 16px;font-size:16px;"><i class="fa-solid fa-gears"></i> Procesar Devolución — ${WMS.esc(d.numero_devolucion)}</h3>
          <p style="font-size:12px;color:#64748b;margin-bottom:12px;">Asigna el destino de cada ítem antes de confirmar.</p>
          <table class="erp-table" style="font-size:12px;">
            <thead><tr><th>Producto</th><th>Lote</th><th class="text-center">Cant.</th><th>Condición</th><th>Destino</th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
          <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px;">
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('proc-overlay').remove()">Cancelar</button>
            <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones.confirmarProcesar(${id})">
              <i class="fa-solid fa-check"></i> Confirmar Procesamiento
            </button>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
  },

  async confirmarProcesar(id) {
    const selects = document.querySelectorAll('.proc-dest');
    const items = [];
    let valid = true;
    selects.forEach(s => {
      if (!s.value) { valid = false; s.style.borderColor = '#dc2626'; }
      else { s.style.borderColor = ''; }
      items.push({ id: parseInt(s.dataset.id), destino: s.value });
    });
    if (!valid) { WMS.toast('error', 'Todos los ítems deben tener destino'); return; }

    document.getElementById('proc-overlay')?.remove();
    WMS.spinner();
    try {
      const r = await API.post('/devoluciones/' + id + '/procesar', { items });
      if (r.error) { WMS.toast('error', r.message); return; }
      let msg = 'Devolución procesada correctamente';
      if (r.data?.devolucion_proveedor_id) {
        msg += ` — Se creó automáticamente la devolución al proveedor.`;
      }
      WMS.toast('success', msg);
      this.showDetalle(id);
    } catch(e) { WMS.toast('error', 'Error al procesar'); }
  },
```

- [ ] **Step 4: Agregar `showNueva()` con formulario + QR**

```javascript
  // ── NUEVA DEVOLUCIÓN ──────────────────────────────────────────────────────

  showNueva() {
    this._state.items  = [];
    this._state.qrProd = null;
    WMS.setToolbar(`
      <button class="btn btn-secondary btn-sm" onclick="WMS_MODULES.devoluciones.showLista()">
        <i class="fa-solid fa-arrow-left"></i> Volver
      </button>`);

    WMS.setContent(`
      <div class="card" style="max-width:860px;">
        <div class="card-header"><span class="card-title"><i class="fa-solid fa-plus"></i> Nueva Devolución</span></div>
        <div style="padding:20px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
          <div>
            <label class="form-label">Tipo *</label>
            <select id="dv-new-tipo" class="form-control">
              <option value="cliente">Cliente → WMS</option>
              <option value="proveedor">WMS → Proveedor</option>
              <option value="interna">Interna</option>
            </select>
          </div>
          <div>
            <label class="form-label">Referencia ERP</label>
            <input type="text" id="dv-new-ref" class="form-control" placeholder="Ej: NC-12345">
          </div>
          <div style="grid-column:span 1;">
            <label class="form-label">Motivo general *</label>
            <input type="text" id="dv-new-motivo" class="form-control" placeholder="Razón de la devolución">
          </div>
        </div>

        <div style="padding:0 20px 20px;">
          <div class="card-header" style="border-radius:8px 8px 0 0;margin-bottom:0;">
            <span class="card-title" style="font-size:13px;"><i class="fa-solid fa-qrcode"></i> Agregar productos</span>
          </div>
          <div style="border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;padding:14px;">
            <div style="display:flex;gap:8px;align-items:flex-end;margin-bottom:12px;flex-wrap:wrap;">
              <div style="flex:1;min-width:220px;">
                <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;">
                  <i class="fa-solid fa-qrcode"></i> Escanear QR / EAN / Código interno
                </label>
                <input type="text" id="dv-qr-input" class="form-control" placeholder="Escanee QR o escriba código..."
                  onkeydown="if(event.key==='Enter'){WMS_MODULES.devoluciones.buscarQr();event.preventDefault();}">
              </div>
              <button class="btn btn-outline-primary btn-sm" onclick="WMS_MODULES.devoluciones.buscarQr()">
                <i class="fa-solid fa-magnifying-glass"></i> Buscar
              </button>
            </div>
            <div id="dv-qr-found" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:8px 12px;margin-bottom:10px;font-size:12px;">
              <strong id="dv-qr-nombre"></strong> — Lote: <span id="dv-qr-lote">-</span> / Vence: <span id="dv-qr-fv">-</span>
            </div>
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:8px;align-items:end;">
              <div>
                <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Lote</label>
                <input type="text" id="dv-item-lote" class="form-control form-control-sm" placeholder="Lote">
              </div>
              <div>
                <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Fecha Venc.</label>
                <input type="date" id="dv-item-fv" class="form-control form-control-sm">
              </div>
              <div>
                <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Cantidad</label>
                <input type="number" id="dv-item-cant" class="form-control form-control-sm" min="0.001" step="0.001" placeholder="0">
              </div>
              <div>
                <label style="font-size:11px;font-weight:600;display:block;margin-bottom:3px;">Condición</label>
                <select id="dv-item-cond" class="form-control form-control-sm">
                  <option value="bueno">Bueno</option>
                  <option value="dañado">Dañado</option>
                  <option value="vencido">Vencido</option>
                  <option value="otro">Otro</option>
                </select>
              </div>
              <button class="btn btn-primary btn-sm" onclick="WMS_MODULES.devoluciones.agregarItem()" style="height:34px;">
                <i class="fa-solid fa-plus"></i>
              </button>
            </div>
          </div>
        </div>

        <div style="padding:0 20px 20px;">
          <div id="dv-items-table"></div>
        </div>

        <div style="padding:0 20px 24px;text-align:right;">
          <button class="btn btn-primary" onclick="WMS_MODULES.devoluciones.guardarNueva()">
            <i class="fa-solid fa-save"></i> Registrar Devolución
          </button>
        </div>
      </div>`);

    this._renderItemsTable();
  },

  async buscarQr() {
    const qr = document.getElementById('dv-qr-input')?.value?.trim();
    if (!qr) return;
    try {
      const r = await API.get('/recepciones/buscar-qr?q=' + encodeURIComponent(qr));
      if (r.error) { WMS.toast('error', r.message || 'Producto no encontrado'); return; }
      const p = r.data.producto;
      this._state.qrProd = { id: p.id, nombre: p.nombre, codigo: p.codigo_interno };
      document.getElementById('dv-qr-input').value = '';
      document.getElementById('dv-item-lote').value = r.data.lote_raw || '';
      document.getElementById('dv-item-fv').value   = r.data.fecha_vencimiento || '';
      document.getElementById('dv-qr-nombre').textContent = p.nombre;
      document.getElementById('dv-qr-lote').textContent   = r.data.lote_raw || '-';
      document.getElementById('dv-qr-fv').textContent     = r.data.fecha_vencimiento || '-';
      document.getElementById('dv-qr-found').style.display = 'block';
      document.getElementById('dv-item-cant').focus();
      WMS.toast('success', 'Producto: ' + p.nombre);
    } catch(e) { WMS.toast('error', 'Producto no encontrado'); }
  },

  agregarItem() {
    const prod = this._state.qrProd;
    if (!prod) { WMS.toast('error', 'Busque un producto primero'); return; }
    const cant = parseFloat(document.getElementById('dv-item-cant')?.value || 0);
    if (!cant || cant <= 0) { WMS.toast('error', 'Ingrese una cantidad válida'); return; }
    this._state.items.push({
      producto_id:       prod.id,
      producto_nombre:   prod.nombre,
      codigo:            prod.codigo,
      lote:              document.getElementById('dv-item-lote')?.value || null,
      fecha_vencimiento: document.getElementById('dv-item-fv')?.value || null,
      cantidad:          cant,
      condicion:         document.getElementById('dv-item-cond')?.value || 'bueno',
    });
    // Reset
    this._state.qrProd = null;
    document.getElementById('dv-qr-found').style.display = 'none';
    document.getElementById('dv-qr-input').value = '';
    document.getElementById('dv-item-lote').value = '';
    document.getElementById('dv-item-fv').value   = '';
    document.getElementById('dv-item-cant').value = '';
    this._renderItemsTable();
  },

  _renderItemsTable() {
    const el = document.getElementById('dv-items-table');
    if (!el) return;
    if (!this._state.items.length) { el.innerHTML = '<p style="color:#94a3b8;font-size:12px;">Sin ítems. Busque un producto arriba.</p>'; return; }
    el.innerHTML = `<table class="erp-table" style="font-size:12px;">
      <thead><tr><th>Producto</th><th>Lote</th><th>Vence</th><th class="text-center">Cant.</th><th>Condición</th><th></th></tr></thead>
      <tbody>${this._state.items.map((it,i) => `<tr>
        <td>${WMS.esc(it.producto_nombre)}</td>
        <td><code>${WMS.esc(it.lote||'-')}</code></td>
        <td style="font-size:11px;">${it.fecha_vencimiento||'-'}</td>
        <td class="text-center fw-700">${WMS.formatNum(it.cantidad)}</td>
        <td>${WMS.esc(it.condicion)}</td>
        <td><button class="btn btn-danger" style="padding:2px 6px;font-size:10px;" onclick="WMS_MODULES.devoluciones._quitarItem(${i})">
          <i class="fa-solid fa-trash"></i></button></td>
      </tr>`).join('')}</tbody>
    </table>`;
  },

  _quitarItem(i) {
    this._state.items.splice(i, 1);
    this._renderItemsTable();
  },

  async guardarNueva() {
    const tipo   = document.getElementById('dv-new-tipo')?.value;
    const ref    = document.getElementById('dv-new-ref')?.value?.trim() || null;
    const motivo = document.getElementById('dv-new-motivo')?.value?.trim();
    if (!motivo) { WMS.toast('error', 'Ingrese el motivo general'); return; }
    if (!this._state.items.length) { WMS.toast('error', 'Agregue al menos un producto'); return; }
    WMS.spinner();
    try {
      const r = await API.post('/devoluciones', {
        tipo, referencia_externa: ref, motivo_general: motivo,
        detalles: this._state.items.map(it => ({
          producto_id: it.producto_id, lote: it.lote,
          fecha_vencimiento: it.fecha_vencimiento,
          cantidad: it.cantidad, condicion: it.condicion,
          motivo: 'Otro',
        })),
      });
      if (r.error) { WMS.toast('error', r.message); return; }
      WMS.toast('success', 'Devolución ' + r.data.numero + ' registrada. Pendiente de aprobación.');
      this.showDetalle(r.data.devolucion_id);
    } catch(e) { WMS.toast('error', 'Error al registrar'); }
  },

}; // end WMS_MODULES.devoluciones
```

- [ ] **Step 5: Sintaxis check**

```bash
node --check public/assets/js/desktop/devoluciones.js
```

Expected: Sin errores.

- [ ] **Step 6: Commit**

```bash
git add public/assets/js/desktop/devoluciones.js
git commit -m "feat: devoluciones.js — módulo desktop completo (lista, nueva, detalle, procesar)"
```

---

## Task 6: index.html — registrar módulo + badge + notificaciones + sidebar

**Files:**
- Modify: `public/index.html`

- [ ] **Step 1: Agregar tab de módulo en el header nav**

Encontrar la línea que contiene `data-module="rotulos"` (línea ~161). Agregar después:

```html
        <button class="module-tab" data-module="devoluciones" onclick="WMS.nav('devoluciones')">
          <i class="fa-solid fa-rotate-left"></i><span>Devoluciones</span>
        </button>
```

- [ ] **Step 2: Registrar script en el objeto `scripts` de `nav()`**

Encontrar el bloque `const scripts = {` (línea ~1587). Después de la línea:
```javascript
          rotulos:       'assets/js/desktop/rotulos.js',
```
Agregar:
```javascript
          devoluciones:  'assets/js/desktop/devoluciones.js',
```

- [ ] **Step 3: Agregar sidebar para el módulo devoluciones**

En el objeto `renderSidebar`, encontrar el cierre del último módulo (antes de `};` del objeto `menus`). Agregar la entrada:

```javascript
          devoluciones: [
            {
              section: 'DEVOLUCIONES', items: [
                { icon: 'fa-list', label: 'Lista',             action: "WMS.nav('devoluciones','lista')" },
                { icon: 'fa-plus', label: 'Nueva Devolución',  action: "WMS.nav('devoluciones','nueva')" },
              ]
            },
          ],
```

- [ ] **Step 4: Agregar `devoluciones` al label de setBreadcrumb**

Encontrar la línea con `const labels = { inicio:'Inicio', ...` (línea ~1627). Agregar `devoluciones:'Devoluciones'` al objeto:

```javascript
          const labels = { inicio:'Inicio', maestro:'Maestros', recepcion:'Recepción', almacenamiento:'Almacenamiento', picking:'Picking', despacho:'Despacho', inventario:'Inventario', reportes:'Reportes', inteligencia:'Inteligencia ML', logistica:'Logística Pro', rotacion:'Rotación ML', rotulos:'Rótulos', devoluciones:'Devoluciones' };
```

- [ ] **Step 5: Agregar `_devolucionPendingCount` a propiedades de WMS**

Encontrar la línea `_expiryPendingCount: 0,` (línea ~1013). Agregar justo después:

```javascript
      _devolucionPendingCount: 0,
```

- [ ] **Step 6: Extender `loadBadge()` para incluir devoluciones pendientes**

Encontrar en `loadBadge()` el bloque que suma `_expiryPendingCount` (líneas ~1955-1963):
```javascript
          if (privileged.includes(rol)) {
            try {
              const ea = await API.get('/aprobaciones/vencimiento/pendientes');
              if (!ea.error && Array.isArray(ea.data)) {
                totalBadge += ea.data.length;
                this._expiryPendingCount = ea.data.length;
              }
            } catch(_) {}
          }
```

Reemplazar con:
```javascript
          if (privileged.includes(rol)) {
            try {
              const ea = await API.get('/aprobaciones/vencimiento/pendientes');
              if (!ea.error && Array.isArray(ea.data)) {
                totalBadge += ea.data.length;
                this._expiryPendingCount = ea.data.length;
              }
            } catch(_) {}
            try {
              const dv = await API.get('/devoluciones?estado=PendienteAprobacion');
              if (!dv.error && Array.isArray(dv.data)) {
                totalBadge += dv.data.length;
                this._devolucionPendingCount = dv.data.length;
              }
            } catch(_) {}
          }
```

- [ ] **Step 7: Agregar tarjetas de devoluciones al panel de notificaciones**

Encontrar el bloque que agrega las tarjetas de expiry (línea ~2054). Inmediatamente después del cierre de ese bloque `try { ... } catch(_) {}`, agregar:

```javascript
        // Append devolucion approval cards for supervisor-level roles
        if (expiryPrivileged.includes(expiryRol)) {
          try {
            const dv = await API.get('/devoluciones?estado=PendienteAprobacion');
            if (!dv.error && dv.data && dv.data.length > 0) {
              const dvHtml = dv.data.map(d => `
                <div class="notif-item unread" id="dev-card-${d.id}">
                  <div class="notif-item-icon info"><i class="fa-solid fa-rotate-left"></i></div>
                  <div class="notif-body">
                    <div class="notif-titulo">↩ Devolución pendiente — ${this.esc(d.numero_devolucion)}</div>
                    <div class="notif-mensaje">${this.esc(d.tipo)} · ${(d.detalles||[]).length} ítem(s)</div>
                    <div class="notif-mensaje" style="color:#64748b;font-size:.72rem;">Motivo: ${this.esc((d.motivo_general||'').substring(0,80))}</div>
                    <div class="notif-actions" style="margin-top:6px;display:flex;gap:6px;">
                      <button class="btn btn-sm btn-success" onclick="WMS._aprobarDevolucion(${d.id},this)">
                        <i class="fa-solid fa-check"></i> Aprobar
                      </button>
                      <button class="btn btn-sm btn-danger" onclick="WMS._rechazarDevolucion(${d.id},this)">
                        <i class="fa-solid fa-times"></i> Rechazar
                      </button>
                      <button class="btn btn-sm btn-outline-primary" onclick="WMS.nav('devoluciones');setTimeout(()=>WMS_MODULES.devoluciones?.showDetalle(${d.id}),600)">
                        Ver
                      </button>
                    </div>
                  </div>
                </div>`).join('');
              list.insertAdjacentHTML('afterbegin', dvHtml);
            }
          } catch(_) {}
        }
```

- [ ] **Step 8: Agregar métodos `_aprobarDevolucion` y `_rechazarDevolucion` en WMS**

Encontrar el método `_resolverVencimiento` (línea ~2109). Agregar justo después:

```javascript
      async _aprobarDevolucion(id, btn) {
        btn.disabled = true;
        try {
          const r = await API.post('/devoluciones/' + id + '/aprobar', {});
          if (r.error) { this.toast('error', r.message); btn.disabled = false; return; }
          document.getElementById('dev-card-' + id)?.remove();
          this.toast('success', 'Devolución aprobada');
          this._devolucionPendingCount = Math.max(0, this._devolucionPendingCount - 1);
          this.loadBadge();
        } catch(e) { this.toast('error', 'Error al aprobar'); btn.disabled = false; }
      },

      async _rechazarDevolucion(id, btn) {
        btn.disabled = true;
        try {
          const r = await API.post('/devoluciones/' + id + '/rechazar', {});
          if (r.error) { this.toast('error', r.message); btn.disabled = false; return; }
          document.getElementById('dev-card-' + id)?.remove();
          this.toast('success', 'Devolución rechazada');
          this._devolucionPendingCount = Math.max(0, this._devolucionPendingCount - 1);
          this.loadBadge();
        } catch(e) { this.toast('error', 'Error al rechazar'); btn.disabled = false; }
      },
```

- [ ] **Step 9: Verificar en navegador**

1. Abrir la app, iniciar sesión. El header muestra nueva pestaña "Devoluciones".
2. Hacer clic en "Devoluciones" → carga el módulo y muestra la lista vacía.
3. Clic "Nueva Devolución" → aparece el formulario con el campo QR.
4. Escribir un código en el campo QR y presionar Enter → el producto se rellena.

Expected: Sin errores en la consola del navegador.

- [ ] **Step 10: Commit**

```bash
git add public/index.html
git commit -m "feat: index.html — módulo devoluciones en nav, badge, panel notificaciones"
```

---

## Task 7: Mobile — sección devoluciones

**Files:**
- Modify: `public/mobile/index.html`

- [ ] **Step 1: Agregar botón "Devolución" al menú principal mobile**

Encontrar el array `mods` en `showHome()` (línea ~365). Agregar el ítem al array:

```javascript
      { id:'dv', l:'Devolución',  i:'fa-rotate-left',      c:'amber',   a:'viewDevolucion' },
```

Agregar después del ítem `ci` (Consultar).

- [ ] **Step 2: Agregar propiedades de estado para devoluciones**

Encontrar la sección de propiedades `_expiryPollTimer` (línea ~210 aprox). Agregar:

```javascript
  _devItems: [],
  _devQrProd: null,
```

- [ ] **Step 3: Agregar el método `viewDevolucion()`**

Agregar antes de la función `_doSinOdcQr` o al final de los métodos de MWMS:

```javascript
  viewDevolucion() {
    this._devItems  = [];
    this._devQrProd = null;
    document.getElementById('m-title').textContent = 'Devolución';
    document.getElementById('nav-home').classList.remove('active');
    document.getElementById('m-content').innerHTML = `
      <div class="op-view">
        <div style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
          <div>
            <h2 style="font-size:1rem;font-weight:800;margin:0;">Registrar Devolución</h2>
            <p style="font-size:.72rem;color:#64748b;margin:3px 0 0;">Solo devoluciones Cliente → WMS</p>
          </div>
          <button class="m-btn secondary sm" onclick="MWMS.showHome()">Volver</button>
        </div>

        <!-- QR Input -->
        <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:12px;margin-bottom:12px;">
          <div class="m-label" style="color:#065f46;margin-bottom:6px;"><i class="fa-solid fa-qrcode"></i> Escanear QR / Código</div>
          <div style="display:flex;gap:8px;">
            <input type="text" id="dv-m-qr" class="m-input-full" placeholder="Escanee QR o código del producto"
              onkeydown="if(event.key==='Enter'){MWMS._dvBuscarQr();}"
              style="flex:1;">
            <button class="m-btn primary sm" onclick="MWMS._dvBuscarQr()" style="white-space:nowrap;">
              <i class="fa-solid fa-magnifying-glass"></i>
            </button>
          </div>
          <div id="dv-m-prod-found" style="display:none;margin-top:8px;font-size:.75rem;color:#15803d;font-weight:600;"></div>
        </div>

        <!-- Campos del ítem -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;">
          <div>
            <div class="m-label">Lote</div>
            <input type="text" id="dv-m-lote" class="m-input-full" placeholder="Lote (opcional)">
          </div>
          <div>
            <div class="m-label">Cant.</div>
            <input type="number" id="dv-m-cant" class="m-input-full" min="0.001" step="0.001" placeholder="0">
          </div>
        </div>
        <div style="margin-bottom:10px;">
          <div class="m-label">Condición</div>
          <select id="dv-m-cond" class="m-input-full">
            <option value="bueno">Bueno</option>
            <option value="dañado">Dañado</option>
            <option value="vencido">Vencido</option>
            <option value="otro">Otro</option>
          </select>
        </div>
        <button class="m-btn primary" onclick="MWMS._dvAgregarItem()" style="width:100%;margin-bottom:14px;">
          <i class="fa-solid fa-plus"></i> Agregar producto
        </button>

        <!-- Lista de ítems -->
        <div id="dv-m-items" style="margin-bottom:12px;"></div>

        <!-- Motivo + submit -->
        <div style="margin-bottom:10px;">
          <div class="m-label">Motivo general *</div>
          <textarea id="dv-m-motivo" class="m-input-full" rows="2" placeholder="Razón de la devolución..." style="resize:none;"></textarea>
        </div>
        <button class="m-btn primary" id="dv-m-submit" onclick="MWMS._dvEnviar()" style="width:100%;">
          <i class="fa-solid fa-paper-plane"></i> Enviar Devolución
        </button>
      </div>`;
    this._dvRenderItems();
  },

  async _dvBuscarQr() {
    const qr = document.getElementById('dv-m-qr')?.value?.trim();
    if (!qr) return;
    this.loading(true);
    try {
      const r = await mApi('GET', '/recepciones/buscar-qr?q=' + encodeURIComponent(qr));
      const p = r.data.producto;
      this._devQrProd = { id: p.id, nombre: p.nombre };
      document.getElementById('dv-m-qr').value = '';
      document.getElementById('dv-m-lote').value = r.data.lote_raw || '';
      document.getElementById('dv-m-prod-found').textContent = '✓ ' + p.nombre + (r.data.lote_raw ? ' · Lote: ' + r.data.lote_raw : '');
      document.getElementById('dv-m-prod-found').style.display = 'block';
      document.getElementById('dv-m-cant').focus();
      this.toast(p.nombre, 'success');
    } catch(e) {
      this.toast('Producto no encontrado', 'error');
    } finally { this.loading(false); }
  },

  _dvAgregarItem() {
    if (!this._devQrProd) { this.toast('Busque un producto primero', 'error'); return; }
    const cant = parseFloat(document.getElementById('dv-m-cant')?.value || 0);
    if (!cant || cant <= 0) { this.toast('Ingrese cantidad', 'error'); return; }
    this._devItems.push({
      producto_id: this._devQrProd.id,
      nombre:      this._devQrProd.nombre,
      lote:        document.getElementById('dv-m-lote')?.value || null,
      cantidad:    cant,
      condicion:   document.getElementById('dv-m-cond')?.value || 'bueno',
      motivo:      'Otro',
    });
    this._devQrProd = null;
    document.getElementById('dv-m-prod-found').style.display = 'none';
    document.getElementById('dv-m-lote').value  = '';
    document.getElementById('dv-m-cant').value  = '';
    this._dvRenderItems();
  },

  _dvRenderItems() {
    const el = document.getElementById('dv-m-items');
    if (!el) return;
    if (!this._devItems.length) { el.innerHTML = ''; return; }
    el.innerHTML = `<div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
      ${this._devItems.map((it,i) => `
        <div style="padding:8px 12px;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;font-size:.78rem;">
          <div>
            <div style="font-weight:700;">${this.esc(it.nombre)}</div>
            <div style="color:#64748b;">Lote: ${it.lote||'-'} · ${it.condicion} · ${it.cantidad} uds</div>
          </div>
          <button onclick="MWMS._dvQuitarItem(${i})" style="background:none;border:none;color:#dc2626;font-size:1rem;cursor:pointer;">✕</button>
        </div>`).join('')}
    </div>`;
  },

  _dvQuitarItem(i) { this._devItems.splice(i, 1); this._dvRenderItems(); },

  async _dvEnviar() {
    const motivo = document.getElementById('dv-m-motivo')?.value?.trim();
    if (!motivo) { this.toast('Ingrese el motivo', 'error'); return; }
    if (!this._devItems.length) { this.toast('Agregue al menos un producto', 'error'); return; }
    const btn = document.getElementById('dv-m-submit');
    if (btn) btn.disabled = true;
    this.loading(true);
    try {
      const r = await mApi('POST', '/devoluciones', {
        tipo:             'cliente',
        motivo_general:   motivo,
        detalles:         this._devItems,
      });
      this.toast('Devolución ' + r.data.numero + ' registrada', 'success');
      this._devItems  = [];
      this._devQrProd = null;
      this.showHome();
    } catch(e) {
      this.toast(e.message || 'Error al enviar', 'error');
      if (btn) btn.disabled = false;
    } finally { this.loading(false); }
  },
```

- [ ] **Step 4: Verificar en el navegador mobile**

Abrir `http://localhost/WMS_FENIX/public/mobile/`. Iniciar sesión. Verificar que el menú principal muestra el botón "Devolución" (ámbar). Al presionarlo, aparece la pantalla de registro.

Escanear / escribir un código de producto → aparece el nombre.
Ingresar cantidad y condición → "Agregar" → producto aparece en la lista.
Ingresar motivo → "Enviar Devolución" → toast de confirmación y regreso al menú.

- [ ] **Step 5: Commit**

```bash
git add public/mobile/index.html
git commit -m "feat: mobile — sección devoluciones con QR, ítems y submit"
```

---

## Self-Review

**Spec coverage:**
- [x] Tres tipos (cliente, proveedor, interna) → `devoluciones.tipo` enum extendido — Task 1
- [x] Estado machine completo (PendienteAprobacion → Aprobada → Procesada + Rechazada + Anulada) → Task 1 + Task 3
- [x] Referencia externa ERP → columna nueva + campo en form — Task 1, 5
- [x] Flujo aprobación supervisor → `aprobar()` + `rechazar()` — Task 3
- [x] Destino por ítem (restock/descarte/proveedor) → `procesar()` — Task 3
- [x] Restock: actualiza `inventarios` + crea movimiento `Devolucion` — Task 3
- [x] Descarte: crea movimiento `AjusteNegativo` — Task 3
- [x] Proveedor: crea nueva devolución automática + devuelve `devolucion_proveedor_id` — Task 3
- [x] Badge + notificaciones supervisor → loadBadge + panel — Task 6
- [x] QR scanning desktop → reutiliza `GET /recepciones/buscar-qr` — Task 5
- [x] QR scanning mobile → mismo endpoint — Task 7
- [x] Mobile solo tipo cliente — Task 7 + spec §10
- [x] `numero_devolucion` auto DEV-YYYY-NNNN → `Devolucion::generarNumero()` — Task 2
- [x] Multi-tenancy: todos los endpoints usan `getEffectiveEmpresaId` + `getEffectiveSucursalId` — Task 3
- [x] Error 409 al procesar sin estado Aprobada — Task 3
- [x] Error 422 al procesar sin destino en todos los ítems — Task 3
- [x] `condicion` en devolucion_detalles — Task 1
- [x] anomaly_flag insertado en `store()` — Task 3

**Placeholder scan:** Sin TBD ni TODO en el plan. Todo el código está completo.

**Type consistency:** `Devolucion::ESTADO_PENDIENTE`, `ESTADO_APROBADA`, `ESTADO_PROCESADA` usados consistentemente en Task 3. `DevolucionDetalle::DESTINO_RESTOCK`, `DESTINO_DESCARTE`, `DESTINO_PROVEEDOR` definidos en Task 2 y usados en Task 3.
