<?php
// src/Models/Devolucion.php
namespace App\Models;

use App\Models\Concerns\TenantScoped;

class Devolucion extends BaseModel
{
    use TenantScoped;
    protected static bool $tenantUsesSucursal = true;
    protected $table = 'devoluciones';

    protected $fillable = [
        'empresa_id', 'sucursal_id', 'recepcion_id', 'odc_id', 'numero_devolucion',
        'proveedor', 'referencia_externa', 'tipo', 'auxiliar_id', 'solicitado_por',
        'fecha_movimiento', 'hora_inicio', 'hora_fin',
        'estado', 'motivo_general', 'fotos_json', 'observaciones',
        'autorizado_por', 'fecha_autorizacion', 'fecha_devolucion',
        'aprobado_por', 'procesado_por', 'aprobado_at', 'procesado_at',
    ];

    protected $casts = [
        'fecha_movimiento'   => 'date',
        'fecha_autorizacion' => 'datetime',
        'aprobado_at'        => 'datetime',
        'procesado_at'       => 'datetime',
        'fotos_json'         => 'array',
    ];

    // Tipos legacy (proveedor)
    const TIPO_AVERIA    = 'AProveedorAveria';
    const TIPO_VENCIDO   = 'AProveedorVencido';
    const TIPO_REINGRESO = 'ReingresoBuenEstado';

    // Tipos nuevos
    const TIPO_CLIENTE   = 'cliente';
    const TIPO_PROVEEDOR = 'proveedor';
    const TIPO_INTERNA   = 'interna';

    // Estados
    const ESTADO_PENDIENTE = 'PendienteAprobacion';
    const ESTADO_APROBADA  = 'Aprobada';
    const ESTADO_PROCESADA = 'Procesada';
    const ESTADO_RECHAZADA = 'Rechazada';
    const ESTADO_ANULADA   = 'Anulada';

    public function empresa()    { return $this->belongsTo(Empresa::class); }
    public function sucursal()   { return $this->belongsTo(Sucursal::class); }
    public function recepcion()  { return $this->belongsTo(Recepcion::class); }
    public function auxiliar()   { return $this->belongsTo(Personal::class, 'auxiliar_id'); }
    public function solicitante(){ return $this->belongsTo(Personal::class, 'solicitado_por'); }
    public function aprobador()  { return $this->belongsTo(Personal::class, 'aprobado_por'); }
    public function detalles()   { return $this->hasMany(DevolucionDetalle::class); }

    public static function generarNumero(int $empresaId): string
    {
        $year = date('Y');
        $last = self::where('empresa_id', $empresaId)
            ->where('numero_devolucion', 'like', "DEV-{$year}-%")
            ->count();
        return sprintf('DEV-%s-%04d', $year, $last + 1);
    }
}
