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
 * Módulo profesional de Inventarios WMS Prooriente.
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

            $q = SesionInventario::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->with(['creadoPor:id,nombre', 'ajustadoPor:id,nombre'])
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

        if (!in_array($data['tipo'], ['Ciclico', 'General'])) {
            return $this->error($res, "Tipo debe ser 'Ciclico' o 'General'");
        }

        $numConteos = (int)($data['num_conteos'] ?? 1);
        if ($data['tipo'] === 'General' && ($numConteos < 1 || $numConteos > 3)) {
            return $this->error($res, "Para inventario General, num_conteos debe ser 1, 2 o 3");
        }
        if ($data['tipo'] === 'Ciclico') {
            $numConteos = 1;
        }

        try {
            $sesion = SesionInventario::create([
                'empresa_id'       => $user->empresa_id,
                'sucursal_id'      => $user->sucursal_id,
                'nombre'           => trim($data['nombre']),
                'descripcion'      => $data['descripcion'] ?? null,
                'tipo'             => $data['tipo'],
                'num_conteos'      => $numConteos,
                'comparar_sistema' => filter_var($data['comparar_sistema'] ?? true, FILTER_VALIDATE_BOOLEAN),
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

        $sesion = SesionInventario::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find($args['id']);

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
                $sesion->estado      = SesionInventario::ESTADO_EN_CURSO;
                $sesion->fecha_inicio = date('Y-m-d');
                $sesion->save();

                // Marcar asignaciones ronda 1 como notificadas
                $asignaciones = $sesion->asignaciones()->where('ronda', 1)->get();
                foreach ($asignaciones as $a) {
                    $a->estado        = SesionAsignacion::ESTADO_NOTIFICADO;
                    $a->notificado_at = date('Y-m-d H:i:s');
                    $a->save();

                    // Bloquear ubicaciones según la instrucción
                    $this->bloquearUbicacionesDeInstruccion($a, $user);

                    // Crear notificación en sistema
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
            $sesion = SesionInventario::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->with([
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
            $sesion = SesionInventario::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->find($args['id']);

            if (!$sesion) return $this->notFound($res, "Sesión no encontrada");

            // No permitir borrar si ya fue ajustada o cerrada
            if (in_array($sesion->estado, [SesionInventario::ESTADO_AJUSTADO, SesionInventario::ESTADO_CERRADO])) {
                return $this->error($res, "No se puede eliminar la sesión '{$sesion->nombre}' porque ya ha sido finalizada o ajustada.");
            }

            Capsule::transaction(function() use ($sesion, $user) {
                // 1. Eliminar líneas de conteo
                SesionLinea::where('sesion_id', $sesion->id)->delete();
                
                // 2. Eliminar asignaciones
                SesionAsignacion::where('sesion_id', $sesion->id)->delete();
                
                // 3. Eliminar la sesión misma
                $sesion->delete();
                
                $this->audit($user, 'inventario_v2', 'eliminar_sesion', 'sesiones_inventario', $sesion->id, null, ["nombre" => $sesion->nombre]);
            });

            return $this->ok($res, null, "Sesión '{$sesion->nombre}' eliminada correctamente");
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
            $sesion = SesionInventario::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->find($args['id']);

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
        $query = Ubicacion::where('empresa_id', $user->empresa_id)
                          ->where('sucursal_id', $user->sucursal_id);

        if ($asig->tipo_instruccion === SesionAsignacion::INSTRUCCION_PASILLO) {
            $query->where('pasillo', $asig->pasillo);
        } elseif ($asig->tipo_instruccion === SesionAsignacion::INSTRUCCION_MODULO) {
            $query->where('modulo', $asig->modulo);
        } elseif ($asig->tipo_instruccion === SesionAsignacion::INSTRUCCION_REFERENCIA) {
            $ubiIds = Inventario::where('producto_id', $asig->producto_id)
                                ->pluck('ubicacion_id');
            $query->whereIn('id', $ubiIds);
        } else {
            return; 
        }

        $query->update(['estado' => Ubicacion::ESTADO_LOCKED]);
    }

    /**
     * Libera las ubicaciones que fueron bloqueadas por una sesión.
     */
    private function liberarUbicacionesDeSesion(SesionInventario $sesion, $user)
    {
        foreach ($sesion->asignaciones as $asig) {
            $query = Ubicacion::where('empresa_id', $user->empresa_id)
                              ->where('sucursal_id', $user->sucursal_id)
                              ->where('estado', Ubicacion::ESTADO_LOCKED);

            if ($asig->tipo_instruccion === SesionAsignacion::INSTRUCCION_PASILLO) {
                $query->where('pasillo', $asig->pasillo);
            } elseif ($asig->tipo_instruccion === SesionAsignacion::INSTRUCCION_MODULO) {
                $query->where('modulo', $asig->modulo);
            } elseif ($asig->tipo_instruccion === SesionAsignacion::INSTRUCCION_REFERENCIA) {
                $ubiIds = Inventario::where('producto_id', $asig->producto_id)
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

        $sesion = SesionInventario::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find($args['id']);

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

        $asignacion = SesionAsignacion::find($args['id']);
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
                    'producto:id,nombre,codigo_interno',
                ])
                ->where(function ($q) use ($user) {
                    $q->whereHas('sesion', function ($sq) use ($user) {
                        $sq->where('empresa_id', $user->empresa_id)
                           ->where('sucursal_id', $user->sucursal_id)
                           ->where('estado', SesionInventario::ESTADO_EN_CURSO);
                    });
                })
                ->orderByDesc('created_at')
                ->get()
                ->map(function ($a) {
                    $a->descripcion_instruccion = $a->descripcion_instruccion;
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
        $asignacion  = SesionAsignacion::find($args['id']);

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
        $asignacion = SesionAsignacion::with('sesion')->find($args['id']);

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

        $cantidadContada = (int)$data['cantidad_contada'];
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
        $asignacion = SesionAsignacion::with('sesion')->find($args['id']);

        if (!$asignacion || $asignacion->auxiliar_id !== $user->id) {
            return $this->notFound($res, 'Asignación no encontrada');
        }

        $lineas = SesionLinea::where('asignacion_id', $asignacion->id)
            ->where('estado', 'Activo')->count();

        if ($lineas === 0) {
            return $this->error($res, 'Debe registrar al menos una línea antes de finalizar');
        }

        $asignacion->estado        = SesionAsignacion::ESTADO_FINALIZADO;
        $asignacion->finalizado_at = date('Y-m-d H:i:s');
        $asignacion->save();

        // Verificar si toda la sesión puede pasar a PendienteAjuste
        $this->verificarCompletitudSesion($asignacion->sesion);

        return $this->ok($res, null, 'Conteo finalizado correctamente');
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

            $sesion = SesionInventario::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->with(['creadoPor:id,nombre', 'ajustadoPor:id,nombre', 'asignaciones.auxiliar:id,nombre'])
                ->find($args['id']);

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
                    'sesion_lineas.cantidad_sistema',
                    'sesion_lineas.diferencia',
                    'sesion_lineas.fecha_vencimiento',
                    'sesion_lineas.lote',
                    'sesion_lineas.editado_por',
                    'sesion_lineas.editado_at',
                    'productos.id as producto_id',
                    'productos.nombre as producto',
                    'productos.codigo_interno as codigo',
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
                    'sistema'      => (int)$ultimoConteo->cantidad_sistema,
                    'diferencia'   => (int)($group->where('ronda', $group->max('ronda'))->sum('cantidad_contada') - $ultimoConteo->cantidad_sistema),
                    // Sub-agrupación por Ubicación y Vencimiento para el detalle
                    'detalles'     => $group->groupBy(function($gl) {
                        return $gl->ubicacion_id . '_' . ($gl->fecha_vencimiento ?? 'N/A');
                    })->map(function($subGroup) {
                        $sFirst = $subGroup->first();
                        return [
                            'ubicacion'    => $sFirst->ubicacion->codigo,
                            'f_venc'       => $sFirst->fecha_vencimiento,
                            'dias_v_u'     => $sFirst->fecha_vencimiento ? Carbon::now()->startOfDay()->diffInDays(Carbon::parse($sFirst->fecha_vencimiento), false) : null,
                            'r1'           => (int)$subGroup->where('ronda', 1)->sum('cantidad_contada'),
                            'r2'           => (int)$subGroup->where('ronda', 2)->sum('cantidad_contada'),
                            'r3'           => (int)$subGroup->where('ronda', 3)->sum('cantidad_contada'),
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
                ],
                'matriz_conteo'        => $lineas,
                'matriz_diferencias'   => $matrizDiff,
                'matriz_consolidada'   => $consolidado,
                'consistencia_rondas'  => $consistencia,
                'necesita_tercer_conteo' => $necesitaTercerConteo,
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
        if (!$linea || $linea->sesion->empresa_id !== $user->empresa_id) {
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
        $auditDataNew = ['cantidad_contada' => (int)$data['cantidad_contada']];

        // Cambio de Producto
        if (!empty($data['nuevo_producto_codigo'])) {
            $codigoProd = strtoupper(trim($data['nuevo_producto_codigo']));
            $prod = Producto::whereRaw("UPPER(codigo_interno) = ?", [$codigoProd])->first();
            if (!$prod) return $this->error($res, "Producto no encontrado: {$codigoProd}");
            $auditDataOld['producto_id'] = $linea->producto_id;
            $linea->producto_id = $prod->id;
            $auditDataNew['producto_id'] = $prod->id;
        }

        // Cambio de Ubicación
        if (!empty($data['nueva_ubicacion_codigo'])) {
            $codigoUbic = strtoupper(trim($data['nueva_ubicacion_codigo']));
            $ubic = Ubicacion::whereRaw("UPPER(codigo) = ?", [$codigoUbic])
                ->where('empresa_id', $user->empresa_id)
                ->first();
            if (!$ubic) return $this->error($res, "Ubicación no encontrada: {$codigoUbic}");
            $auditDataOld['ubicacion_id'] = $linea->ubicacion_id;
            $linea->ubicacion_id = $ubic->id;
            $auditDataNew['ubicacion_id'] = $ubic->id;
            
            // Si cambia la ubicación, debemos actualizar la cantidad_sistema del SNAPSHOT
            // Para V2 simplificado, buscaremos el stock actual de esa ubicación o mantendremos el snapshot 
            // En este sistema, cantidad_sistema se captura al momento del conteo.
            // Si re-ubicamos administrativamente, lo ideal es obtener el stock SNAPSHOT de esa ubicación.
            $stockUbic = Inventario::where('producto_id', $linea->producto_id)
                ->where('ubicacion_id', $ubic->id)
                ->sum('cantidad');
            $linea->cantidad_sistema = $stockUbic;
        }

        if (isset($data['fecha_vencimiento'])) {
            $auditDataOld['fecha_vencimiento'] = $linea->fecha_vencimiento;
            $linea->fecha_vencimiento = $data['fecha_vencimiento'];
            $auditDataNew['fecha_vencimiento'] = $data['fecha_vencimiento'];
        }

        $nueva = (int)$data['cantidad_contada'];
        if ($nueva < 0) return $this->error($res, 'La cantidad no puede ser negativa');

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
        if (!$linea || $linea->sesion->empresa_id !== $user->empresa_id) {
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

        $sesion = SesionInventario::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find($args['id']);

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

        $sesion = SesionInventario::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find($args['id']);

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

        try {
            $resultado = Capsule::transaction(function () use ($sesion, $lineas, $noContadas, $user) {
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
        bool $esCeroForzado = false   // true cuando se detecta ausencia física ML
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
                'empresa_id'        => $sesion->empresa_id,
                'sucursal_id'       => $sesion->sucursal_id,
                'producto_id'       => $linea->producto_id,
                'ubicacion_id'      => $linea->ubicacion_id,
                'lote'              => $linea->lote,
                'fecha_vencimiento' => $linea->fecha_vencimiento,
                'cantidad'          => $cantidadNueva,
                'estado'            => 'Disponible',
            ]);
        }

        // ── 5. Movimiento de inventario (Kardex) — cantidad = diferencia real ──
        $tipoMovimiento = $diferenciaReal >= 0
            ? MovimientoInventario::TIPO_AJUSTE_POSITIVO
            : MovimientoInventario::TIPO_AJUSTE_NEGATIVO;

        $motivoMov = $esCeroForzado
            ? "Ajuste ML — referencia no contada (ausencia física detectada) — Sesión #{$sesion->id}: {$sesion->nombre}"
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
            ? "ML-Ausencia física: referencia presente en sistema pero no contada — sesión: {$sesion->nombre}"
            : "Ajuste por conteo físico — sesión: {$sesion->nombre} (Ronda {$linea->ronda})";

        $ajuste = AjusteInventario::create([
            'empresa_id'        => $sesion->empresa_id,
            'sucursal_id'       => $sesion->sucursal_id,
            'origen'            => $origen,
            'sesion_id'         => $sesion->id,
            'linea_id'          => $linea->id,
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

        $linea->ajustado = true;
        $linea->save();

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
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $sesion = SesionInventario::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find($args['id']);

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
                Producto::whereIn('id', $productoIds)->get(['id','nombre','codigo_interno'])->keyBy('id');
            $ubicaciones = empty($ubicacionIds) ? collect() :
                Ubicacion::whereIn('id', $ubicacionIds)->get(['id','codigo'])->keyBy('id');

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
        $cantidadIncremental = isset($data['cantidad'])     ? abs((int)$data['cantidad'])     : null;
        $cantidadAbsoluta    = isset($data['cantidad_nueva']) ? (int)$data['cantidad_nueva'] : null;
        if ($cantidadIncremental === null && $cantidadAbsoluta === null) {
            return $this->error($res, "Se requiere 'cantidad' o 'cantidad_nueva'");
        }
        if ($cantidadIncremental !== null && $cantidadIncremental <= 0) {
            return $this->error($res, "La cantidad debe ser mayor a 0");
        }

        try {
            $result = Capsule::transaction(function () use ($data, $user, $cantidadIncremental, $cantidadAbsoluta) {
                $fv = !empty($data['fecha_vencimiento'])
                      ? Carbon::parse($data['fecha_vencimiento'])->format('Y-m-d')
                      : null;

                // Buscar registro de inventario existente
                $invQuery = Inventario::where('empresa_id',  $user->empresa_id)
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
                    $primeraUbi = \App\Models\Ubicacion::where('empresa_id', $user->empresa_id)
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
                        'empresa_id'        => $user->empresa_id,
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
                    'empresa_id'           => $user->empresa_id,
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
                    'empresa_id'       => $user->empresa_id,
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

            $q = AjusteInventario::where('ajustes_inventario.empresa_id', $user->empresa_id)
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

            $movimientos = MovimientoInventario::where('movimiento_inventarios.empresa_id', $user->empresa_id)
                ->where('movimiento_inventarios.sucursal_id', $user->sucursal_id)
                ->where('movimiento_inventarios.producto_id', $params['producto_id'])
                ->join('productos', 'movimiento_inventarios.producto_id', '=', 'productos.id')
                ->leftJoin('personal', 'movimiento_inventarios.auxiliar_id', '=', 'personal.id')
                ->leftJoin('ubicaciones as uo', 'movimiento_inventarios.ubicacion_origen_id', '=', 'uo.id')
                ->leftJoin('ubicaciones as ud', 'movimiento_inventarios.ubicacion_destino_id', '=', 'ud.id')
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
                    'movimiento_inventarios.lote',
                    'movimiento_inventarios.fecha_vencimiento',
                    'uo.codigo as ubicacion_origen',
                    'ud.codigo as ubicacion_destino',
                    'personal.nombre as usuario',
                    'movimiento_inventarios.referencia_tipo',
                    'movimiento_inventarios.referencia_id',
                    'movimiento_inventarios.observaciones',
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
                $headers = ['Fecha', 'Hora', 'Tipo', 'Entradas', 'Salidas', 'Saldo', 'Lote', 'F.Vencimiento', 'Origen', 'Destino', 'Usuario', 'Observaciones'];
                $rows = $movimientos->map(fn($m) => [
                    $m->fecha, $m->hora, $m->tipo,
                    $m->entradas ?: '', $m->salidas ?: '', $m->saldo,
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

            $query = Inventario::where('inventarios.empresa_id', $user->empresa_id)
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
                    Capsule::raw("(inventarios.fecha_vencimiento::date - CURRENT_DATE) as dias_restantes"),
                    Capsule::raw("CASE
                        WHEN inventarios.fecha_vencimiento < '{$hoy}' THEN 'VENCIDO'
                        WHEN inventarios.fecha_vencimiento <= (CURRENT_DATE + INTERVAL '30 days') THEN 'CRITICO'
                        WHEN inventarios.fecha_vencimiento <= (CURRENT_DATE + INTERVAL '60 days') THEN 'ALERTA'
                        WHEN inventarios.fecha_vencimiento <= (CURRENT_DATE + INTERVAL '90 days') THEN 'PROXIMO'
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

            $sesion = SesionInventario::where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->with(['creadoPor:id,nombre', 'ajustadoPor:id,nombre'])
                ->find($args['id']);

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

            $ajustes = AjusteInventario::where('sesion_id', $sesion->id)
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

        $sesion = SesionInventario::where('empresa_id', $user->empresa_id)
            ->where('sucursal_id', $user->sucursal_id)
            ->find($args['id']);

        if (!$sesion) return $this->notFound($res, 'Sesión no encontrada');
        if (!in_array($sesion->estado, ['EnCurso', 'PendienteAjuste'])) {
            return $this->error($res, 'Solo se pueden agregar líneas a sesiones activas.');
        }

        $data = $req->getParsedBody() ?? [];
        $required = ['ubicacion_codigo', 'cantidad', 'ronda'];
        foreach ($required as $f) {
            if (empty($data[$f])) return $this->error($res, "Campo requerido: {$f}");
        }

        try {
            $pId = $data['producto_id'] ?? null;
            $pCod = strtoupper(trim($data['producto_codigo'] ?? ''));
            $uCod = strtoupper(trim($data['ubicacion_codigo']));

            if ($pId) {
                $prod = Producto::find($pId);
            } else {
                $prod = Producto::whereRaw("UPPER(codigo_interno) = ?", [$pCod])->first();
            }

            if (!$prod) return $this->error($res, "Producto no encontrado.");

            $ubic = Ubicacion::whereRaw("UPPER(codigo) = ?", [$uCod])
                ->where('empresa_id', $user->empresa_id)
                ->first();
            if (!$ubic) return $this->error($res, "Ubicación no encontrada: {$uCod}");

            $ronda = (int)$data['ronda'];
            $lote  = !empty($data['lote']) ? $data['lote'] : null;
            $fv    = !empty($data['fecha_vencimiento']) ? $data['fecha_vencimiento'] : null;

            // Obtener stock SNAPSHOT actual (al momento de este ingreso)
            $stockSnapshot = Inventario::where('producto_id', $prod->id)
                ->where('ubicacion_id', $ubic->id)
                ->where('empresa_id', $user->empresa_id)
                ->where('sucursal_id', $user->sucursal_id)
                ->when($lote, fn($q) => $q->where('lote', $lote))
                ->sum('cantidad');

            $cantidadContada = (float)$data['cantidad'];

            // Crear o actualizar la línea
            $linea = SesionLinea::updateOrCreate(
                [
                    'sesion_id'    => $sesion->id,
                    'producto_id'  => $prod->id,
                    'ubicacion_id' => $ubic->id,
                    'ronda'         => $ronda,
                    'lote'          => $lote,
                    'estado'        => SesionLinea::ESTADO_ACTIVO
                ],
                [
                    'auxiliar_id'      => $user->id,
                    'cantidad_contada' => $cantidadContada,
                    'cantidad_sistema' => $stockSnapshot,
                    'diferencia'       => $cantidadContada - $stockSnapshot,
                    'fecha_vencimiento'=> $fv,
                    'hora_conteo'      => date('Y-m-d H:i:s'),
                ]
            );

            return $this->ok($res, $linea->fresh(['producto', 'ubicacion']), 'Conteo manual registrado.');
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }
}
