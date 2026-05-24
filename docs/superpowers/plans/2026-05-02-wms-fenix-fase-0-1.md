# Plan de Implementación: Fase 0 + Fase 1 — WMS Fénix

Este plan detalla la construcción del núcleo del sistema, estableciendo las bases del backend modular, la infraestructura multi-tenant y los maestros base para la operación logística.

**Estado:** 🕒 Pendiente de aprobación
**Skill:** `superpowers:writing-plans`

---

## Fase 0: Infraestructura y Setup (Corazón del Sistema)

El objetivo es tener un "Hello World" arquitectónico completo: Backend validado, Frontend conectado, y Auth multi-tenant funcionando.

### Tarea 0.1: Estructura Backend y Base de Datos
- **Descripción:** Inicializar la estructura de carpetas `backend/app/`, configurar SQLAlchemy 2.0 (async), y crear el `BaseModel` con auditoría.
- **Archivos:** `backend/app/core/database.py`, `backend/app/common/models.py`, `backend/app/main.py`.
- **Tests:** Prueba de conexión a DB y creación de tablas iniciales.
- **Verificación:** FastAPI levanta en `/docs` y el pool de conexiones es saludable.

### Tarea 0.2: BaseService y Dependency Injection Multi-tenant
- **Descripción:** Implementar el `BaseService` genérico que inyecta automáticamente el `empresa_id` en todas las queries.
- **Archivos:** `backend/app/common/service.py`, `backend/app/core/security.py`, `backend/app/core/dependencies.py`.
- **Tests:** Mockear un `current_user` y verificar que una query select incluye el `WHERE empresa_id = X`.

### Tarea 0.3: Alembic y Migraciones Iniciales
- **Descripción:** Configurar Alembic para detectar modelos de forma asíncrona y generar la primera migración de las tablas de sistema.
- **Archivos:** `backend/alembic.ini`, `backend/app/alembic/env.py`.
- **Verificación:** `alembic upgrade head` se ejecuta sin errores en PostgreSQL.

### Tarea 0.4: Estructura Frontend y Componentes UI Base
- **Descripción:** Inicializar Vite+TS, configurar Tailwind y crear los componentes atómicos (`Button`, `Input`, `Modal`) bajo el estándar de SLT.
- **Archivos:** `frontend/src/components/ui/`, `frontend/tailwind.config.ts`.
- **Verificación:** Storybook o página de pruebas visuales muestra los componentes con la paleta `#0F4C81`.

### Tarea 0.5: Auth Flow y AppShell (Layout Maestro)
- **Descripción:** Implementar login con JWT, axios interceptors, y el `AppShell` con sidebar colapsable y selector de bodega.
- **Archivos:** `frontend/src/api/client.ts`, `frontend/src/components/wms/AppShell.tsx`, `frontend/src/hooks/useAuth.ts`.
- **Verificación:** El selector de bodega en el topbar persiste el `X-Bodega-Id` en los headers de axios.

---

## 🚩 CHECKPOINT 0: Infraestructura Validada
> Detenerse aquí para verificar que el túnel backend-frontend está limpio y el aislamiento tenant es hermético.

---

## Fase 1: Módulos Maestros (Base Logística)

Implementación de los 4 pilares de configuración del tenant. Cada uno sigue el patrón: Model -> Schema -> Service -> Router -> Frontend (List/Form).

### Tarea 1.1: Módulo Empresas (Tenant Root)
- **Descripción:** CRUD para la configuración de la empresa (Nombre, NIT, Logo, Configuración global).
- **Archivos:** `backend/app/modules/empresas/`, `frontend/src/modules/empresas/`.
- **Verificación:** Solo usuarios con rol SUPERADMIN pueden editar este módulo.

### Tarea 1.2: Módulo Bodegas y Ubicaciones Jerárquicas
- **Descripción:** Gestión de bodegas vinculadas a la empresa y sus ubicaciones (Zona -> Pasillo -> Estante -> Posición).
- **Archivos:** `backend/app/modules/bodegas/`, `backend/app/modules/ubicaciones/`, `frontend/src/modules/bodegas/`.
- **Verificación:** Las ubicaciones deben heredar el `bodega_id` de forma obligatoria. El invariante de integridad referencial es crítico aquí.

### Tarea 1.3: Módulo Usuarios, Roles y Permisos
- **Descripción:** Gestión de usuarios, asignación a múltiples bodegas (`usuario_bodegas`) y RBAC modular.
- **Archivos:** `backend/app/modules/usuarios/`, `frontend/src/modules/usuarios/`.
- **Verificación:** Un usuario que no pertenece a la Bodega A no debe poder ver datos de esa bodega, incluso si pertenece a la misma Empresa.

### Tarea 1.4: SmartGrid y KPIs Maestros
- **Descripción:** Implementar el `SmartGrid` (TanStack Table) con filtros avanzados y la tira de KPIs superior para los módulos creados.
- **Archivos:** `frontend/src/components/wms/SmartGrid.tsx`, `frontend/src/components/wms/KpiStrip.tsx`.
- **Verificación:** Exportación a Excel de la vista filtrada en Empresas y Usuarios.

---

## 🚩 CHECKPOINT 1: Base Operativa Completa
> Demostración de CRUDs completos con multi-tenant y auditoría activa.

---

## Criterios de Aceptación (DoD)
1. **Multi-tenant:** Todo registro creado en Fase 1 tiene `empresa_id` correcto.
2. **Seguridad:** Los endpoints están protegidos por JWT y verifican permisos de bodega.
3. **Calidad:** `pytest` cubre los flujos de creación de Empresa y Usuario.
4. **UI:** El layout es 100% responsivo en móviles de operadores (640px).
5. **Logs:** Todas las mutaciones (POST/PATCH/DELETE) aparecen en el log de auditoría.
