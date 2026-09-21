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
use App\Models\SesionIcgLinea;
use App\Helpers\InventoryGuard;
use App\Helpers\ExcelExporter;
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

            // Crear asignaciones iniciales si vienen en la petición
            if (!empty($data['asignaciones']) && is_array($data['asignaciones'])) {
                foreach ($data['asignaciones'] as $asigData) {
                    if (empty($asigData['auxiliar_id'])) continue;
                    $tipoInst = $asigData['tipo_instruccion'] ?? 'Libre';
                    
                    $prodIds = [];
                    if (!empty($asigData['producto_id_list']) && is_array($asigData['producto_id_list'])) {
                        $prodIds = array_filter(array_map('intval', $asigData['producto_id_list']));
                    } elseif (!empty($asigData['producto_id'])) {
                        $prodIds = [(int)$asigData['producto_id']];
                    }

                    if ($tipoInst === 'Referencia' && !empty($prodIds)) {
                        foreach ($prodIds as $pid) {
                            if (!$pid) continue;
                            SesionAsignacion::create([
                                'sesion_id'        => $sesion->id,
                                'auxiliar_id'      => (int)$asigData['auxiliar_id'],
                                'ronda'            => (int)($asigData['ronda'] ?? 1),
                                'tipo_instruccion' => 'Referencia',
                                'producto_id'      => $pid,
                                'estado'           => SesionAsignacion::ESTADO_PENDIENTE,
                            ]);
                        }
                    } else {
                        SesionAsignacion::create([
                            'sesion_id'         => $sesion->id,
                            'auxiliar_id'       => (int)$asigData['auxiliar_id'],
                            'ronda'             => (int)($asigData['ronda'] ?? 1),
                            'tipo_instruccion'  => $tipoInst,
                            'pasillo'           => $asigData['pasillo'] ?? null,
                            'modulo'            => $asigData['modulo'] ?? null,
                            'producto_id'       => !empty($asigData['producto_id']) ? (int)$asigData['producto_id'] : null,
                            'instruccion_libre' => $asigData['instruccion_libre'] ?? null,
                            'estado'            => SesionAsignacion::ESTADO_PENDIENTE,
                        ]);
                    }
                }
            }

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

            // Blindaje: aunque el ESTADO de la sesión todavía no sea Ajustado/Cerrado,
            // puede haber líneas individuales ya ajustadas vía ajustar-linea() (el botón
            // más usado en la pantalla de diferencias, uno por uno). Cada ajuste aplicado
            // queda en ajustes_inventario con FK RESTRICT hacia sesion_lineas.id — borrar
            // esas líneas violaría esa FK (y, peor, dejaría el inventario real ya movido
            // sin la línea de conteo que lo originó). Se bloquea con un mensaje claro en
            // vez de dejar que la base de datos falle con el SQLSTATE crudo.
            if (AjusteInventario::where('sesion_id', $sesion->id)->exists()) {
                return $this->error($res, "No se puede eliminar la sesión '{$sesion->nombre}': ya tiene ajustes de inventario aplicados sobre una o más líneas. Esos ajustes ya afectaron el inventario real y no se pueden borrar sin perder la trazabilidad — use 'Cerrar' para finalizarla en vez de 'Eliminar'.");
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

        $prodIds = [];
        if (!empty($data['producto_id_list']) && is_array($data['producto_id_list'])) {
            $prodIds = array_filter(array_map('intval', $data['producto_id_list']));
        } elseif (!empty($data['producto_id'])) {
            $prodIds = [(int)$data['producto_id']];
        }

        if ($tipoInstruccion === 'Referencia' && empty($prodIds)) {
            return $this->error($res, 'Se requiere al menos un producto válido para asignación por Referencia');
        }

        try {
            $creadas = [];
            $omitidosDuplicados = 0;
            if ($tipoInstruccion === 'Referencia' && !empty($prodIds)) {
                foreach ($prodIds as $pid) {
                    // BUG CORREGIDO 2026-08-19 (a pedido explícito): sin este chequeo, si el
                    // usuario hacía clic varias veces en "Guardar" (o la pantalla reabría
                    // el modal con las mismas filas antes de que el primer guardado
                    // terminara), cada clic volvía a crear una asignación idéntica —
                    // caso real confirmado: 11 referencias enviadas terminaban
                    // duplicadas (hasta 4 copias de la misma referencia en ~15 segundos,
                    // sesión #48). Ahora, si YA existe una asignación activa para esta
                    // misma referencia en esta sesión/ronda, se omite en silencio en vez
                    // de duplicar.
                    $yaExiste = SesionAsignacion::where('sesion_id', $sesion->id)
                        ->where('producto_id', $pid)
                        ->where('tipo_instruccion', 'Referencia')
                        ->where('ronda', $ronda)
                        ->first();
                    if ($yaExiste) {
                        $omitidosDuplicados++;
                        $creadas[] = $yaExiste->load('auxiliar:id,nombre');
                        continue;
                    }

                    $asignacion = SesionAsignacion::create([
                        'sesion_id'         => $sesion->id,
                        'auxiliar_id'       => $data['auxiliar_id'],
                        'ronda'             => $ronda,
                        'tipo_instruccion'  => 'Referencia',
                        'producto_id'       => $pid,
                        'instruccion_libre' => $data['instruccion_libre'] ?? null,
                        'estado'            => SesionAsignacion::ESTADO_PENDIENTE,
                    ]);

                    if (!in_array($sesion->estado, [SesionInventario::ESTADO_CERRADO, SesionInventario::ESTADO_AJUSTADO, SesionInventario::ESTADO_FINALIZADO, 'Cancelado'])) {
                        $this->crearNotificacionAuxiliar($asignacion, $sesion);
                        $asignacion->estado        = SesionAsignacion::ESTADO_NOTIFICADO;
                        $asignacion->notificado_at = date('Y-m-d H:i:s');
                        $asignacion->save();
                    }
                    $creadas[] = $asignacion->load('auxiliar:id,nombre');
                }
            } else {
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

                if (!in_array($sesion->estado, [SesionInventario::ESTADO_CERRADO, SesionInventario::ESTADO_AJUSTADO, SesionInventario::ESTADO_FINALIZADO, 'Cancelado'])) {
                    $this->crearNotificacionAuxiliar($asignacion, $sesion);
                    $asignacion->estado        = SesionAsignacion::ESTADO_NOTIFICADO;
                    $asignacion->notificado_at = date('Y-m-d H:i:s');
                    $asignacion->save();
                }
                $creadas[] = $asignacion->load('auxiliar:id,nombre');
            }

            $mensaje = 'Asignación creada correctamente';
            if ($omitidosDuplicados > 0) {
                $mensaje = count($creadas) . " referencia(s) procesadas — {$omitidosDuplicados} ya existían en esta sesión y se omitieron (no se duplicaron).";
            }

            return $this->ok($res, count($creadas) === 1 ? $creadas[0] : $creadas, $mensaje);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * PUT /api/v2/inventario/asignaciones/{id}/auxiliar
     * Cambia el auxiliar asignado a una instrucción de conteo (línea por línea).
     */
    public function cambiarAuxiliarAsignacion(Request $req, Response $res, array $args): Response
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

        if (!$asignacion) return $this->notFound($res, 'Asignación no encontrada');

        $data = $req->getParsedBody() ?? [];
        $nuevoAuxId = (int)($data['auxiliar_id'] ?? 0);
        if ($nuevoAuxId <= 0) {
            return $this->error($res, 'Campo requerido: auxiliar_id');
        }

        $nuevoAuxiliar = Personal::where('empresa_id', $empresaId)->find($nuevoAuxId);
        if (!$nuevoAuxiliar) {
            return $this->error($res, 'El auxiliar seleccionado no existe o no pertenece a la empresa');
        }

        $oldAuxId = $asignacion->auxiliar_id;
        $asignacion->auxiliar_id = $nuevoAuxId;

        $sesion = $asignacion->sesion;

        if (!in_array($sesion->estado, [SesionInventario::ESTADO_CERRADO, SesionInventario::ESTADO_AJUSTADO, SesionInventario::ESTADO_FINALIZADO, 'Cancelado'])) {
            $this->crearNotificacionAuxiliar($asignacion, $sesion);
            if ($asignacion->estado === SesionAsignacion::ESTADO_PENDIENTE) {
                $asignacion->estado = SesionAsignacion::ESTADO_NOTIFICADO;
            }
            $asignacion->notificado_at = date('Y-m-d H:i:s');
        }

        $asignacion->save();

        $this->audit($user, 'inventario', 'cambiar_auxiliar_asignacion', 'sesion_asignaciones', $asignacion->id, [
            'auxiliar_anterior' => $oldAuxId,
            'auxiliar_nuevo'    => $nuevoAuxId,
        ]);

        return $this->ok($res, $asignacion->load('auxiliar:id,nombre'), 'Auxiliar de asignación actualizado correctamente');
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
                ->whereIn('estado', ['Pendiente', 'Notificado', 'EnConteo'])
                ->with([
                    'sesion:id,nombre,tipo,empresa_id,sucursal_id',
                    'producto:id,nombre,codigo_interno,unidades_caja,factor_udm,unidad_contenido,controla_vencimiento',
                ])
                ->where(function ($q) use ($user, $req) {
                    $q->whereHas('sesion', function ($sq) use ($user, $req) {
                        $sq->where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                           ->where('sucursal_id', $user->sucursal_id)
                           ->whereIn('estado', [SesionInventario::ESTADO_EN_CURSO, 'PendienteAjuste', 'Borrador']);
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
     * POST /api/v2/inventario/asignaciones/{id}/reabrir
     * Solo Admin. Reabre una asignación de conteo (Referencia, Pasillo, Módulo o
     * Libre) que ya fue cerrada por el auxiliar, para que pueda agregar más
     * ubicaciones. Las líneas ya contadas se CONSERVAN activas (no se marcan
     * Eliminado) — al reabrir, getProductoUbicaciones() las muestra con el valor
     * que el auxiliar realmente contó (no el del sistema), y conteoReferenciaCompleto()
     * actualiza esa misma línea en vez de duplicarla si se reenvía sin cambios.
     */
    public function reabrirAsignacion(Request $req, Response $res, array $args): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

        $asignacion = SesionAsignacion::with('sesion')
            ->whereHas('sesion', function ($q) use ($user, $req) {
                $q->where('empresa_id', $this->getEffectiveEmpresaId($user, $req))
                  ->where('sucursal_id', $user->sucursal_id);
            })->find($args['id']);

        if (!$asignacion) return $this->notFound($res, 'Asignación no encontrada');

        if ($asignacion->estado !== SesionAsignacion::ESTADO_FINALIZADO) {
            return $this->error($res, 'Esta asignación no está cerrada — no hay nada que reabrir.');
        }

        $asignacion->estado        = SesionAsignacion::ESTADO_EN_CONTEO;
        $asignacion->finalizado_at = null;
        $asignacion->save();

        $this->audit($user, 'inventario', 'reabrir_asignacion_conteo', 'sesion_asignaciones', $asignacion->id,
            ['estado' => SesionAsignacion::ESTADO_FINALIZADO],
            ['estado' => SesionAsignacion::ESTADO_EN_CONTEO],
            "Asignación #{$asignacion->id} reabierta — se conserva el conteo previo, se pueden agregar más ubicaciones");

        return $this->ok($res, $asignacion, 'Asignación reabierta. El conteo anterior se conservó — el auxiliar ya puede agregar más ubicaciones.');
    }

    /**
     * POST /api/v2/inventario/asignaciones/{id}/conteo-referencia
     * Recibe los conteos por ubicación para una referencia asignada en inventario cíclico.
     * Exige que TODAS las ubicaciones registradas en sistema para esa referencia hayan sido contadas (o puestas en cero).
     * Permite agregar nuevas ubicaciones.
     * Al finalizar, ajusta automáticamente el inventario de la referencia contada sin tocar los demás productos de esas ubicaciones.
     */
    public function conteoReferenciaCompleto(Request $req, Response $res, array $args): Response
    {
        $user         = $req->getAttribute('user');
        $asigId       = (int)$args['id'];
        $empresaId    = $this->getEffectiveEmpresaId($user, $req);
        $effectiveSuc = $this->getEffectiveSucursalId($user, $req);
        $userSuc      = (int)($user->sucursal_id ?? 0);
        $sucs         = array_unique(array_filter([$effectiveSuc, $userSuc]));
        $sucursalId   = $effectiveSuc ?: $userSuc;

        $asignacion = SesionAsignacion::with('sesion')->find($asigId);

        if (!$asignacion || $asignacion->auxiliar_id !== $user->id) {
            return $this->notFound($res, 'Asignación no encontrada');
        }

        // Blindaje 2026-08-12: esta función marca la asignación como Finalizado al
        // terminar (más abajo), pero nunca validaba ESE estado al entrar — permitía
        // reenviar el conteo indefinidamente después de cerrado, generando líneas
        // duplicadas. Una vez cerrada, solo un Admin puede reabrirla (ver reabrirAsignacion()).
        if ($asignacion->estado === SesionAsignacion::ESTADO_FINALIZADO) {
            return $this->error($res, 'Esta referencia ya fue cerrada. Un administrador debe reabrirla para poder agregar más ubicaciones al conteo.', 409);
        }

        $productoId = (int)$asignacion->producto_id;
        if (!$productoId) {
            return $this->error($res, 'La asignación no tiene una referencia o producto asociado');
        }

        $data = $req->getParsedBody() ?? [];
        $ubicacionesContadas = $data['conteo_ubicaciones'] ?? [];

        if (!is_array($ubicacionesContadas)) {
            return $this->error($res, 'Se requiere la lista de conteo por ubicaciones');
        }

        // 1. Obtener ubicaciones existentes registradas en sistema para este producto
        $rowsStockQuery = Inventario::where('empresa_id', $empresaId)
            ->where('producto_id', $productoId)
            ->where(function($q) {
                $q->where('cantidad', '>', 0)
                  ->orWhere('cantidad_cajas', '>', 0)
                  ->orWhere('saldos', '>', 0);
            });

        if (!empty($sucs)) {
            $rowsStockQuery->whereIn('sucursal_id', $sucs);
        }

        $rowsStock = $rowsStockQuery->with('ubicacion:id,codigo')->get();

        // Map de conteo recibido: ubicacion_id -> item
        $mapContadosById = [];
        $mapContadosByCode = [];
        foreach ($ubicacionesContadas as $item) {
            $uId = isset($item['ubicacion_id']) ? (int)$item['ubicacion_id'] : null;
            $code = !empty($item['codigo']) ? strtoupper(trim($item['codigo'])) : null;
            if ($uId) $mapContadosById[$uId] = $item;
            if ($code) $mapContadosByCode[$code] = $item;
        }

        // 2. REGLA OBLIGATORIA: Verificar que CADA ubicación registrada en sistema fue contada (o puesta en cero)
        $faltantes = [];
        foreach ($rowsStock as $inv) {
            $uId = $inv->ubicacion_id;
            $uCode = strtoupper(trim($inv->ubicacion->codigo ?? ''));
            if (!isset($mapContadosById[$uId]) && (!isset($mapContadosByCode[$uCode]))) {
                $faltantes[] = $uCode ?: "ID #{$uId}";
            }
        }

        if (!empty($faltantes)) {
            $listaFaltantes = implode(', ', array_unique($faltantes));
            return $this->error($res, "Debe realizar el conteo de todas las ubicaciones registradas en sistema (o colocar cero 0). Faltan por contar: {$listaFaltantes}");
        }

        // 3. Ejecutar guardado de líneas y ajuste de inventario en una transacción
        try {
            Capsule::transaction(function () use ($asignacion, $productoId, $ubicacionesContadas, $user, $empresaId, $sucursalId) {
                $prod = Producto::find($productoId);
                // Blindaje 2026-08-12: mismo bug de getProductoUbicaciones (ignoraba
                // factor_udm) — aquí es más grave porque esto es lo que se GUARDA como
                // cantidad_contada y alimenta la diferencia contra sistema.
                $upc = (float)($prod->factor_udm ?? 0) > 0
                    ? (float)$prod->factor_udm
                    : max(1, (int)($prod->unidades_caja ?? 1));
                $sesionId = $asignacion->sesion_id;

                foreach ($ubicacionesContadas as $item) {
                    $ubicId = !empty($item['ubicacion_id']) ? (int)$item['ubicacion_id'] : null;
                    $code   = !empty($item['codigo']) ? strtoupper(trim($item['codigo'])) : null;

                    if (!$ubicId && $code) {
                        $uObj = Ubicacion::where('empresa_id', $empresaId)
                            ->where('sucursal_id', $sucursalId)
                            ->whereRaw('UPPER(codigo) = ?', [$code])
                            ->first();
                        if ($uObj) {
                            $ubicId = $uObj->id;
                        } else {
                            $uObj = Ubicacion::create([
                                'empresa_id'     => $empresaId,
                                'sucursal_id'    => $sucursalId,
                                'codigo'         => $code,
                                'nombre'         => "Ubicación {$code}",
                                'zona'           => 'General',
                                'pasillo'        => 'GENERAL',
                                'modulo'         => '01',
                                'nivel'          => '01',
                                'tipo'           => 'Normal',
                                'tipo_ubicacion' => 'Almacenamiento',
                                'estado'         => 'Libre',
                            ]);
                            $ubicId = $uObj->id;
                        }
                    }

                    if (!$ubicId) continue;

                    $cajas  = max(0, (float)($item['cajas'] ?? 0));
                    $saldos = max(0, (float)($item['saldos'] ?? 0));
                    $esCero = !empty($item['es_cero']);
                    $lote   = !empty($item['lote']) ? trim($item['lote']) : 'N/A';
                    $fv     = !empty($item['fecha_vencimiento']) ? $item['fecha_vencimiento'] : null;

                    // Buscar registro de inventario existente para obtener la fecha de vencimiento previa si no se envió una nueva
                    $invQuery = Inventario::where('empresa_id', $empresaId)
                        ->where('sucursal_id', $sucursalId)
                        ->where('producto_id', $productoId)
                        ->where('ubicacion_id', $ubicId);

                    if ($lote && $lote !== 'N/A') {
                        $invQuery->where('lote', $lote);
                    }

                    $invRow = $invQuery->first();
                    if (empty($fv) && $invRow && !empty($invRow->fecha_vencimiento)) {
                        $fv = $invRow->fecha_vencimiento;
                    }

                    $cantContada = $esCero ? 0 : round(($cajas * $upc) + $saldos, 3);
                    $stockActual = (float)($invRow ? $invRow->cantidad : 0);
                    $diff        = $cantContada - $stockActual;

                    // Blindaje 2026-08-12: antes SIEMPRE creaba una línea nueva — al
                    // reabrir una asignación y reenviar (incluyendo ubicaciones ya
                    // contadas antes de cerrar), esto duplicaba esas líneas en vez de
                    // conservar/actualizar el conteo ya guardado. Ahora actualiza la
                    // línea activa existente para esta asignación+ubicación si ya
                    // existe, y solo crea una nueva si es una ubicación agregada ahora.
                    $lineaExistente = SesionLinea::where('asignacion_id', $asignacion->id)
                        ->where('ubicacion_id', $ubicId)
                        ->where('estado', SesionLinea::ESTADO_ACTIVO)
                        ->first();

                    $datosLinea = [
                        'lote'             => $lote,
                        'fecha_vencimiento'=> $fv,
                        'cantidad_cajas'   => (int)$cajas,
                        'saldos'           => (float)$saldos,
                        'cantidad_contada' => $cantContada,
                        'cantidad_sistema' => $stockActual,
                        'diferencia'       => $diff,
                        'hora_conteo'      => date('Y-m-d H:i:s'),
                    ];

                    if ($lineaExistente) {
                        $lineaExistente->fill($datosLinea);
                        $lineaExistente->save();
                    } else {
                        SesionLinea::create(array_merge($datosLinea, [
                            'sesion_id'        => $sesionId,
                            'asignacion_id'    => $asignacion->id,
                            'auxiliar_id'      => $user->id,
                            'ronda'            => $asignacion->ronda ?: 1,
                            'producto_id'      => $productoId,
                            'ubicacion_id'     => $ubicId,
                            'estado'           => SesionLinea::ESTADO_ACTIVO,
                            'ajustado'         => false,
                        ]));
                    }
                }

                // Marcar la asignación como finalizada en el móvil (conteo enviado a revisión)
                $asignacion->estado        = SesionAsignacion::ESTADO_FINALIZADO;
                $asignacion->finalizado_at = date('Y-m-d H:i:s');
                $asignacion->save();

                $this->verificarCompletitudSesion($asignacion->sesion, $asignacion->ronda ?: 1);
            });

            return $this->ok($res, null, 'Conteo de referencia registrado correctamente. Pendiente por aprobar ajuste.');
        } catch (\Throwable $e) {
            return $this->error($res, 'Error al procesar conteo de referencia: ' . $e->getMessage(), 500);
        }
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
                // Blindaje 2026-08-12: la pestaña "Asig." no mostraba QUÉ referencia
                // tenía asignada cada auxiliar (columna "Instrucción" solo decía
                // "Referencia" sin producto) porque nunca se cargaba la relación.
                $sesion->load([
                    'creadoPor:id,nombre', 'ajustadoPor:id,nombre',
                    'asignaciones.auxiliar:id,nombre',
                    'asignaciones.producto:id,nombre,codigo_interno',
                ]);
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
                    // Blindaje 2026-08-12: mostraba productos.unidades_caja crudo — para
                    // productos donde el factor real es factor_udm (ej. "X 500 GR" con
                    // unidades_caja=1), la columna U/E de la Matriz mostraba "1" en vez
                    // del factor real, y por lo tanto UND/TOTAL (cantidad_contada, ya
                    // guardada correctamente) no coincidía visualmente con Cajas × U/E + Saldos.
                    Capsule::raw('CASE WHEN productos.factor_udm > 0 THEN productos.factor_udm ELSE productos.unidades_caja END as unidades_caja'),
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
                    'Cajas', 'U/E', 'Saldos', 'UND/TOTAL', 'Sistema', 'Diferencia', 'Ajustado',
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
                    $l->unidades_caja,
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
                
                $ultimoConteo   = $group->sortByDesc('created_at')->first();
                $conteoMax      = (float)$group->where('ronda', $group->max('ronda'))->sum('cantidad_contada');
                $todosAjustados = $group->every(fn($l) => !empty($l->ajustado));

                $stockSistema = $todosAjustados ? $conteoMax : (float)($ultimoConteo->cantidad_sistema ?? 0);
                $difSistema   = $todosAjustados ? 0.0 : round($conteoMax - (float)($ultimoConteo->cantidad_sistema ?? 0), 3);

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
                    'sistema'      => $stockSistema,
                    'diferencia'   => $difSistema,
                    // Sub-agrupación por Ubicación y Vencimiento para el detalle
                    'detalles'     => $group->groupBy(function($gl) {
                        return $gl->ubicacion_id . '_' . ($gl->fecha_vencimiento ?? 'N/A');
                    })->map(function($subGroup) {
                        $sFirst       = $subGroup->first();
                        $subContado   = (float)$subGroup->where('ronda', $subGroup->max('ronda'))->sum('cantidad_contada');
                        $subAjustados = $subGroup->every(fn($l) => !empty($l->ajustado));
                        $subSistema   = $subAjustados ? $subContado : (float)($subGroup->first()->cantidad_sistema ?? 0);
                        $subDif       = $subAjustados ? 0.0 : round($subContado - $subSistema, 3);

                        return [
                            'ubicacion'    => $sFirst->ubicacion->codigo,
                            'f_venc'       => $sFirst->fecha_vencimiento,
                            'dias_v_u'     => $sFirst->fecha_vencimiento ? Carbon::now()->startOfDay()->diffInDays(Carbon::parse($sFirst->fecha_vencimiento), false) : null,
                            'r1'           => (float)$subGroup->where('ronda', 1)->sum('cantidad_contada'),
                            'r2'           => (float)$subGroup->where('ronda', 2)->sum('cantidad_contada'),
                            'r3'           => (float)$subGroup->where('ronda', 3)->sum('cantidad_contada'),
                            'sistema'      => $subSistema,
                            'diferencia'   => $subDif,
                            'auxiliares'   => $subGroup->pluck('auxiliar.nombre')->unique()->values()->all(),
                            'ultimo_c'     => $subGroup->max('hora_conteo')
                        ];
                    })->values()
                ];
            })->values();

            // ── Analítica por Ambientes con Desglose de Referencias ────────
            $ambientesRaw = Capsule::table('sesion_lineas as sl')
                ->join('productos as p', 'sl.producto_id', '=', 'p.id')
                ->leftJoin('ambientes as a', 'p.ambiente_id', '=', 'a.id')
                ->where('sl.sesion_id', $sesion->id)
                ->where('sl.estado', SesionLinea::ESTADO_ACTIVO)
                ->when($rondaFiltro > 0, fn($q) => $q->where('sl.ronda', $rondaFiltro))
                ->select(
                    Capsule::raw("COALESCE(a.codigo, a.descripcion, UPPER(p.temperatura_almacen), 'SECO') as ambiente"),
                    'p.id as producto_id',
                    'p.codigo_interno as codigo',
                    'p.nombre as producto',
                    'p.unidades_caja',
                    'sl.ubicacion_id',
                    'sl.cantidad_contada',
                    'sl.fecha_vencimiento',
                    'sl.lote'
                )
                ->get();

            $ambientesData = $ambientesRaw->groupBy('ambiente')->map(function ($group, $ambKey) {
                $refGroup = $group->groupBy('producto_id');
                $detallesRef = $refGroup->map(function ($gRef) {
                    $first = $gRef->first();
                    $fVenc = $gRef->pluck('fecha_vencimiento')->filter()->sort()->first();
                    return [
                        'producto_id'          => $first->producto_id,
                        'codigo'               => $first->codigo,
                        'producto'             => $first->producto,
                        'unidades_caja'        => $first->unidades_caja ?? 1,
                        'total_unidades'       => round((float)$gRef->sum('cantidad_contada'), 3),
                        'ubicaciones_contadas' => $gRef->pluck('ubicacion_id')->unique()->count(),
                        'lotes'                => $gRef->pluck('lote')->filter()->unique()->values()->all(),
                        'proximo_vencimiento'  => $fVenc,
                        'dias_v_u'             => $fVenc ? Carbon::now()->startOfDay()->diffInDays(Carbon::parse($fVenc), false) : null
                    ];
                })->sortByDesc('total_unidades')->values();

                $allFVenc = $group->pluck('fecha_vencimiento')->filter()->sort()->first();
                $diasVuAvg = $allFVenc ? Carbon::now()->startOfDay()->diffInDays(Carbon::parse($allFVenc), false) : null;

                return [
                    'ambiente'             => $ambKey,
                    'total_referencias'    => $refGroup->count(),
                    'total_unidades'       => round((float)$group->sum('cantidad_contada'), 3),
                    'ubicaciones_contadas' => $group->pluck('ubicacion_id')->unique()->count(),
                    'proximo_vencimiento'  => $allFVenc,
                    'promedio_dias_vu'     => $diasVuAvg,
                    'referencias'          => $detallesRef
                ];
            })->values();

            // ── Analítica por Auxiliares con Desglose de Referencias ───────
            $auxiliaresRaw = Capsule::table('sesion_lineas as sl')
                ->join('personal as pers', 'sl.auxiliar_id', '=', 'pers.id')
                ->join('productos as p', 'sl.producto_id', '=', 'p.id')
                ->join('ubicaciones as u', 'sl.ubicacion_id', '=', 'u.id')
                ->where('sl.sesion_id', $sesion->id)
                ->where('sl.estado', SesionLinea::ESTADO_ACTIVO)
                ->when($rondaFiltro > 0, fn($q) => $q->where('sl.ronda', $rondaFiltro))
                ->select(
                    'pers.id as auxiliar_id',
                    'pers.nombre as auxiliar',
                    'sl.id as linea_id',
                    'p.id as producto_id',
                    'p.codigo_interno as codigo',
                    'p.nombre as producto',
                    'u.codigo as ubicacion',
                    'sl.cantidad_contada',
                    'sl.hora_conteo'
                )
                ->get();

            $auxiliaresData = $auxiliaresRaw->groupBy('auxiliar_id')->map(function ($group) {
                $first = $group->first();
                $refGroup = $group->groupBy('producto_id');

                $detallesRef = $refGroup->map(function ($gRef) {
                    $rFirst = $gRef->first();
                    return [
                        'producto_id'      => $rFirst->producto_id,
                        'codigo'           => $rFirst->codigo,
                        'producto'         => $rFirst->producto,
                        'total_registros'  => $gRef->count(),
                        'total_unidades'   => round((float)$gRef->sum('cantidad_contada'), 3),
                        'ubicaciones'      => $gRef->pluck('ubicacion')->unique()->values()->all(),
                        'ultima_actividad' => $gRef->max('hora_conteo')
                    ];
                })->sortByDesc('total_unidades')->values();

                return [
                    'auxiliar_id'          => $first->auxiliar_id,
                    'auxiliar'             => $first->auxiliar,
                    'total_registros'      => $group->count(),
                    'referencias_contadas' => $refGroup->count(),
                    'total_unidades'       => round((float)$group->sum('cantidad_contada'), 3),
                    'ultima_actividad'     => $group->max('hora_conteo'),
                    'referencias'          => $detallesRef
                ];
            })->sortByDesc('total_unidades')->values();

            // ── Analítica & Comparativo ICG ─────────────────────────────────
            $icgLineas = SesionIcgLinea::where('sesion_id', $sesion->id)
                ->with(['producto.ambiente'])
                ->get();

            $icgAnalysis = [
                'cargado'                       => false,
                'total_referencias_icg'         => 0,
                'total_unidades_icg'            => 0,
                'referencias_contadas_icg'      => 0,
                'exactitud_ira_icg'             => 100,
                'referencias_sobrantes_icg'     => 0,
                'referencias_faltantes_icg'     => 0,
                'referencias_coincidentes_icg'  => 0,
                'sobrantes_unidades_icg'        => 0,
                'faltantes_unidades_icg'        => 0,
                'pct_avance_icg'                => 0,
                'avance_por_ambiente'           => [],
                'lineas_icg_comparativo'        => [],
                'discrepancias'                 => [
                    'icg_no_contados' => [],
                    'conteo_no_en_icg' => []
                ]
            ];

            if ($icgLineas->count() > 0) {
                $icgAnalysis['cargado'] = true;
                $icgAnalysis['total_referencias_icg'] = $icgLineas->count();
                $icgAnalysis['total_unidades_icg'] = (float)$icgLineas->sum('cantidad_icg');

                // Mapear conteo WMS por producto_id o código
                $conteoPorProd = $todasLasLineas->where('ronda', $rondaFiltro > 0 ? $rondaFiltro : 1)->groupBy('producto_id')->map(fn($g) => $g->sum('cantidad_contada'));
                $conteoPorCod  = $todasLasLineas->where('ronda', $rondaFiltro > 0 ? $rondaFiltro : 1)->groupBy(fn($l) => strtoupper(trim($l->producto->codigo_interno ?? '')))->map(fn($g) => $g->sum('cantidad_contada'));

                $icgMapByProd = $icgLineas->groupBy('producto_id')->map(fn($g) => $g->sum('cantidad_icg'));

                $lineasComp = [];
                $refContadasCount = 0;
                $lineasConDif = 0;
                $sobrantesUds = 0;
                $faltantesUds = 0;
                $sobrantesRef = 0;
                $faltantesRef = 0;
                $coincidentesRef = 0;

                $icgCodigosSet = [];
                $icgAmbientes = [];

                foreach ($icgLineas as $icg) {
                    $cod = strtoupper(trim($icg->codigo_referencia));
                    $icgCodigosSet[$cod] = true;
                    if ($icg->producto && $icg->producto->codigo_interno) {
                        $icgCodigosSet[strtoupper(trim($icg->producto->codigo_interno))] = true;
                    }

                    $cantIcg  = (float)$icg->cantidad_icg;
                    $cantWms  = $icg->producto_id ? ($conteoPorProd[$icg->producto_id] ?? 0) : ($conteoPorCod[$cod] ?? 0);
                    $diff     = $cantWms - $cantIcg;

                    $amb = $icg->producto ? ($icg->producto->ambiente->codigo ?? $icg->producto->ambiente->descripcion ?? strtoupper($icg->producto->temperatura_almacen ?? 'SECO')) : 'SECO';
                    if (!isset($icgAmbientes[$amb])) {
                        $icgAmbientes[$amb] = ['tot_ref' => 0, 'cont_ref' => 0, 'tot_icg' => 0, 'tot_wms' => 0];
                    }
                    $icgAmbientes[$amb]['tot_ref']++;
                    $icgAmbientes[$amb]['tot_icg'] += $cantIcg;
                    $icgAmbientes[$amb]['tot_wms'] += $cantWms;

                    if ($cantWms > 0) {
                        $refContadasCount++;
                        $icgAmbientes[$amb]['cont_ref']++;
                    }

                    $estadoStr = 'Coincidente';
                    if ($cantWms > $cantIcg) {
                        $estadoStr = 'Sobrante';
                        $lineasConDif++;
                        $sobrantesRef++;
                        $sobrantesUds += ($cantWms - $cantIcg);
                    } elseif ($cantWms < $cantIcg) {
                        $estadoStr = 'Faltante';
                        $lineasConDif++;
                        $faltantesRef++;
                        $faltantesUds += ($cantIcg - $cantWms);
                    } else {
                        $coincidentesRef++;
                    }

                    $lineasComp[] = [
                        'producto_id'      => $icg->producto_id,
                        'codigo'           => $icg->codigo_referencia,
                        'producto'         => $icg->nombre_referencia,
                        'unidades_caja'    => $icg->producto->unidades_caja ?? 1,
                        'ambiente'         => $amb,
                        'cantidad_icg'     => $cantIcg,
                        'cantidad_contada' => $cantWms,
                        'diferencia_icg'   => $diff,
                        'estado'           => $estadoStr
                    ];
                }

                // Avance por ambiente
                $avanceAmbientes = [];
                foreach ($icgAmbientes as $ambName => $ambStat) {
                    $avanceAmbientes[] = [
                        'ambiente'       => $ambName,
                        'referencias'    => $ambStat['tot_ref'],
                        'contadas'       => $ambStat['cont_ref'],
                        'unidades_icg'   => $ambStat['tot_icg'],
                        'unidades_wms'   => $ambStat['tot_wms'],
                        'pct_avance'     => $ambStat['tot_ref'] > 0 ? round(($ambStat['cont_ref'] / $ambStat['tot_ref']) * 100, 1) : 0
                    ];
                }

                // Discrepancia 1: En ICG no contados en WMS
                $icgNoContados = array_filter($lineasComp, fn($item) => $item['cantidad_contada'] == 0);

                // Discrepancia 2: Contados en WMS no en ICG
                $wmsNoEnIcg = [];
                foreach ($todasLasLineas->groupBy('producto_id') as $pId => $gLineas) {
                    $pFirst = $gLineas->first();
                    $pCod = strtoupper(trim($pFirst->producto->codigo_interno ?? ''));
                    if (!isset($icgCodigosSet[$pCod])) {
                        $ambW = $pFirst->producto ? ($pFirst->producto->ambiente->codigo ?? $pFirst->producto->ambiente->descripcion ?? strtoupper($pFirst->producto->temperatura_almacen ?? 'SECO')) : 'SECO';
                        $wmsNoEnIcg[] = [
                            'producto_id'      => $pId,
                            'codigo'           => $pFirst->producto->codigo_interno ?? '-',
                            'producto'         => $pFirst->producto->nombre ?? '-',
                            'unidades_caja'    => $pFirst->producto->unidades_caja ?? 1,
                            'ambiente'         => $ambW,
                            'cantidad_contada' => $gLineas->sum('cantidad_contada'),
                            'auxiliares'       => $gLineas->pluck('auxiliar.nombre')->filter()->unique()->values()->all()
                        ];
                    }
                }

                $icgAnalysis['referencias_contadas_icg'] = $refContadasCount;
                $icgAnalysis['referencias_sobrantes_icg'] = $sobrantesRef;
                $icgAnalysis['referencias_faltantes_icg'] = $faltantesRef;
                $icgAnalysis['referencias_coincidentes_icg'] = $coincidentesRef;
                $icgAnalysis['sobrantes_unidades_icg'] = (float)$sobrantesUds;
                $icgAnalysis['faltantes_unidades_icg'] = (float)$faltantesUds;
                $icgAnalysis['exactitud_ira_icg'] = $icgLineas->count() > 0 ? round((100 - ($lineasConDif / $icgLineas->count() * 100)), 1) : 100;
                $icgAnalysis['pct_avance_icg'] = $icgLineas->count() > 0 ? round(($refContadasCount / $icgLineas->count() * 100), 1) : 0;
                $icgAnalysis['avance_por_ambiente'] = $avanceAmbientes;
                $icgAnalysis['lineas_icg_comparativo'] = $lineasComp;
                $icgAnalysis['discrepancias']['icg_no_contados'] = array_values($icgNoContados);
                $icgAnalysis['discrepancias']['conteo_no_en_icg'] = array_values($wmsNoEnIcg);

                // Enriquecer consolidado con columna ICG
                foreach ($consolidado as &$item) {
                    $item['cantidad_icg'] = (float)($icgMapByProd[$item['producto_id']] ?? 0);
                }
            }

            // ── Programación de Segundos Conteos (Ronda 2) ────────────────
            $segundosConteosData = SesionAsignacion::where('sesion_id', $sesion->id)
                ->where('ronda', 2)
                ->with(['auxiliar:id,nombre', 'producto:id,codigo_interno,nombre'])
                ->get()
                ->map(function ($asig) use ($todasLasLineas) {
                    $r1 = $todasLasLineas->where('producto_id', $asig->producto_id)->where('ronda', 1)->sum('cantidad_contada');
                    $r2 = $todasLasLineas->where('producto_id', $asig->producto_id)->where('ronda', 2)->sum('cantidad_contada');
                    return [
                        'id'               => $asig->id,
                        'auxiliar_id'      => $asig->auxiliar_id,
                        'auxiliar'         => $asig->auxiliar->nombre ?? 'Sin Asignar',
                        'producto_id'      => $asig->producto_id,
                        'codigo'           => $asig->producto->codigo_interno ?? '-',
                        'producto'         => $asig->producto->nombre ?? '-',
                        'etiqueta'         => $asig->instruccion_libre ?: 'Segundo Conteo',
                        'estado'           => $asig->estado,
                        'r1'               => round((float)$r1, 3),
                        'r2'               => round((float)$r2, 3),
                        'created_at'       => $asig->created_at ? $asig->created_at->format('Y-m-d H:i') : null,
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
                'analisis_ambientes'   => $ambientesData,
                'analisis_auxiliares'  => $auxiliaresData,
                'analisis_icg'         => $icgAnalysis,
                'segundos_conteos'     => $segundosConteosData,
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

        if (array_key_exists('fecha_vencimiento', $data)) {
            $fvVal = (!empty($data['fecha_vencimiento']) && trim((string)$data['fecha_vencimiento']) !== '') ? trim($data['fecha_vencimiento']) : null;
            $auditDataOld['fecha_vencimiento'] = $linea->fecha_vencimiento;
            $linea->fecha_vencimiento = $fvVal;
            $auditDataNew['fecha_vencimiento'] = $fvVal;
        }

        if (array_key_exists('lote', $data)) {
            $loteVal = (!empty($data['lote']) && trim((string)$data['lote']) !== '') ? trim($data['lote']) : null;
            $auditDataOld['lote'] = $linea->lote;
            $linea->lote = $loteVal;
            $auditDataNew['lote'] = $loteVal;
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
        // A pedido explícito (2026-08-20): el ajuste de ciclico sobrescribe el
        // inventario real — se restringe solo al Administrador.
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

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
            // Envuelto en transacción: si la limpieza de otras ubicaciones (abajo)
            // fallara a mitad de camino, no debe quedar el ajuste principal aplicado
            // sin la reconciliación, ni viceversa.
            [$ajuste, $ajustesCero] = Capsule::transaction(function () use ($sesion, $linea, $user) {
                $ajuste = $this->ejecutarAjuste($sesion, $linea, $user, AjusteInventario::ORIGEN_CONTEO_LINEA);

                // BUG CORREGIDO 2026-08-20 (a pedido explícito): esta reconciliación
                // ("dejar en 0 el stock de esta MISMA referencia en cualquier otra
                // ubicación no confirmada dentro de esta asignación") antes SOLO corría
                // dentro de ajustarTodo() — si el administrador ajustaba línea por línea
                // (botón "Ajustar" individual, el más usado en la pantalla de
                // diferencias) esa limpieza nunca se ejecutaba, dejando stock duplicado/
                // huérfano en ubicaciones que el auxiliar nunca contó. Ahora se aplica
                // también aquí, acotada SOLO a la asignación de esta línea (no a toda la
                // sesión) — solo aplica cuando la línea viene de una asignación tipo
                // "Referencia" (verificar una referencia puntual, sin importar ubicación).
                $ajustesCero = [];
                if ($linea->asignacion_id) {
                    $asignacion = SesionAsignacion::find($linea->asignacion_id);
                    if ($asignacion && $asignacion->tipo_instruccion === SesionAsignacion::INSTRUCCION_REFERENCIA) {
                        $sinExistencia = $this->detectarReferenciasSinExistencia($sesion, $linea->ronda, [], $asignacion->id);
                        foreach ($sinExistencia as $lineaVirtual) {
                            $ajustesCero[] = $this->ejecutarAjuste(
                                $sesion, $lineaVirtual, $user, AjusteInventario::ORIGEN_CONTEO_LINEA,
                                true, 'Referencia confirmada sin existencia física en otra ubicación'
                            );
                        }
                    }
                }

                return [$ajuste, $ajustesCero];
            });

            // Regla de Oro #3 (modo alerta, no bloquea) — mismo patrón que en PickingController.
            $productosMovidos = [$ajuste->producto_id => true];
            foreach ($ajustesCero as $az) { $productosMovidos[$az->producto_id] = true; }
            $guardInv = new InventoryGuard($sesion->empresa_id, $sesion->sucursal_id, $user->id);
            foreach (array_keys($productosMovidos) as $productoId) {
                $guardInv->assertLedgerMatchesStock((int)$productoId);
            }

            // Obtener stock actualizado tras el ajuste (para mostrar en frontend)
            $stockActual = Inventario::where('empresa_id',  $sesion->empresa_id)
                ->where('sucursal_id',  $sesion->sucursal_id)
                ->where('producto_id',  $linea->producto_id)
                ->where('ubicacion_id', $linea->ubicacion_id)
                ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote))
                ->value('cantidad') ?? 0;

            return $this->ok($res, [
                'ajuste'         => $ajuste,
                'ajustes_cero_otras_ubicaciones' => count($ajustesCero),
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
        // A pedido explícito (2026-08-20): ajusta TODO el inventario con diferencia
        // de golpe — se restringe solo al Administrador.
        if ($deny = $this->requireAdmin($user, $res)) return $deny;

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

        // Determinar las líneas definitivas a ajustar:
        // Para cada (producto_id, ubicación_id, lote), tomar la línea activa del mayor número de ronda realizada.
        $todasLineas = SesionLinea::where('sesion_id', $sesion->id)
            ->where('estado', SesionLinea::ESTADO_ACTIVO)
            ->orderBy('ronda', 'desc')
            ->get();

        $lineasDefinitivas = $todasLineas->unique(function ($item) {
            return $item->producto_id . '_' . $item->ubicacion_id . '_' . ($item->lote ?? 'N/A');
        });

        // Filtrar solo aquellas con diferencia real frente al stock del sistema al momento de ajustar
        $lineas = $lineasDefinitivas->filter(function ($linea) use ($sesion) {
            $stockActual = (float) Inventario::where('producto_id', $linea->producto_id)
                ->where('ubicacion_id', $linea->ubicacion_id)
                ->where('empresa_id', $sesion->empresa_id)
                ->where('sucursal_id', $sesion->sucursal_id)
                ->when($linea->lote, fn($q) => $q->where('lote', $linea->lote), fn($q) => $q->where(fn($sq) => $sq->whereNull('lote')->orWhere('lote', 'N/A')->orWhere('lote', '')))
                ->sum('cantidad');
            
            $linea->cantidad_sistema = $stockActual;
            $linea->diferencia       = (float)$linea->cantidad_contada - $stockActual;
            return abs($linea->diferencia) > 0.0001;
        })->values();

        $rondaFinal = $lineasDefinitivas->max('ronda') ?: 1;

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

            // Regla de Oro #3 (modo alerta, no bloquea) — mismo patrón que en PickingController.
            $productosMovidos = array_unique(array_column($resultado['stockResumen'], 'producto_id'));
            $guardInv = new InventoryGuard($sesion->empresa_id, $sesion->sucursal_id, $user->id);
            foreach ($productosMovidos as $productoId) {
                $guardInv->assertLedgerMatchesStock((int)$productoId);
            }

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
        $invQuery = Inventario::where('empresa_id',  $sesion->empresa_id)
            ->where('sucursal_id',  $sesion->sucursal_id)
            ->where('producto_id',  $linea->producto_id)
            ->where('ubicacion_id', $linea->ubicacion_id);

        $loteNorm = trim((string)$linea->lote);
        if ($loteNorm !== '' && strtoupper($loteNorm) !== 'N/A') {
            $invQuery->whereRaw("COALESCE(NULLIF(NULLIF(TRIM(lote), 'N/A'), 'n/a'), '') = ?", [$loteNorm]);
        } else {
            $invQuery->whereRaw("COALESCE(NULLIF(NULLIF(TRIM(lote), 'N/A'), 'n/a'), '') = ''");
        }

        if (!empty($linea->fecha_vencimiento)) {
            $invQuery->where('fecha_vencimiento', $linea->fecha_vencimiento);
        }

        $inv = $invQuery->first();

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
        // BUG CORREGIDO (2026-09-08, caso real: SOLOMITO X 5 UND, CONGELACION/01-31-03,
        // lote 31/08/2026 — Sesión #59): el ajuste por conteo físico solo tocaba
        // 'cantidad' (la verdad absoluta del conteo) y dejaba 'cantidad_cajas'/'saldos'
        // en su valor previo al conteo (rama existente) o siempre en 0 (rama nueva),
        // rompiendo el invariante cantidad_cajas*upc+saldos==cantidad que sí mantienen
        // Picking/Traspaso. Stock General suma 'cantidad' (correcto), pero cualquier
        // pantalla que muestre el desglose cajas/sueltos quedaba mostrando un conteo
        // viejo — parecía que el sistema tenía unidades "de más" que no cuadraban con
        // las cajas físicas. Se deriva el desglose de 'cantidad_contada' con la misma
        // fórmula que ya usa el resto del sistema (floor/fmod por upc).
        $upcAjuste = max(1, (float)($linea->producto->factor_udm ?? 0) > 0
            ? (float)$linea->producto->factor_udm
            : (float)($linea->producto->unidades_caja ?? 1));

        if ($inv) {
            if ($cantidadNueva <= 0) {
                // Eliminar registro: la referencia no existe físicamente
                $inv->delete();
            } else {
                $inv->cantidad       = $cantidadNueva;
                $inv->cantidad_cajas = (int)floor($cantidadNueva / $upcAjuste);
                $inv->saldos         = round(fmod($cantidadNueva, $upcAjuste), 2);
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
                'cantidad_cajas'     => (int)floor($cantidadNueva / $upcAjuste),
                'saldos'             => round(fmod($cantidadNueva, $upcAjuste), 2),
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
        // BLINDAJE CÍCLICO 2026-08-26: Un inventario Cíclico SOLO ajusta las referencias/ubicaciones
        // explícitamente contadas. NUNCA deduce ni pone a cero referencias no contadas del sistema.
        if ($sesion->tipo === 'Ciclico') return [];

        // Las ubicaciones se obtienen de las LÍNEAS CONTADAS, no de las asignaciones.
        $ubicacionIds = SesionLinea::where('sesion_id', $sesion->id)
            ->where('ronda', $rondaFinal)
            ->where('estado', SesionLinea::ESTADO_ACTIVO)
            ->pluck('ubicacion_id')
            ->unique()
            ->values()
            ->toArray();

        // Para Ciclico, si no se contaron ubicaciones, no hay nada que deducir.
        if (empty($ubicacionIds) && $sesion->tipo === 'Ciclico') return [];

        // ── Una sola query SQL con NOT EXISTS (usa índices, sin carga en PHP) ──
        $queryEnSistema = Capsule::table('inventarios')
            ->where('inventarios.empresa_id',  $sesion->empresa_id)
            ->where('inventarios.sucursal_id',  $sesion->sucursal_id)
            ->where('inventarios.cantidad', '>', 0);
            
        // Si es Cíclico, solo eliminamos lo que no se contó en las ubicaciones VISITADAS.
        // Si es General o CargueInicial, eliminamos TODO el inventario de la sucursal que no se haya contado (incluyendo ubicaciones no visitadas).
        if ($sesion->tipo === 'Ciclico') {
            $queryEnSistema->whereIn('inventarios.ubicacion_id', $ubicacionIds);
        }

        $enSistema = $queryEnSistema->whereNotExists(function ($sub) use ($sesion, $rondaFinal) {
                // La referencia NO fue contada si no hay línea activa en la ronda final
                $sub->select(Capsule::raw(1))
                    ->from('sesion_lineas')
                    ->where('sesion_lineas.sesion_id', $sesion->id)
                    ->where('sesion_lineas.ronda',     $rondaFinal)
                    ->where('sesion_lineas.estado',    SesionLinea::ESTADO_ACTIVO)
                    ->whereRaw('sesion_lineas.producto_id  = inventarios.producto_id')
                    ->whereRaw('sesion_lineas.ubicacion_id = inventarios.ubicacion_id')
                    // Comparación segura de lote normalizado (trata NULL, '', 'N/A' como idénticos)
                    ->whereRaw("COALESCE(NULLIF(NULLIF(TRIM(sesion_lineas.lote), 'N/A'), 'n/a'), '') = COALESCE(NULLIF(NULLIF(TRIM(inventarios.lote), 'N/A'), 'n/a'), '')")
                    // BUG CORREGIDO (2026-09-08): sin comparar fecha_vencimiento, una
                    // ubicación con el mismo lote pero DOS vencimientos distintos del
                    // mismo producto solo necesitaba contar UNO para que el otro quedara
                    // "ya cubierto" y nunca se detectara como ausencia (ver mismo caso
                    // real en detectarReferenciasSinExistencia(), Jamón Serrano).
                    ->whereRaw('COALESCE(sesion_lineas.fecha_vencimiento, \'1900-01-01\') = COALESCE(inventarios.fecha_vencimiento, \'1900-01-01\')');
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
     * @param int|null $soloAsignacionId Si se indica, acota la reconciliación a UNA sola
     *                           asignación (uso desde ajustarLinea(), ajuste individual) en
     *                           vez de todas las asignaciones "Referencia" de la sesión
     *                           (uso desde ajustarTodo()).
     * @return array Lista de SesionLinea virtuales (cantidad_contada = 0) para ajustar
     */
    private function detectarReferenciasSinExistencia(SesionInventario $sesion, int $rondaFinal, array $yaCubiertas = [], ?int $soloAsignacionId = null): array
    {
        // BUG CORREGIDO (2026-09-08, caso real: JAMON SERRANO, sesión Cíclica "por
        // referencia" en REFRIGERACION/02-31-03): el "Blindaje Cíclico 2026-08-26" de
        // abajo bloqueaba ESTA función por completo para cualquier sesión tipo
        // 'Ciclico', pero esta función solo actúa sobre asignaciones tipo 'Referencia'
        // (ver query debajo) — que por definición le piden al auxiliar ubicar TODA la
        // existencia de un producto en la bodega, sin importar el tipo de sesión que
        // lo contenga. El blindaje era correcto para el otro detector
        // (detectarReferenciasNoContadas(), que sí debe respetar que un Cíclico por
        // zona/pasillo es parcial), pero aplicado aquí dejaba lotes/vencimientos del
        // mismo producto NO contados en Stock General con su cantidad vieja intacta
        // (el jamón serrano de 02-31-03 lote G/21-12-2026 y lote —/13-02-2027 nunca
        // se ponían en 0 aunque la asignación "Referencia" ya se había cerrado).
        // Si la sesión no tiene ninguna asignación 'Referencia', el query de abajo
        // devuelve vacío igual — quitar el guard no afecta un Cíclico por zona normal.
        $asignacionesReferencia = SesionAsignacion::where('sesion_id', $sesion->id)
            ->where('ronda', $rondaFinal)
            ->where('tipo_instruccion', SesionAsignacion::INSTRUCCION_REFERENCIA)
            ->where('estado', SesionAsignacion::ESTADO_FINALIZADO)
            ->whereNotNull('producto_id')
            ->when($soloAsignacionId, fn($q) => $q->where('id', $soloAsignacionId))
            ->get();

        if ($asignacionesReferencia->isEmpty()) return [];

        $vistos = [];
        foreach ($yaCubiertas as $lv) {
            $vistos[$lv->producto_id . '|' . $lv->ubicacion_id . '|' . ($lv->lote ?? '')] = true;
        }

        $sinExistencia = [];
        foreach ($asignacionesReferencia as $asig) {
            // BUG CORREGIDO (2026-09-08, mismo caso Jamón Serrano): antes se excluía
            // por 'ubicacion_id' completo — si esa ubicación tenía VARIOS lotes/fechas
            // de vencimiento del mismo producto y solo se contó uno, los demás quedaban
            // "ya cubiertos" solo por compartir la ubicación (REFRIGERACION/02-31-03
            // tenía 3 filas: la contada, y otras 2 con lote/vencimiento distintos que
            // escapaban a esta reconciliación). Ahora se excluye por la combinación
            // exacta ubicación+lote(normalizado)+vencimiento realmente contada, igual
            // que ya hace detectarReferenciasNoContadas() para General/CargueInicial.
            $inventarios = Capsule::table('inventarios')
                ->where('inventarios.empresa_id',  $sesion->empresa_id)
                ->where('inventarios.sucursal_id', $sesion->sucursal_id)
                ->where('inventarios.producto_id', $asig->producto_id)
                ->where('inventarios.cantidad', '>', 0)
                ->whereNotExists(function ($sub) use ($sesion, $asig) {
                    $sub->select(Capsule::raw(1))
                        ->from('sesion_lineas')
                        ->where('sesion_lineas.sesion_id', $sesion->id)
                        ->where('sesion_lineas.asignacion_id', $asig->id)
                        ->where('sesion_lineas.estado', SesionLinea::ESTADO_ACTIVO)
                        ->whereRaw('sesion_lineas.ubicacion_id = inventarios.ubicacion_id')
                        ->whereRaw("COALESCE(NULLIF(NULLIF(TRIM(sesion_lineas.lote), 'N/A'), 'n/a'), '') = COALESCE(NULLIF(NULLIF(TRIM(inventarios.lote), 'N/A'), 'n/a'), '')")
                        ->whereRaw('COALESCE(sesion_lineas.fecha_vencimiento, \'1900-01-01\') = COALESCE(inventarios.fecha_vencimiento, \'1900-01-01\')');
                })
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

                // Sin este chequeo, una corrección de tipo Entrada podía registrar como
                // "Disponible" stock cuya fecha_vencimiento ya pasó — este endpoint no
                // tenía ninguna validación de vencimiento (a diferencia de picking/packing/
                // recepción, que sí bloquean vencido vía ExpiryGuard/InventoryGuard R09-R10).
                if ($data['tipo_ajuste'] === 'Entrada' && $fv && strtotime($fv) < strtotime(date('Y-m-d'))) {
                    throw new \RuntimeException("La fecha de vencimiento ({$fv}) ya pasó. No se puede registrar como stock disponible.");
                }

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
                // fecha_vencimiento acota a la partida exacta cuando se conoce — es el
                // diferenciador real entre partidas (no el lote, que puede repetirse o
                // faltar). Sin esto, una corrección sin ubicación/lote podía tomar
                // arbitrariamente la primera fila del producto y sobrescribir la fecha
                // de vencimiento de un lote distinto al que se pretendía corregir.
                if ($fv) {
                    $invQuery->where('fecha_vencimiento', $fv);
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

                // Resolver upc del producto para persistir cajas/saldos consistentemente
                // (antes se descartaban los valores enviados por el formulario y la fila
                // quedaba con cantidad_cajas/saldos desincronizados de 'cantidad').
                $productoCorr = \App\Models\Producto::select('unidades_caja')->find($data['producto_id']);
                $upcCorr = max(1, (int)(($productoCorr->unidades_caja ?? null) ?: 1));
                if (isset($data['cantidad_cajas']) || isset($data['saldos'])) {
                    $cantCajasCorr = (int)($data['cantidad_cajas'] ?? (int)floor($cantidadNueva / $upcCorr));
                    $saldosCorr    = round((float)($data['saldos'] ?? fmod($cantidadNueva, (float)$upcCorr)), 2);
                } else {
                    $cantCajasCorr = (int)floor($cantidadNueva / $upcCorr);
                    $saldosCorr    = round(fmod($cantidadNueva, (float)$upcCorr), 2);
                }

                // Actualizar inventario
                if ($inv) {
                    if ($cantidadNueva <= 0) {
                        $inv->delete();
                    } else {
                        $inv->cantidad       = $cantidadNueva;
                        $inv->cantidad_cajas = $cantCajasCorr;
                        $inv->saldos         = $saldosCorr;
                        $inv->ubicacion_id   = $ubicacionId;
                        if ($fv) $inv->fecha_vencimiento = $fv;
                        $inv->save();
                    }
                } elseif ($cantidadNueva > 0) {
                    Inventario::create([
                        'empresa_id'         => $this->getEffectiveEmpresaId($user, $req),
                        'sucursal_id'        => $user->sucursal_id,
                        'producto_id'        => $data['producto_id'],
                        'ubicacion_id'       => $ubicacionId,
                        'lote'               => $data['lote'] ?? null,
                        'fecha_vencimiento'  => $fv,
                        'cantidad'           => $cantidadNueva,
                        'cantidad_cajas'     => $cantCajasCorr,
                        'saldos'             => $saldosCorr,
                        'cantidad_reservada' => 0,
                        'estado'             => 'Disponible',
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
            $empresaId   = $this->getEffectiveEmpresaId($user, $req);

            // ── Saldo de apertura: suma de TODOS los movimientos anteriores al rango
            // filtrado, con la misma clasificación entrada/salida usada más abajo. Sin
            // esto, "saldo" arrancaba en 0 al inicio de cada ventana — correcto solo si
            // se consultaba desde el primer movimiento histórico del producto; con
            // cualquier filtro de fecha (el caso normal, p.ej. "últimos 30 días") el
            // saldo mostrado quedaba subestimado frente al stock real.
            $signoAperturaCase = "CASE
                WHEN tipo_movimiento IN ('Entrada','AjustePositivo','Devolucion','Reabastecimiento') THEN cantidad
                WHEN tipo_movimiento IN ('Salida','AjusteNegativo','Picking') THEN -cantidad
                ELSE 0
            END";
            $saldoApertura = (float) (MovimientoInventario::where('empresa_id', $empresaId)
                ->where('sucursal_id', $sucursalId)
                ->where('producto_id', $params['producto_id'])
                ->where('fecha_movimiento', '<', substr($ini, 0, 10))
                ->selectRaw("COALESCE(SUM({$signoAperturaCase}), 0) as saldo")
                ->value('saldo') ?? 0);

            $movimientos = MovimientoInventario::where('movimiento_inventarios.empresa_id', $this->getEffectiveEmpresaId($user, $req))
                ->where('movimiento_inventarios.sucursal_id', $sucursalId)
                ->where('movimiento_inventarios.producto_id', $params['producto_id'])
                ->join('productos', 'movimiento_inventarios.producto_id', '=', 'productos.id')
                ->leftJoin('personal', 'movimiento_inventarios.auxiliar_id', '=', 'personal.id')
                ->leftJoin('ubicaciones as uo', 'movimiento_inventarios.ubicacion_origen_id', '=', 'uo.id')
                ->leftJoin('ubicaciones as ud', 'movimiento_inventarios.ubicacion_destino_id', '=', 'ud.id')
                ->leftJoin('orden_pickings as op_kx', function ($j) {
                    $j->on('op_kx.id', '=', 'movimiento_inventarios.referencia_id')
                      ->where('movimiento_inventarios.referencia_tipo', '=', 'OrdenPicking');
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
                    'op_kx.sucursal_entrega as sucursal_pedido'
                )
                ->groupBy(
                    'movimiento_inventarios.id', 'movimiento_inventarios.fecha_movimiento', 'movimiento_inventarios.hora_inicio',
                    'productos.nombre', 'productos.codigo_interno', 'movimiento_inventarios.tipo_movimiento',
                    'movimiento_inventarios.cantidad', 'movimiento_inventarios.cantidad_cajas', 'movimiento_inventarios.saldos',
                    'movimiento_inventarios.lote', 'movimiento_inventarios.fecha_vencimiento',
                    'uo.codigo', 'ud.codigo', 'personal.nombre',
                    'movimiento_inventarios.referencia_tipo', 'movimiento_inventarios.referencia_id', 'movimiento_inventarios.observaciones',
                    'op_kx.sucursal_entrega'
                )
                ->orderBy('movimiento_inventarios.fecha_movimiento')
                ->orderBy('movimiento_inventarios.hora_inicio')
                ->orderBy('movimiento_inventarios.id')
                ->get();

            // Calcular saldo acumulado (Kardex running balance), arrancando del saldo
            // de apertura calculado arriba en vez de 0.
            $saldo = $saldoApertura;
            $movimientos = $movimientos->map(function ($m) use (&$saldo) {
                $esSuma = in_array($m->tipo, ['Entrada', 'AjustePositivo', 'Devolucion', 'Reabastecimiento']);
                $esResta = in_array($m->tipo, ['Salida', 'AjusteNegativo', 'Picking']);
                $esTraslado = $m->tipo === 'Traslado';

                $m->saldo_anterior = $saldo;
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
                $headers = ['Fecha', 'Hora', 'Tipo', 'Sucursal Pedido', 'Entradas', 'Salidas', 'Cajas', 'Saldos', 'UND/TOTAL', 'Saldo Anterior', 'Saldo Acumulado', 'Lote', 'F.Vencimiento', 'Origen', 'Destino', 'Usuario', 'Observaciones'];
                $rows = $movimientos->map(fn($m) => [
                    $m->fecha, $m->hora, $m->tipo, $m->sucursal_pedido ?? '—',
                    $m->entradas ?: '', $m->salidas ?: '',
                    $m->cantidad_cajas ?? '—', $m->saldos ?? '—', $m->cantidad,
                    $m->saldo_anterior, $m->saldo,
                    $m->lote ?? '—', $m->fecha_vencimiento ?? '—',
                    $m->ubicacion_origen ?? '—', $m->ubicacion_destino ?? '—',
                    $m->usuario ?? '—', $m->observaciones ?? '',
                ])->toArray();
                return $this->exportCsv($res, $headers, $rows, 'kardex_' . date('Y-m-d'));
            }

            return $this->ok($res, [
                'saldo_apertura' => (float)$saldoApertura,
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

            // Blindaje 2026-08-12: mismo candado de conteoReferenciaCompleto() — si la
            // línea trae una asignación ya cerrada, no se acepta más conteo sobre ella.
            if ($asignacionId) {
                $asigCheck = SesionAsignacion::find($asignacionId);
                if ($asigCheck && $asigCheck->estado === SesionAsignacion::ESTADO_FINALIZADO) {
                    return $this->error($res, 'Esta asignación ya fue cerrada. Un administrador debe reabrirla para poder agregar más líneas.', 409);
                }
            }

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

            // Buscar si ya existe una línea activa para esta sesión, auxiliar, producto, ubicación, ronda, lote y fecha de vencimiento.
            $queryLinea = SesionLinea::where('sesion_id', $sesion->id)
                ->where('auxiliar_id', $user->id)
                ->where('producto_id', $prod->id)
                ->where('ubicacion_id', $ubic->id)
                ->where('ronda', $ronda)
                ->where('estado', SesionLinea::ESTADO_ACTIVO);

            if (!empty($lote)) {
                $queryLinea->where('lote', $lote);
            } else {
                $queryLinea->where(fn($q) => $q->whereNull('lote')->orWhere('lote', 'N/A')->orWhere('lote', ''));
            }

            if (!empty($fv)) {
                $queryLinea->where('fecha_vencimiento', $fv);
            } else {
                $queryLinea->whereNull('fecha_vencimiento');
            }

            $linea = $queryLinea->first();

            if ($linea) {
                // Si la línea ya existe para la misma ubicación, referencia, lote y FV, ACUMULAMOS la cantidad contada.
                $nuevaCantidadContada = (float)$linea->cantidad_contada + $cantidadContada;
                $nuevaCantidadCajas   = ($linea->cantidad_cajas !== null && $cantidadCajas !== null) ? ($linea->cantidad_cajas + $cantidadCajas) : ($cantidadCajas ?? $linea->cantidad_cajas);
                $nuevosSaldos        = ($linea->saldos !== null && $saldos !== null) ? ($linea->saldos + $saldos) : ($saldos ?? $linea->saldos);

                $linea->asignacion_id    = $asignacionId ?: $linea->asignacion_id;
                $linea->cantidad_contada = $nuevaCantidadContada;
                $linea->cantidad_cajas   = $nuevaCantidadCajas;
                $linea->saldos           = $nuevosSaldos;
                $linea->diferencia       = $nuevaCantidadContada - $linea->cantidad_sistema;
                $linea->hora_conteo      = date('Y-m-d H:i:s');
                $linea->save();
            } else {
                $linea = SesionLinea::create([
                    'sesion_id'         => $sesion->id,
                    'auxiliar_id'       => $user->id,
                    'producto_id'       => $prod->id,
                    'ubicacion_id'      => $ubic->id,
                    'ronda'             => $ronda,
                    'lote'              => $lote,
                    'fecha_vencimiento' => $fv,
                    'estado'            => SesionLinea::ESTADO_ACTIVO,
                    'asignacion_id'     => $asignacionId,
                    'cantidad_contada'  => $cantidadContada,
                    'cantidad_cajas'    => $cantidadCajas,
                    'saldos'            => $saldos,
                    'cantidad_sistema'  => $stockSnapshot,
                    'diferencia'        => $cantidadContada - $stockSnapshot,
                    'hora_conteo'       => date('Y-m-d H:i:s'),
                ]);
            }

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
        $params     = $req->getQueryParams();
        // Blindaje 2026-08-12: si se reabre una referencia ya cerrada para agregar
        // más ubicaciones, esta lista mostraba el stock del SISTEMA (que puede ser
        // distinto a lo que el auxiliar contó físicamente) — perdiendo de vista el
        // conteo real ya registrado. Si viene asignacion_id, se sobreescribe con lo
        // que ya quedó guardado en SesionLinea para esa ubicación.
        $asigId = !empty($params['asignacion_id']) ? (int)$params['asignacion_id'] : null;

        try {
            $empresaId    = $this->getEffectiveEmpresaId($user, $req);
            $effectiveSuc = $this->getEffectiveSucursalId($user, $req);
            $userSuc      = (int)($user->sucursal_id ?? 0);
            $sucs         = array_unique(array_filter([$effectiveSuc, $userSuc]));

            $prod = Producto::find($productoId);
            // Blindaje 2026-08-11: usaba solo unidades_caja, ignorando factor_udm —
            // para productos donde el factor real de conversión es factor_udm (ej.
            // "X 500 GR" con unidades_caja=1, factor_udm=500), esto mostraba el total
            // de unidades directo en el campo "Cajas" (35000 en vez de 70), rompiendo
            // el conteo cíclico. Mismo criterio ya usado en planillaDetalles()/
            // renderPKConsolidado(): factor_udm manda si aplica, si no, unidades_caja.
            $upc = (float)($prod->factor_udm ?? 0) > 0
                ? (float)$prod->factor_udm
                : max(1, (int)($prod->unidades_caja ?? 1));

            $rowsQuery = Inventario::where('empresa_id', $empresaId)
                ->where('producto_id', $productoId)
                ->where(function($q) {
                    $q->where('cantidad', '>', 0)
                      ->orWhere('cantidad_cajas', '>', 0)
                      ->orWhere('saldos', '>', 0);
                });

            if (!empty($sucs)) {
                $rowsQuery->whereIn('sucursal_id', $sucs);
            }

            $rows = $rowsQuery->with('ubicacion:id,codigo')->get();

            $ubicaciones = $rows->groupBy('ubicacion_id')->map(function ($items) use ($upc) {
                $u = $items->first()->ubicacion;
                $tot = round((float)$items->sum('cantidad'), 3);
                $cj  = $upc > 1 ? floor($tot / $upc) : round($tot);
                $sl  = $upc > 1 ? round(($tot - ($cj * $upc)) * 1000) / 1000 : 0;
                $firstWithLote = $items->first(fn($i) => !empty($i->lote));

                // Blindaje 2026-08-11:
                // 1) `fecha_vencimiento` es cast 'date' (Carbon) — al serializarlo tal
                //    cual en el JSON sale en formato ISO completo, que el <input
                //    type="date"> del móvil no reconoce y queda en blanco. Se formatea
                //    explícito a 'Y-m-d'.
                // 2) Si en esta ubicación hay más de una fecha de vencimiento distinta
                //    mezclada (varios lotes físicos juntos), no se debe sugerir
                //    ninguna al azar — se deja vacío para que el auxiliar la
                //    diligencie con la fecha real que está contando.
                $fechasDistintas = $items->pluck('fecha_vencimiento')
                    ->filter()
                    ->map(fn($f) => $f->format('Y-m-d'))
                    ->unique();
                $fechaVenc = $fechasDistintas->count() === 1 ? $fechasDistintas->first() : null;

                return [
                    'id'                => $u?->id,
                    'ubicacion_id'      => $u?->id,
                    'codigo'            => $u?->codigo ?? '—',
                    'nombre'            => $u?->nombre ?? '',
                    'cantidad'          => $tot,
                    'cajas'             => $cj,
                    'saldos'            => $sl,
                    'unidades_caja'     => $upc,
                    'lote'              => $firstWithLote?->lote ?? 'N/A',
                    'fecha_vencimiento' => $fechaVenc,
                ];
            })->values();

            if ($asigId) {
                $contadas = SesionLinea::where('asignacion_id', $asigId)
                    ->where('estado', SesionLinea::ESTADO_ACTIVO)
                    ->get()
                    ->keyBy('ubicacion_id');

                $ubicaciones = $ubicaciones->map(function ($u) use ($contadas) {
                    $c = $contadas->get($u['ubicacion_id']);
                    if ($c) {
                        $u['cajas']             = (float)$c->cantidad_cajas;
                        $u['saldos']            = (float)$c->saldos;
                        $u['lote']              = $c->lote ?: 'N/A';
                        $u['fecha_vencimiento'] = $c->fecha_vencimiento?->format('Y-m-d');
                        $u['ya_contada']        = true;
                    }
                    return $u;
                });

                // Ubicaciones que sí se contaron pero ya no aparecen en el stock del
                // sistema (ej. se contó en 0 y el registro de inventario desapareció) —
                // se agregan igual para que el conteo previo nunca se pierda de vista.
                $idsYaListados = $ubicaciones->pluck('ubicacion_id')->filter()->all();
                $faltantesPorAgregar = $contadas->filter(fn($c) => !in_array($c->ubicacion_id, $idsYaListados));
                foreach ($faltantesPorAgregar as $c) {
                    $ubic = Ubicacion::find($c->ubicacion_id);
                    $ubicaciones->push([
                        'id'                => $ubic?->id,
                        'ubicacion_id'      => $ubic?->id,
                        'codigo'            => $ubic?->codigo ?? '—',
                        'nombre'            => $ubic?->nombre ?? '',
                        'cantidad'          => (float)$c->cantidad_contada,
                        'cajas'             => (float)$c->cantidad_cajas,
                        'saldos'            => (float)$c->saldos,
                        'unidades_caja'     => $upc,
                        'lote'              => $c->lote ?: 'N/A',
                        'fecha_vencimiento' => $c->fecha_vencimiento?->format('Y-m-d'),
                        'ya_contada'        => true,
                    ]);
                }
            }

            $ubicaciones = $ubicaciones->sortBy('codigo')->values();

            return $this->ok($res, $ubicaciones);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v2/inventario/sesiones/{id}/icg-upload
     * Carga un archivo plano (CSV/TXT/Excel) o JSON con el inventario teórico de ICG (Código, Cantidad).
     * Reemplaza automáticamente la carga anterior de esa sesión en caliente.
     */
    public function uploadIcgFile(Request $req, Response $res, array $args): Response
    {
        try {
            $user = $req->getAttribute('user');
            $sesionId = (int)$args['id'];
            $sesion = $this->_findSesion($sesionId, $user, $req);
            if (!$sesion) return $this->notFound($res, 'Sesión de inventario no encontrada');

            $parsedLines = [];
            $filasInvalidas = []; // BUG CORREGIDO 2026-08-19: se reportan en vez de tumbar todo el insert
            $body = $req->getParsedBody() ?? [];

            // Límite defensivo: cantidad_icg es NUMERIC(12,3) — el valor absoluto debe
            // ser menor a 10^9. Un conteo cíclico real jamás se acerca a esa magnitud;
            // cualquier valor que la alcance es evidencia de una fila mal leída (columna
            // corrida, un EAN/código de barras colado en la celda de cantidad, etc.),
            // no una cantidad real. Se deja margen amplio (10 millones) sobre cualquier
            // cantidad físicamente plausible en un conteo.
            $ICG_CANTIDAD_MAX = 10000000.0;

            if (!empty($body['lineas']) && is_array($body['lineas'])) {
                $parsedLines = $body['lineas'];
            } else {
                $uploadedFiles = $req->getUploadedFiles();
                $file = $uploadedFiles['file'] ?? $uploadedFiles['archivo'] ?? $uploadedFiles['icg_file'] ?? null;

                if ($file && $file->getError() === UPLOAD_ERR_OK) {
                    $tmpPath = $file->getFilePath();
                    if (!$tmpPath) {
                        $stream = $file->getStream();
                        $tmpPath = $stream->getMetadata('uri');
                    }
                    $content = file_get_contents($tmpPath);

                    // BUG CORREGIDO 2026-09-17: un .xlsx/.xls REAL (binario) se leía
                    // como si fuera texto plano — el contenido binario terminaba
                    // insertado en columnas VARCHAR y reventaba el INSERT con un
                    // SQLSTATE crudo ("String data, right truncated"). No hay
                    // librería de lectura de Excel instalada en este servidor (ni la
                    // extensión `zip` de PHP habilitada), así que en vez de fallar a
                    // ciegas se detecta el binario ANTES de parsear y se le pide al
                    // usuario guardarlo como CSV — que sí funciona correctamente.
                    if ($this->esArchivoBinario($content)) {
                        return $this->errorArchivoBinario($res);
                    }

                    if (!mb_detect_encoding($content, 'UTF-8', true)) {
                        $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
                    }
                    $lines = preg_split('/\r\n|\r|\n/', $content);
                    $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));

                    // BUG CORREGIDO 2026-08-19: antes partía cada línea con preg_split
                    // sobre CUALQUIER coma/punto y coma/tab a la vez — si el nombre del
                    // producto traía una coma ("JUGO DE NARANJA, SIN AZUCAR"), todas las
                    // columnas siguientes se corrían y un valor grande (EAN, código de
                    // barras, etc.) podía terminar en la posición de "cantidad", sin
                    // ninguna validación antes de insertar — eso rompía la carga completa
                    // con un error crudo de SQL. Ahora se detecta UN solo delimitador
                    // real del archivo (mismo criterio que PickingController::importarPedidos())
                    // y se usa str_getcsv(), que sí respeta comillas alrededor de campos
                    // con el delimitador adentro.
                    $sep = ',';
                    if (!empty($lines[0])) {
                        if (str_contains($lines[0], "\t")) $sep = "\t";
                        elseif (str_contains($lines[0], ';')) $sep = ';';
                    }

                    foreach ($lines as $idx => $lineStr) {
                        $cols = str_getcsv(trim($lineStr), $sep);
                        if (count($cols) < 2) continue;

                        $codigo = trim($cols[0] ?? '', " \"'\r\n\t");
                        $cantStr = trim($cols[1] ?? '', " \"'\r\n\t");

                        if ($idx === 0 && (stristr($codigo, 'codigo') || stristr($codigo, 'referencia') || stristr($codigo, 'sku'))) {
                            continue;
                        }

                        if (empty($codigo)) continue;

                        // Formato numérico latino: punto = separador de miles, coma =
                        // decimal (mismo criterio que importarPedidos() para 'costo').
                        $cantNormalizado = str_replace(',', '.', str_replace('.', '', $cantStr));
                        $cant = is_numeric($cantNormalizado) ? (float)$cantNormalizado : (float)str_replace(',', '.', $cantStr);

                        if (abs($cant) >= $ICG_CANTIDAD_MAX) {
                            $filasInvalidas[] = [
                                'fila'     => $idx + 1,
                                'codigo'   => $codigo,
                                'valor'    => $cantStr,
                            ];
                            continue;
                        }

                        $parsedLines[] = [
                            'codigo'   => $codigo,
                            'cantidad' => $cant
                        ];
                    }
                }
            }

            if (empty($parsedLines)) {
                $detalle = !empty($filasInvalidas)
                    ? ' Se descartaron ' . count($filasInvalidas) . ' fila(s) con cantidades inválidas (revise el archivo — probablemente una columna corrida).'
                    : '';
                return $this->badRequest($res, 'No se encontraron registros válidos en el archivo. Asegúrese de que tenga columnas: Código, Cantidad.' . $detalle);
            }

            // Opcion de Reemplazo en Caliente: Eliminar las líneas ICG anteriores de esta sesión
            SesionIcgLinea::where('sesion_id', $sesion->id)->delete();

            // Cargar catálogo de productos para asociar producto_id, UxC y ambiente
            $empresaId = $this->getEffectiveEmpresaId($user, $req);
            $productos = Producto::select('id', 'codigo_interno', 'nombre', 'unidades_caja', 'ambiente_id', 'temperatura_almacen')
                ->where('empresa_id', $empresaId)
                ->get();

            $prodMap = [];
            foreach ($productos as $p) {
                $prodMap[strtoupper(trim($p->codigo_interno))] = $p;
            }

            $insertData = [];
            $reconocidos = 0;
            $noReconocidos = 0;

            foreach ($parsedLines as $item) {
                $cod = strtoupper(trim($item['codigo']));
                $cant = (float)($item['cantidad'] ?? 0);

                // Segunda barrera (además de la del parseo de archivo más arriba): el
                // camino de 'lineas' vía JSON (body['lineas']) no pasa por esa
                // validación — sin este chequeo, una cantidad absurda seguía
                // rompiendo el insert con un error crudo de SQL en vez de un mensaje
                // claro. Mismo límite que arriba (10 millones, muy por debajo del
                // tope real de la columna NUMERIC(12,3)).
                if (abs($cant) >= $ICG_CANTIDAD_MAX) {
                    $filasInvalidas[] = [
                        'fila'   => count($insertData) + count($filasInvalidas) + 1,
                        'codigo' => $item['codigo'] ?? $cod,
                        'valor'  => (string)$cant,
                    ];
                    continue;
                }

                $p = $prodMap[$cod] ?? null;

                if ($p) {
                    $reconocidos++;
                } else {
                    $noReconocidos++;
                }

                $insertData[] = [
                    'sesion_id'         => $sesion->id,
                    'producto_id'       => $p ? $p->id : null,
                    'codigo_referencia' => $item['codigo'],
                    'nombre_referencia' => $p ? $p->nombre : ($item['nombre'] ?? $item['codigo']),
                    'cantidad_icg'      => $cant,
                    'empresa_id'        => $sesion->empresa_id,
                    'sucursal_id'       => $sesion->sucursal_id,
                    'created_at'        => date('Y-m-d H:i:s'),
                    'updated_at'        => date('Y-m-d H:i:s'),
                ];
            }

            foreach (array_chunk($insertData, 200) as $chunk) {
                SesionIcgLinea::insert($chunk);
            }

            $this->audit($user, 'inventario_v2', 'cargar_icg', 'sesiones_inventario', $sesion->id, null, [
                'total_cargado' => count($insertData),
                'reconocidos'   => $reconocidos,
                'no_reconocidos'=> $noReconocidos,
                'filas_invalidas' => count($filasInvalidas),
            ]);

            $mensaje = 'Archivo plano ICG procesado y cargado exitosamente';
            if (!empty($filasInvalidas)) {
                $ejemplos = array_slice($filasInvalidas, 0, 5);
                $ejemplosTxt = implode('; ', array_map(
                    fn($f) => "fila {$f['fila']} (código {$f['codigo']}: \"{$f['valor']}\")",
                    $ejemplos
                ));
                $mensaje .= ' — ATENCIÓN: se descartaron ' . count($filasInvalidas)
                    . ' fila(s) con una cantidad no válida (probablemente una columna corrida '
                    . 'o un valor mal formateado): ' . $ejemplosTxt
                    . (count($filasInvalidas) > 5 ? '; ...' : '') . '. Revise esas filas en el archivo original.';
            }

            return $this->ok($res, [
                'mensaje'         => $mensaje,
                'filas_invalidas' => $filasInvalidas,
                'total_lineas'   => count($insertData),
                'reconocidos'    => $reconocidos,
                'no_reconocidos' => $noReconocidos
            ]);
        } catch (\Throwable $e) {
            return $this->error($res, 'Error al procesar archivo ICG: ' . $e->getMessage(), 500);
        }
    }

    /**
     * DELETE /api/v2/inventario/sesiones/{id}/icg-delete
     * Elimina el archivo plano ICG cargado previamente en la sesión.
     */
    public function deleteIcgFile(Request $req, Response $res, array $args): Response
    {
        try {
            $user = $req->getAttribute('user');
            $sesionId = (int)$args['id'];
            $sesion = $this->_findSesion($sesionId, $user, $req);
            if (!$sesion) return $this->notFound($res, 'Sesión de inventario no encontrada');

            $count = SesionIcgLinea::where('sesion_id', $sesion->id)->delete();
            $this->audit($user, 'inventario_v2', 'eliminar_icg', 'sesiones_inventario', $sesion->id, null, ['registros' => $count]);

            return $this->ok($res, ['mensaje' => 'Datos de ICG eliminados de la sesión', 'eliminados' => $count]);
        } catch (\Throwable $e) {
            return $this->error($res, $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/v2/inventario/sesiones/{id}/segundos-conteos
     * Permite agregar referencias dinámicamente para Segundo Conteo (Ronda 2) y asignarlas a auxiliares.
     */
    public function crearSegundosConteosBatch(Request $req, Response $res, array $args): Response
    {
        $user = $req->getAttribute('user');
        if ($deny = $this->requireSupervisor($user, $res)) return $deny;

        $sesion = $this->_findSesion((int)$args['id'], $user, $req);
        if (!$sesion) return $this->notFound($res, 'Sesión no encontrada');

        $data = $req->getParsedBody() ?? [];
        $auxiliarId  = (int)($data['auxiliar_id'] ?? 0);
        $productoIds = $data['producto_ids'] ?? [];
        $etiqueta    = trim($data['etiqueta'] ?? 'Segundo Conteo (Ronda 2)');

        if (!$auxiliarId) {
            return $this->error($res, 'Debe seleccionar un auxiliar para asignar el segundo conteo');
        }
        if (empty($productoIds) || !is_array($productoIds)) {
            return $this->error($res, 'Debe seleccionar al menos una referencia para el segundo conteo');
        }

        // Elevar número de conteos de la sesión a mínimo 2 rondas
        if ($sesion->num_conteos < 2) {
            $sesion->num_conteos = 2;
            $sesion->save();
        }

        $creados = 0;
        foreach ($productoIds as $prodId) {
            $prodId = (int)$prodId;
            if (!$prodId) continue;

            $exists = SesionAsignacion::where('sesion_id', $sesion->id)
                ->where('auxiliar_id', $auxiliarId)
                ->where('ronda', 2)
                ->where('producto_id', $prodId)
                ->exists();

            if (!$exists) {
                $asig = SesionAsignacion::create([
                    'sesion_id'         => $sesion->id,
                    'auxiliar_id'       => $auxiliarId,
                    'ronda'             => 2,
                    'tipo_instruccion'  => 'Referencia',
                    'producto_id'       => $prodId,
                    'instruccion_libre' => $etiqueta,
                    'estado'            => SesionAsignacion::ESTADO_PENDIENTE,
                ]);

                if ($sesion->estado === SesionInventario::ESTADO_EN_CURSO) {
                    $this->crearNotificacionAuxiliar($asig, $sesion);
                    $asig->estado        = SesionAsignacion::ESTADO_NOTIFICADO;
                    $asig->notificado_at = date('Y-m-d H:i:s');
                    $asig->save();
                }
                $creados++;
            }
        }

        return $this->ok($res, [
            'creados' => $creados,
            'sesion'  => $sesion->fresh()
        ], "Se asignaron {$creados} referencia(s) a Segundo Conteo (R2)");
    }

    /**
     * POST /api/v2/inventario/validar-codigos
     * Recibe texto o lista de códigos/EANs y devuelve los productos válidos encontrados.
     */
    public function validarCodigos(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        $data = $req->getParsedBody() ?? [];

        $rawInput = '';
        if (isset($data['codigos']) && is_string($data['codigos'])) {
            $rawInput = $data['codigos'];
        } elseif (isset($data['codigos']) && is_array($data['codigos'])) {
            $rawInput = implode(',', $data['codigos']);
        }

        if (empty(trim($rawInput))) {
            return $this->error($res, 'Ingrese al menos un código de referencia');
        }

        // Tokenizar por comas, saltos de línea, punto y coma, espacios o tabulaciones
        $tokens = preg_split('/[\s,;\n\r]+/', trim($rawInput));
        $tokens = array_values(array_unique(array_filter(array_map('trim', $tokens))));

        if (empty($tokens)) {
            return $this->error($res, 'No se encontraron códigos válidos');
        }

        $empresaId = $this->getEffectiveEmpresaId($user, $req);

        // Buscar productos por código interno o por EAN
        $prods = Producto::where('empresa_id', $empresaId)
            ->where(function($q) use ($tokens) {
                $q->whereIn('codigo_interno', $tokens)
                  ->orWhereHas('eans', function($eq) use ($tokens) {
                      $eq->whereIn('codigo_ean', $tokens);
                  });
            })
            ->select('id', 'codigo_interno', 'nombre', 'unidades_caja', 'controla_vencimiento')
            ->get();

        $encontrados = [];
        $foundCodes = [];

        foreach ($prods as $p) {
            $encontrados[] = [
                'id'                   => $p->id,
                'codigo'               => $p->codigo_interno,
                'nombre'               => $p->nombre,
                'unidades_caja'        => $p->unidades_caja ?? 1,
                'controla_vencimiento' => (bool)$p->controla_vencimiento,
            ];
            $foundCodes[] = strtoupper(trim($p->codigo_interno));
        }

        $noEncontrados = [];
        foreach ($tokens as $t) {
            if (!in_array(strtoupper($t), $foundCodes)) {
                $noEncontrados[] = $t;
            }
        }

        return $this->ok($res, [
            'encontrados'    => $encontrados,
            'no_encontrados' => array_values(array_unique($noEncontrados)),
            'total_validos'  => count($encontrados),
        ]);
    }

    /**
     * POST /v2/inventario/importar-referencias-archivo
     *
     * Carga un archivo (.csv/.txt, separador , ; o tab) con dos columnas:
     * código de referencia y auxiliar (documento o nombre) — a pedido
     * explícito de Camilo (2026-09-15): al crear un conteo cíclico "por
     * referencia" se debe poder importar de una vez las referencias Y quién
     * las va a contar, en lugar de pegar códigos fila por fila en el modal.
     *
     * Encabezados aceptados (opcionales, se detectan automáticamente):
     *   codigo | referencia | codigo_interno   →  columna de referencia
     *   auxiliar | documento | cedula | responsable → columna de auxiliar
     * Sin encabezado, se asume: columna 1 = código, columna 2 = auxiliar.
     *
     * Devuelve las asignaciones ya agrupadas por auxiliar (mismo shape que
     * validarCodigos() para reusar _renderRefBadges() en el frontend), listas
     * para poblar el modal "Nueva Sesión" sin más llamadas al backend.
     */

    /**
     * GET /api/v2/inventario/plantilla-referencias
     *
     * Plantilla CSV de ejemplo para "Importar archivo (referencias +
     * auxiliar)" — a pedido explícito de Camilo (2026-09-17). Antes se
     * generaba en el navegador con un Blob + <a download>, pero en un Chrome
     * corporativo/gestionado (visto en vivo repetidas veces: "MS Fénix |
     * Enterprise") ese tipo de descarga programática se renombraba a un UUID
     * genérico sin extensión — probablemente el escaneo de descargas de la
     * política empresarial, que no toca las descargas servidas por HTTP real
     * con Content-Disposition (como esta). Se sirve igual que el resto de
     * exportes del sistema (ExcelExporter, mismo patrón ya probado en
     * exportConteoV2) para que el nombre de archivo llegue intacto.
     */
    public function plantillaReferencias(Request $req, Response $res): Response
    {
        $headers = ['codigo', 'auxiliar'];
        $rows = [
            ['101037', '1017130145'],
            ['112027', '1017130145'],
            ['106003', 'MARIA PEREZ'],
        ];
        return ExcelExporter::download($res, $headers, $rows, 'plantilla_conteo_ciclico_referencias');
    }

    public function importarReferenciasArchivo(Request $req, Response $res): Response
    {
        $user       = $req->getAttribute('user');
        $empresaId  = $this->getEffectiveEmpresaId($user, $req);

        $uploadedFiles = $req->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? $uploadedFiles['archivo'] ?? null;
        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->error($res, 'Debe adjuntar un archivo (.csv o .txt)', 400);
        }

        $content = $file->getStream()->getContents();

        // Defensivo: si alguien sube un .xlsx real renombrado a .csv, se
        // rechaza con un mensaje claro en vez de intentar parsearlo como
        // texto (ver BaseController::esArchivoBinario — mismo bug corregido
        // 2026-09-17 en uploadIcgFile()).
        if ($this->esArchivoBinario($content)) {
            return $this->errorArchivoBinario($res);
        }

        if (!mb_detect_encoding($content, 'UTF-8', true)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $lines = array_values(array_filter($lines, fn($l) => trim($l) !== ''));
        if (empty($lines)) {
            return $this->error($res, 'El archivo está vacío', 400);
        }

        $sep = ',';
        if (str_contains($lines[0], "\t")) $sep = "\t";
        elseif (str_contains($lines[0], ';')) $sep = ';';

        $colCodigos   = ['codigo', 'referencia', 'codigo_interno', 'ean'];
        $colAuxNames  = ['auxiliar', 'documento', 'cedula', 'cédula', 'responsable'];
        $header = array_map(fn($h) => mb_strtolower(trim($h)), str_getcsv($lines[0], $sep));
        $idxCodigo = 0;
        $idxAux    = 1;
        $startIdx  = 0;
        $tieneEncabezado = count(array_intersect($header, array_merge($colCodigos, $colAuxNames))) > 0;
        if ($tieneEncabezado) {
            $startIdx = 1;
            foreach ($header as $i => $h) {
                if (in_array($h, $colCodigos, true)) $idxCodigo = $i;
                if (in_array($h, $colAuxNames, true)) $idxAux = $i;
            }
        }

        // Filas crudas [codigo, auxiliarRaw]
        $filas = [];
        for ($i = $startIdx; $i < count($lines); $i++) {
            $cols = str_getcsv(trim($lines[$i]), $sep);
            $codigo = trim($cols[$idxCodigo] ?? '');
            $auxRaw = trim($cols[$idxAux] ?? '');
            if ($codigo === '' && $auxRaw === '') continue;
            $filas[] = ['codigo' => $codigo, 'auxiliar_raw' => $auxRaw];
        }
        if (empty($filas)) {
            return $this->error($res, 'No se encontraron filas válidas en el archivo', 400);
        }

        // Resolver productos — mismo criterio que validarCodigos() (código interno o EAN)
        $tokensCodigo = array_values(array_unique(array_filter(array_column($filas, 'codigo'))));
        $prods = Producto::where('empresa_id', $empresaId)
            ->where(function ($q) use ($tokensCodigo) {
                $q->whereIn('codigo_interno', $tokensCodigo)
                  ->orWhereHas('eans', function ($eq) use ($tokensCodigo) {
                      $eq->whereIn('codigo_ean', $tokensCodigo);
                  });
            })
            ->with(['eans:id,producto_id,codigo_ean'])
            ->select('id', 'codigo_interno', 'nombre')
            ->get();
        $prodPorCodigo = [];
        foreach ($prods as $p) {
            $prodPorCodigo[mb_strtoupper(trim($p->codigo_interno))] = $p;
            foreach ($p->eans as $e) {
                $prodPorCodigo[mb_strtoupper(trim($e->codigo_ean))] = $p;
            }
        }

        // Resolver auxiliares — por documento o por nombre. Mismo criterio que
        // ParametrosController::getPersonal() (que alimenta el <select> de
        // auxiliares de este mismo modal): withoutTenantScope() + empresa_id,
        // SIN filtrar por 'activo' (personal.activo es smallint, no boolean —
        // comparar con `true` revienta en Postgres) para no desincronizar el
        // universo de auxiliares reconocidos por archivo vs. el del combo.
        $auxiliares = Personal::withoutTenantScope()
            ->where('empresa_id', $empresaId)
            ->select('id', 'nombre', 'documento')
            ->get();
        $auxPorDocumento = [];
        $auxPorNombre    = [];
        foreach ($auxiliares as $a) {
            if (!empty($a->documento)) $auxPorDocumento[trim($a->documento)] = $a;
            if (!empty($a->nombre))    $auxPorNombre[mb_strtoupper(trim($a->nombre))] = $a;
        }

        $grupos = [];             // auxiliar_id => { auxiliar_id, auxiliar_nombre, productos:[], codigos_no_encontrados:[] }
        $auxiliaresNoEncontrados = [];

        foreach ($filas as $fila) {
            $auxRaw = $fila['auxiliar_raw'];
            if ($auxRaw === '') { $auxiliaresNoEncontrados['(fila sin auxiliar)'] = true; continue; }

            $auxiliar = $auxPorDocumento[$auxRaw] ?? $auxPorNombre[mb_strtoupper($auxRaw)] ?? null;
            if (!$auxiliar) {
                $auxiliaresNoEncontrados[$auxRaw] = true;
                continue;
            }

            if (!isset($grupos[$auxiliar->id])) {
                $grupos[$auxiliar->id] = [
                    'auxiliar_id'            => $auxiliar->id,
                    'auxiliar_nombre'        => $auxiliar->nombre,
                    'productos'              => [],
                    'productos_ids_vistos'   => [],
                    'codigos_no_encontrados' => [],
                ];
            }

            $codigo = $fila['codigo'];
            if ($codigo === '') continue;
            $producto = $prodPorCodigo[mb_strtoupper($codigo)] ?? null;
            if ($producto) {
                if (!in_array($producto->id, $grupos[$auxiliar->id]['productos_ids_vistos'], true)) {
                    $grupos[$auxiliar->id]['productos_ids_vistos'][] = $producto->id;
                    $grupos[$auxiliar->id]['productos'][] = [
                        'id'     => $producto->id,
                        'codigo' => $producto->codigo_interno,
                        'nombre' => $producto->nombre,
                    ];
                }
            } else {
                $grupos[$auxiliar->id]['codigos_no_encontrados'][] = $codigo;
            }
        }

        $asignaciones = array_values(array_map(function ($g) {
            unset($g['productos_ids_vistos']);
            $g['codigos_no_encontrados'] = array_values(array_unique($g['codigos_no_encontrados']));
            return $g;
        }, $grupos));

        return $this->ok($res, [
            'asignaciones'              => $asignaciones,
            'auxiliares_no_encontrados' => array_keys($auxiliaresNoEncontrados),
            'total_filas'               => count($filas),
        ]);
    }
}
