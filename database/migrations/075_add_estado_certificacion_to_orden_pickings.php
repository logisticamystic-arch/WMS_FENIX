<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return new class {
    public function up(): void
    {
        $schema = Capsule::schema();
        if (!$schema->hasColumn('orden_pickings', 'estado_certificacion')) {
            $schema->table('orden_pickings', function ($table) {
                $table->string('estado_certificacion', 30)->default('Pendiente')->after('estado');
            });
        }
    }

    public function down(): void
    {
        $schema = Capsule::schema();
        if ($schema->hasColumn('orden_pickings', 'estado_certificacion')) {
            $schema->table('orden_pickings', function ($table) {
                $table->dropColumn('estado_certificacion');
            });
        }
    }
};
