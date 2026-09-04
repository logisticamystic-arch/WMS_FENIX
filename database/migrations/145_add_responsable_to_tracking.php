<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Capsule\Manager as Capsule;

class AddResponsableToTracking extends Migration
{
    public function up()
    {
        $schema = Capsule::schema();
        if ($schema->hasTable('devolucion_tracking')) {
            if (!$schema->hasColumn('devolucion_tracking', 'responsable')) {
                $schema->table('devolucion_tracking', function (Blueprint $table) {
                    $table->string('responsable')->nullable()->after('observacion');
                });
            }
        }
    }

    public function down()
    {
        $schema = Capsule::schema();
        if ($schema->hasTable('devolucion_tracking')) {
            if ($schema->hasColumn('devolucion_tracking', 'responsable')) {
                $schema->table('devolucion_tracking', function (Blueprint $table) {
                    $table->dropColumn('responsable');
                });
            }
        }
    }
}
