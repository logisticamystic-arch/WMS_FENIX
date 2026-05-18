<?php

namespace App\Helpers;

class TenantContext
{
    private static ?int $empresaId = null;
    private static ?int $sucursalId = null;

    public static function setCurrentTenant(?int $empresaId, ?int $sucursalId = null): void
    {
        self::$empresaId = $empresaId;
        self::$sucursalId = $sucursalId;
    }

    public static function getEmpresaId(): ?int
    {
        return self::$empresaId;
    }

    public static function getSucursalId(): ?int
    {
        return self::$sucursalId;
    }

    public static function getCurrentTenant(): array
    {
        return [
            'empresa_id' => self::$empresaId,
            'sucursal_id' => self::$sucursalId,
        ];
    }

    public static function hasTenant(): bool
    {
        return self::$empresaId !== null;
    }

    public static function reset(): void
    {
        self::$empresaId = null;
        self::$sucursalId = null;
    }
}
