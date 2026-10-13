<?php
/**
 * Migración 167 — devolucion_detalles.cantidad a decimal.
 *
 * 2026-10-12: el móvil no dejaba registrar devoluciones con cantidad fraccionada
 * (p. ej. 0.3 cajas × 5 = 1.5 und) — la columna era INTEGER y Postgres rechazaba
 * «1.5» con 500. El formulario (escritorio y móvil) acepta step 0.001, el modelo
 * castea a float e inventarios.cantidad ya es NUMERIC: solo la columna estaba mal.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

Capsule::statement("ALTER TABLE devolucion_detalles ALTER COLUMN cantidad TYPE NUMERIC(14,3)");
