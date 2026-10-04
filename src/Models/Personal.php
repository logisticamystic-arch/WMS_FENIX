<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\TenantScoped;

class Personal extends BaseModel
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;





    protected $table = 'personal';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'nombre', 'documento', 'pin', 'rol', 'activo',
    ];

    protected $hidden = ['pin'];

    protected $casts = [
        'activo' => 'boolean',
        'ultimo_login' => 'datetime',
    ];

    // Roles constants
    const ROL_ADMIN = 'Admin';
    const ROL_SUPERADMIN = 'SuperAdmin';
    const ROL_SUPERVISOR = 'Supervisor';
    const ROL_AUXILIAR = 'Auxiliar';
    const ROL_MONTACARGUISTA = 'Montacarguista';
    const ROL_ANALISTA = 'Analista';

    public function isSuperAdmin(): bool
    {
        return strcasecmp($this->rol ?? '', self::ROL_SUPERADMIN) === 0;
    }

    public function isAdminOrSuperAdmin(): bool
    {
        return $this->isSuperAdmin() || strcasecmp($this->rol ?? '', self::ROL_ADMIN) === 0;
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function recepciones()
    {
        return $this->hasMany(Recepcion::class, 'auxiliar_id');
    }

    public function recepcionDetalles()
    {
        return $this->hasManyThrough(RecepcionDetalle::class, Recepcion::class, 'auxiliar_id', 'recepcion_id');
    }

    /**
     * Verify PIN against stored hash
     */
    public function verifyPin(string $pin): bool
    {
        return password_verify($pin, $this->pin);
    }

    /**
     * Lista para el selector del login móvil (WMS y TMS), 2026-10-01: solo
     * id, nombre y rol. Cuando un nombre se repite (caso real: dos "Camilo
     * Perez") se agrega `ref` con los últimos 3 dígitos del documento para
     * poder distinguirlos sin exponer el documento completo.
     */
    public static function listaLogin(int $empresaId): array
    {
        $rows = static::withoutTenantScope()->where('empresa_id', $empresaId)->where('activo', true)
            ->orderBy('nombre')->get(['id', 'nombre', 'rol', 'documento']);
        $cuenta = array_count_values(array_map(fn($n) => mb_strtolower(trim($n)), $rows->pluck('nombre')->all()));
        return $rows->map(fn($p) => [
            'id'     => $p->id,
            'nombre' => $p->nombre,
            'rol'    => $p->rol,
            'ref'    => $cuenta[mb_strtolower(trim($p->nombre))] > 1 && $p->documento ? substr($p->documento, -3) : null,
        ])->all();
    }

    /**
     * Valida el PIN con bloqueo temporal (5 fallos seguidos → 5 min) —
     * 2026-10-01, login por "nombre + PIN" (ver migración 163). Devuelve null
     * si es correcto, o el mensaje de error para mostrar.
     */
    public function intentarPin(string $pin): ?string
    {
        if ($this->pin_bloqueado_hasta && strtotime($this->pin_bloqueado_hasta) > time()) {
            $min = (int)ceil((strtotime($this->pin_bloqueado_hasta) - time()) / 60);
            return "Usuario bloqueado por intentos fallidos. Intente de nuevo en {$min} min.";
        }
        if ($this->verifyPin($pin)) {
            if ($this->pin_intentos || $this->pin_bloqueado_hasta) {
                static::withoutTenantScope()->where('id', $this->id)->update(['pin_intentos' => 0, 'pin_bloqueado_hasta' => null]);
            }
            return null;
        }
        $intentos = (int)$this->pin_intentos + 1;
        $upd = ['pin_intentos' => $intentos >= 5 ? 0 : $intentos];
        if ($intentos >= 5) $upd['pin_bloqueado_hasta'] = date('Y-m-d H:i:s', time() + 300);
        static::withoutTenantScope()->where('id', $this->id)->update($upd);
        return $intentos >= 5
            ? 'PIN incorrecto. Usuario bloqueado 5 minutos por intentos fallidos.'
            : 'PIN incorrecto. Le quedan ' . (5 - $intentos) . ' intento(s).';
    }

    /**
     * Hash PIN before storing
     */
    public static function hashPin(string $pin): string
    {
        return password_hash($pin, PASSWORD_BCRYPT);
    }

    /**
     * Check if user has a specific permission
     */
    public function hasPermission(string $modulo, string $accion): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return RolPermiso::where('empresa_id', $this->empresa_id)
            ->where('rol', $this->rol)
            ->whereHas('permiso', function ($q) use ($modulo, $accion) {
                $q->where('modulo', $modulo)->where('accion', $accion);
            })
            ->where('concedido', true)
            ->exists();
    }
}




