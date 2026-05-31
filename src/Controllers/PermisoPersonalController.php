<?php

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * RBAC a nivel de usuario individual (sobrescribe permisos de rol)
 */
class PermisoPersonalController extends BaseController
{
    /**
     * GET /api/personal/{id}/permisos
     * Lista TODOS los permisos del sistema, mostrando su estado según el rol
     * del usuario y cualquier override individual que tenga.
     */
    public function getPermisos(Request $request, Response $response, array $args): Response
    {
        $user       = $request->getAttribute('user');
        $personalId = (int)$args['id'];

        // Load the person
        $persona = DB::table('personal')->where('id', $personalId)->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))->first();
        if (!$persona) {
            return $this->json($response, ['error' => true, 'message' => 'Personal no encontrado'], 404);
        }

        // 1. Get ALL available permissions from the system
        $allPermisos = DB::table('permisos')->orderBy('modulo')->orderBy('accion')->get();

        // 2. Get role-based granted permission IDs for this user's role
        $rolGrantedMap = [];
        if (!empty($persona->rol)) {
            $rolPermisos = DB::table('rol_permisos as rp')
                ->join('permisos as p', 'p.id', '=', 'rp.permiso_id')
                ->where('rp.empresa_id', $this->getEffectiveEmpresaId($user, $request))
                ->where('rp.rol', $persona->rol)
                ->select('p.modulo', 'p.accion', 'rp.concedido')
                ->get();
            foreach ($rolPermisos as $rp) {
                $key = "{$rp->modulo}|{$rp->accion}";
                $rolGrantedMap[$key] = (bool)$rp->concedido;
            }
        }

        // 3. Get individual overrides
        $overrides = DB::table('personal_permisos')
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('personal_id', $personalId)
            ->get();
        $overrideMap = [];
        foreach ($overrides as $o) {
            $key = "{$o->modulo}|{$o->accion}";
            $overrideMap[$key] = (bool)$o->concedido;
        }

        // 4. Build unified permissions list from ALL permisos
        $result = [];
        foreach ($allPermisos as $p) {
            $key = "{$p->modulo}|{$p->accion}";
            $fromRol     = $rolGrantedMap[$key] ?? false;
            $hasOverride = array_key_exists($key, $overrideMap);
            $concedido   = $hasOverride ? $overrideMap[$key] : $fromRol;

            $result[] = [
                'modulo'     => $p->modulo,
                'submodulo'  => '',
                'accion'     => $p->accion,
                'concedido'  => $concedido,
                'esOverride' => $hasOverride,
            ];
        }

        return $this->json($response, [
            'error'             => false,
            'personal'          => $persona,
            'permisos_rol'      => $result,
            'permisos_personal' => $overrides,
        ]);
    }

    /**
     * POST /api/personal/{id}/permisos/toggle
     * Toggle a personal-level permission override
     * Body: { modulo, submodulo, accion, concedido }
     */
    public function togglePermiso(Request $request, Response $response, array $args): Response
    {
        $user       = $request->getAttribute('user');
        $personalId = (int)$args['id'];
        $rol = $user->rol ?? '';

        if (!in_array($rol, ['Admin', 'Supervisor'])) {
            return $this->json($response, ['error' => true, 'message' => 'Sin permiso'], 403);
        }

        $data = $request->getParsedBody() ?? [];
        $modulo    = trim($data['modulo'] ?? '');
        $accion    = trim($data['accion'] ?? 'ver');
        $concedido = (bool)($data['concedido'] ?? true);

        if (empty($modulo)) {
            return $this->json($response, ['error' => true, 'message' => 'Modulo requerido'], 400);
        }

        $existing = DB::table('personal_permisos')
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('personal_id', $personalId)
            ->where('modulo', $modulo)
            ->where('accion', $accion)
            ->first();

        if ($existing) {
            DB::table('personal_permisos')->where('id', $existing->id)->update([
                'concedido'  => $concedido,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            DB::table('personal_permisos')->insert([
                'empresa_id'  => $this->getEffectiveEmpresaId($user, $request),
                'personal_id' => $personalId,
                'modulo'      => $modulo,
                'accion'      => $accion,
                'concedido'   => $concedido,
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->json($response, ['error' => false, 'message' => 'Permisos actualizados correctamente.']);
    }

    // ── DELETE /api/personal/{id}/permisos ────────────────────────────────────
    // Resets all individual overrides for the user, falling back to role defaults.
    public function resetPermisos(Request $request, Response $response, array $args): Response
    {
        $user       = $request->getAttribute('user');
        $personalId = (int)$args['id'];
        $rol        = $user->rol ?? '';

        if (!in_array($rol, ['Admin', 'Supervisor'])) {
            return $this->json($response, ['error' => true, 'message' => 'Sin permiso'], 403);
        }

        $deleted = DB::table('personal_permisos')
            ->where('empresa_id', $this->getEffectiveEmpresaId($user, $request))
            ->where('personal_id', $personalId)
            ->delete();

        return $this->json($response, [
            'error'   => false,
            'message' => "Permisos individuales eliminados ({$deleted} registros). El usuario usará los permisos de su rol.",
        ]);
    }
}
