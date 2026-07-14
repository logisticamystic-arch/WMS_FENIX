<?php

use Illuminate\Database\Capsule\Manager as Capsule;

return [
    'up' => function () {
        Capsule::connection()->statement('ALTER TABLE ubicaciones ALTER COLUMN zona TYPE VARCHAR(50)');
        Capsule::connection()->statement('ALTER TABLE ubicaciones ALTER COLUMN codigo TYPE VARCHAR(80)');
    },
    'down' => function () {
        Capsule::connection()->statement('ALTER TABLE ubicaciones ALTER COLUMN zona TYPE VARCHAR(10)');
        Capsule::connection()->statement('ALTER TABLE ubicaciones ALTER COLUMN codigo TYPE VARCHAR(30)');
    },
];
