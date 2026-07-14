<?php
/**
 * Script para crear las tablas necesarias para los módulos de
 * Inteligencia ML y Logística Pro (forecast_demanda, yard_appointments, ubicaciones_optimas).
 */
require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();
require dirname(__DIR__) . '/config/app.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

$capsule = new Capsule;
$capsule->addConnection([
    'driver' => $_ENV['DB_DRIVER'] ?? 'pgsql',
    'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port' => $_ENV['DB_PORT'] ?? '5432',
    'database' => $_ENV['DB_NAME'] ?? 'wms_fenix',
    'username' => $_ENV['DB_USER'] ?? 'postgres',
    'password' => $_ENV['DB_PASS'] ?? 'Logistica2101+',
    'charset' => 'utf8',
    'prefix' => '',
    'schema' => 'public',
]);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$schema = Capsule::schema();

// 1. forecast_demanda
if (!$schema->hasTable('forecast_demanda')) {
    $schema->create('forecast_demanda', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('empresa_id');
        $table->unsignedBigInteger('sucursal_id');
        $table->unsignedBigInteger('producto_id');
        $table->date('fecha_prediccion');
        $table->decimal('cantidad_esperada', 15, 2);
        $table->decimal('nivel_confianza', 5, 2)->default(90.00); // %
        $table->string('modelo_usado', 100)->nullable();
        $table->timestamps();
    });
    echo "Tabla 'forecast_demanda' creada.\n";
} else {
    echo "La tabla 'forecast_demanda' ya existe.\n";
}

// 2. yard_appointments
if (!$schema->hasTable('yard_appointments')) {
    $schema->create('yard_appointments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('empresa_id');
        $table->unsignedBigInteger('sucursal_id');
        $table->unsignedBigInteger('proveedor_id')->nullable();
        $table->string('placa_vehiculo', 20);
        $table->string('conductor_nombre', 100)->nullable();
        $table->string('conductor_cedula', 30)->nullable();
        $table->dateTime('fecha_hora_programada');
        $table->dateTime('fecha_hora_llegada')->nullable();
        $table->dateTime('fecha_hora_salida')->nullable();
        $table->string('muelle_asignado', 50)->nullable();
        $table->string('estado', 30)->default('PROGRAMADO'); // PROGRAMADO, EN_PATIO, EN_MUELLE, FINALIZADO
        $table->string('tipo_operacion', 50)->default('DESCARGUE'); // DESCARGUE, CARGUE
        $table->timestamps();
    });
    echo "Tabla 'yard_appointments' creada.\n";
} else {
    echo "La tabla 'yard_appointments' ya existe.\n";
}

// 3. ubicaciones_optimas
if (!$schema->hasTable('ubicaciones_optimas')) {
    $schema->create('ubicaciones_optimas', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('empresa_id');
        $table->unsignedBigInteger('sucursal_id');
        $table->unsignedBigInteger('producto_id');
        $table->unsignedBigInteger('ubicacion_sugerida_id');
        $table->decimal('score_afinidad', 5, 2)->default(0.00);
        $table->text('motivo_recomendacion')->nullable();
        $table->timestamps();
    });
    echo "Tabla 'ubicaciones_optimas' creada.\n";
} else {
    echo "La tabla 'ubicaciones_optimas' ya existe.\n";
}

echo "Proceso completado.\n";
