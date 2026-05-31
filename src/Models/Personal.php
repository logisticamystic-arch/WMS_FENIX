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




