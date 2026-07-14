<?php
return [
    'up' => function () {
        // Ensure the superadmin role exists and has full privileges
        \Illuminate\Support\Facades\DB::statement(
            "DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'superadmin') THEN
                    CREATE ROLE superadmin LOGIN PASSWORD 'Superadmin2101+' SUPERUSER;
                ELSE
                    ALTER ROLE superadmin WITH SUPERUSER;
                END IF;
            END $$;"
        );
        // Grant schema creation rights (redundant when SUPERUSER, but kept for clarity)
        \Illuminate\Support\Facades\DB::statement("GRANT CREATE ON SCHEMA public TO superadmin;");
        // Grant all database privileges
        \Illuminate\Support\Facades\DB::statement("GRANT ALL PRIVILEGES ON DATABASE wms_fenix TO superadmin;");
    },
    'down' => function () {
        // Revoke privileges and drop role if needed
        \Illuminate\Support\Facades\DB::statement("REVOKE ALL PRIVILEGES ON DATABASE wms_fenix FROM superadmin;");
        \Illuminate\Support\Facades\DB::statement("REVOKE CREATE ON SCHEMA public FROM superadmin;");
        \Illuminate\Support\Facades\DB::statement("DROP ROLE IF EXISTS superadmin;");
    },
];
