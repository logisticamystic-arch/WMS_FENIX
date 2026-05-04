#!/usr/bin/env bash
# ══════════════════════════════════════════════════════════════
#  WMS Fénix — Script de despliegue en servidor Ubuntu + PostgreSQL
#  Servidor objetivo: Ubuntu 22.04 LTS, PostgreSQL 16, PHP 8.2, Apache 2
#
#  USO:
#    chmod +x scripts/deploy_postgres.sh
#    sudo ./scripts/deploy_postgres.sh
#
#  Variables a ajustar antes de ejecutar (sección CONFIGURACIÓN)
# ══════════════════════════════════════════════════════════════
set -e
set -o pipefail

# ── CONFIGURACIÓN ─────────────────────────────────────────────
APP_DIR="/var/www/wms_fenix"
APP_USER="www-data"
DB_NAME="wms_fenix"
DB_USER="wms_fenix_user"
DB_PASS=""                        # ← Completar con contraseña segura
PG_VERSION="16"
PHP_VERSION="8.2"
DOMAIN=""                         # ← Ej: wms.miempresa.com (dejar vacío para IP)
# ──────────────────────────────────────────────────────────────

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
info()  { echo -e "${BLUE}[INFO]${NC}  $1"; }
ok()    { echo -e "${GREEN}[OK]${NC}    $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC}  $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

[[ $DB_PASS == "" ]] && error "Configura DB_PASS en el script antes de ejecutar"
[[ $EUID -ne 0 ]]   && error "Ejecutar como root (sudo)"

echo ""
echo -e "${BLUE}══════════════════════════════════════════${NC}"
echo -e "${BLUE}   WMS Fénix — Deploy PostgreSQL          ${NC}"
echo -e "${BLUE}══════════════════════════════════════════${NC}"
echo ""

# ── 1. Actualizar sistema ──────────────────────────────────────
info "Actualizando paquetes del sistema..."
apt-get update -qq && apt-get upgrade -y -qq
ok "Sistema actualizado"

# ── 2. Instalar dependencias ───────────────────────────────────
info "Instalando PHP ${PHP_VERSION}, Apache, PostgreSQL ${PG_VERSION}..."
apt-get install -y -qq \
    software-properties-common curl git unzip \
    apache2 libapache2-mod-php${PHP_VERSION} \
    php${PHP_VERSION} php${PHP_VERSION}-pgsql php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-xml php${PHP_VERSION}-zip php${PHP_VERSION}-curl \
    php${PHP_VERSION}-intl php${PHP_VERSION}-opcache php${PHP_VERSION}-bcmath \
    postgresql-${PG_VERSION} postgresql-client-${PG_VERSION}

# Composer
if ! command -v composer &>/dev/null; then
    curl -sS https://getcomposer.org/installer | php
    mv composer.phar /usr/local/bin/composer
fi
ok "Dependencias instaladas"

# ── 3. Configurar PostgreSQL ───────────────────────────────────
info "Configurando PostgreSQL..."
systemctl start postgresql
systemctl enable postgresql

# Crear usuario y base de datos
sudo -u postgres psql -tc "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'" | grep -q 1 || \
    sudo -u postgres psql -c "CREATE USER ${DB_USER} WITH PASSWORD '${DB_PASS}';"

sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1 || \
    sudo -u postgres psql -c "CREATE DATABASE ${DB_NAME} OWNER ${DB_USER} ENCODING 'UTF8';"

sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE ${DB_NAME} TO ${DB_USER};"
sudo -u postgres psql -d "${DB_NAME}" -c "GRANT ALL ON SCHEMA public TO ${DB_USER};"
ok "PostgreSQL configurado: DB=${DB_NAME}, USER=${DB_USER}"

# ── 4. Desplegar código ────────────────────────────────────────
info "Desplegando código en ${APP_DIR}..."
mkdir -p "${APP_DIR}"
rsync -a --exclude='.git' --exclude='node_modules' --exclude='.env' \
    "$(dirname "$(dirname "$0")")/" "${APP_DIR}/"

# Permisos
chown -R "${APP_USER}:${APP_USER}" "${APP_DIR}"
chmod -R 755 "${APP_DIR}"
chmod -R 775 "${APP_DIR}/backups" "${APP_DIR}/public/uploads" 2>/dev/null || true
ok "Código desplegado"

# ── 5. Crear .env de producción ────────────────────────────────
info "Creando .env de producción..."
APP_URL_FINAL="${DOMAIN:+https://${DOMAIN}}"
APP_URL_FINAL="${APP_URL_FINAL:-http://$(hostname -I | awk '{print $1}')}"
JWT_SECRET=$(openssl rand -hex 32)

cat > "${APP_DIR}/.env" <<EOF
# WMS Fénix — Producción (generado por deploy_postgres.sh)
DB_DRIVER=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=${DB_NAME}
DB_USER=${DB_USER}
DB_PASS=${DB_PASS}
DB_CHARSET=utf8
DB_SSLMODE=prefer

JWT_SECRET=${JWT_SECRET}
JWT_EXPIRY=28800

APP_ENV=production
APP_DEBUG=false
APP_URL=${APP_URL_FINAL}

CORS_ALLOWED_ORIGINS=${APP_URL_FINAL}

BACKUP_DIR=backups
BACKUP_RETENTION_DAYS=30
EOF
chown "${APP_USER}:${APP_USER}" "${APP_DIR}/.env"
chmod 640 "${APP_DIR}/.env"
ok ".env creado (JWT_SECRET generado automáticamente)"

# ── 6. Instalar dependencias PHP ───────────────────────────────
info "Instalando dependencias Composer..."
cd "${APP_DIR}"
sudo -u "${APP_USER}" composer install --no-dev --optimize-autoloader --quiet
ok "Composer listo"

# ── 7. Aplicar schema PostgreSQL ──────────────────────────────
info "Aplicando schema completo en PostgreSQL..."
PGPASSWORD="${DB_PASS}" psql -h 127.0.0.1 -U "${DB_USER}" -d "${DB_NAME}" \
    -f "${APP_DIR}/database/migrations/postgresql_full_schema.sql" -q
ok "Schema aplicado"

# ── 8. Ejecutar seeds iniciales ───────────────────────────────
info "Ejecutando seeds iniciales..."
cd "${APP_DIR}"
sudo -u "${APP_USER}" php -r "
  require_once 'vendor/autoload.php';
  \$dotenv = Dotenv\Dotenv::createImmutable('.');
  \$dotenv->safeLoad();
  \$cfg = require 'config/database.php';
  \$c = new Illuminate\Database\Capsule\Manager;
  \$c->addConnection(\$cfg);
  \$c->setAsGlobal(); \$c->bootEloquent();
  \$seeder = require 'database/seeds/DatabaseSeeder.php';
  if (is_callable(\$seeder)) \$seeder();
  elseif (isset(\$seeder['run'])) (\$seeder['run'])();
  echo 'Seeds OK' . PHP_EOL;
" 2>&1 | tail -5
ok "Seeds ejecutados"

# ── 9. Configurar Apache ───────────────────────────────────────
info "Configurando Apache..."
a2enmod rewrite
a2enmod headers

VHOST_FILE="/etc/apache2/sites-available/wms_fenix.conf"
cat > "${VHOST_FILE}" <<APACHE
<VirtualHost *:80>
    ${DOMAIN:+ServerName ${DOMAIN}}
    DocumentRoot ${APP_DIR}/public
    DirectoryIndex index.php

    <Directory "${APP_DIR}/public">
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    # Seguridad
    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options DENY
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    ErrorLog \${APACHE_LOG_DIR}/wms_fenix_error.log
    CustomLog \${APACHE_LOG_DIR}/wms_fenix_access.log combined
</VirtualHost>
APACHE

a2dissite 000-default.conf 2>/dev/null || true
a2ensite wms_fenix.conf
systemctl reload apache2
ok "Apache configurado"

# ── 10. OPcache PHP ───────────────────────────────────────────
info "Optimizando OPcache..."
PHP_INI="/etc/php/${PHP_VERSION}/apache2/conf.d/99-wms-opcache.ini"
cat > "${PHP_INI}" <<INI
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=10000
opcache.revalidate_freq=60
opcache.validate_timestamps=0
opcache.interned_strings_buffer=16
INI
systemctl reload apache2
ok "OPcache configurado"

# ── 11. Verificación final ─────────────────────────────────────
echo ""
echo -e "${GREEN}══════════════════════════════════════════${NC}"
echo -e "${GREEN}   Despliegue completado exitosamente!    ${NC}"
echo -e "${GREEN}══════════════════════════════════════════${NC}"
echo ""
echo -e "  App URL : ${BLUE}${APP_URL_FINAL}${NC}"
echo -e "  DB      : ${BLUE}postgresql://127.0.0.1:5432/${DB_NAME}${NC}"
echo -e "  Logs    : ${BLUE}/var/log/apache2/wms_fenix_*.log${NC}"
echo ""
echo -e "${YELLOW}IMPORTANTE:${NC}"
echo "  1. Guarda el JWT_SECRET del .env en un gestor de contraseñas"
echo "  2. Configura SSL con: certbot --apache -d ${DOMAIN:-tu-dominio.com}"
echo "  3. Configura backup automático con: crontab -e"
echo "     0 2 * * * cd ${APP_DIR} && php scripts/backup.php >> logs/backup.log"
echo ""
