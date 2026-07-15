<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Models\SesionInventario;
use App\Models\SesionAsignacion;
use App\Models\SesionLinea;
use App\Models\AjusteInventario;
use App\Models\Inventario;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Ubicacion;
use App\Models\Personal;
use App\Models\Notificacion;
use Carbon\Carbon;

/**
 * InventarioV2Controller
 * ======================
 * Módulo profesional de Inventarios WMS Fénix.
 *
 * Funcionalidades:
 *  1. Sesiones de inventario (Cíclico y General)
 *  2. Asignaciones a auxiliares con instrucción de conteo
 *  3. Registro de líneas contadas (por auxiliar, por ronda)
 *  4. Dashboard administrativo con matrices de control
 *  5. Acciones: editar línea, eliminar línea, ajustar línea, ajustar todo
 *  6. Tabla de ajustes inmutable con trazabilidad completa
 *  7. Kardex enriquecido (todos los movimientos: Entrada, Picking, Traslado, Ajuste, Salida)
 *  8. Reporte de vencimientos
 *  9. Sub-módulo de corrección manual de inventario
 * 10. API para versión móvil del auxiliar
 */
class InventarioV2Controller extends BaseController
{
    // ════════════════════════════════════════════════════════════════════════
    //  ██████  HELPERS PRIVADOS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Busca una SesionInventario por ID respetando empresa.
     * Para Admin/SuperAdmin no filtra por sucursal (pueden gestionar todas).
     */
    private function _findSesion(int $id, $user, Request $req): ?SesionInventario
    {
        $q = SesionInventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $req));
        if (!in_array($user->rol ?? '', ['Admin', 'SuperAdmin'])) {
            $q->where('sucursal_id', $user->sucursal_id);
        }
        return $q->find($id);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  ██████  SESIONES
    // ════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/v2/inventario/sesiones
     * Lista sesiones con filtros y paginación.
     */
    public function getSesiones(Request $req, Response $res): Response
    {
        try {
            $user   = $req->getAttribute('user');
            $params = $req->getQueryParams();

            $q = SesionInventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $req));
            if (!in_array($user->rol ?? '', ['Admin', 'SuperAdmin'])) {
                $q->where('sucursal_id', $user->sucursal_id);
            }
            $q->with(['creadoPor:id,nombre', 'ajustadoPor:id,nombre'])
                ->withCount([
                    'asignaciones',
                    'lineas',
                    'lineas as lineas_activas' => fn($q) => $q->where('estado', 'Activo'),
                    'ajustes',
                ]);

            if (!empty($params['tipo'])) {
                $q->where('tipo', $params['tipo']);
            }
            if (!empty($params['estado'])) {
                $q->where('estado', $params['estado']);
            }

            $sesiones = $q->orderByDesc('created_at')->paginate($params['per_page'] ?? 20);

            return $this->ok($res, $sesiones);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v2/inventario/sesiones
     * Crea una nueva sesión de inventario (Cíclico o General).
     */
    public function crearSesion(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $data = $req->getParsedBody() ?? [];
        $required = ['nombre', 'tipo'];
        foreach ($required as $f) {
            if (empty($data[$f])) {
                return $this->error($res, "Campo requerido: {$f}");
            }
        }

        if (!in_array($data['tipo'], ['Ciclico', 'General', 'CargueInicial'])) {
            return $this->error($res, "Tipo debe ser 'Ciclico', 'General' o 'CargueInicial'");
        }

        $numConteos = (int)($data['num_conteos'] ?? 1);
        if ($data['tipo'] === 'General' && ($numConteos < 1 || $numConteos > 3)) {
            return $this->error($res, "Para inventario General, num_conteos debe ser 1, 2 o 3");
        }
        if (in_array($data['tipo'], ['Ciclico', 'CargueInicial'])) {
            $numConteos = 1;
        }

        try {
            $sesion = SesionInventario::create([
                'empresa_id'       => $this->getEffectiveEmpresaId($user, $req),
                'sucursal_id'      => $user->sucursal_id,
                'nombre'           => trim($data['nombre']),
                'descripcion'      => $data['descripcion'] ?? null,
                'tipo'             => $data['tipo'],
                'num_conteos'      => $numConteos,
                'comparar_sistema' => filter_var($data['comparar_sistema'] ?? true, FILTER_VALIDATE_BOOLEAN),
                // CargueInicial siempre obliga FV; para otros tipos respeta el parámetro (default true)
                'fv_obligatorio'   => $data['tipo'] === 'CargueInicial'
                                      ? true
                                      : filter_var($data['fv_obligatorio'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'estado'           => SesionInventario::ESTADO_BORRADOR,
                'creado_por'       => $user->id,
                'fecha_inicio'     => $data['fecha_inicio'] ?? date('Y-m-d'),
            ]);

            $this->audit($user, 'inventario_v2', 'crear_sesion', 'sesiones_inventario', $sesion->id, null, $data);

            return $this->ok($res, $sesion->fresh(), 'Sesión creada correctamente');
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/v2/inventario/sesiones/{id}/iniciar
     * Cambia el estado de Borrador a EnCurso.
     * Envía notificaciones push a todos los auxiliares asignados en ronda 1.
     */
    public function iniciarSesion(Request $req, Response $res, array $args): Response
    {
        $user   = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $sesionQuery = SesionInventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $req));
        if (!in_array($user->rol ?? '', ['Admin', 'SuperAdmin'])) {
            $sesionQuery->where('sucursal_id', $user->sucursal_id);
        }
        $sesion = $sesionQuery->find($args['id']);

        if (!$sesion) return $this->notFound($res, 'Sesión no encontrada');

        if ($sesion->estado !== SesionInventario::ESTADO_BORRADOR) {
            return $this->error($res, "Solo se puede iniciar una sesión en estado Borrador (actual: {$sesion->estado})");
        }

        $asignacionesRonda1 = $sesion->asignaciones()->where('ronda', 1)->count();
        if ($asignacionesRonda1 === 0) {
            return $this->error($res, 'Debe asignar al menos un auxiliar para la ronda 1 antes de iniciar');
        }

        try {
            Capsule::transaction(function () use ($sesion, $user) {
                $sesion->estado       = SesionInventario::ESTADO_EN_CURSO;
                $sesion->fecha_inicio = date('Y-m-d');
                $sesion->save();

                $asignaciones = $sesion->asignaciones()->where('ronda', 1)->get();
                foreach ($asignaciones as $a) {
                    $a->estado        = SesionAsignacion::ESTADO_NOTIFICADO;
                    $a->notificado_at = date('Y-m-d H:i:s');
                    $a->save();

                    // Bloqueo de ubicaciones: no crítico, no debe revertir la sesión
                    try {
                        $this->bloquearUbicacionesDeInstruccion($a, $user);
                    } catch (\Throwable $bErr) {
                        error_log("bloquearUbicaciones sesion={$sesion->id} asig={$a->id}: " . $bErr->getMessage());
                    }

                    $this->crearNotificacionAuxiliar($a, $sesion);
                }
            });

            return $this->ok($res, $sesion->fresh()->load('asignaciones.auxiliar'), 'Sesión iniciada y auxiliares notificados');
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v2/inventario/sesiones/{id}
     * Detalle completo de una sesión para el dashboard administrativo.
     */
    public function getSesion(Request $req, Response $res, array $args): Response
    {
        try {
            $user = $req->getAttribute('user');
            $sesQ = SesionInventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $req));
            if (!in_array($user->rol ?? '', ['Admin', 'SuperAdmin'])) {
                $sesQ->where('sucursal_id', $user->sucursal_id);
            }
            $sesion = $sesQ->with([
                    'creadoPor:id,nombre',
                    'ajustadoPor:id,nombre',
                    'asignaciones.auxiliar:id,nombre',
                    'asignaciones.producto:id,nombre,codigo_interno',
                ])
                ->find($args['id']);

            if (!$sesion) return $this->notFound($res);

            return $this->ok($res, $sesion);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v2/inventario/sesiones/{id}
     * Elimina una sesión de inventario y sus datos relacionados.
     */
    public function eliminarSesion(Request $req, Response $res, array $args): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        try {
            $sesQuery = SesionInventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $req));
            if (!in_array($user->rol ?? '', ['Admin', 'SuperAdmin'])) {
                $sesQuery->where('sucursal_id', $user->sucursal_id);
            }
            $sesion = $sesQuery->find($args['id']);

            if (!$sesion) return $this->notFound($res, "Sesión no encontrada");

            if (in_array($sesion->estado, [SesionInventario::ESTADO_AJUSTADO, SesionInventario::ESTADO_CERRADO])) {
                return $this->error($res, "No se puede eliminar la sesión '{$sesion->nombre}' porque ya ha sido finalizada o ajustada.");
            }

            $nombre    = $sesion->nombre;
            $sesionId  = $sesion->id;

            Capsule::transaction(function() use ($sesion) {
                SesionLinea::where('sesion_id', $sesion->id)->delete();
                SesionAsignacion::where('sesion_id', $sesion->id)->delete();
                $sesion->delete();
            });

            // Audit fuera de la transacción: su fallo no debe revertir el delete
            try {
                $this->audit($user, 'inventario_v2', 'eliminar_sesion', 'sesiones_inventario', $sesionId, null, ["nombre" => $nombre]);
            } catch (\Throwable $ae) {
                error_log("audit eliminar_sesion: " . $ae->getMessage());
            }

            return $this->ok($res, null, "Sesión '{$nombre}' eliminada correctamente");
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v2/inventario/sesiones/{id}/cerrar
     * Concluye formalmente el conteo y libera las posiciones bloqueadas.
     */
    public function cerrarSesion(Request $req, Response $res, array $args): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        try {
            $sesion = $this->_findSesion((int)$args['id'], $user, $req);

            if (!$sesion) return $this->notFound($res, "Sesión no encontrada");

            // Se permite cerrar si está ajustado o si se quiere liberar la bodega sin ajustar (EnCurso / PendienteAjuste)
            if (!in_array($sesion->estado, [SesionInventario::ESTADO_AJUSTADO, 'EnCurso', 'PendienteAjuste'])) {
                return $this->error($res, "Solo se puede cerrar una sesión ajustada o en proceso para liberar la bodega (Estado actual: {$sesion->estado}).");
            }

            Capsule::transaction(function() use ($sesion, $user) {
                $sesion->estado = SesionInventario::ESTADO_CERRADO;
                $sesion->fecha_cierre = date('Y-m-d');
                $sesion->save();

                // Liberar ubicaciones
                $this->liberarUbicacionesDeSesion($sesion, $user);

                $this->audit($user, 'inventario_v2', 'cerrar_sesion', 'sesiones_inventario', $sesion->id, null, ["nombre" => $sesion->nombre]);
            });

            return $this->ok($res, null, "Sesión '{$sesion->nombre}' cerrada y ubicaciones liberadas.");
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * Bloquea las ubicaciones involucradas en una instrucción de conteo.
     */
    private function bloquearUbicacionesDeInstruccion(SesionAsignacion $asig, $user)
    {
        $query = Ubicacion::where('empresa_id', $asig->sesion->empresa_id)
                          ->where('sucursal_id', $asig->sesion->sucursal_id);

        if ($asig->tipo_instruccion === SesionAsignacion::INSTRUCCION_PASILLO) {
            $query->where('pasillo', $asig->pasillo);
        } elseif ($asig->tipo_instruccion === SesionAsignacion::INSTRUCCION_MODULO) {
            $query->where('modulo', $asig->modulo);
        } elseif ($asig->tipo_instruccion === SesionAsignacion::INSTRUCCION_REFERENCIA) {
            $ubiIds = Inventario::where('producto_id', $asig->producto_id)
                                ->where('empresa_id', $asig->sesion->empresa_id)
                                ->pluck('ubicacion_id');
            $query->whereIn('id', $ubiIds);
        } else {
            return;
        }

        $query->update(['estado' => Ubicacion::ESTADO_LOCKED]);
    }

    /**
     * Crea una notificación para el auxiliar asignado a un conteo.
     * No interrumpe el flujo si falla.
     */
    private function crearNotificacionAuxiliar(SesionAsignacion $asig, SesionInventario $sesion): void
    {
        try {
            $tipoLabel = match($sesion->tipo) {
                'CargueInicial' => 'Cargue Inicial',
                'General'       => 'Inventario General',
                default         => 'Conteo Cíclico',
            };
            Notificacion::create([
                'empresa_id'      => $sesion->empresa_id,
                'sucursal_id'     => $sesion->sucursal_id,
                'personal_id'     => $asig->auxiliar_id,
                'tipo'            => 'inventario',
                'titulo'          => "{$tipoLabel}: {$sesion->nombre}",
                'mensaje'         => 'Tienes una tarea de conteo asignada. ' . ($asig->descripcion_instruccion ?? ''),
                'modulo'          => 'inventario',
                'referencia_tipo' => 'sesion_inventario',
                'referencia_id'   => $sesion->id,
                'link_accion'     => 'inventario',
                'sonido'          => true,
                'leida'           => false,
                'completada'      => false,
            ]);
        } catch (\Throwable $e) {
            // Notificación no crítica — no interrumpir el flujo
        }
    }

    /**
     * Libera las ubicaciones que fueron bloqueadas por una sesión.
     */
    private function liberarUbicacionesDeSesion(SesionInventario $sesion, $user)
    {
        foreach ($sesion->asignaciones as $asig) {
            $query = Ubicacion::where('empresa_id', $sesion->empresa_id)
                              ->where('sucursal_id', $sesion->sucursal_id)
                              ->where('estado', Ubicacion::ESTADO_LOCKED);

            if ($asig->tipo_instruccion === SesionAsignacion::INSTRUCCION_PASILLO) {
                $query->where('pasillo', $asig->pasillo);
            } elseif ($asig->tipo_instruccion === SesionAsignacion::INSTRUCCION_MODULO) {
                $query->where('modulo', $asig->modulo);
            } elseif ($asig->tipo_instruccion === SesionAsignacion::INSTRUCCION_REFERENCIA) {
                $ubiIds = Inventario::where('producto_id', $asig->producto_id)
                                    ->where('empresa_id', $sesion->empresa_id)
                                    ->pluck('ubicacion_id');
                $query->whereIn('id', $ubiIds);
            } else {
                continue;
            }

            $ubicaciones = $query->get();
            foreach ($ubicaciones as $u) {
                $u->recalcularEstado();
            }
        }
    }



    // ════════════════════════════════════════════════════════════════════════
    //  ██████  ASIGNACIONES
    // ════════════════════════════════════════════════════════════════════════

    /**
     * POST /api/v2/inventario/sesiones/{id}/asignaciones
     * Agrega una instrucción de conteo a un auxiliar en una ronda específica.
     */
    public function crearAsignacion(Request $req, Response $res, array $args): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $sesion = $this->_findSesion((int)$args['id'], $user, $req);

        if (!$sesion) return $this->notFound($res, 'Sesión no encontrada');
        if ($sesion->estado === SesionInventario::ESTADO_CERRADO) {
            return $this->error($res, 'No se pueden agregar asignaciones a una sesión cerrada');
        }

        $data = $req->getParsedBody() ?? [];
        if (empty($data['auxiliar_id'])) {
            return $this->error($res, 'Campo requerido: auxiliar_id');
        }

        $ronda = (int)($data['ronda'] ?? 1);
        if ($sesion->tipo === 'Ciclico') $ronda = 1;
        if ($ronda > $sesion->num_conteos) {
            return $this->error($res, "Esta sesión solo tiene {$sesion->num_conteos} ronda(s) configuradas");
        }

        $tipoInstruccion = $data['tipo_instruccion'] ?? 'Libre';
        if (!in_array($tipoInstruccion, ['Pasillo', 'Modulo', 'Referencia', 'Libre'])) {
            return $this->error($res, "tipo_instruccion inválido");
        }
        if ($tipoInstruccion === 'Referencia' && empty($data['producto_id'])) {
            return $this->error($res, 'Se requiere producto_id para tipo_instruccion Referencia');
        }

        try {
            $asignacion = SesionAsignacion::create([
                'sesion_id'         => $sesion->id,
                'auxiliar_id'       => $data['auxiliar_id'],
                'ronda'             => $ronda,
                'tipo_instruccion'  => $tipoInstruccion,
                'pasillo'           => $data['pasillo']    ?? null,
                'modulo'            => $data['modulo']     ?? null,
                'producto_id'       => $data['producto_id'] ?? null,
                'instruccion_libre' => $data['instruccion_libre'] ?? null,
                'estado'            => SesionAsignacion::ESTADO_PENDIENTE,
            ]);

            // Si la sesión ya está EnCurso y es ronda activa, notificar de inmediato
            if ($sesion->estado === SesionInventario::ESTADO_EN_CURSO) {
                $this->crearNotificacionAuxiliar($asignacion, $sesion);
                $asignacion->estado        = SesionAsignacion::ESTADO_NOTIFICADO;
                $asignacion->notificado_at = date('Y-m-d H:i:s');
                $asignacion->save();
            }

            return $this->ok($res, $asignacion->load('auxiliar:id,nombre'), 'Asignación creada');
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v2/inventario/asignaciones/{id}
     * Elimina una asignación siempre que no tenga líneas contadas.
     */
    public function eliminarAsignacion(Request $req, Response $res, array $args): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $empresaId  = $this->getEffectiveEmpresaId($user, $req);
        $esAdmin    = in_array($user->rol ?? '', ['Admin', 'SuperAdmin']);
        $asignacion = SesionAsignacion::whereHas('sesion', function ($q) use ($user, $req, $empresaId, $esAdmin) {
                $q->where('empresa_id', $empresaId);
                if (!$esAdmin) {
                    $q->where('sucursal_id', $user->sucursal_id);
                }
            })->find($args['id']);
        if (!$asignacion) return $this->notFound($res);

        $lineasCount = SesionLinea::where('asignacion_id', $asignacion->id)
            ->where('estado', 'Activo')->count();

        if ($lineasCount > 0) {
            return $this->error($res, "No se puede eliminar: el auxiliar ya registró {$lineasCount} línea(s) de conteo");
        }

        $asignacion->delete();
        return $this->ok($res, null, 'Asignación eliminada');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  ██████  CONTEO (uso del auxiliar — versión móvil)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/v2/inventario/mis-asignaciones
     * Retorna las asignaciones pendientes del auxiliar autenticado (móvil).
     */
    public function getMisAsignaciones(Request $req, Response $res): Response
    {
        try {
            $user = $req->getAttribute('user');

            $asignaciones = SesionAsignacion::where('auxiliar_id', $user->id)
                ->whereIn('estado', ['Notificado', 'EnConteo'])
                ->with([
                    'sesion:id,nombre,tipo,empresa_id,sucursal_id',
                    'producto:id,nombre,codigo_interno,unidades_caja,factor_udm,unidad_contenido,controla_vencimiento',
                ])
                ->where(function ($q) use ($user, $req) {
                    $q->whereHas('sesion', function ($sq) use ($user, $req) {
                        $sq->where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                           ->where('sucursal_id', $user->sucursal_id)
                           ->where('estado', SesionInventario::ESTADO_EN_CURSO);
                    });
                })
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($a) {
                    $a->descripcion_instruccion = $a->descripcion_instruccion;
                    if ($a->sesion) {
                        $a->sesion->ronda_actual = $a->ronda;
                    }
                    return $a;
                });

            return $this->ok($res, $asignaciones);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v2/inventario/asignaciones/{id}/iniciar
     * El auxiliar inicia su conteo asignado.
     */
    public function iniciarConteo(Request $req, Response $res, array $args): Response
    {
        $user        = $req->getAttribute('user');
        $asignacion  = SesionAsignacion::whereHas('sesion', function ($q) use ($user, $req) {
                $q->where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                  ->where('sucursal_id', $user->sucursal_id);
            })->find($args['id']);

        if (!$asignacion || $asignacion->auxiliar_id !== $user->id) {
            return $this->notFound($res, 'Asignación no encontrada o no pertenece a este usuario');
        }

        $asignacion->estado      = SesionAsignacion::ESTADO_EN_CONTEO;
        $asignacion->iniciado_at = date('Y-m-d H:i:s');
        $asignacion->save();

        return $this->ok($res, $asignacion, 'Conteo iniciado');
    }

    /**
     * POST /api/v2/inventario/asignaciones/{id}/linea
     * El auxiliar registra una línea de conteo.
     * Captura automáticamente el snapshot de cantidad en sistema.
     */
    public function registrarLinea(Request $req, Response $res, array $args): Response
    {
        $user       = $req->getAttribute('user');
        $asignacion = SesionAsignacion::with('sesion')
            ->whereHas('sesion', function ($q) use ($user, $req) {
                $q->where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                  ->where('sucursal_id', $user->sucursal_id);
            })->find($args['id']);

        if (!$asignacion || $asignacion->auxiliar_id !== $user->id) {
            return $this->notFound($res, 'Asignación no encontrada');
        }
        if ($asignacion->estado === 'Finalizado') {
            return $this->error($res, 'Esta asignación ya fue finalizada');
        }

        $data = $req->getParsedBody() ?? [];
        $required = ['producto_id', 'ubicacion_id', 'cantidad_contada'];
        foreach ($required as $f) {
            if (!isset($data[$f])) {
                return $this->error($res, "Campo requerido: {$f}");
            }
        }

        $cantidadContada = (float)$data['cantidad_contada'];
        if ($cantidadContada < 0) {
            return $this->error($res, 'La cantidad contada no puede ser negativa');
        }

        try {
            // Snapshot del inventario actual en sistema
            $stockSistema = Inventario::where('empresa_id',  $asignacion->sesion->empresa_id)
                ->where('sucursal_id',  $asignacion->sesion->sucursal_id)
                ->where('producto_id',  $data['producto_id'])
                ->where('ubicacion_id', $data['ubicacion_id'])
                ->when($data['lote'] ?? null, fn($q) => $q->where('lote', $data['lote']))
                ->sum('cantidad');

            $fv       = !empty($data['fecha_vencimiento'])
                        ? Carbon::parse($data['fecha_vencimiento'])->format('Y-m-d')
                        : null;
            $diferencia = $cantidadContada - $stockSistema;

            $linea = SesionLinea::create([
                'sesion_id'        => $asignacion->sesion_id,
                'asignacion_id'    => $asignacion->id,
                'auxiliar_id'      => $user->id,
                'ronda'            => $asignacion->ronda,
                'producto_id'      => $data['producto_id'],
                'ubicacion_id'     => $data['ubicacion_id'],
                'lote'             => $data['lote'] ?? null,
                'fecha_vencimiento'=> $fv,
                'cantidad_contada' => $cantidadContada,
                'cantidad_sistema' => $stockSistema,
                'diferencia'       => $diferencia,
                'hora_conteo'      => date('Y-m-d H:i:s'),
                'estado'           => SesionLinea::ESTADO_ACTIVO,
            ]);

            // Iniciar asignación si aún no empezó
            if ($asignacion->estado === 'Notificado') {
                $asignacion->estado      = 'EnConteo';
                $asignacion->iniciado_at = date('Y-m-d H:i:s');
                $asignacion->save();
            }

            return $this->ok($res, $linea->load(['producto:id,nombre,codigo_interno', 'ubicacion:id,codigo']), 'Línea registrada');
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v2/inventario/asignaciones/{id}/finalizar
     * El auxiliar finaliza su asignación de conteo.
     */
    public function finalizarAsignacion(Request $req, Response $res, array $args): Response
    {
        $user       = $req->getAttribute('user');
        $asignacion = SesionAsignacion::with('sesion')
            ->whereHas('sesion', function ($q) use ($user, $req) {
                $q->where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                  ->where('sucursal_id', $user->sucursal_id);
            })->find($args['id']);

        if (!$asignacion || $asignacion->auxiliar_id !== $user->id) {
            return $this->notFound($res, 'Asignación no encontrada');
        }

        $lineas = SesionLinea::where('asignacion_id', $asignacion->id)
            ->where('estado', 'Activo')->count();

        // Para asignaciones de tipo "Referencia" (verificar una referencia puntual)
        // se permite finalizar sin ninguna línea registrada: es el caso de negocio
        // en que la referencia enviada a contar no tiene existencia física alguna.
        // Para Pasillo/Módulo/Libre se mantiene la exigencia de al menos una línea.
        if ($lineas === 0 && $asignacion->tipo_instruccion !== SesionAsignacion::INSTRUCCION_REFERENCIA) {
            return $this->error($res, 'Debe registrar al menos una línea antes de finalizar');
        }

        $asignacion->estado        = SesionAsignacion::ESTADO_FINALIZADO;
        $asignacion->finalizado_at = date('Y-m-d H:i:s');
        $asignacion->save();

        // Verificar si toda la sesión puede pasar a PendienteAjuste (no crítico:
        // un fallo aquí no debe impedir que el conteo del auxiliar quede finalizado)
        try {
            $this->verificarCompletitudSesion($asignacion->sesion, $asignacion->ronda);
        } catch (\Throwable $e) {
            error_log("verificarCompletitudSesion asignacion={$asignacion->id}: " . $e->getMessage());
        }

        return $this->ok($res, null, 'Conteo finalizado correctamente');
    }

    /**
     * Si todas las asignaciones de la ronda ya finalizaron, pasa la sesión a PendienteAjuste.
     */
    private function verificarCompletitudSesion(SesionInventario $sesion, int $ronda): void
    {
        if ($sesion->estado !== SesionInventario::ESTADO_EN_CURSO) return;
        if ($sesion->rondaCompleta($ronda)) {
            $sesion->estado = SesionInventario::ESTADO_PENDIENTE_AJUSTE;
            $sesion->save();
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    //  ██████  DASHBOARD ADMINISTRATIVO
    // ════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/v2/inventario/sesiones/{id}/dashboard
     * Retorna todos los datos para el dashboard administrativo del conteo.
     *
     * Responde con:
     *  - sesion: datos de cabecera
     *  - resumen: KPIs (total líneas, diferencias, % avance)
     *  - matriz_conteo: tabla con Fecha, Auxiliar, Ref, Cantidad, Ubicación, FV, Días vida útil, Hora
     *  - matriz_diferencias: Referencia, Cant. contada, Cant. sistema, Cant. ubicación, Diferencias
     *  - consistencia_rondas: (solo General con 2+ rondas) diferencias entre rondas
     *  - necesita_tercer_conteo: bool
     */
    public function getDashboard(Request $req, Response $res, array $args): Response
    {
        try {
            $user   = $req->getAttribute('user');
            $params = $req->getQueryParams();

            $sesion = $this->_findSesion((int)$args['id'], $user, $req);
            if ($sesion) {
                $sesion->load(['creadoPor:id,nombre', 'ajustadoPor:id,nombre', 'asignaciones.auxiliar:id,nombre']);
            }

            if (!$sesion) return $this->notFound($res);

            $rondaFiltro = (int)($params['ronda'] ?? 1);

            // ── Matriz de conteo (todas las líneas activas de la ronda) ────
            $lineas = SesionLinea::where('sesion_lineas.sesion_id', $sesion->id)
                ->where('sesion_lineas.estado', SesionLinea::ESTADO_ACTIVO)
                ->when($rondaFiltro > 0, fn($q) => $q->where('sesion_lineas.ronda', $rondaFiltro))
                ->join('productos',   'sesion_lineas.producto_id',  '=', 'productos.id')
                ->join('ubicaciones', 'sesion_lineas.ubicacion_id', '=', 'ubicaciones.id')
                ->join('personal',    'sesion_lineas.auxiliar_id',  '=', 'personal.id')
                ->select(
                    'sesion_lineas.id',
                    'sesion_lineas.ronda',
                    'sesion_lineas.hora_conteo',
                    'sesion_lineas.cantidad_contada',
                    'sesion_lineas.cantidad_cajas',
                    'sesion_lineas.saldos',
                    'sesion_lineas.cantidad_sistema',
                    'sesion_lineas.diferencia',
                    'sesion_lineas.fecha_vencimiento',
                    'sesion_lineas.lote',
                    'sesion_lineas.editado_por',
                    'sesion_lineas.editado_at',
                    'productos.id as producto_id',
                    'productos.nombre as producto',
                    'productos.codigo_interno as codigo',
                    'productos.unidades_caja',
                    'ubicaciones.id as ubicacion_id',
                    'ubicaciones.codigo as ubicacion',
                    'personal.nombre as auxiliar',
                    'sesion_lineas.ajustado',
                    Capsule::raw("CASE
                        WHEN sesion_lineas.fecha_vencimiento IS NULL THEN NULL
                        ELSE (sesion_lineas.fecha_vencimiento::date - CURRENT_DATE)
                    END as dias_vida_util")
                )
                ->orderBy('sesion_lineas.hora_conteo')
                ->get();

            // ── Exportar el conteo (ajustado o no) a Excel ─────────────────
            if (($params['export'] ?? '') === 'excel') {
                $headers = [
                    'Ronda', 'Fecha/Hora', 'Auxiliar', 'Código', 'Producto', 'Ubicación',
                    'Lote', 'F.Vencimiento', 'Días V.U.',
                    'Cajas', 'Saldos', 'UND/TOTAL', 'Sistema', 'Diferencia', 'Ajustado',
                ];
                $rows = $lineas->map(fn($l) => [
                    $l->ronda,
                    $l->hora_conteo,
                    $l->auxiliar,
                    $l->codigo,
                    $l->producto,
                    $l->ubicacion,
                    $l->lote ?? '—',
                    $l->fecha_vencimiento ?? '—',
                    $l->dias_vida_util ?? '—',
                    $l->cantidad_cajas ?? '—',
                    $l->saldos ?? '—',
                    $l->cantidad_contada,
                    $l->cantidad_sistema,
                    $l->diferencia,
                    $l->ajustado ? 'Sí' : 'No',
                ])->toArray();
                return $this->exportCsv($res, $headers, $rows, 'conteo_ciclico_sesion' . $sesion->id . '_' . date('Y-m-d'));
            }

            // ── Matriz de diferencias (agrupada por producto + ubicación) ──
            $matrizDiff = SesionLinea::where('sesion_lineas.sesion_id', $sesion->id)
                ->where('sesion_lineas.estado', SesionLinea::ESTADO_ACTIVO)
                ->when($rondaFiltro > 0, fn($q) => $q->where('sesion_lineas.ronda', $rondaFiltro))
                ->join('productos',   'sesion_lineas.producto_id',  '=', 'productos.id')
                ->join('ubicaciones', 'sesion_lineas.ubicacion_id', '=', 'ubicaciones.id')
                ->leftJoin('inventarios', function ($j) use ($sesion) {
                    $j->on('inventarios.producto_id',  '=', 'sesion_lineas.producto_id')
                      ->on('inventarios.ubicacion_id', '=', 'sesion_lineas.ubicacion_id')
                      ->where('inventarios.empresa_id',  $sesion->empresa_id)
                      ->where('inventarios.sucursal_id', $sesion->sucursal_id);
                })
                ->select(
                    'sesion_lineas.id',
                    'productos.codigo_interno as codigo',
                    'productos.nombre as producto',
                    'ubicaciones.codigo as ubicacion',
                    'sesion_lineas.lote',
                    'sesion_lineas.fecha_vencimiento',
                    'sesion_lineas.cantidad_contada',
                    'sesion_lineas.cantidad_sistema as cantidad_sistema_snap',
                    Capsule::raw('COALESCE(SUM(inventarios.cantidad), 0) as cantidad_en_ubicacion'),
                    'sesion_lineas.diferencia',
                    'sesion_lineas.ajustado',
                    Capsule::raw("CASE WHEN sesion_lineas.diferencia > 0 THEN 'Sobrante'
                                       WHEN sesion_lineas.diferencia < 0 THEN 'Faltante'
                                       ELSE 'Sin diferencia' END as tipo_diferencia")
                )
                ->groupBy(
                    'sesion_lineas.id', 'productos.codigo_interno', 'productos.nombre',
                    'ubicaciones.codigo', 'sesion_lineas.lote', 'sesion_lineas.fecha_vencimiento',
                    'sesion_lineas.cantidad_contada', 'sesion_lineas.cantidad_sistema',
                    'sesion_lineas.diferencia', 'sesion_lineas.ajustado'
                )
                ->orderBy('sesion_lineas.diferencia')
                ->get();

            // ── KPIs del dashboard ─────────────────────────────────────────
            $totalLineas   = $lineas->count();
            $conDiferencia = $lineas->where('diferencia', '!=', 0)->count();
            $sobrantes     = $lineas->where('diferencia', '>', 0)->sum('diferencia');
            $faltantes     = $lineas->where('diferencia', '<', 0)->sum('diferencia');
            $auxiliares    = $lineas->pluck('auxiliar')->unique()->values();

            // Avance de asignaciones
            $asignacionesTotal     = $sesion->asignaciones()->where('ronda', $rondaFiltro)->count();
            $asignacionesTerminadas = $sesion->asignaciones()->where('ronda', $rondaFiltro)->where('estado', 'Finalizado')->count();
            $pctAvance = $asignacionesTotal > 0 ? round(($asignacionesTerminadas / $asignacionesTotal) * 100, 1) : 0;

            // ── Ubicaciones en cero: el auxiliar contó 0 donde el sistema reporta stock ──
            $ubicacionesEnCero = SesionLinea::where('sesion_lineas.sesion_id', $sesion->id)
                ->where('sesion_lineas.estado', SesionLinea::ESTADO_ACTIVO)
                ->where('sesion_lineas.cantidad_contada', 0)
                ->where('sesion_lineas.cantidad_sistema', '>', 0)
                ->when($rondaFiltro > 0, fn($q) => $q->where('sesion_lineas.ronda', $rondaFiltro))
                ->join('productos',   'sesion_lineas.producto_id',  '=', 'productos.id')
                ->join('ubicaciones', 'sesion_lineas.ubicacion_id', '=', 'ubicaciones.id')
                ->join('personal',    'sesion_lineas.auxiliar_id',  '=', 'personal.id')
                ->select(
                    'sesion_lineas.id',
                    'sesion_lineas.hora_conteo',
                    'sesion_lineas.cantidad_sistema as stock_sistema',
                    'sesion_lineas.ajustado',
                    'sesion_lineas.lote',
                    'productos.codigo_interno as codigo',
                    'productos.nombre as producto',
                    'ubicaciones.codigo as ubicacion',
                    'personal.nombre as auxiliar'
                )
                ->orderBy('sesion_lineas.hora_conteo', 'desc')
                ->get();

            // Consistencia entre rondas (solo para General con 2+ rondas)
            $consistencia = null;
            $necesitaTercerConteo = false;
            if ($sesion->tipo === 'General' && $sesion->num_conteos >= 2) {
                $result = $sesion->verificarConsistenciaRondas();
                $consistencia = $result;
                // El tercer conteo se activa automáticamente si hay diferencias entre ronda 1 y 2
                if ($sesion->num_conteos === 3 && !$result['ok']) {
                    $necesitaTercerConteo = true;
                }
            }

            // ── Matriz Consolidada (Agrupada por Referencia + Ubicación + Lote) ──
            $todasLasLineas = SesionLinea::where('sesion_id', $sesion->id)
                ->where('estado', SesionLinea::ESTADO_ACTIVO)
                ->with(['producto.eanPrincipal', 'ubicacion:id,codigo', 'auxiliar:id,nombre'])
                ->get();

            $consolidado = $todasLasLineas->groupBy(function($l) {
                return $l->producto_id . '_' . ($l->lote ?? 'N/A');
            })->map(function($group) {
                $first = $group->first();
                $r1 = $group->where('ronda', 1)->sum('cantidad_contada');
                $r2 = $group->where('ronda', 2)->sum('cantidad_contada');
                $r3 = $group->where('ronda', 3)->sum('cantidad_contada');
                
                $ultimoConteo = $group->sortByDesc('created_at')->first();
                
                return [
                    'producto_id'  => $first->producto_id,
                    'codigo'       => $first->producto->codigo_interno,
                    'producto'     => $first->producto->nombre,
                    'ean'          => $first->producto->eanPrincipal->codigo_ean ?? null,
                    'lote'         => $first->lote ?? 'N/A',
                    'f_venc'       => $first->fecha_vencimiento,
                    'ronda_1'      => (int)$r1,
                    'ronda_2'      => (int)$r2,
                    'ronda_3'      => (int)$r3,
                    'sistema'      => (float)$ultimoConteo->cantidad_sistema,
                    'diferencia'   => (float)($group->where('ronda', $group->max('ronda'))->sum('cantidad_contada') - $ultimoConteo->cantidad_sistema),
                    // Sub-agrupación por Ubicación y Vencimiento para el detalle
                    'detalles'     => $group->groupBy(function($gl) {
                        return $gl->ubicacion_id . '_' . ($gl->fecha_vencimiento ?? 'N/A');
                    })->map(function($subGroup) {
                        $sFirst = $subGroup->first();
                        return [
                            'ubicacion'    => $sFirst->ubicacion->codigo,
                            'f_venc'       => $sFirst->fecha_vencimiento,
                            'dias_v_u'     => $sFirst->fecha_vencimiento ? Carbon::now()->startOfDay()->diffInDays(Carbon::parse($sFirst->fecha_vencimiento), false) : null,
                            'r1'           => (float)$subGroup->where('ronda', 1)->sum('cantidad_contada'),
                            'r2'           => (float)$subGroup->where('ronda', 2)->sum('cantidad_contada'),
                            'r3'           => (float)$subGroup->where('ronda', 3)->sum('cantidad_contada'),
                            'auxiliares'   => $subGroup->pluck('auxiliar.nombre')->unique()->values()->all(),
                            'ultimo_c'     => $subGroup->max('hora_conteo')
                        ];
                    })->values()
                ];
            })->values();

            return $this->ok($res, [
                'sesion'               => $sesion,
                'ronda_filtro'         => $rondaFiltro,
                'kpis'                 => [
                    'total_lineas'              => $totalLineas,
                    'lineas_con_diferencia'     => $conDiferencia,
                    'pct_diferencia'            => $totalLineas > 0 ? round(($conDiferencia / $totalLineas) * 100, 1) : 0,
                    'sobrantes_unidades'        => (int)$sobrantes,
                    'faltantes_unidades'        => (int)$faltantes,
                    'auxiliares_involucrados'   => $auxiliares,
                    'asignaciones_total'        => $asignacionesTotal,
                    'asignaciones_terminadas'   => $asignacionesTerminadas,
                    'pct_avance'                => $pctAvance,
                    'ubicaciones_vaciadas'      => $ubicacionesEnCero->count(),
                ],
                'matriz_conteo'        => $lineas,
                'matriz_diferencias'   => $matrizDiff,
                'matriz_consolidada'   => $consolidado,
                'consistencia_rondas'  => $consistencia,
                'necesita_tercer_conteo' => $necesitaTercerConteo,
                'ubicaciones_en_cero'  => $ubicacionesEnCero,
            ]);
        } catch (\Throwable $e) {
            error_log('Dashboard error: ' . $e->getMessage());
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    //  ██████  ACCIONES ADMINISTRATIVAS SOBRE LÍNEAS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * PUT /api/v2/inventario/lineas/{id}
     * Edita la cantidad física contada de una línea.
     * Guarda la cantidad original y auditoría.
     */
    public function editarLinea(Request $req, Response $res, array $args): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $linea = SesionLinea::with('sesion')->find($args['id']);
        if (!$linea || $linea->sesion->empresa_id !== $this->getEffectiveEmpresaId($user, $req)) {
            return $this->notFound($res);
        }
        if ($linea->estado === SesionLinea::ESTADO_ELIMINADO) {
            return $this->error($res, 'No se puede editar una línea eliminada');
        }
        if (!in_array($linea->sesion->estado, ['EnCurso', 'PendienteAjuste'])) {
            return $this->error($res, 'Solo se pueden editar líneas de sesiones en curso o pendientes de ajuste');
        }

        $data = $req->getParsedBody() ?? [];
        if (!isset($data['cantidad_contada'])) {
            return $this->error($res, 'Campo requerido: cantidad_contada');
        }

        $auditDataOld = ['cantidad_contada' => $linea->cantidad_contada];
        $auditDataNew = ['cantidad_contada' => (float)$data['cantidad_contada']];

        // Cambio de Producto
        if (!empty($data['nuevo_producto_codigo'])) {
            $codigoProd = strtoupper(trim($data['nuevo_producto_codigo']));
            $prod = Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                ->whereRaw("UPPER(codigo_interno) = ?", [$codigoProd])->first();
            if (!$prod) return $this->error($res, "Producto no encontrado: {$codigoProd}");
            $auditDataOld['producto_id'] = $linea->producto_id;
            $linea->producto_id = $prod->id;
            $auditDataNew['producto_id'] = $prod->id;
        }

        // Cambio de Ubicación
        if (!empty($data['nueva_ubicacion_codigo'])) {
            $codigoUbic = strtoupper(trim($data['nueva_ubicacion_codigo']));
            $ubic = Ubicacion::whereRaw("UPPER(codigo) = ?", [$codigoUbic])
                ->where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                ->first();
            if (!$ubic) return $this->error($res, "Ubicación no encontrada: {$codigoUbic}");
            $auditDataOld['ubicacion_id'] = $linea->ubicacion_id;
            $linea->ubicacion_id = $ubic->id;
            $auditDataNew['ubicacion_id'] = $ubic->id;
            
            // Si cambia la ubicación, debemos actualizar la cantidad_sistema del SNAPSHOT
            // Para V2 simplificado, buscaremos el stock actual de esa ubicación o mantendremos el snapshot 
            // En este sistema, cantidad_sistema se captura al momento del conteo.
            // Si re-ubicamos administrativamente, lo ideal es obtener el stock SNAPSHOT de esa ubicación.
            $stockUbic = Inventario::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                ->where('sucursal_id', $user->sucursal_id)
                ->where('producto_id', $linea->producto_id)
                ->where('ubicacion_id', $ubic->id)
                ->sum('cantidad');
            $linea->cantidad_sistema = $stockUbic;
        }

        if (isset($data['fecha_vencimiento'])) {
            $auditDataOld['fecha_vencimiento'] = $linea->fecha_vencimiento;
            $linea->fecha_vencimiento = $data['fecha_vencimiento'];
            $auditDataNew['fecha_vencimiento'] = $data['fecha_vencimiento'];
        }

        $nueva = (float)$data['cantidad_contada'];
        if ($nueva < 0) return $this->error($res, 'La cantidad no puede ser negativa');

        // Desglose Cajas/Saldos (solo presentación): se persiste tal cual lo capturó
        // el supervisor, para no recalcular una combinación distinta al reabrir la línea.
        if (array_key_exists('cantidad_cajas', $data)) {
            $auditDataOld['cantidad_cajas'] = $linea->cantidad_cajas;
            $linea->cantidad_cajas = $data['cantidad_cajas'] !== null ? (int)$data['cantidad_cajas'] : null;
            $auditDataNew['cantidad_cajas'] = $linea->cantidad_cajas;
        }
        if (array_key_exists('saldos', $data)) {
            $auditDataOld['saldos'] = $linea->saldos;
            $linea->saldos = $data['saldos'] !== null ? (float)$data['saldos'] : null;
            $auditDataNew['saldos'] = $linea->saldos;
        }

        $linea->cantidad_original = $linea->cantidad_original ?? $linea->cantidad_contada;
        $linea->cantidad_contada  = $nueva;
        $linea->diferencia        = $nueva - $linea->cantidad_sistema;
        $linea->editado_por       = $user->id;
        $linea->editado_at        = date('Y-m-d H:i:s');
        $linea->motivo_edicion    = $data['motivo'] ?? 'Corrección administrativa';
        $linea->save();

        $this->audit($user, 'inventario_v2', 'editar_linea', 'sesion_lineas', $linea->id,
            $auditDataOld,
            array_merge($auditDataNew, ['motivo' => $linea->motivo_edicion])
        );

        return $this->ok($res, $linea->fresh(), 'Línea actualizada');
    }

    /**
     * DELETE /api/v2/inventario/lineas/{id}
     * Soft-delete de una línea (no se borra físicamente).
     */
    public function eliminarLinea(Request $req, Response $res, array $args): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $linea = SesionLinea::with('sesion')->find($args['id']);
        if (!$linea || $linea->sesion->empresa_id !== $this->getEffectiveEmpresaId($user, $req)) {
            return $this->notFound($res);
        }
        if ($linea->estado === SesionLinea::ESTADO_ELIMINADO) {
            return $this->error($res, 'La línea ya fue eliminada');
        }

        $data = $req->getParsedBody() ?? [];

        $linea->estado       = SesionLinea::ESTADO_ELIMINADO;
        $linea->eliminado_por = $user->id;
        $linea->eliminado_at  = date('Y-m-d H:i:s');
        $linea->motivo_edicion = $data['motivo'] ?? 'Eliminado por administrador';
        $linea->save();

        $this->audit($user, 'inventario_v2', 'eliminar_linea', 'sesion_lineas', $linea->id, null, []);

        return $this->ok($res, null, 'Línea eliminada');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  ██████  AJUSTES DE INVENTARIO
    // ════════════════════════════════════════════════════════════════════════

    /**
     * POST /api/v2/inventario/sesiones/{id}/ajustar-linea
     * Ajusta el inventario para UNA línea específica del conteo.
     * body: { linea_id: int }
     */
    public function ajustarLinea(Request $req, Response $res, array $args): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $sesion = $this->_findSesion((int)$args['id'], $user, $req);

        if (!$sesion) return $this->notFound($res, 'Sesión no encontrada');

        $data = $req->getParsedBody() ?? [];
        if (empty($data['linea_id'])) {
            return $this->error($res, 'Campo requerido: linea_id');
        }

        $linea = SesionLinea::where('sesion_id', $sesion->id)
            ->where('estado', SesionLinea::ESTADO_ACTIVO)
            ->find($data['linea_id']);

        if (!$linea) return $this->notFound($res, 'Línea no encontrada o ya eliminada');
        if ($linea->diferencia === 0) {
            return $this->ok($res, null, 'Sin diferencia — no se requiere ajuste');
        }

        try {
            $ajuste = $this->ejecutarAjuste($sesion, $linea, $user, AjusteInventario::ORIGEN_CONTEO_LINEA);

            // Obtener stock actualizado tras el ajuste (para mostrar en frontend)
            $stockActual = Inventario::where('empresa_id',  $sesion->empresa_id)
                ->where('sucursal_id',  $sesion->sucursal_id)
                ->where('producto_id',  $linea->producto_id)
                ->where('ubicacion_id', $linea->ubicacion_id)
                ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                ->value('cantidad') ?? 0;

            return $this->ok($res, [
                'ajuste'         => $ajuste,
                'stock_nuevo'    => (float)$stockActual,
                'producto_id'    => $linea->producto_id,
                'ubicacion_id'   => $linea->ubicacion_id,
                'cantidad_contada' => (float)$linea->cantidad_contada,
                'diferencia_real'  => $ajuste->diferencia,
            ], 'Ajuste de línea realizado correctamente');
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v2/inventario/sesiones/{id}/ajustar-todo
     * Ajusta el inventario para TODAS las líneas con diferencia del conteo.
     * Requiere verificación (campo confirm: true en el body).
     */
    public function ajustarTodo(Request $req, Response $res, array $args): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $sesion = $this->_findSesion((int)$args['id'], $user, $req);

        if (!$sesion) return $this->notFound($res, 'Sesión no encontrada');

        $data = $req->getParsedBody() ?? [];
        if (empty($data['confirm']) || $data['confirm'] !== true) {
            return $this->error($res, 'Se requiere confirm: true para ejecutar el ajuste masivo');
        }

        // Para General con 2 rondas: verificar consistencia antes de ajustar
        if ($sesion->tipo === 'General' && $sesion->num_conteos === 2
            && !filter_var($data['omitir_verificacion'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $check = $sesion->verificarConsistenciaRondas();
            if (!$check['ok']) {
                return $this->error($res,
                    'Existen ' . count($check['diferencias']) . ' diferencia(s) entre ronda 1 y ronda 2. ' .
                    'Resuelva las diferencias o use el tercer conteo antes de ajustar.'
                );
            }
        }

        // Determinar ronda final a ajustar
        $rondaFinal = $sesion->num_conteos; // La última ronda configurada
        if ($sesion->tipo === 'General' && $sesion->num_conteos === 3) {
            // Si hay tercer conteo, usar ronda 3 como definitiva
            $tieneRonda3 = SesionLinea::where('sesion_id', $sesion->id)
                ->where('ronda', 3)->where('estado', 'Activo')->exists();
            if (!$tieneRonda3) {
                $rondaFinal = 1; // Solo hay ronda 1 (num_conteos=3 pero solo para posibilidad)
            }
        }

        $lineas = SesionLinea::where('sesion_id', $sesion->id)
            ->where('ronda', $rondaFinal)
            ->where('estado', SesionLinea::ESTADO_ACTIVO)
            ->where('diferencia', '!=', 0)
            ->get();

        if ($lineas->isEmpty()) {
            // Marcar como ajustado sin diferencias
            $sesion->estado      = SesionInventario::ESTADO_AJUSTADO;
            $sesion->ajustado_por = $user->id;
            $sesion->fecha_cierre = date('Y-m-d');
            $sesion->save();
            return $this->ok($res, ['ajustes_realizados' => 0], 'Sin diferencias que ajustar. Sesión marcada como ajustada.');
        }

        // ── Detectar referencias no contadas (análisis ML de ausencia física) ──
        $noContadas = $this->detectarReferenciasNoContadas($sesion, $rondaFinal);

        // ── Reconciliar asignaciones "por Referencia": el auxiliar pudo confirmar
        //    la referencia en una ubicación distinta a la que el sistema tenía
        //    registrada (o no encontrarla en ninguna) → se fuerza a 0 en TODAS las
        //    demás ubicaciones donde el sistema aún muestra stock de esa referencia,
        //    para no dejar el stock original duplicado junto al recién confirmado. ──
        $sinExistencia = $this->detectarReferenciasSinExistencia($sesion, $rondaFinal, $noContadas);

        try {
            $resultado = Capsule::transaction(function () use ($sesion, $lineas, $noContadas, $sinExistencia, $user) {
                $ajustes          = [];
                $ajustesCero      = [];
                $stockResumen     = [];

                // 1. Ajustar líneas con diferencia contada
                foreach ($lineas as $linea) {
                    $aj = $this->ejecutarAjuste($sesion, $linea, $user, AjusteInventario::ORIGEN_CONTEO_TOTAL);
                    $ajustes[] = $aj;
                    $stockResumen[] = [
                        'producto_id'   => $linea->producto_id,
                        'ubicacion_id'  => $linea->ubicacion_id,
                        'lote'          => $linea->lote,
                        'cantidad_nueva'=> (float)$linea->cantidad_contada,
                        'diferencia'    => $aj->diferencia,
                        'tipo'          => 'conteo',
                    ];
                }

                // 2. Ajustar referencias no contadas → ausencia física → poner en 0
                foreach ($noContadas as $lineaVirtual) {
                    $aj = $this->ejecutarAjuste(
                        $sesion, $lineaVirtual, $user,
                        AjusteInventario::ORIGEN_CONTEO_TOTAL,
                        true // esCeroForzado = true
                    );
                    $ajustesCero[] = $aj;
                    $stockResumen[] = [
                        'producto_id'   => $lineaVirtual->producto_id,
                        'ubicacion_id'  => $lineaVirtual->ubicacion_id,
                        'lote'          => $lineaVirtual->lote,
                        'cantidad_nueva'=> 0,
                        'diferencia'    => $aj->diferencia,
                        'tipo'          => 'ml_ausencia',
                    ];
                }

                // 3. Ajustar referencias confirmadas sin existencia (asignación tipo Referencia)
                //    → poner en 0 y eliminar en TODAS las ubicaciones del sistema
                foreach ($sinExistencia as $lineaVirtual) {
                    $aj = $this->ejecutarAjuste(
                        $sesion, $lineaVirtual, $user,
                        AjusteInventario::ORIGEN_CONTEO_TOTAL,
                        true, // esCeroForzado = true
                        'Referencia confirmada sin existencia física en toda la bodega'
                    );
                    $ajustesCero[] = $aj;
                    $stockResumen[] = [
                        'producto_id'   => $lineaVirtual->producto_id,
                        'ubicacion_id'  => $lineaVirtual->ubicacion_id,
                        'lote'          => $lineaVirtual->lote,
                        'cantidad_nueva'=> 0,
                        'diferencia'    => $aj->diferencia,
                        'tipo'          => 'referencia_sin_existencia',
                    ];
                }

                $sesion->estado       = SesionInventario::ESTADO_AJUSTADO;
                $sesion->ajustado_por  = $user->id;
                $sesion->fecha_cierre  = date('Y-m-d');
                $sesion->save();

                return compact('ajustes', 'ajustesCero', 'stockResumen');
            });

            $totalAjustes = count($resultado['ajustes']) + count($resultado['ajustesCero']);

            $this->audit($user, 'inventario_v2', 'ajustar_todo', 'sesiones_inventario', $sesion->id, null, [
                'ajustes_conteo'   => count($resultado['ajustes']),
                'ajustes_ml_cero'  => count($resultado['ajustesCero']),
                'total'            => $totalAjustes,
            ]);

            return $this->ok($res, [
                'ajustes_realizados'    => $totalAjustes,
                'ajustes_conteo'        => count($resultado['ajustes']),
                'ajustes_ml_ausencia'   => count($resultado['ajustesCero']),
                'sesion_id'             => $sesion->id,
                'estado'                => $sesion->estado,
                'stock_resumen'         => $resultado['stockResumen'],
            ], 'Ajuste masivo completado correctamente');
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * Lógica central de ajuste: actualiza inventarios, crea AjusteInventario y MovimientoInventario.
     *
     * PRINCIPIO FUNDAMENTAL DE INVENTARIO FÍSICO:
     * ─────────────────────────────────────────────────────────────────────
     * El conteo físico es la VERDAD ABSOLUTA. Si el auxiliar contó 10 unidades,
     * el sistema DEBE quedar en 10, independientemente de lo que diga el sistema
     * en ese momento. NO hacemos: stock_actual + diferencia_snapshot, porque eso
     * puede multiplicar errores si hubo movimientos entre el conteo y el ajuste.
     *
     * Fórmula CORRECTA: cantidad_nueva = cantidad_contada (siempre)
     * Diferencia REAL  = cantidad_contada - stock_actual_sistema
     * ─────────────────────────────────────────────────────────────────────
     */
    private function ejecutarAjuste(
        SesionInventario $sesion,
        SesionLinea $linea,
        $user,
        string $origen,
        bool $esCeroForzado = false,   // true cuando se detecta ausencia física ML
        ?string $motivoExtra = null    // texto adicional para el motivo del ajuste (auditoría)
    ): AjusteInventario {

        // ── 1. Obtener stock REAL y ACTUAL del sistema (puede diferir del snapshot) ──
        $inv = Inventario::where('empresa_id',  $sesion->empresa_id)
            ->where('sucursal_id',  $sesion->sucursal_id)
            ->where('producto_id',  $linea->producto_id)
            ->where('ubicacion_id', $linea->ubicacion_id)
            ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
            ->first();

        $cantidadSistemaActual = $inv ? (float)$inv->cantidad : 0.0;

        // ── 2. VERDAD ABSOLUTA: lo que se contó físicamente ──
        $cantidadNueva = $esCeroForzado ? 0.0 : (float)$linea->cantidad_contada;

        // ── 3. Diferencia REAL entre conteo y stock actual ──
        $diferenciaReal = $cantidadNueva - $cantidadSistemaActual;

        // Si no hay diferencia real (stock ya correcto), registrar igualmente para trazabilidad
        $tipoAjuste = $diferenciaReal >= 0
            ? AjusteInventario::TIPO_ENTRADA
            : AjusteInventario::TIPO_SALIDA;

        // ── 4. Actualizar tabla inventarios ──
        if ($inv) {
            if ($cantidadNueva <= 0) {
                // Eliminar registro: la referencia no existe físicamente
                $inv->delete();
            } else {
                $inv->cantidad = $cantidadNueva;
                if ($linea->fecha_vencimiento) {
                    $inv->fecha_vencimiento = $linea->fecha_vencimiento;
                }
                $inv->save();
            }
        } elseif ($cantidadNueva > 0) {
            // Nueva referencia descubierta en conteo (no estaba en sistema)
            Inventario::create([
                'empresa_id'         => $sesion->empresa_id,
                'sucursal_id'        => $sesion->sucursal_id,
                'producto_id'        => $linea->producto_id,
                'ubicacion_id'       => $linea->ubicacion_id,
                'lote'               => $linea->lote,
                'fecha_vencimiento'  => $linea->fecha_vencimiento,
                'cantidad'           => $cantidadNueva,
                'cantidad_reservada' => 0,
                'cantidad_cajas'     => 0,
                'saldos'             => 0,
                'estado'             => 'Disponible',
            ]);
        }

        // ── 5. Movimiento de inventario (Kardex) — cantidad = diferencia real ──
        $tipoMovimiento = $diferenciaReal >= 0
            ? MovimientoInventario::TIPO_AJUSTE_POSITIVO
            : MovimientoInventario::TIPO_AJUSTE_NEGATIVO;

        $motivoMov = $esCeroForzado
            ? ($motivoExtra ?? "Ajuste ML — referencia no contada (ausencia física detectada)") . " — Sesión #{$sesion->id}: {$sesion->nombre}"
            : "Ajuste de inventario — Sesión #{$sesion->id}: {$sesion->nombre} (Ronda {$linea->ronda})";

        $mov = MovimientoInventario::create([
            'empresa_id'           => $sesion->empresa_id,
            'sucursal_id'          => $sesion->sucursal_id,
            'producto_id'          => $linea->producto_id,
            'tipo_movimiento'      => $tipoMovimiento,
            'cantidad'             => abs($diferenciaReal),
            'ubicacion_origen_id'  => $linea->ubicacion_id,
            'ubicacion_destino_id' => $linea->ubicacion_id,
            'lote'                 => $linea->lote,
            'fecha_vencimiento'    => $linea->fecha_vencimiento,
            'auxiliar_id'          => $user->id,
            'referencia_tipo'      => 'sesion_inventario',
            'referencia_id'        => $sesion->id,
            'observaciones'        => $motivoMov,
            'fecha_movimiento'     => date('Y-m-d'),
            'hora_inicio'          => date('H:i:s'),
        ]);

        // ── 6. Registro inmutable en ajustes_inventario (trazabilidad completa) ──
        $motivoAjuste = $esCeroForzado
            ? ($motivoExtra ?? "ML-Ausencia física: referencia presente en sistema pero no contada") . " — sesión: {$sesion->nombre}"
            : "Ajuste por conteo físico — sesión: {$sesion->nombre} (Ronda {$linea->ronda})";

        $ajuste = AjusteInventario::create([
            'empresa_id'        => $sesion->empresa_id,
            'sucursal_id'       => $sesion->sucursal_id,
            'origen'            => $origen,
            'sesion_id'         => $sesion->id,
            'linea_id'          => $esCeroForzado ? null : $linea->id, // No line ID for virtual lines
            'movimiento_id'     => $mov->id,
            'producto_id'       => $linea->producto_id,
            'ubicacion_id'      => $linea->ubicacion_id,
            'lote'              => $linea->lote,
            'fecha_vencimiento' => $linea->fecha_vencimiento,
            'cantidad_fisica'   => $cantidadNueva,                // lo que hay físicamente
            'cantidad_sistema'  => $cantidadSistemaActual,        // stock real al momento del ajuste
            'diferencia'        => $diferenciaReal,               // diferencia real (no snapshot)
            'tipo_ajuste'       => $tipoAjuste,
            'motivo'            => $motivoAjuste,
            'auxiliar_id'       => $linea->auxiliar_id,
            'ajustado_por'      => $user->id,
            'fecha'             => date('Y-m-d'),
            'hora'              => date('H:i:s'),
        ]);

        if (!$esCeroForzado) {
            $linea->ajustado = true;
            $linea->save();
        }

        return $ajuste;
    }

    /**
     * Detecta referencias que el sistema registra en las ubicaciones de la sesión
     * pero que NO fueron contadas durante el inventario.
     * Principio: si el auxiliar recorrió la ubicación y no la contó, no existe físicamente.
     *
     * OPTIMIZACIÓN: usa NOT EXISTS en SQL (1 sola query) en lugar de cargar
     * dos colecciones completas y comparar en PHP (antes O(n×m) en memoria).
     *
     * @return array  Lista de SesionLinea virtuales (con cantidad_contada = 0) para ajustar
     */
    private function detectarReferenciasNoContadas(SesionInventario $sesion, int $rondaFinal): array
    {
        // Las ubicaciones se obtienen de las LÍNEAS CONTADAS, no de las asignaciones.
        // sesion_asignaciones define al auxiliar y el tipo de instrucción (pasillo/módulo/libre),
        // pero NO tiene ubicacion_id directamente. La ubicación aparece en cada línea registrada.
        // Lógica: si un auxiliar contó algo en una ubicación, recorrió esa ubicación.
        // Por lo tanto, si hay stock en esa ubicación que NO fue contado → ausencia física.
        $ubicacionIds = SesionLinea::where('sesion_id', $sesion->id)
            ->where('ronda', $rondaFinal)
            ->where('estado', SesionLinea::ESTADO_ACTIVO)
            ->pluck('ubicacion_id')
            ->unique()
            ->values()
            ->toArray();

        if (empty($ubicacionIds)) return [];

        // ── Una sola query SQL con NOT EXISTS (usa índices, sin carga en PHP) ──
        // Trae todas las referencias en inventarios para las ubicaciones asignadas
        // que NO tienen una línea de conteo en la ronda final de esta sesión.
        $enSistema = Capsule::table('inventarios')
            ->where('inventarios.empresa_id',  $sesion->empresa_id)
            ->where('inventarios.sucursal_id',  $sesion->sucursal_id)
            ->whereIn('inventarios.ubicacion_id', $ubicacionIds)
            ->where('inventarios.cantidad', '>', 0)
            ->whereNotExists(function ($sub) use ($sesion, $rondaFinal) {
                // La referencia NO fue contada si no hay línea activa en la ronda final
                $sub->select(Capsule::raw(1))
                    ->from('sesion_lineas')
                    ->where('sesion_lineas.sesion_id', $sesion->id)
                    ->where('sesion_lineas.ronda',     $rondaFinal)
                    ->where('sesion_lineas.estado',    SesionLinea::ESTADO_ACTIVO)
                    ->whereRaw('sesion_lineas.producto_id  = inventarios.producto_id')
                    ->whereRaw('sesion_lineas.ubicacion_id = inventarios.ubicacion_id')
                    // Comparación segura de lote nullable: NULL = NULL es TRUE aquí
                    ->whereRaw('(sesion_lineas.lote = inventarios.lote OR (sesion_lineas.lote IS NULL AND inventarios.lote IS NULL))');
            })
            ->select('producto_id', 'ubicacion_id', 'lote', 'fecha_vencimiento', 'cantidad')
            ->get();

        // Construir líneas virtuales para pasar a ejecutarAjuste
        $noContadas = [];
        foreach ($enSistema as $inv) {
            $lineaVirtual                    = new SesionLinea();
            $lineaVirtual->sesion_id         = $sesion->id;
            $lineaVirtual->producto_id       = $inv->producto_id;
            $lineaVirtual->ubicacion_id      = $inv->ubicacion_id;
            $lineaVirtual->lote              = $inv->lote;
            $lineaVirtual->fecha_vencimiento = $inv->fecha_vencimiento;
            $lineaVirtual->cantidad_contada  = 0;
            $lineaVirtual->cantidad_sistema  = $inv->cantidad;
            $lineaVirtual->diferencia        = -(float)$inv->cantidad;
            $lineaVirtual->ronda             = $rondaFinal;
            $lineaVirtual->auxiliar_id       = null;
            $lineaVirtual->estado            = SesionLinea::ESTADO_ACTIVO;
            $lineaVirtual->id                = 0; // virtual, no persiste
            $noContadas[] = $lineaVirtual;
        }

        return $noContadas;
    }

    /**
     * Para asignaciones de tipo "Referencia" (verificar una referencia puntual,
     * sin importar ubicación): el auxiliar puede confirmar la referencia en una
     * ubicación distinta a la que el sistema tiene registrada (o en ninguna, si
     * ya no existe físicamente). En ambos casos hay que reconciliar TODAS las
     * demás ubicaciones donde el sistema todavía muestra stock de ese producto
     * y que NO fueron confirmadas en esta asignación — se ponen en 0, porque de
     * lo contrario el stock quedaría duplicado (el original, nunca tocado, más
     * el recién confirmado en la nueva ubicación).
     *
     * @param array $yaCubiertas Líneas virtuales ya generadas por detectarReferenciasNoContadas(),
     *                           para no duplicar el ajuste sobre la misma producto+ubicación+lote.
     * @return array Lista de SesionLinea virtuales (cantidad_contada = 0) para ajustar
     */
    private function detectarReferenciasSinExistencia(SesionInventario $sesion, int $rondaFinal, array $yaCubiertas = []): array
    {
        $asignacionesReferencia = SesionAsignacion::where('sesion_id', $sesion->id)
            ->where('ronda', $rondaFinal)
            ->where('tipo_instruccion', SesionAsignacion::INSTRUCCION_REFERENCIA)
            ->where('estado', SesionAsignacion::ESTADO_FINALIZADO)
            ->whereNotNull('producto_id')
            ->get();

        if ($asignacionesReferencia->isEmpty()) return [];

        $vistos = [];
        foreach ($yaCubiertas as $lv) {
            $vistos[$lv->producto_id . '|' . $lv->ubicacion_id . '|' . ($lv->lote ?? '')] = true;
        }

        $sinExistencia = [];
        foreach ($asignacionesReferencia as $asig) {
            // Ubicaciones que sí quedaron confirmadas (contadas) en esta asignación —
            // se excluyen de la reconciliación aunque la cantidad contada sea 0, porque
            // "0 en una ubicación visitada" también es información confirmada, no ausencia.
            $ubicIdsCubiertas = SesionLinea::where('sesion_id', $sesion->id)
                ->where('asignacion_id', $asig->id)
                ->where('estado', SesionLinea::ESTADO_ACTIVO)
                ->pluck('ubicacion_id')
                ->toArray();

            $inventarios = Capsule::table('inventarios')
                ->where('empresa_id',  $sesion->empresa_id)
                ->where('sucursal_id', $sesion->sucursal_id)
                ->where('producto_id', $asig->producto_id)
                ->where('cantidad', '>', 0)
                ->when(!empty($ubicIdsCubiertas), fn($q) => $q->whereNotIn('ubicacion_id', $ubicIdsCubiertas))
                ->select('producto_id', 'ubicacion_id', 'lote', 'fecha_vencimiento', 'cantidad')
                ->get();

            foreach ($inventarios as $inv) {
                $clave = $inv->producto_id . '|' . $inv->ubicacion_id . '|' . ($inv->lote ?? '');
                if (isset($vistos[$clave])) continue;
                $vistos[$clave] = true;

                $lineaVirtual                    = new SesionLinea();
                $lineaVirtual->sesion_id         = $sesion->id;
                $lineaVirtual->producto_id       = $inv->producto_id;
                $lineaVirtual->ubicacion_id      = $inv->ubicacion_id;
                $lineaVirtual->lote              = $inv->lote;
                $lineaVirtual->fecha_vencimiento = $inv->fecha_vencimiento;
                $lineaVirtual->cantidad_contada  = 0;
                $lineaVirtual->cantidad_sistema  = $inv->cantidad;
                $lineaVirtual->diferencia        = -(float)$inv->cantidad;
                $lineaVirtual->ronda             = $rondaFinal;
                $lineaVirtual->auxiliar_id       = $asig->auxiliar_id;
                $lineaVirtual->estado            = SesionLinea::ESTADO_ACTIVO;
                $lineaVirtual->id                = 0; // virtual, no persiste
                $sinExistencia[] = $lineaVirtual;
            }
        }

        return $sinExistencia;
    }

    // ════════════════════════════════════════════════════════════════════════
    //  ██████  ANÁLISIS ML — REFERENCIAS NO CONTADAS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/v2/inventario/sesiones/{id}/ml-analisis
     * Devuelve las referencias que el sistema muestra en las ubicaciones asignadas
     * pero que NO han sido contadas en la ronda actual.
     * Es un preview — no ejecuta ningún ajuste.
     */
    public function mlAnalisis(Request $req, Response $res, array $args): Response
    {
        $user = $req->getAttribute('user');

        $sesion = $this->_findSesion((int)$args['id'], $user, $req);

        if (!$sesion) return $this->notFound($res, 'Sesión no encontrada');

        $params     = $req->getQueryParams();
        $rondaFinal = (int)($params['ronda'] ?? $sesion->num_conteos ?? 1);

        try {
            $noContadas = $this->detectarReferenciasNoContadas($sesion, $rondaFinal);

            // Enriquecer con nombres de producto y ubicación para el frontend
            $productoIds = array_unique(array_column(
                array_map(fn($l) => ['producto_id' => $l->producto_id], $noContadas), 'producto_id'
            ));
            $ubicacionIds = array_unique(array_column(
                array_map(fn($l) => ['ubicacion_id' => $l->ubicacion_id], $noContadas), 'ubicacion_id'
            ));

            $productos  = empty($productoIds)  ? collect() :
                Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                    ->whereIn('id', $productoIds)->get(['id','nombre','codigo_interno'])->keyBy('id');
            $ubicaciones = empty($ubicacionIds) ? collect() :
                Ubicacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                    ->whereIn('id', $ubicacionIds)->get(['id','codigo'])->keyBy('id');

            $data = array_map(function($l) use ($productos, $ubicaciones) {
                return [
                    'producto_id'       => $l->producto_id,
                    'producto_nombre'   => $productos[$l->producto_id]->nombre ?? "Producto #{$l->producto_id}",
                    'codigo_interno'    => $productos[$l->producto_id]->codigo_interno ?? '—',
                    'ubicacion_id'      => $l->ubicacion_id,
                    'ubicacion_codigo'  => $ubicaciones[$l->ubicacion_id]->codigo ?? "Ubic#{$l->ubicacion_id}",
                    'lote'              => $l->lote,
                    'fecha_vencimiento' => $l->fecha_vencimiento,
                    'cantidad_sistema'  => $l->cantidad_sistema,
                    'cantidad_contada'  => 0,
                    'impacto'           => $l->cantidad_sistema <= 5 ? 'bajo' :
                                         ($l->cantidad_sistema <= 20 ? 'medio' : 'alto'),
                ];
            }, $noContadas);

            // Ordenar por impacto (alto primero) para priorizar en el frontend
            usort($data, fn($a, $b) => ['alto' => 0, 'medio' => 1, 'bajo' => 2][$a['impacto']]
                                     - ['alto' => 0, 'medio' => 1, 'bajo' => 2][$b['impacto']]);

            return $this->ok($res, [
                'total'            => count($data),
                'referencias'      => $data,
                'sesion_id'        => $sesion->id,
                'ronda_analizada'  => $rondaFinal,
                'alerta'           => count($data) > 0
                    ? count($data) . ' referencia(s) en sistema no fueron contadas. Si finalizas el inventario ahora, el sistema las eliminará automáticamente (ausencia física confirmada).'
                    : null,
            ]);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    //  ██████  CORRECCIÓN MANUAL DE INVENTARIO
    // ════════════════════════════════════════════════════════════════════════

    /**
     * POST /api/v2/inventario/correccion
     * Sub-módulo de corrección manual. Solo admin.
     * Busca producto y ubicación, aplica ajuste y lo registra en ajustes_inventario.
     */
    public function correccionManual(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $data = $req->getParsedBody() ?? [];

        // Acepta tanto 'cantidad' (incremental) como 'cantidad_nueva' (absoluta)
        // Si llega 'cantidad' + tipo_ajuste, lo convertimos a absoluta internamente
        $required = ['producto_id', 'tipo_ajuste', 'motivo'];
        foreach ($required as $f) {
            if (!isset($data[$f]) || $data[$f] === '') {
                return $this->error($res, "Campo requerido: {$f}");
            }
        }
        if (!in_array($data['tipo_ajuste'], ['Entrada', 'Salida'])) {
            return $this->error($res, "tipo_ajuste debe ser 'Entrada' o 'Salida'");
        }
        // Validar que venga alguna cantidad
        $cantidadIncremental = isset($data['cantidad'])     ? abs((float)$data['cantidad'])     : null;
        $cantidadAbsoluta    = isset($data['cantidad_nueva']) ? (float)$data['cantidad_nueva'] : null;
        if ($cantidadIncremental === null && $cantidadAbsoluta === null) {
            return $this->error($res, "Se requiere 'cantidad' o 'cantidad_nueva'");
        }
        if ($cantidadIncremental !== null && $cantidadIncremental <= 0) {
            return $this->error($res, "La cantidad debe ser mayor a 0");
        }

        try {
            $result = Capsule::transaction(function () use ($data, $user, $req, $cantidadIncremental, $cantidadAbsoluta) {
                $fv = !empty($data['fecha_vencimiento'])
                      ? Carbon::parse($data['fecha_vencimiento'])->format('Y-m-d')
                      : null;

                // Buscar registro de inventario existente
                $invQuery = Inventario::where('empresa_id',  $this->getEffectiveEmpresaId($user, $req))
                    ->where('sucursal_id',  $user->sucursal_id)
                    ->where('producto_id',  $data['producto_id']);

                if (!empty($data['ubicacion_id'])) {
                    $invQuery->where('ubicacion_id', $data['ubicacion_id']);
                }
                if (!empty($data['lote'])) {
                    $invQuery->where('lote', $data['lote']);
                }

                $inv = $invQuery->first();
                $cantidadSistema = $inv ? $inv->cantidad : 0;

                // Calcular cantidad nueva
                if ($cantidadAbsoluta !== null) {
                    // Modo absoluto: se indica el nuevo total exacto
                    $cantidadNueva = $cantidadAbsoluta;
                } else {
                    // Modo incremental: Entrada suma, Salida resta
                    $cantidadNueva = $data['tipo_ajuste'] === 'Entrada'
                        ? $cantidadSistema + $cantidadIncremental
                        : max(0, $cantidadSistema - $cantidadIncremental);
                }

                $diferencia = $cantidadNueva - $cantidadSistema;

                // Resolver ubicacion_id (requerida para el registro de inventario)
                $ubicacionId = !empty($data['ubicacion_id']) ? (int)$data['ubicacion_id'] : null;
                if (!$ubicacionId && $inv) {
                    $ubicacionId = $inv->ubicacion_id;
                }
                // Si no hay ubicación y no hay registro, buscar la primera ubicación activa
                if (!$ubicacionId) {
                    $primeraUbi = \App\Models\Ubicacion::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                        ->where('sucursal_id', $user->sucursal_id)
                        ->where('activo', true)
                        ->first();
                    $ubicacionId = $primeraUbi?->id;
                }
                if (!$ubicacionId) {
                    throw new \RuntimeException('No se encontró ubicación. Seleccione una ubicación en el formulario.');
                }

                // Actualizar inventario
                if ($inv) {
                    if ($cantidadNueva <= 0) {
                        $inv->delete();
                    } else {
                        $inv->cantidad      = $cantidadNueva;
                        $inv->ubicacion_id  = $ubicacionId;
                        if ($fv) $inv->fecha_vencimiento = $fv;
                        $inv->save();
                    }
                } elseif ($cantidadNueva > 0) {
                    Inventario::create([
                        'empresa_id'        => $this->getEffectiveEmpresaId($user, $req),
                        'sucursal_id'       => $user->sucursal_id,
                        'producto_id'       => $data['producto_id'],
                        'ubicacion_id'      => $ubicacionId,
                        'lote'              => $data['lote'] ?? null,
                        'fecha_vencimiento' => $fv,
                        'cantidad'          => $cantidadNueva,
                        'estado'            => 'Disponible',
                    ]);
                }

                // Movimiento de inventario (solo si hay diferencia real)
                $tipoMov = $diferencia >= 0
                    ? MovimientoInventario::TIPO_AJUSTE_POSITIVO
                    : MovimientoInventario::TIPO_AJUSTE_NEGATIVO;

                $cantMov = abs($diferencia);
                $mov = MovimientoInventario::create([
                    'empresa_id'           => $this->getEffectiveEmpresaId($user, $req),
                    'sucursal_id'          => $user->sucursal_id,
                    'producto_id'          => $data['producto_id'],
                    'tipo_movimiento'      => $tipoMov,
                    'cantidad'             => $cantMov ?: 1, // mínimo 1 para el registro
                    'ubicacion_origen_id'  => $ubicacionId,
                    'ubicacion_destino_id' => $ubicacionId,
                    'lote'                 => $data['lote'] ?? null,
                    'fecha_vencimiento'    => $fv,
                    'auxiliar_id'          => $user->id,
                    'referencia_tipo'      => 'correccion_manual',
                    'observaciones'        => $data['motivo'],
                    'fecha_movimiento'     => date('Y-m-d'),
                    'hora_inicio'          => date('H:i:s'),
                ]);

                // Registro inmutable en ajustes_inventario
                $ajuste = AjusteInventario::create([
                    'empresa_id'       => $this->getEffectiveEmpresaId($user, $req),
                    'sucursal_id'      => $user->sucursal_id,
                    'origen'           => AjusteInventario::ORIGEN_CORRECCION,
                    'movimiento_id'    => $mov->id,
                    'producto_id'      => $data['producto_id'],
                    'ubicacion_id'     => $ubicacionId,
                    'lote'             => $data['lote'] ?? null,
                    'fecha_vencimiento'=> $fv,
                    'cantidad_fisica'  => $cantidadNueva,
                    'cantidad_sistema' => $cantidadSistema,
                    'diferencia'       => $diferencia,
                    'tipo_ajuste'      => $data['tipo_ajuste'],
                    'motivo'           => $data['motivo'],
                    'auxiliar_id'      => null,
                    'ajustado_por'     => $user->id,
                    'fecha'            => date('Y-m-d'),
                    'hora'             => date('H:i:s'),
                ]);

                return $ajuste;
            });

            $this->audit($user, 'inventario_v2', 'correccion_manual', 'ajustes_inventario', $result->id,
                null, $data, "Corrección manual: {$data['motivo']}");

            return $this->ok($res, $result, 'Corrección de inventario aplicada');
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    //  ██████  REPORTE DE AJUSTES
    // ════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/v2/inventario/ajustes
     * Reporte de tabla de ajustes con filtros.
     * Incluye todos los tipos: Entrada y Salida (+ y -).
     */
    public function getAjustes(Request $req, Response $res): Response
    {
        try {
            $user   = $req->getAttribute('user');
            $params = $req->getQueryParams();
            [$ini, $fin] = $this->getDateRange($params);

            $q = AjusteInventario::where('ajustes_inventario.empresa_id', $this->getEffectiveEmpresaId($user, $req))
                ->where('ajustes_inventario.sucursal_id', $user->sucursal_id)
                ->join('productos',   'ajustes_inventario.producto_id',  '=', 'productos.id')
                ->join('ubicaciones', 'ajustes_inventario.ubicacion_id', '=', 'ubicaciones.id')
                ->join('personal as adj', 'ajustes_inventario.ajustado_por', '=', 'adj.id')
                ->leftJoin('personal as aux', 'ajustes_inventario.auxiliar_id', '=', 'aux.id')
                ->whereBetween('ajustes_inventario.fecha', [
                    substr($ini, 0, 10), substr($fin, 0, 10)
                ])
                ->select(
                    'ajustes_inventario.id',
                    'ajustes_inventario.fecha',
                    'ajustes_inventario.hora',
                    'productos.codigo_interno as referencia',
                    'productos.nombre as producto',
                    'ajustes_inventario.cantidad_fisica as fisico',
                    'ajustes_inventario.cantidad_sistema as sistema',
                    'ajustes_inventario.diferencia as dif',
                    'ajustes_inventario.tipo_ajuste',
                    'ajustes_inventario.fecha_vencimiento',
                    'ajustes_inventario.lote',
                    'ubicaciones.codigo as ubicacion',
                    'aux.nombre as auxiliar',
                    'adj.nombre as ajustado_por',
                    'ajustes_inventario.motivo',
                    'ajustes_inventario.origen',
                    'ajustes_inventario.sesion_id',
                );

            if (!empty($params['tipo_ajuste'])) {
                $q->where('ajustes_inventario.tipo_ajuste', $params['tipo_ajuste']);
            }
            if (!empty($params['producto_id'])) {
                $q->where('ajustes_inventario.producto_id', $params['producto_id']);
            }
            if (!empty($params['origen'])) {
                $q->where('ajustes_inventario.origen', $params['origen']);
            }
            if (!empty($params['sesion_id'])) {
                $q->where('ajustes_inventario.sesion_id', $params['sesion_id']);
            }

            $ajustes = $q->orderByDesc('ajustes_inventario.fecha')
                         ->orderByDesc('ajustes_inventario.hora')
                         ->get();

            if (($params['export'] ?? '') === 'excel') {
                $headers = ['Fecha', 'Hora', 'Referencia', 'Producto', 'Tipo', 'Físico', 'Sistema', 'Diferencia', 'F.Vencimiento', 'Ubicación', 'Auxiliar', 'Ajustado Por', 'Motivo', 'Origen'];
                $rows = $ajustes->map(fn($a) => [
                    $a->fecha, $a->hora, $a->referencia, $a->producto,
                    $a->tipo_ajuste, $a->fisico, $a->sistema, $a->dif,
                    $a->fecha_vencimiento ?? '—', $a->ubicacion,
                    $a->auxiliar ?? '—', $a->ajustado_por, $a->motivo, $a->origen,
                ])->toArray();
                return $this->exportCsv($res, $headers, $rows, 'ajustes_inventario_' . date('Y-m-d'));
            }

            return $this->ok($res, $ajustes);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    //  ██████  KARDEX ENRIQUECIDO POR REFERENCIA
    // ════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/v2/inventario/kardex
     * Kardex completo por referencia mostrando TODOS los movimientos:
     * Entrada, Picking, Traslado, AjustePositivo, AjusteNegativo, Salida, Devolucion.
     * Incluye saldo acumulado en tiempo real.
     */
    public function getKardexCompleto(Request $req, Response $res): Response
    {
        try {
            $user   = $req->getAttribute('user');
            $params = $req->getQueryParams();

            if (empty($params['producto_id'])) {
                return $this->error($res, 'Parámetro requerido: producto_id');
            }

            [$ini, $fin] = $this->getDateRange($params);
            $sucursalId  = $this->getEffectiveSucursalId($user, $req);

            $movimientos = MovimientoInventario::where('movimiento_inventarios.empresa_id', $this->getEffectiveEmpresaId($user, $req))
                ->where('movimiento_inventarios.sucursal_id', $sucursalId)
                ->where('movimiento_inventarios.producto_id', $params['producto_id'])
                ->join('productos', 'movimiento_inventarios.producto_id', '=', 'productos.id')
                ->leftJoin('personal', 'movimiento_inventarios.auxiliar_id', '=', 'personal.id')
                ->leftJoin('ubicaciones as uo', 'movimiento_inventarios.ubicacion_origen_id', '=', 'uo.id')
                ->leftJoin('ubicaciones as ud', 'movimiento_inventarios.ubicacion_destino_id', '=', 'ud.id')
                // Salida por pedido (Picking): resuelve la sucursal de entrega del pedido por
                // aproximación producto + fecha de movimiento (mismo criterio ya usado por
                // TrazabilidadController para este tipo de movimiento, que no queda enlazado
                // por referencia_tipo/referencia_id como despachos/devoluciones/recepciones).
                ->leftJoin('picking_detalles as pd_kx', function ($j) {
                    $j->on('pd_kx.producto_id', '=', 'movimiento_inventarios.producto_id');
                })
                ->leftJoin('orden_pickings as op_kx', function ($j) {
                    $j->on('op_kx.id', '=', 'pd_kx.orden_picking_id')
                      ->whereColumn('op_kx.fecha_movimiento', 'movimiento_inventarios.fecha_movimiento');
                })
                ->whereBetween('movimiento_inventarios.fecha_movimiento', [
                    substr($ini, 0, 10), substr($fin, 0, 10)
                ])
                ->select(
                    'movimiento_inventarios.id',
                    'movimiento_inventarios.fecha_movimiento as fecha',
                    'movimiento_inventarios.hora_inicio as hora',
                    'productos.nombre as producto',
                    'productos.codigo_interno as codigo',
                    'movimiento_inventarios.tipo_movimiento as tipo',
                    Capsule::raw("CASE
                        WHEN movimiento_inventarios.tipo_movimiento IN ('Entrada','AjustePositivo','Devolucion','Reabastecimiento') THEN movimiento_inventarios.cantidad
                        ELSE 0
                    END as entradas"),
                    Capsule::raw("CASE
                        WHEN movimiento_inventarios.tipo_movimiento IN ('Salida','AjusteNegativo','Picking') THEN movimiento_inventarios.cantidad
                        ELSE 0
                    END as salidas"),
                    'movimiento_inventarios.cantidad',
                    'movimiento_inventarios.cantidad_cajas',
                    'movimiento_inventarios.saldos',
                    'movimiento_inventarios.lote',
                    'movimiento_inventarios.fecha_vencimiento',
                    'uo.codigo as ubicacion_origen',
                    'ud.codigo as ubicacion_destino',
                    'personal.nombre as usuario',
                    'movimiento_inventarios.referencia_tipo',
                    'movimiento_inventarios.referencia_id',
                    'movimiento_inventarios.observaciones',
                    Capsule::raw("MAX(CASE WHEN movimiento_inventarios.tipo_movimiento = 'Picking' THEN op_kx.sucursal_entrega ELSE NULL END) as sucursal_pedido")
                )
                ->groupBy(
                    'movimiento_inventarios.id', 'movimiento_inventarios.fecha_movimiento', 'movimiento_inventarios.hora_inicio',
                    'productos.nombre', 'productos.codigo_interno', 'movimiento_inventarios.tipo_movimiento',
                    'movimiento_inventarios.cantidad', 'movimiento_inventarios.cantidad_cajas', 'movimiento_inventarios.saldos',
                    'movimiento_inventarios.lote', 'movimiento_inventarios.fecha_vencimiento',
                    'uo.codigo', 'ud.codigo', 'personal.nombre',
                    'movimiento_inventarios.referencia_tipo', 'movimiento_inventarios.referencia_id', 'movimiento_inventarios.observaciones'
                )
                ->orderBy('movimiento_inventarios.fecha_movimiento')
                ->orderBy('movimiento_inventarios.hora_inicio')
                ->orderBy('movimiento_inventarios.id')
                ->get();

            // Calcular saldo acumulado (Kardex running balance)
            $saldo = 0;
            $movimientos = $movimientos->map(function ($m) use (&$saldo) {
                $esSuma = in_array($m->tipo, ['Entrada', 'AjustePositivo', 'Devolucion', 'Reabastecimiento']);
                $esResta = in_array($m->tipo, ['Salida', 'AjusteNegativo', 'Picking']);
                $esTraslado = $m->tipo === 'Traslado';

                if ($esSuma) {
                    $saldo += $m->cantidad;
                } elseif ($esResta) {
                    $saldo -= $m->cantidad;
                }
                // Traslados no afectan saldo total (sólo cambia ubicación)

                $m->saldo = $saldo;
                $m->es_ajuste = in_array($m->tipo, ['AjustePositivo', 'AjusteNegativo']);
                return $m;
            });

            // Resumen
            $totalEntradas = $movimientos->sum('entradas');
            $totalSalidas  = $movimientos->sum('salidas');
            $saldoFinal    = $movimientos->last()?->saldo ?? 0;

            if (($params['export'] ?? '') === 'excel') {
                $headers = ['Fecha', 'Hora', 'Tipo', 'Sucursal Pedido', 'Entradas', 'Salidas', 'Cajas', 'Saldos', 'UND/TOTAL', 'Saldo Acumulado', 'Lote', 'F.Vencimiento', 'Origen', 'Destino', 'Usuario', 'Observaciones'];
                $rows = $movimientos->map(fn($m) => [
                    $m->fecha, $m->hora, $m->tipo, $m->sucursal_pedido ?? '—',
                    $m->entradas ?: '', $m->salidas ?: '',
                    $m->cantidad_cajas ?? '—', $m->saldos ?? '—', $m->cantidad,
                    $m->saldo,
                    $m->lote ?? '—', $m->fecha_vencimiento ?? '—',
                    $m->ubicacion_origen ?? '—', $m->ubicacion_destino ?? '—',
                    $m->usuario ?? '—', $m->observaciones ?? '',
                ])->toArray();
                return $this->exportCsv($res, $headers, $rows, 'kardex_' . date('Y-m-d'));
            }

            return $this->ok($res, [
                'movimientos'    => $movimientos,
                'total_entradas' => (int)$totalEntradas,
                'total_salidas'  => (int)$totalSalidas,
                'saldo_final'    => (int)$saldoFinal,
            ]);
        } catch (\Throwable $e) {
            error_log('KardexCompleto error: ' . $e->getMessage());
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    //  ██████  REPORTE DE VENCIMIENTOS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/v2/inventario/vencimientos
     * Reporte completo de vencimientos con semáforo de urgencia.
     * Parámetros: dias_alerta (default 90), export (excel)
     */
    public function getVencimientos(Request $req, Response $res): Response
    {
        try {
            $user   = $req->getAttribute('user');
            $params = $req->getQueryParams();
            $diasAlerta = (int)($params['dias_alerta'] ?? 90);

            $hoy = date('Y-m-d');
            $limite = date('Y-m-d', strtotime("+{$diasAlerta} days"));

            $driver = Capsule::connection()->getDriverName();
            if ($driver === 'pgsql') {
                $diasRestantesExpr = "(inventarios.fecha_vencimiento::date - CURRENT_DATE)";
                $int30 = "CURRENT_DATE + INTERVAL '30 days'";
                $int60 = "CURRENT_DATE + INTERVAL '60 days'";
                $int90 = "CURRENT_DATE + INTERVAL '90 days'";
            } else {
                $diasRestantesExpr = "DATEDIFF(inventarios.fecha_vencimiento, CURDATE())";
                $int30 = "DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
                $int60 = "DATE_ADD(CURDATE(), INTERVAL 60 DAY)";
                $int90 = "DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
            }

            $query = Inventario::where('inventarios.empresa_id', $this->getEffectiveEmpresaId($user, $req))
                ->where('inventarios.sucursal_id', $user->sucursal_id)
                ->whereNotNull('inventarios.fecha_vencimiento')
                ->where('inventarios.cantidad', '>', 0)
                ->join('productos',   'inventarios.producto_id',  '=', 'productos.id')
                ->join('ubicaciones', 'inventarios.ubicacion_id', '=', 'ubicaciones.id')
                ->leftJoin('marcas', 'productos.marca_id', '=', 'marcas.id')
                ->select(
                    'inventarios.id',
                    'productos.codigo_interno as referencia',
                    'productos.nombre as producto',
                    'marcas.nombre as marca',
                    'inventarios.lote',
                    'inventarios.fecha_vencimiento',
                    'inventarios.cantidad',
                    'ubicaciones.codigo as ubicacion',
                    Capsule::raw("{$diasRestantesExpr} as dias_restantes"),
                    Capsule::raw("CASE
                        WHEN inventarios.fecha_vencimiento < '{$hoy}' THEN 'VENCIDO'
                        WHEN inventarios.fecha_vencimiento <= {$int30} THEN 'CRITICO'
                        WHEN inventarios.fecha_vencimiento <= {$int60} THEN 'ALERTA'
                        WHEN inventarios.fecha_vencimiento <= {$int90} THEN 'PROXIMO'
                        ELSE 'OK'
                    END as semaforo")
                );

            if (!empty($params['solo_proximos'])) {
                $query->where('inventarios.fecha_vencimiento', '<=', $limite);
            }
            if (!empty($params['semaforo'])) {
                // Filtro por nivel de urgencia
                $nivel = $params['semaforo'];
                switch ($nivel) {
                    case 'VENCIDO':
                        $query->where('inventarios.fecha_vencimiento', '<', $hoy);
                        break;
                    case 'CRITICO':
                        $query->whereBetween('inventarios.fecha_vencimiento', [$hoy, date('Y-m-d', strtotime('+30 days'))]);
                        break;
                    case 'ALERTA':
                        $query->whereBetween('inventarios.fecha_vencimiento', [
                            date('Y-m-d', strtotime('+30 days')),
                            date('Y-m-d', strtotime('+60 days'))
                        ]);
                        break;
                }
            }
            if (!empty($params['producto_id'])) {
                $query->where('inventarios.producto_id', $params['producto_id']);
            }

            $data = $query->orderBy('inventarios.fecha_vencimiento')->get();

            // Resumen por semáforo
            $resumen = [
                'VENCIDO' => $data->where('semaforo', 'VENCIDO')->count(),
                'CRITICO' => $data->where('semaforo', 'CRITICO')->count(),
                'ALERTA'  => $data->where('semaforo', 'ALERTA')->count(),
                'PROXIMO' => $data->where('semaforo', 'PROXIMO')->count(),
                'OK'      => $data->where('semaforo', 'OK')->count(),
            ];

            if (($params['export'] ?? '') === 'excel') {
                $headers = ['Referencia', 'Producto', 'Marca', 'Lote', 'F.Vencimiento', 'Días Restantes', 'Estado', 'Cantidad', 'Ubicación'];
                $rows = $data->map(fn($r) => [
                    $r->referencia, $r->producto, $r->marca ?? '—', $r->lote ?? '—',
                    $r->fecha_vencimiento, $r->dias_restantes, $r->semaforo,
                    $r->cantidad, $r->ubicacion,
                ])->toArray();
                return $this->exportCsv($res, $headers, $rows, 'vencimientos_' . date('Y-m-d'));
            }

            return $this->ok($res, [
                'resumen' => $resumen,
                'items'   => $data,
                'total'   => $data->count(),
            ]);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    //  ██████  REPORTE DE CONTEO (IMPRESIÓN)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * GET /api/v2/inventario/sesiones/{id}/reporte
     * Genera el informe detallado del conteo en formato JSON para impresión.
     * Incluye cabecera, líneas contadas, diferencias y resumen.
     */
    public function getReporteConteo(Request $req, Response $res, array $args): Response
    {
        try {
            $user   = $req->getAttribute('user');
            $params = $req->getQueryParams();

            $sesion = $this->_findSesion((int)$args['id'], $user, $req);
            if ($sesion) {
                $sesion->load(['creadoPor:id,nombre', 'ajustadoPor:id,nombre']);
            }

            if (!$sesion) return $this->notFound($res);

            // Todas las líneas activas de todas las rondas
            $lineas = SesionLinea::where('sesion_lineas.sesion_id', $sesion->id)
                ->where('sesion_lineas.estado', SesionLinea::ESTADO_ACTIVO)
                ->join('productos',   'sesion_lineas.producto_id',  '=', 'productos.id')
                ->join('ubicaciones', 'sesion_lineas.ubicacion_id', '=', 'ubicaciones.id')
                ->join('personal',    'sesion_lineas.auxiliar_id',  '=', 'personal.id')
                ->select(
                    'sesion_lineas.ronda',
                    'sesion_lineas.hora_conteo',
                    'personal.nombre as auxiliar',
                    'productos.codigo_interno as referencia',
                    'productos.nombre as producto',
                    'ubicaciones.codigo as ubicacion',
                    'sesion_lineas.lote',
                    'sesion_lineas.fecha_vencimiento',
                    'sesion_lineas.cantidad_contada',
                    'sesion_lineas.cantidad_sistema',
                    'sesion_lineas.diferencia',
                    Capsule::raw("CASE WHEN sesion_lineas.fecha_vencimiento IS NULL THEN NULL
                                       ELSE (sesion_lineas.fecha_vencimiento::date - CURRENT_DATE)
                                  END as dias_vida_util"),
                    Capsule::raw("CASE WHEN sesion_lineas.diferencia > 0 THEN 'Sobrante'
                                       WHEN sesion_lineas.diferencia < 0 THEN 'Faltante'
                                       ELSE 'OK' END as tipo_diferencia")
                )
                ->orderBy('sesion_lineas.ronda')
                ->orderBy('sesion_lineas.hora_conteo')
                ->get();

            $ajustes = AjusteInventario::where('ajustes_inventario.empresa_id', $this->getEffectiveEmpresaId($user, $req))
                ->where('ajustes_inventario.sucursal_id', $user->sucursal_id)
                ->where('sesion_id', $sesion->id)
                ->join('productos',   'ajustes_inventario.producto_id',  '=', 'productos.id')
                ->join('ubicaciones', 'ajustes_inventario.ubicacion_id', '=', 'ubicaciones.id')
                ->join('personal',    'ajustes_inventario.ajustado_por', '=', 'personal.id')
                ->select(
                    'ajustes_inventario.fecha',
                    'ajustes_inventario.hora',
                    'productos.codigo_interno as referencia',
                    'productos.nombre as producto',
                    'ajustes_inventario.tipo_ajuste',
                    'ajustes_inventario.cantidad_fisica',
                    'ajustes_inventario.cantidad_sistema',
                    'ajustes_inventario.diferencia',
                    'ajustes_inventario.motivo',
                    'ubicaciones.codigo as ubicacion',
                    'personal.nombre as ajustado_por_nombre',
                    'ajustes_inventario.lote',
                    'ajustes_inventario.fecha_vencimiento'
                )
                ->orderBy('ajustes_inventario.fecha')
                ->orderBy('ajustes_inventario.hora')
                ->get();

            // Resumen estadístico del reporte
            $totalLineas     = $lineas->count();
            $lineasOk        = $lineas->where('diferencia', 0)->count();
            $lineasDif       = $lineas->where('diferencia', '!=', 0)->count();
            $sobrantes       = $lineas->where('diferencia', '>', 0)->count();
            $faltantes       = $lineas->where('diferencia', '<', 0)->count();
            $totalAjustes    = $ajustes->count();

            return $this->ok($res, [
                'sesion'  => [
                    'id'             => $sesion->id,
                    'nombre'         => $sesion->nombre,
                    'tipo'           => $sesion->tipo,
                    'estado'         => $sesion->estado,
                    'num_conteos'    => $sesion->num_conteos,
                    'fecha_inicio'   => $sesion->fecha_inicio,
                    'fecha_cierre'   => $sesion->fecha_cierre,
                    'creado_por'     => $sesion->creadoPor?->nombre ?? '—',
                    'ajustado_por'   => $sesion->ajustadoPor?->nombre ?? '—',
                ],
                'resumen' => [
                    'total_lineas'   => $totalLineas,
                    'lineas_ok'      => $lineasOk,
                    'lineas_dif'     => $lineasDif,
                    'sobrantes'      => $sobrantes,
                    'faltantes'      => $faltantes,
                    'total_ajustes'  => $totalAjustes,
                    'precision_pct'  => $totalLineas > 0
                        ? round(($lineasOk / $totalLineas) * 100, 2)
                        : 100,
                ],
                'lineas'  => $lineas,
                'ajustes' => $ajustes,
            ]);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * POST /v2/inventario/sesiones/{id}/conteo-manual
     * Registra una línea de conteo físico directamente desde escritorio.
     */
    public function addManualLinea(Request $req, Response $res, array $args): Response
    {
        $user = $req->getAttribute('user');
        // Quitamos restricción de supervisor para permitir el uso desde dispositivos móviles (Auxiliares)
        // if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $sesion = $this->_findSesion((int)$args['id'], $user, $req);

        if (!$sesion) return $this->notFound($res, 'Sesión no encontrada');
        if (!in_array($sesion->estado, ['EnCurso', 'PendienteAjuste'])) {
            return $this->error($res, 'Solo se pueden agregar líneas a sesiones activas.');
        }

        $data = $req->getParsedBody() ?? [];
        $required = ['ubicacion_codigo', 'cantidad', 'ronda'];
        foreach ($required as $f) {
            if (!isset($data[$f]) || (is_string($data[$f]) && trim($data[$f]) === '')) {
                return $this->error($res, "Campo requerido: {$f}");
            }
        }

        try {
            $pId = $data['producto_id'] ?? null;
            $pCod = strtoupper(trim($data['producto_codigo'] ?? ''));
            $uCod = strtoupper(trim($data['ubicacion_codigo']));

            if ($pId) {
                $prod = Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))->find($pId);
            } else {
                $prod = Producto::where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                    ->whereRaw("UPPER(codigo_interno) = ?", [$pCod])->first();
            }

            if (!$prod) return $this->error($res, "Producto no encontrado.");

            $ubic = Ubicacion::whereRaw("UPPER(codigo) = ?", [$uCod])
                ->where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                ->first();
            if (!$ubic) return $this->error($res, "Ubicación no encontrada: {$uCod}");

            $ronda = (int)$data['ronda'];
            $lote  = !empty($data['lote']) ? $data['lote'] : null;
            $fv    = !empty($data['fecha_vencimiento']) ? $data['fecha_vencimiento'] : null;
            $asignacionId = !empty($data['asignacion_id']) ? (int)$data['asignacion_id'] : null;

            // Obtener stock SNAPSHOT actual (al momento de este ingreso)
            $stockSnapshot = (int) Inventario::where('producto_id', $prod->id)
                ->where('ubicacion_id', $ubic->id)
                ->where('empresa_id', $sesion->empresa_id)
                ->where('sucursal_id', $sesion->sucursal_id)
                ->when($lote, fn($q) => $q->where('lote', $lote))
                ->sum('cantidad');

            $cantidadContada = (float)$data['cantidad'];
            // Desglose Cajas/Saldos (solo presentación — cantidad_contada sigue siendo la verdad).
            // Se persiste para poder reabrir la línea mostrando exactamente lo que se capturó,
            // en vez de recalcular una combinación distinta (floor/resto) a partir del total.
            $cantidadCajas = isset($data['cantidad_cajas']) ? (int)$data['cantidad_cajas'] : null;
            $saldos        = isset($data['saldos']) ? (float)$data['saldos'] : null;

            // Crear o actualizar la línea — auxiliar_id en criterio de búsqueda
            // para que cada auxiliar tenga su propia línea por producto+ubicación+ronda.
            $linea = SesionLinea::updateOrCreate(
                [
                    'sesion_id'    => $sesion->id,
                    'auxiliar_id'  => $user->id,
                    'producto_id'  => $prod->id,
                    'ubicacion_id' => $ubic->id,
                    'ronda'        => $ronda,
                    'lote'         => $lote,
                    'estado'       => SesionLinea::ESTADO_ACTIVO,
                ],
                [
                    'asignacion_id'     => $asignacionId,
                    'cantidad_contada'  => $cantidadContada,
                    'cantidad_cajas'    => $cantidadCajas,
                    'saldos'            => $saldos,
                    'cantidad_sistema'  => $stockSnapshot,
                    'diferencia'        => $cantidadContada - $stockSnapshot,
                    'fecha_vencimiento' => $fv,
                    'hora_conteo'       => date('Y-m-d H:i:s'),
                ]
            );

            return $this->ok($res, $linea->fresh(['producto', 'ubicacion']), 'Conteo manual registrado.');
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * GET /v2/inventario/sesiones/{id}/mis-lineas
     * Líneas contadas por el auxiliar autenticado en esta sesión.
     */
    public function getMisLineas(Request $req, Response $res, array $args): Response
    {
        $user = $req->getAttribute('user');
        $sesion = $this->_findSesion((int)$args['id'], $user, $req);
        if (!$sesion) return $this->notFound($res, 'Sesión no encontrada');

        try {
            $asignacionId = $req->getQueryParams()['asignacion_id'] ?? null;

            $lineas = SesionLinea::where('sesion_lineas.sesion_id', $sesion->id)
                ->where('sesion_lineas.auxiliar_id', $user->id)
                ->where('sesion_lineas.estado', SesionLinea::ESTADO_ACTIVO)
                ->when($asignacionId, fn($q) => $q->where('sesion_lineas.asignacion_id', (int)$asignacionId))
                ->join('productos',   'sesion_lineas.producto_id',  '=', 'productos.id')
                ->join('ubicaciones', 'sesion_lineas.ubicacion_id', '=', 'ubicaciones.id')
                ->select(
                    'sesion_lineas.id',
                    'sesion_lineas.asignacion_id',
                    'sesion_lineas.ronda',
                    'sesion_lineas.cantidad_contada as cantidad',
                    'sesion_lineas.lote',
                    'sesion_lineas.fecha_vencimiento',
                    'sesion_lineas.hora_conteo',
                    'productos.nombre as producto_nombre',
                    'productos.codigo_interno as producto_codigo',
                    'ubicaciones.codigo as ubicacion_codigo'
                )
                ->orderBy('sesion_lineas.hora_conteo', 'desc')
                ->limit(50)
                ->get();

            return $this->ok($res, $lineas);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * GET /v2/inventario/productos/{id}/fechas-vencimiento
     * Retorna las últimas 3 fechas de vencimiento distintas que ha manejado
     * un producto (en inventarios activos y en ajustes), opcionalmente filtrado
     * por ubicacion_id, para que el auxiliar pueda seleccionar una.
     */
    public function getUltimasFechasVencimiento(Request $req, Response $res, array $args): Response
    {
        $user      = $req->getAttribute('user');
        $productoId = (int)$args['id'];
        $params    = $req->getQueryParams();
        $ubicId    = !empty($params['ubicacion_id']) ? (int)$params['ubicacion_id'] : null;

        try {
            $empresaId  = $this->getEffectiveEmpresaId($user, $req);
            $sucursalId = $user->sucursal_id;

            // Consulta inventarios activos con esa referencia
            $query = Inventario::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->where('producto_id', $productoId)
                ->whereNotNull('fecha_vencimiento');

            if ($ubicId) {
                $query->where('ubicacion_id', $ubicId);
            }

            $fechas = $query
                ->orderBy('fecha_vencimiento', 'asc')
                ->pluck('fecha_vencimiento')
                ->unique()
                ->values()
                ->take(3)
                ->map(fn($f) => \Carbon\Carbon::parse($f)->format('Y-m-d'))
                ->values()
                ->toArray();

            // Si no hay en inventarios activos, buscar en ajustes históricos
            if (empty($fechas)) {
                $fechas = \Illuminate\Database\Capsule\Manager::table('ajustes_inventario')
                    ->where('empresa_id', $empresaId)
                    ->where('sucursal_id', $sucursalId)
                    ->where('producto_id', $productoId)
                    ->whereNotNull('fecha_vencimiento')
                    ->when($ubicId, fn($q) => $q->where('ubicacion_id', $ubicId))
                    ->orderBy('fecha_vencimiento', 'asc')
                    ->pluck('fecha_vencimiento')
                    ->unique()
                    ->take(3)
                    ->map(fn($f) => \Carbon\Carbon::parse($f)->format('Y-m-d'))
                    ->values()
                    ->toArray();
            }

            return $this->ok($res, $fechas);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/v2/inventario/productos/{id}/ubicaciones
     * Ubicaciones con stock > 0 de un producto. Uso móvil en inventario cíclico por referencia.
     */
    public function getProductoUbicaciones(Request $req, Response $res, array $args): Response
    {
        $user       = $req->getAttribute('user');
        $productoId = (int)$args['id'];

        try {
            $empresaId  = $this->getEffectiveEmpresaId($user, $req);
            $sucursalId = $user->sucursal_id;

            $rows = Inventario::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->where('producto_id', $productoId)
                ->where('cantidad', '>', 0)
                ->with('ubicacion:id,codigo,nombre')
                ->get();

            $ubicaciones = $rows->groupBy('ubicacion_id')->map(function ($items) {
                $u = $items->first()->ubicacion;
                return [
                    'id'                => $u?->id,
                    'codigo'            => $u?->codigo ?? '—',
                    'nombre'            => $u?->nombre ?? '',
                    'cantidad'          => round($items->sum('cantidad'), 3),
                    'fecha_vencimiento' => $items->sortBy('fecha_vencimiento')
                                                 ->first()?->fecha_vencimiento,
                ];
            })->values()->sortBy('codigo')->values();

            return $this->ok($res, $ubicaciones);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }
}
