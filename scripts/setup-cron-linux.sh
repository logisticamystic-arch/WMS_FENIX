#!/usr/bin/env bash
# setup-cron-linux.sh
# Instala el cron diario de backup WMS Fénix a las 04:00 AM en Ubuntu/Linux.
#
# EJECUTAR UNA SOLA VEZ como root (o con sudo):
#   sudo bash /var/www/WMS_FENIX/scripts/setup-cron-linux.sh

set -euo pipefail

# ─── CONFIGURACIÓN ────────────────────────────────────────────────────────────
APP_DIR="${APP_DIR:-/var/www/WMS_FENIX}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
CRON_FILE="/etc/cron.d/wms-backup"
LOG_FILE="$APP_DIR/backups/backup.log"
RUN_HOUR="4"
RUN_MIN="0"
# Usuario que ejecutará el cron (debe tener acceso a pg_dump y a la carpeta del proyecto)
CRON_USER="${CRON_USER:-www-data}"

# ─── VALIDACIONES ─────────────────────────────────────────────────────────────
if [ "$EUID" -ne 0 ]; then
    echo "❌ ERROR: Ejecuta este script como root (sudo bash $0)"
    exit 1
fi

if [ ! -d "$APP_DIR" ]; then
    echo "❌ ERROR: No se encontró el directorio de la aplicación: $APP_DIR"
    echo "   Ajusta la variable APP_DIR o ejecuta desde la carpeta correcta."
    exit 1
fi

if [ ! -f "$PHP_BIN" ]; then
    echo "❌ ERROR: No se encontró PHP en: $PHP_BIN"
    echo "   Instala PHP 8.2: sudo apt install php8.2-cli php8.2-pgsql"
    exit 1
fi

if ! command -v pg_dump &>/dev/null; then
    echo "⚠️  ADVERTENCIA: pg_dump no encontrado en PATH."
    echo "   Instala: sudo apt install postgresql-client"
fi

if ! command -v tar &>/dev/null; then
    echo "❌ ERROR: tar no encontrado."
    exit 1
fi

# ─── CREAR DIRECTORIOS DE BACKUP ──────────────────────────────────────────────
mkdir -p "$APP_DIR/backups/db"
mkdir -p "$APP_DIR/backups/files"
touch "$LOG_FILE"
chown -R "$CRON_USER":"$CRON_USER" "$APP_DIR/backups" 2>/dev/null || true

# ─── INSTALAR CRON ────────────────────────────────────────────────────────────
cat > "$CRON_FILE" << EOF
# WMS Fénix — Backup diario automático
# Generado por setup-cron-linux.sh el $(date '+%Y-%m-%d %H:%M:%S')
#
# Ejecución: 04:00 AM todos los días
# BD PostgreSQL (comprimida, formato custom) + archivos uploads/ y docs/
#
# Para ejecutar manualmente:
#   sudo -u $CRON_USER $PHP_BIN $APP_DIR/scripts/backup.php
#
# Para ver el log en tiempo real:
#   tail -f $LOG_FILE

SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

$RUN_MIN $RUN_HOUR * * * $CRON_USER $PHP_BIN $APP_DIR/scripts/backup.php >> $LOG_FILE 2>&1
EOF

chmod 644 "$CRON_FILE"

# ─── VERIFICAR QUE CRON LEE EL ARCHIVO ───────────────────────────────────────
if systemctl is-active --quiet cron 2>/dev/null; then
    systemctl reload cron 2>/dev/null || true
elif systemctl is-active --quiet crond 2>/dev/null; then
    systemctl reload crond 2>/dev/null || true
fi

# ─── PRUEBA RÁPIDA DE SINTAXIS PHP ───────────────────────────────────────────
$PHP_BIN -l "$APP_DIR/scripts/backup.php" >/dev/null 2>&1 && \
    echo "✅ Sintaxis PHP correcta" || \
    echo "⚠️  Error de sintaxis en backup.php"

# ─── RESUMEN ──────────────────────────────────────────────────────────────────
echo ""
echo "======================================================"
echo "  Cron de backup instalado exitosamente:"
echo "  Archivo cron : $CRON_FILE"
echo "  Hora         : 04:00 AM (diario)"
echo "  Usuario      : $CRON_USER"
echo "  PHP          : $PHP_BIN"
echo "  Script       : $APP_DIR/scripts/backup.php"
echo "  Log          : $LOG_FILE"
echo "  Backups BD   : $APP_DIR/backups/db/"
echo "  Backups files: $APP_DIR/backups/files/"
echo "======================================================"
echo ""
echo "Para ejecutar el backup AHORA MISMO:"
echo "  sudo -u $CRON_USER $PHP_BIN $APP_DIR/scripts/backup.php"
echo ""
echo "Para ver el log:"
echo "  tail -f $LOG_FILE"
echo ""
