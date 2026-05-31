<?php
// database/migrations/073_seed_cdp_medellin.php
use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        // ── 1. Empresa CDP MEDELLIN ──────────────────────────────────────────
        $empresaId = Capsule::table('empresas')
            ->where('nit', '123456789')
            ->value('id');

        if (!$empresaId) {
            $empresaId = Capsule::table('empresas')->insertGetId([
                'nit'          => '123456789',
                'razon_social' => 'CDP MEDELLIN',
                'direccion'    => 'Medellín, Antioquia',
                'telefono'     => '3001234567',
                'email'        => 'admin@cdpmedellin.com',
                'activo'       => 1,
                'created_at'   => date('Y-m-d H:i:s'),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        } else {
            Capsule::table('empresas')->where('id', $empresaId)->update([
                'razon_social' => 'CDP MEDELLIN',
                'activo'       => 1,
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        // ── 2. Sucursal CDP → empresa CDP MEDELLIN ───────────────────────────
        $sucursalId = Capsule::table('sucursales')
            ->where('empresa_id', $empresaId)
            ->where('codigo', 'CDP')
            ->value('id');

        if (!$sucursalId) {
            // Intentar reutilizar la sucursal huérfana (codigo='CDP MED', empresa_id=1)
            $huerfana = Capsule::table('sucursales')
                ->where('codigo', 'CDP MED')
                ->where('empresa_id', '!=', $empresaId)
                ->first();

            if ($huerfana) {
                Capsule::table('sucursales')->where('id', $huerfana->id)->update([
                    'empresa_id' => $empresaId,
                    'codigo'     => 'CDP',
                    'nombre'     => 'CDP',
                    'tipo'       => 'CEDI',
                    'activo'     => 1,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $sucursalId = $huerfana->id;
            } else {
                $sucursalId = Capsule::table('sucursales')->insertGetId([
                    'empresa_id' => $empresaId,
                    'codigo'     => 'CDP',
                    'nombre'     => 'CDP',
                    'direccion'  => 'Medellín, Antioquia',
                    'ciudad'     => 'Medellín',
                    'tipo'       => 'CEDI',
                    'activo'     => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        // ── 3. Usuario SuperAdmin Global ─────────────────────────────────────
        $pinHash = password_hash('2101', PASSWORD_BCRYPT);

        $existing = Capsule::table('personal')
            ->where('documento', 'SUPERADMIN')
            ->first();

        if ($existing) {
            Capsule::table('personal')->where('id', $existing->id)->update([
                'empresa_id'  => $empresaId,
                'sucursal_id' => $sucursalId,
                'nombre'      => 'SuperAdmin Global',
                'rol'         => 'SuperAdmin',
                'pin'         => $pinHash,
                'activo'      => 1,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        } else {
            Capsule::table('personal')->insert([
                'empresa_id'  => $empresaId,
                'sucursal_id' => $sucursalId,
                'nombre'      => 'SuperAdmin Global',
                'documento'   => 'SUPERADMIN',
                'pin'         => $pinHash,
                'rol'         => 'SuperAdmin',
                'activo'      => 1,
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }
    },

    'down' => function () {
        // No revertir datos de producción — solo estructura
    },
];
