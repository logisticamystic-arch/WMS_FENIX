#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════
# deploy_to_ubuntu.sh — Sube los archivos modificados al servidor Ubuntu
# Uso: bash deploy_to_ubuntu.sh usuario@192.168.1.9
#
# Versión 2.0 — Sprint 1-4 completo (Abril 2026)
# Incluye: controladores nuevos + migraciones SQL + archivos frontend
# ═══════════════════════════════════════════════════════════════════════════
SERVER=${1:-"usuario@192.168.1.9"}
REMOTE="/var/www/WMS_PROORIENTE"
LOCAL="$(cd "$(dirname "$0")" && pwd)"

# Colores para output
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; NC='\033[0m'

echo ""
echo "╔══════════════════════════════════════════════════════════╗"
echo "║   WMS ProOriente — Deploy v2.0                          ║"
echo "║   Servidor: $SERVER"
echo "╚══════════════════════════════════════════════════════════╝"
echo ""

ERRORES=0
SUBIDOS=0

# ── Función auxiliar: scp → home → sudo mv ────────────────────────────────
upload() {
  local src="$1"
  local dst="$2"
  local fname
  fname=$(basename "$src")
  printf "  \033[0;36m↑\033[0m  %s\n" "$fname"

  # Crear directorio remoto si no existe
  local dir
  dir=$(dirname "$REMOTE/$dst")
  ssh "$SERVER" "sudo mkdir -p $dir 2>/dev/null; sudo chown www-data:www-data $dir 2>/dev/null" 2>/dev/null

  scp "$LOCAL/$src" "$SERVER:~/$fname" && \
  ssh "$SERVER" "sudo mv ~/$fname $REMOTE/$dst && sudo chown www-data:www-data $REMOTE/$dst" && \
  { printf "  \033[0;32m✓\033[0m  %s\n" "$fname"; SUBIDOS=$((SUBIDOS+1)); } || \
  { printf "  \033[0;31m✗  ERROR subiendo %s\033[0m\n" "$fname"; ERRORES=$((ERRORES+1)); }
}

# ── Función: subir solo si el archivo existe localmente ───────────────────
upload_if_exists() {
  local src="$1"
  local dst="$2"
  if [ -f "$LOCAL/$src" ]; then
    upload "$src" "$dst"
  else
    printf "  \033[1;33m⊘\033[0m  %s (no existe localmente, omitido)\n" "$(basename "$src")"
  fi
}

# ══════════════════════════════════════════════════════════════════════════
# SPRINT 1 — Backend PHP existente (bug fixes + PostgreSQL compat)
# ══════════════════════════════════════════════════════════════════════════
echo "── Sprint 1: Backend PHP (correcciones PostgreSQL) ──────────"
upload "src/Controllers/PickingController.php"    "src/Controllers/PickingController.php"
upload "src/Controllers/ReportesController.php"   "src/Controllers/ReportesController.php"
upload "src/Controllers/AlertasController.php"    "src/Controllers/AlertasController.php"

# ── JavaScript frontend ───────────────────────────────────────────────────
echo ""
echo "── Frontend JS ──────────────────────────────────────────────"
upload "public/assets/js/desktop/picking.js"    "public/assets/js/desktop/picking.js"
upload "public/assets/js/desktop/recepcion.js"  "public/assets/js/desktop/recepcion.js"

# ── TV Dashboard ──────────────────────────────────────────────────────────
echo ""
echo "── TV Dashboard ─────────────────────────────────────────────"
upload "public/tv-picking.html"  "public/tv-picking.html"

# ══════════════════════════════════════════════════════════════════════════
# SPRINT 2-4 — Controladores nuevos (ML / Rotación / Logística)
# ══════════════════════════════════════════════════════════════════════════
echo ""
echo "── Sprint 2-4: Controladores Nuevos ─────────────────────────"
upload_if_exists "src/Controllers/RotacionController.php"    "src/Controllers/RotacionController.php"
upload_if_exists "src/Controllers/ForecastController.php"    "src/Controllers/ForecastController.php"
upload_if_exists "src/Controllers/SlottingController.php"    "src/Controllers/SlottingController.php"
upload_if_exists "src/Controllers/CrossDockController.php"   "src/Controllers/CrossDockController.php"
upload_if_exists "src/Controllers/UbicacionesController.php" "src/Controllers/UbicacionesController.php"
upload_if_exists "src/Controllers/YardController.php"        "src/Controllers/YardController.php"
upload_if_exists "src/Controllers/WaveController.php"        "src/Controllers/WaveController.php"

# ══════════════════════════════════════════════════════════════════════════
# MIGRACIONES SQL — Subir al servidor (NO se ejecutan automáticamente)
# ══════════════════════════════════════════════════════════════════════════
echo ""
echo "── Migraciones SQL ──────────────────────────────────────────"

if [ -d "$LOCAL/migrations" ]; then
  for migration in "$LOCAL"/migrations/*.sql; do
    fname=$(basename "$migration")
    printf "  \033[0;36m↑\033[0m  %s\n" "$fname"
    scp "$migration" "$SERVER:~/wms_mig_$fname" && \
    ssh "$SERVER" "sudo mkdir -p $REMOTE/migrations && sudo mv ~/wms_mig_$fname $REMOTE/migrations/$fname && sudo chown www-data:www-data $REMOTE/migrations/$fname" && \
    printf "  \033[0;32m✓\033[0m  %s subido\n" "$fname" || \
    printf "  \033[0;31m✗  ERROR subiendo %s\033[0m\n" "$fname"
  done

  echo ""
  echo "  ╔─────────────────────────────────────────────────────────╗"
  echo "  │  INSTRUCCIONES: Ejecutar migraciones en el servidor     │"
  echo "  ╠─────────────────────────────────────────────────────────╣"
  echo "  │  ssh $SERVER"
  echo "  │  cd $REMOTE/migrations"
  echo "  │"
  echo "  │  psql -U postgres -d wms_prooriente \\"
  echo "  │       -f 001_sprint1_indices.sql"
  echo "  │"
  echo "  │  psql -U postgres -d wms_prooriente \\"
  echo "  │       -f 002_sprint2_ml_tables.sql"
  echo "  │"
  echo "  │  psql -U postgres -d wms_prooriente \\"
  echo "  │       -f 003_sprint2_mv_y_jobs.sql"
  echo "  │"
  echo "  │  # Poblar datos históricos (ajustar empresa_id/sucursal_id):"
  echo "  │  psql -U postgres -d wms_prooriente \\"
  echo "  │       -c \"SELECT poblar_ventas_ml(1, 1);\""
  echo "  │  psql -U postgres -d wms_prooriente \\"
  echo "  │       -c \"SELECT ejecutar_abc_xyz(1, 1, 12);\""
  echo "  │  psql -U postgres -d wms_prooriente \\"
  echo "  │       -c \"SELECT refresh_mv_rotacion();\""
  echo "  ╚─────────────────────────────────────────────────────────╝"
else
  printf "  \033[1;33m⊘\033[0m  Carpeta migrations/ no encontrada localmente\n"
fi

# ══════════════════════════════════════════════════════════════════════════
# LIMPIAR CACHÉ PHP (opcache)
# ══════════════════════════════════════════════════════════════════════════
echo ""
echo "── Limpiando caché del servidor ─────────────────────────────"
ssh "$SERVER" "sudo php -r 'if(function_exists(\"opcache_reset\")) opcache_reset();' 2>/dev/null || true"
ssh "$SERVER" "sudo systemctl reload php8.2-fpm 2>/dev/null || sudo systemctl reload php-fpm 2>/dev/null || true"
ssh "$SERVER" "sudo systemctl reload apache2 2>/dev/null || sudo systemctl reload nginx 2>/dev/null || true"
printf "  \033[0;32m✓\033[0m  Caché limpiado y servicios recargados\n"

# ══════════════════════════════════════════════════════════════════════════
# VERIFICAR SINTAXIS PHP
# ══════════════════════════════════════════════════════════════════════════
echo ""
echo "── Verificando sintaxis PHP ─────────────────────────────────"

PHP_FILES=(
  "src/Controllers/PickingController.php"
  "src/Controllers/ReportesController.php"
  "src/Controllers/AlertasController.php"
  "src/Controllers/RotacionController.php"
  "src/Controllers/ForecastController.php"
  "src/Controllers/SlottingController.php"
  "src/Controllers/CrossDockController.php"
  "src/Controllers/UbicacionesController.php"
  "src/Controllers/YardController.php"
  "src/Controllers/WaveController.php"
)

for f in "${PHP_FILES[@]}"; do
  RESULT=$(ssh "$SERVER" "[ -f $REMOTE/$f ] && php -l $REMOTE/$f 2>&1 || echo '__SKIP__'")
  fname=$(basename "$f")
  if echo "$RESULT" | grep -q "__SKIP__"; then
    printf "  \033[1;33m⊘\033[0m  %s (aún no en servidor)\n" "$fname"
  elif echo "$RESULT" | grep -q "No syntax errors"; then
    printf "  \033[0;32m✓\033[0m  %s OK\n" "$fname"
  else
    printf "  \033[0;31m✗  ERROR en %s:\033[0m %s\n" "$fname" "$RESULT"
    ERRORES=$((ERRORES+1))
  fi
done

# ══════════════════════════════════════════════════════════════════════════
# RESUMEN FINAL
# ══════════════════════════════════════════════════════════════════════════
echo ""
if [ $ERRORES -eq 0 ]; then
  echo "╔══════════════════════════════════════════════════════════╗"
  printf "║  \033[0;32m✓  Deploy completado — %d archivos subidos\033[0m\n" "$SUBIDOS"
  echo "║  Recargue el navegador con Ctrl+Shift+R"
  echo "╚══════════════════════════════════════════════════════════╝"
else
  echo "╔══════════════════════════════════════════════════════════╗"
  printf "║  \033[1;33m⚠  Deploy con advertencias: %d error(s), %d OK\033[0m\n" "$ERRORES" "$SUBIDOS"
  echo "║  Revise los errores arriba antes de usar en producción."
  echo "╚══════════════════════════════════════════════════════════╝"
fi
echo ""
