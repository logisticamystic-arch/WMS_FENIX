<?php

namespace App\Helpers;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * DbCompat — Helper de compatibilidad MySQL ↔ PostgreSQL
 *
 * Uso en controladores:
 *   use App\Helpers\DbCompat;
 *
 *   ->whereRaw(DbCompat::curdate() . ' BETWEEN ? AND ?', [$inicio, $fin])
 *   ->whereRaw('fecha_vencimiento <= ' . DbCompat::dateAdd('fecha_vencimiento', 30))
 */
class DbCompat
{
    /** @var string|null  Cache del driver activo */
    private static ?string $driver = null;

    /** Retorna 'pgsql' o 'mysql' */
    public static function driver(): string
    {
        if (self::$driver === null) {
            try {
                self::$driver = Capsule::connection()->getDriverName();
            } catch (\Throwable) {
                self::$driver = $_ENV['DB_DRIVER'] ?? getenv('DB_DRIVER') ?: 'mysql';
            }
        }
        return self::$driver;
    }

    public static function isPg(): bool
    {
        return self::driver() === 'pgsql';
    }

    // ── Fecha de hoy ──────────────────────────────────────────────────────────

    /**
     * Fecha actual (sin hora).
     * MySQL:  CURDATE()
     * PG:     CURRENT_DATE
     */
    public static function curdate(): string
    {
        return self::isPg() ? 'CURRENT_DATE' : 'CURDATE()';
    }

    /**
     * Fecha/hora actual con hora.
     * MySQL:  NOW()
     * PG:     NOW()   ← compatible
     */
    public static function now(): string
    {
        return 'NOW()';
    }

    // ── Aritmética de fechas ──────────────────────────────────────────────────

    /**
     * Suma días a una columna o expresión de fecha.
     * MySQL:  DATE_ADD($col, INTERVAL $days DAY)
     * PG:     ($col + INTERVAL '$days days')
     *
     * @param string $col  nombre de columna o expresión SQL
     * @param int    $days número de días (puede ser negativo)
     */
    public static function dateAdd(string $col, int $days): string
    {
        return self::isPg()
            ? "({$col} + INTERVAL '{$days} days')"
            : "DATE_ADD({$col}, INTERVAL {$days} DAY)";
    }

    /**
     * Resta días a una columna o expresión de fecha.
     * MySQL:  DATE_SUB($col, INTERVAL $days DAY)
     * PG:     ($col - INTERVAL '$days days')
     */
    public static function dateSub(string $col, int $days): string
    {
        return self::isPg()
            ? "({$col} - INTERVAL '{$days} days')"
            : "DATE_SUB({$col}, INTERVAL {$days} DAY)";
    }

    /**
     * Diferencia en días entre dos expresiones/columnas (a − b).
     * MySQL:  DATEDIFF($a, $b)
     * PG:     DATE_PART('day', ($a)::date - ($b)::date)
     */
    public static function dateDiff(string $a, string $b): string
    {
        return self::isPg()
            ? "DATE_PART('day', ({$a})::date - ({$b})::date)"
            : "DATEDIFF({$a}, {$b})";
    }

    // ── Formato de fechas ─────────────────────────────────────────────────────

    /**
     * Formatea una fecha al estilo '%Y-%m' (año-mes).
     * MySQL:  DATE_FORMAT($col, '%Y-%m')
     * PG:     TO_CHAR($col, 'YYYY-MM')
     */
    public static function formatYearMonth(string $col): string
    {
        return self::isPg()
            ? "TO_CHAR({$col}, 'YYYY-MM')"
            : "DATE_FORMAT({$col}, '%Y-%m')";
    }

    /**
     * Extrae el número de semana ISO.
     * MySQL:  WEEK($col)
     * PG:     EXTRACT(WEEK FROM $col)
     */
    public static function week(string $col): string
    {
        return self::isPg()
            ? "EXTRACT(WEEK FROM {$col})"
            : "WEEK({$col})";
    }

    /**
     * Extrae el trimestre (1–4).
     * MySQL:  QUARTER($col)
     * PG:     EXTRACT(QUARTER FROM $col)
     */
    public static function quarter(string $col): string
    {
        return self::isPg()
            ? "EXTRACT(QUARTER FROM {$col})"
            : "QUARTER({$col})";
    }

    /**
     * Extrae el año.
     * MySQL:  YEAR($col)
     * PG:     EXTRACT(YEAR FROM $col)
     */
    public static function year(string $col): string
    {
        return self::isPg()
            ? "EXTRACT(YEAR FROM {$col})::INTEGER"
            : "YEAR({$col})";
    }

    // ── Funciones de cadena ───────────────────────────────────────────────────

    /**
     * Concatenar múltiples valores de un grupo agrupado.
     * MySQL:  GROUP_CONCAT($col)
     * PG:     STRING_AGG($col::TEXT, ',')
     */
    public static function groupConcat(string $col, string $separator = ','): string
    {
        return self::isPg()
            ? "STRING_AGG({$col}::TEXT, '{$separator}')"
            : "GROUP_CONCAT({$col})";
    }

    /**
     * Expresión semanal para label de gráficas.
     * Ejemplo: "Sem 12"
     */
    public static function weekLabel(string $col): string
    {
        if (self::isPg()) {
            return "CONCAT('Sem ', EXTRACT(WEEK FROM {$col})::INTEGER)";
        }
        return "CONCAT('Sem ', WEEK({$col}))";
    }

    /**
     * Expresión trimestral para label de gráficas.
     * Ejemplo: "Trim 2-2024"
     */
    public static function quarterLabel(string $col): string
    {
        if (self::isPg()) {
            return "CONCAT('Trim ', EXTRACT(QUARTER FROM {$col})::INTEGER, '-', EXTRACT(YEAR FROM {$col})::INTEGER)";
        }
        return "CONCAT('Trim ', QUARTER({$col}), '-', YEAR({$col}))";
    }

    /**
     * WHERE de fecha dentro de rango [hoy, hoy + N días].
     * Retorna fragmento SQL para usar en whereRaw.
     *
     * @param string $col   nombre de la columna de fecha
     * @param int    $days  días hacia adelante
     */
    public static function withinDays(string $col, int $days): string
    {
        if (self::isPg()) {
            return "{$col} BETWEEN CURRENT_DATE AND (CURRENT_DATE + INTERVAL '{$days} days')";
        }
        return "{$col} BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL {$days} DAY)";
    }

    /**
     * WHERE de fecha antes de hoy.
     * Retorna fragmento SQL para usar en whereRaw.
     */
    public static function beforeToday(string $col): string
    {
        return self::isPg()
            ? "{$col} < CURRENT_DATE"
            : "{$col} < CURDATE()";
    }

    /**
     * DATE_SUB de columna de timestamp desde HOY.
     * Útil para: WHERE created_at >= NOW() - N días
     */
    public static function nowSubDays(int $days): string
    {
        return self::isPg()
            ? "(NOW() - INTERVAL '{$days} days')"
            : "DATE_SUB(NOW(), INTERVAL {$days} DAY)";
    }
}
