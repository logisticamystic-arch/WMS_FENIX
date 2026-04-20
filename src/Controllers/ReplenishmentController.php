<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Models\Notificacion;
use App\Models\Personal;

/**
 * ReplenishmentController — Gestión de Reabastecimiento Automático y Manual.
 */
class ReplenishmentController extends BaseController
{
    /**
     * Ejecuta el proceso de reabastecimiento (Auto-Replenishment).
     * Notifica a todos los Montacarguistas activos de la sucursal.
     */
    public function runAutoReplenishment(Request $req, Response $res): Response
    {
        $user = $req->getAttribute('user');
        
        // 1. Simulación de lógica de stock (aquí iría la consulta real)
        $tareasEncontradas = 3; // Ejemplo

        if ($tareasEncontradas > 0) {
            // 2. Buscar Montacarguistas reales por campo 'rol'
            $operarios = Personal::where('empresa_id', $user->empresa_id)
                ->where('rol', 'Montacarguista')
                ->where('activo', 1)
                ->get();

            foreach ($operarios as $op) {
                Notificacion::create([
                    'empresa_id'  => $user->empresa_id,
                    'sucursal_id' => $user->sucursal_id,
                    'personal_id' => $op->id,
                    'titulo'      => 'Reabastecimiento Necesario',
                    'mensaje'     => "Se han generado {$tareasEncontradas} tareas de reabastecimiento para su zona.",
                    'tipo'        => 'tarea',
                    'modulo'      => 'Inventarios',
                    'url'         => '/almacenamiento/putaway'
                ]);
            }
        }

        return $this->ok($res, null, 'Completado');
    }
}
