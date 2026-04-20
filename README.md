# EMTA Backend (Laravel)

Backend API para EMTA, construido con Laravel 12. Incluye autenticación por tokens (Sanctum), multi-tenant por cabecera, selección de idioma por usuario/tenant y carga automática del modelo de datos desde un Excel.

## Funcionalidades principales

- API CRUD genérica por tabla y endpoints directos por tabla
- Activación del plan y gestión de niveles de alerta
- Gestión de roles, grupos operativos y asignaciones
- Notificaciones y confirmaciones de disponibilidad
- Multi-tenant con selección automática de idioma

## Requisitos

- PHP 8.2+
- Composer
- MySQL 8+ (p. ej. XAMPP)
- Extensiones PHP: `pdo_mysql`
- Node.js (opcional, solo si vas a usar Vite para recursos web)

## Instalación rápida

1) Instalar dependencias:

```bash
composer install
```

2) Crear `.env`:

```bash
copy .env.example .env
php artisan key:generate
```

3) Configurar base de datos en `.env` (valores por defecto del proyecto):

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=emta_db
DB_USERNAME=root
DB_PASSWORD=
```

4) Asegurar que el Excel existe (necesario para crear tablas del diccionario y poblar datos):

- `emta-backend/260114 AppTabs.xlsx` (preferido)
- o `../260114 AppTabs.xlsx` (alternativa)

Si usas CSVs adicionales:

- `emta-backend/CSV/Criterios_alerta.csv`

5) Migrar y seed:

```bash
php artisan migrate:fresh --seed --force
```

6) Levantar el servidor:

```bash
php artisan serve
```

La API queda disponible en `http://127.0.0.1:8000`.

## Multi-tenant e idioma

- Tenant: se resuelve en este orden:
  1) Cabecera `X-Tenant-ID` (o `X-Tenant-Id`)
  2) `tenant_id` del usuario autenticado
  3) Primer tenant existente (si hay tabla `tenants`)
- Idioma (locale): se resuelve en este orden:
  1) `language` del usuario (si está habilitado para el tenant)
  2) `default_language` del tenant (si está habilitado)
  3) Fallback `es`

## Autenticación (Sanctum)

- Login devuelve un token Bearer para usar en requests autenticadas.

### Login (ejemplo)

```bash
curl -X POST "http://127.0.0.1:8000/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -H "X-Tenant-ID: Morell" \
  -d "{\"email\":\"test@example.com\",\"password\":\"password\"}"
```

La respuesta incluye `token` y `user`.

## Endpoints principales (API v1)

### Idiomas

- `PUT /api/v1/tenant/languages` (auth)
- `PUT /api/v1/user/language` (auth)

### Listado de tablas del diccionario

- `GET /api/v1/tables` (auth)

### CRUD por tabla (genérico)

Para operar sobre las tablas del diccionario cargadas desde el Excel:

- `GET /api/v1/crud/{table}` (auth)
- `POST /api/v1/crud/{table}` (auth)
- `GET /api/v1/crud/{table}/one?...pk` (auth)
- `PUT /api/v1/crud/{table}/one?...pk` (auth)
- `DELETE /api/v1/crud/{table}/one?...pk` (auth)
- `GET /api/v1/schema/{table}` (auth) devuelve columnas, PK (incluida compuesta) y columna tenant

### CRUD por tabla (rutas independientes)

Se generan rutas fijas por cada tabla bajo `api/v1/db/...` (auth):

- `GET /api/v1/db/{tabla}`
- `POST /api/v1/db/{tabla}`
- `GET /api/v1/db/{tabla}/one?...pk`
- `PUT /api/v1/db/{tabla}/one?...pk`
- `DELETE /api/v1/db/{tabla}/one?...pk`
- `GET /api/v1/db/{tabla}/schema`

Notas:
- `{tabla}` y columnas se trabajan en minúsculas.
- Para `one`, las PK se envían como query params. En PK compuesta, se envían todas.
- Si la tabla tiene `tenant_id` (o una columna que termina en `tenant_id`), se aplica filtro automático por tenant.

## Postman

Colección lista para importar:

- `postman/EMTA-Backend.postman_collection.json`

Incluye:
- Login (guarda `token` en variable de colección)
- Endpoints de idiomas
- Listado de tablas
- CRUD genérico y CRUD por rutas fijas (`/db/...`)

## Scripts y calidad

- Formato (Pint):

```bash
php vendor/bin/pint
```

- Tests:

```bash
php artisan test
```

## Estructura relevante

- API v1: `app/Http/Controllers/Api/V1`
- Middleware tenant/locale: `app/Http/Middleware`
- Migraciones/seeders: `database/migrations`, `database/seeders`
- Colección Postman: `postman/`

## Troubleshooting

- `could not find driver` al migrar:
  - habilitar `pdo_mysql` en PHP, o usar el PHP de XAMPP que ya lo incluye.
- Error cargando el Excel:
  - verifica que `260114 AppTabs.xlsx` está en `emta-backend/` o en la carpeta padre.
- Si cambias el Excel:
  - vuelve a ejecutar `php artisan migrate:fresh --seed --force` para regenerar tablas y datos.

## Deploy (producción)

1) Configurar `.env`:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://tu-dominio`
- Ajustar `DB_*`, `MAIL_*`, `QUEUE_CONNECTION` y `FILESYSTEM_DISK`

2) Instalar dependencias y caches:

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

3) Migraciones:

```bash
php artisan migrate --force
```

4) Storage y assets:

```bash
php artisan storage:link
```

5) Colas (si `QUEUE_CONNECTION=database`):

```bash
php artisan queue:work --daemon
```

## WhatsApp + correo (Admin)

El sistema permite seleccionar canal de notificación por tenant desde Admin:

- `email`
- `whatsapp`
- `both`

Campos de configuración:

- `notifications_email_enabled`
- `notifications_sms_enabled`
- `notifications_channel`
- `notifications_production_mode`
- `test_notification_emails`
- `test_notification_whatsapp_numbers`
- `test_notification_sms_numbers`

### Variables `.env` para WhatsApp

```env
NOTIFICATIONS_WHATSAPP_PROVIDER=brevo
BREVO_API_KEY=
BREVO_WHATSAPP_SENDER=
BREVO_WHATSAPP_API_URL=https://api.brevo.com/v3/whatsapp/sendMessage
WHATSAPP_WEBHOOK_URL=
```

### Variables `.env` para SMS

```env
NOTIFICATIONS_SMS_PROVIDER=brevo
BREVO_API_KEY=
BREVO_SMS_SENDER=
BREVO_SMS_API_URL=https://api.brevo.com/v3/transactionalSMS/sms
SMS_WEBHOOK_URL=
```

Valores posibles de `NOTIFICATIONS_WHATSAPP_PROVIDER`:

- `brevo`: envío directo por API de Brevo WhatsApp.
- `webhook`: envía a `WHATSAPP_WEBHOOK_URL` para integrar otro proveedor.
- `none`: deshabilita WhatsApp sin tocar correo.

Valores posibles de `NOTIFICATIONS_SMS_PROVIDER`:

- `brevo`: envío directo por API de Brevo SMS transaccional.
- `webhook`: envía a `SMS_WEBHOOK_URL` para integrar otro proveedor.
- `none`: deshabilita SMS sin tocar correo/WhatsApp.

## Hostinger + Brevo (plan básico)

1) Subir backend y ejecutar migraciones:

```bash
php artisan migrate --force
```

2) Configurar `.env` de producción:

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://tu-dominio`
- `DB_*`, `MAIL_*`, `BREVO_API_KEY`, `NOTIFICATIONS_WHATSAPP_PROVIDER`, `BREVO_WHATSAPP_SENDER`

3) Optimizar y cachear:

```bash
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

4) En Admin del tenant:

- Seleccionar canal `email`, `whatsapp` o `both`.
- Habilitar/deshabilitar SMS.
- En PRUEBA cargar correos, WhatsApp y/o SMS de prueba según canal y toggles.

5) Requisitos Brevo para WhatsApp:

- Número de remitente WhatsApp aprobado.
- API key activa.
- Si el plan no habilita WhatsApp API, usar `NOTIFICATIONS_WHATSAPP_PROVIDER=webhook` con un bridge externo.
