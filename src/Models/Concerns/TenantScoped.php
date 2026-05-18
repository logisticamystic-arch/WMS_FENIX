<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use App\Helpers\TenantContext;

trait TenantScoped
{
    // Each model declares its own $tenantUsesSucursal = true/false.
    // The trait does NOT define this property to avoid PHP 8.2 incompatible
    // trait property redefinition errors.

    private static function tenantUsesSucursal(): bool
    {
        return isset(static::$tenantUsesSucursal) && static::$tenantUsesSucursal;
    }

    public static function bootTenantScoped(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $empresaId = TenantContext::getEmpresaId();
            if ($empresaId !== null) {
                $builder->where($builder->getModel()->getTable() . '.empresa_id', $empresaId);
            }

            if (static::tenantUsesSucursal()) {
                $sucursalId = TenantContext::getSucursalId();
                if ($sucursalId !== null) {
                    $builder->where($builder->getModel()->getTable() . '.sucursal_id', $sucursalId);
                }
            }
        });
    }

    public function scopeWithCurrentTenant(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        $empresaId = TenantContext::getEmpresaId();

        if ($empresaId !== null) {
            $query->where("{$table}.empresa_id", $empresaId);
        }

        if (static::tenantUsesSucursal()) {
            $sucursalId = TenantContext::getSucursalId();
            if ($sucursalId !== null) {
                $query->where("{$table}.sucursal_id", $sucursalId);
            }
        }

        return $query;
    }

    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope('tenant');
    }
}
