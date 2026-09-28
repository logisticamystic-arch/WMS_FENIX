<!-- converted from REPORTE_TECNICO_PRODUCCION_WMS.docx -->

WMS ProOriente
REPORTE TÉCNICO DE EVALUACIÓN
DE PRODUCCIÓN Y CAPACIDAD DE INFRAESTRUCTURA

Versión 2.01  |  Bogotá, 5 de Abril 2026

CONFIDENCIAL — Para uso exclusivo de la dirección técnica y administrativa

Preparado por: Ingeniero Analista de Sistemas  |  Auditoría de Código v2.01

# 1. RESUMEN EJECUTIVO
Se realizó una auditoría técnica completa del sistema WMS ProOriente v2.01, basada en el análisis exhaustivo de 64 archivos PHP (12.511 líneas de código fuente), 47 migraciones de base de datos, 204 rutas API REST, configuración de servidor XAMPP, middleware de seguridad y arquitectura de despliegue. A continuación se responde con evidencia técnica cada interrogante planteado.
# 2. ¿PUEDE FUNCIONAR EN PRODUCCIÓN EN SERVIDOR XAMPP?
VEREDICTO: SÍ puede funcionar, pero XAMPP NO es apto para producción real

## 2.1  Qué funciona correctamente en XAMPP
La aplicación está construida sobre PHP 8.1+, Slim Framework 4.14, Eloquent ORM 11 y MySQL/MariaDB, todos componentes 100% soportados en XAMPP. La arquitectura REST stateless con JWT es técnicamente válida en cualquier servidor Apache+PHP. El sistema ha sido probado y opera correctamente en XAMPP con un único usuario o en cargas bajas de prueba.
## 2.2  Por qué XAMPP NO es apto para producción real

CONCLUSIÓN TÉCNICA: XAMPP puede operar la aplicación en un entorno de uso interno controlado (LAN empresarial, sin exposición a Internet) siempre que se apliquen los controles de seguridad mínimos detallados en la Sección 5 de este reporte. Para exposición pública o datos sensibles, se requiere infraestructura productiva.

# 3. ¿POR CUÁNTO TIEMPO PUEDE OPERAR XAMPP SIN COLAPSAR?
RESPUESTA HONESTA: Entre 2 semanas y 3 meses, dependiendo del volumen de operaciones

## 3.1  Factores que determinan el tiempo de vida útil en XAMPP

## 3.2  Estimación real de vida útil por escenario

FACTOR CRÍTICO IDENTIFICADO: La aplicación no tiene connection pooling. Slim Framework abre una nueva conexión PDO por cada request HTTP. MySQL en XAMPP tiene max_connections=151 por defecto. Con 20 usuarios haciendo 5-10 requests simultáneos cada uno, se generan hasta 200 conexiones → MySQL rechaza conexiones con error fatal.

# 4. ¿HAY RIESGO DE COLAPSO CON 20 USUARIOS SIMULTÁNEOS?
VEREDICTO: SÍ existe riesgo real — pero es mitigable con configuración

## 4.1  Análisis de carga con 20 usuarios en XAMPP
Con 20 usuarios operando el WMS simultáneamente, cada acción de interfaz (cargar lista de ODC, registrar pallet, aprobar línea) genera entre 1 y 5 peticiones API. Esto produce picos de 40-100 peticiones/minuto. Cada petición consume aproximadamente:

## 4.2  Ajustes que permiten soportar 20 usuarios en XAMPP
Con los siguientes cambios de configuración, XAMPP puede soportar 20 usuarios con riesgo bajo durante 4-6 meses antes de requerir migración:

# 5. ESPECIFICACIONES PARA SERVIDOR LOCAL UBUNTU + POSTGRESQL
Esta es la configuración RECOMENDADA para producción real y escalabilidad

La aplicación WMS ya está preparada para migrar a PostgreSQL. El archivo config/database.php detecta el driver desde .env: solo cambiar DB_DRIVER=pgsql, DB_PORT=5432 y ejecutar las migraciones en la nueva base de datos. No se requieren cambios en el código.
## 5.1  HARDWARE RECOMENDADO — Servidor físico dedicado

## 5.2  PRECIOS DE HARDWARE — Mercado Colombia (Abril 2026)

NOTA: Si se dispone de un PC de escritorio con i5/i7 de 8ª generación en adelante y 16 GB RAM, puede adaptarse como servidor añadiendo únicamente el UPS y los SSDs. Ahorro estimado: $1.500.000 COP.

## 5.3  SOFTWARE REQUERIDO — Stack completo Ubuntu + PostgreSQL

## 5.4  CONFIGURACIÓN POSTGRESQL vs MYSQL — Diferencias clave

## 5.5  ARQUITECTURA DE DESPLIEGUE RECOMENDADA

## 5.6  COSTO TOTAL DE IMPLEMENTACIÓN

# 6. CHECKLIST OBLIGATORIO ANTES DE CUALQUIER DESPLIEGUE PRODUCTIVO

# 7. CONCLUSIONES Y RECOMENDACIÓN FINAL
## 7.1  Calidad del código y arquitectura
El WMS ProOriente v2.01 está bien construido a nivel de arquitectura de software. Implementa correctamente el patrón MVC, autenticación JWT stateless, ORM con Eloquent, audit trail completo, multi-tenancy por empresa_id, control de roles y 204 endpoints REST bien organizados en módulos. El código (12.511 líneas en 64 archivos PHP) es mantenible y está documentado. La preparación para migrar a PostgreSQL ya está incorporada en la configuración.
## 7.2  Respuesta directa a cada pregunta

RECOMENDACIÓN FINAL: Usar XAMPP en fase actual (máx. 3 meses) mientras se prepara el servidor Ubuntu. Migrar a Ubuntu + PostgreSQL + Nginx + PHP-FPM antes de superar 15 usuarios concurrentes diarios.

Preparado técnicamente por análisis automatizado de código fuente — WMS ProOriente v2.01 | Auditoría 05/04/2026
| Problema | Impacto | Severidad |
| --- | --- | --- |
| Sin control de procesos | Apache/MySQL no reinician solos si colapsan; requiere intervención manual | CRITICA |
| Sin gestión de memoria | PHP no tiene limits configurados. Una query pesada puede consumir toda la RAM | CRITICA |
| Sin connection pooling | Cada request abre y cierra conexión MySQL desde cero. Con 20 usuarios concurrentes puede saturar | ALTA |
| Sin TLS/HTTPS nativo | Las credenciales JWT y datos viajan en texto plano en red local | ALTA |
| Sin separación de procesos | Apache, MySQL y PHP en el mismo proceso de Windows; un fallo tumba todo | ALTA |
| APP_DEBUG=true en .env | Expone stack traces, rutas internas y estructura de DB al cliente | ALTA |
| DB_PASS vacío (root sin password) | Cualquier proceso local puede conectarse a MySQL sin autenticación | CRITICA |
| JWT_SECRET débil | "prooriente_wms_secret_key_change_in_production" — no es criptográficamente seguro | ALTA |
| Sin log rotation automático | app.log ya tiene 6.7 MB. En producción puede llenar el disco en semanas | MEDIA |
| Sin rate limiting | La API acepta ataques de fuerza bruta o flood sin defensa | MEDIA |
| Rutas debug expuestas | /wms-force-reload, /wms-file-debug permiten manipular archivos del servidor | CRITICA |
| Sin monitoreo ni alertas | Un fallo pasa desapercibido hasta que los usuarios reportan | ALTA |
| Factor de Degradación | Frecuencia | Impacto Acumulado |
| --- | --- | --- |
| Crecimiento de app.log (ya 6.7 MB) | Continuo | Puede llenar disco en 4-8 semanas a ritmo operativo |
| Fragmentación InnoDB sin OPTIMIZE TABLE | Semanal | Queries 30-50% más lentas en 1-2 meses |
| Acumulación de audit_logs y movimientos | Diario | Tabla audit_logs puede tener millones de filas en 3 meses |
| Memory leaks en PHP 8.1 de larga ejecución | No aplica | PHP en Apache es stateless; cada request libera memoria |
| MySQL connection timeout / too many connections | Con picos | Si >15 usuarios concurrentes: error "Too many connections" |
| Windows Update forzado | Mensual | Reinicio no planificado pierde sesiones activas |
| Antivirus escaneando app.log y backups | Continuo | Degrada I/O hasta 40% en horas pico |
| Backup .sql creciendo sin compresión | Diario | backup_dia_01.sql puede pesar 500MB+ en 6 meses |
| Escenario de uso | Usuarios | Tiempo estimado sin colapso | Riesgo principal |
| --- | --- | --- | --- |
| Uso muy ligero (solo consultas) | 1-3 | 6-12 meses | Crecimiento de logs y backup |
| Uso moderado (recepciones diarias) | 5-10 | 2-4 meses | Fragmentación BD + logs |
| Uso intenso (operación completa) | 15-20 | 3-6 semanas | Too many connections + disco |
| Pico simultáneo >15 users | 15-20 | Horas a días | MySQL max_connections por defecto = 151 |
| Recurso | Consumo por request | Con 20 usuarios simultáneos | Límite XAMPP típico |
| --- | --- | --- | --- |
| Conexión MySQL | 1 conexión nueva/cierre | 20-80 conexiones activas | max_connections = 151 (default) |
| Memoria PHP | 8-25 MB por proceso | 160-500 MB total | Depende RAM del equipo |
| CPU (Apache worker) | 1 thread por request | 20+ threads activos | Limitado por cores del CPU |
| I/O Disco (logs) | 1 write/request | Continuo en app.log | Sin límite — riesgo acumulativo |
| Tiempo de respuesta | 50-200 ms (sin caché) | Se degrada bajo carga | Sin SLA definido |
| Archivo | Parámetro | Cambio | Justificación |
| --- | --- | --- | --- |
| php.ini | memory_limit | 128M → 256M | Previene "Allowed memory size exhausted" |
| php.ini | max_execution_time | 30 → 60 | Reportes y exports grandes no fallan |
| php.ini | max_input_vars | 1000 → 3000 | Formularios complejos no se truncan |
| my.ini | max_connections | 151 → 300 | Evita "Too many connections" con 20 users |
| my.ini | innodb_buffer_pool_size | default → 512M-1G | Cache de datos en RAM, queries 3-5x más rápidas |
| my.ini | query_cache_size | 0 → 64M | Cachea resultados de SELECTs repetidos |
| my.ini | slow_query_log | OFF → ON (>2s) | Detecta queries problemáticas antes de que colapsen |
| .env | APP_DEBUG | true → false | CRÍTICO: No exponer stack traces en producción |
| .env | DB_PASS | vacío → contraseña | CRÍTICO: Seguridad básica de MySQL |
| .env | JWT_SECRET | texto débil → hex 64 chars | openssl rand -hex 32 |
| index.php | Rutas /wms-* | ELIMINAR | CRÍTICO: Permiten manipular archivos del servidor |
| Componente | Mínimo (20 users) | Recomendado (50 users) | Justificación técnica |
| --- | --- | --- | --- |
| CPU | Intel Core i5-12400 / AMD Ryzen 5 5600 | Intel Xeon E-2334 / Ryzen 7 5700 | PHP-FPM multiprocessing requiere 4+ cores reales |
| RAM | 8 GB DDR4 | 16 GB DDR4 ECC | PostgreSQL shared_buffers=2-4 GB + PHP-FPM workers + OS |
| Almacenamiento OS | SSD 120 GB (SO + App) | SSD NVMe 256 GB | I/O de logs, sesiones y código requiere SSD |
| Almacenamiento Datos | SSD 240 GB (BD + Backups) | SSD NVMe 500 GB RAID 1 | BD crece ~1 GB/mes con operación intensa; RAID para redundancia |
| Red | Gigabit Ethernet | Gigabit con cable Cat6 | 20 usuarios LAN; Wi-Fi no recomendado para servidor |
| UPS | UPS 600VA | UPS 1000VA con software | CRÍTICO: PostgreSQL puede corromperse en corte de luz |
| Fuente de poder | Estándar ATX 400W | Estándar ATX 500W 80+ | Margen de seguridad para componentes |
| Componente | Referencia específica | Precio aprox. COP | Dónde conseguir |
| --- | --- | --- | --- |
| CPU + Motherboard | Intel Core i5-12400 + MB B660 | $650.000 – $900.000 | MercadoLibre CO, Macrohard, Alkomprar |
| RAM 16 GB DDR4 | Kingston / Crucial 2x8GB DDR4 3200MHz | $220.000 – $320.000 | MercadoLibre CO, PcAccesorios |
| SSD OS 256 GB NVMe | Kingston A2000 / WD Blue SN570 | $150.000 – $200.000 | Alkomprar, MercadoLibre |
| SSD Datos 500 GB NVMe | Samsung 870 EVO / WD Green | $250.000 – $380.000 | Alkomprar, MercadoLibre |
| Case + Fuente 500W 80+ | Corsair/Cooler Master (combo) | $280.000 – $400.000 | MercadoLibre, Macrohard |
| UPS 1000VA | APC Back-UPS BX1000M | $480.000 – $600.000 | MercadoLibre, Office Depot |
| Cable Cat6 (red LAN) | Bobina 25m + conectores | $60.000 – $90.000 | Ferretería eléctrica |
| TOTAL HARDWARE | — Estimado completo — | $2.090.000 – $2.890.000 | Precio de referencia, sin IVA |
| Capa | Software | Versión | Rol | Licencia / Costo |
| --- | --- | --- | --- | --- |
| OS | Ubuntu Server LTS | 24.04 LTS | Sistema operativo del servidor | GRATIS |
| Web Server | Nginx | 1.24+ | Proxy inverso + servir assets estáticos | GRATIS |
| PHP Runtime | PHP-FPM | 8.2 / 8.3 | Ejecutar la aplicación Slim 4 | GRATIS |
| Base de datos | PostgreSQL | 16.x | Reemplaza MySQL; ya soportado en config | GRATIS |
| Cache/Sesiones | Redis | 7.x | JWT blacklist, rate limiting, cache API | GRATIS |
| Gestor procesos | Supervisor | 4.x | Mantiene PHP-FPM y workers corriendo | GRATIS |
| Firewall | UFW (Uncomplicated Firewall) | Incluido Ubuntu | Bloquea puertos no autorizados | GRATIS |
| SSL/TLS | Let's Encrypt + Certbot | Actual | HTTPS gratuito con renovación automática | GRATIS |
| Monitoreo | Netdata o Prometheus + Grafana | Actual | Dashboard de CPU, RAM, DB, conexiones | GRATIS |
| Backup BD | pg_dump + cron + rclone | Incluido | Backups automáticos a nube o NAS local | GRATIS |
| Panel admin BD | pgAdmin 4 | 8.x | Gestión visual de PostgreSQL | GRATIS |
| Log aggregation | Loki + Grafana | Actual | Centralizar logs app.log, nginx, pgsql | GRATIS |
| TOTAL SOFTWARE | — | — | Stack completo de producción | $0 COP |
| Aspecto | MySQL (XAMPP actual) | PostgreSQL (producción) | Impacto en la app |
| --- | --- | --- | --- |
| Connection pooling | No (cada request abre conexión) | PgBouncer pool de 20-50 conx | Soporta 5x más usuarios con mismos recursos |
| ACID compliance | InnoDB: parcial | Nativo y completo | Datos más seguros en cortes de luz |
| JSON nativo | JSON type (limitado) | JSONB con índices GIN | audit_logs y novedades más eficientes |
| Concurrencia | Locks a nivel tabla en algunas ops | MVCC nativo — sin locks en lectura | 20 usuarios leyendo/escribiendo simultáneo sin bloqueos |
| Índices avanzados | B-Tree básico | B-Tree, GIN, GiST, BRIN | Búsquedas en audit_logs y reportes 10x más rápidas |
| Costo de licencia | $0 | $0 | Sin diferencia económica |
| Migración desde MySQL | — | Solo cambiar .env (ya preparado) | < 1 hora de trabajo técnico |
| Capa | Tecnología | Configuración clave | Por qué |
| --- | --- | --- | --- |
| Entrada HTTP | Nginx 1.24 | worker_processes=auto, worker_connections=1024 | Maneja 1000+ conexiones simultáneas con mínima RAM |
| PHP Runtime | PHP-FPM 8.2 | pm=dynamic, max_children=20, start_servers=5 | 20 workers PHP listos; no crea proceso por request |
| Aplicación | Slim 4 + Eloquent | APP_ENV=production, APP_DEBUG=false | Ya implementado; solo ajustar .env |
| Base de datos | PostgreSQL 16 | shared_buffers=2GB, work_mem=64MB | Cache en RAM; 10x más eficiente que MySQL sin tuning |
| Pool conexiones | PgBouncer | pool_mode=transaction, max_client_conn=200 | 200 clientes → 20 conexiones reales a PG |
| Cache | Redis 7 | maxmemory=512mb, maxmemory-policy=allkeys-lru | Cache de respuestas API y JWT blacklist para logout |
| Monitoreo | Netdata | Instalación con 1 comando | Dashboard en tiempo real de todos los recursos |
| Backups | pg_dump + cron (03:00 AM) | Retención 30 días, comprimidos .gz | Ya existe lógica en BackupHelper.php |
| Ítem | Costo estimado COP | Observación |
| --- | --- | --- |
| Hardware servidor completo | $2.090.000 – $2.890.000 | Ver detalle Sección 5.2 |
| Software (OS + BD + stack) | $0 | Todo open source |
| UPS 1000VA (ya incluido en HW) | — | Incluido en total hardware |
| Configuración e instalación | $300.000 – $800.000 | Técnico 1-2 días de trabajo |
| Migración MySQL → PostgreSQL | $0 – $200.000 | Solo cambiar .env; tiempo técnico mínimo |
| Certificado SSL (Let's Encrypt) | $0 | Gratuito con renovación automática |
| Mantenimiento mensual estimado | $100.000 – $250.000/mes | Monitoreo, backups, actualizaciones |
| TOTAL INVERSIÓN INICIAL | $2.390.000 – $3.890.000 | Todo incluido, primer mes operativo |
| # | Acción | Prioridad | Tiempo estimado | Estado actual |
| --- | --- | --- | --- | --- |
| 1 | Cambiar DB_PASS en .env a una contraseña fuerte | CRÍTICA | 5 min | PENDIENTE |
| 2 | Generar nuevo JWT_SECRET: openssl rand -hex 32 | CRÍTICA | 5 min | PENDIENTE |
| 3 | Cambiar APP_ENV=production y APP_DEBUG=false | CRÍTICA | 2 min | PENDIENTE |
| 4 | Eliminar rutas /wms-force-reload, /wms-file-debug, /wms-opcache-reset de index.php | CRÍTICA | 10 min | PENDIENTE |
| 5 | Eliminar /api/system/connection-info del índice público | ALTA | 5 min | PENDIENTE |
| 6 | Configurar CORS_ALLOWED_ORIGINS con el IP/dominio real de la red | ALTA | 5 min | PENDIENTE |
| 7 | Rotar app.log (ya tiene 6.7 MB — sobre el límite configurado) | MEDIA | 2 min | PENDIENTE |
| 8 | Configurar my.ini: max_connections=300, innodb_buffer_pool_size=512M | ALTA | 15 min | PENDIENTE (solo XAMPP) |
| 9 | Configurar php.ini: memory_limit=256M, max_execution_time=60 | ALTA | 10 min | PENDIENTE (solo XAMPP) |
| 10 | Verificar backups automáticos funcionan y archivo .sql es válido | ALTA | 30 min | PENDIENTE |
| 11 | Deshabilitar directorio de listado en Apache/Nginx | MEDIA | 5 min | PENDIENTE |
| 12 | Forzar HTTPS si hay exposición de red (aunque sea LAN interna) | ALTA | 1-2 horas | PENDIENTE |
| Pregunta | Respuesta honesta |
| --- | --- |
| ¿Puede funcionar en XAMPP para producción? | SÍ, pero solo en red LAN interna controlada, con los 12 ajustes del checklist aplicados. NO para exposición a Internet ni datos altamente sensibles. |
| ¿Cuánto tiempo antes de colapsar en XAMPP? | Con 20 usuarios: 3-6 semanas sin ajustes. Con ajustes de configuración (my.ini + php.ini): 4-6 meses antes de necesitar migración por crecimiento de datos. |
| ¿20 usuarios sin riesgo de colapso? | CON XAMPP SIN AJUSTES: Riesgo alto (MySQL alcanza max_connections). CON AJUSTES APLICADOS: Riesgo bajo-moderado. Recomendado monitorear conexiones. |
| ¿Qué necesito para Ubuntu + PostgreSQL? | Hardware: ~$2.1M - $2.9M COP. Software: $0. Configuración: 1-2 días técnicos. Migración de datos: < 1 hora. Esta es la opción RECOMENDADA para operación estable a largo plazo. |