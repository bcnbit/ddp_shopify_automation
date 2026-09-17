# Shopify Product Studio

Backoffice en Laravel para preparar fichas de producto de Dies de Platja y enviarlas a
Shopify **siempre como borrador**. La aplicación ayuda a generar descripciones, SEO,
metadatos, tags y ALT de imágenes, pero los datos comerciales reales (composición, medidas,
precio, certificaciones, disponibilidad) los introduce o valida una persona.

El sistema propone; una persona aprueba. La publicación automática queda fuera del alcance.

## Estado del proyecto

| Fase | Contenido | Estado |
|---|---|---|
| RFC-0000 | Visión, alcance y decisiones | Redactado |
| RFC-0001 | Fundación técnica, seguridad y modelo de datos | **Implementado** |
| RFC-0002 | Flujo de alta, edición y aprobación | Pendiente |
| RFC-0003 | IA, SEO y contenido | Pendiente |
| RFC-0004 | Integración Shopify e idempotencia | Pendiente |
| RFC-0005 | Medios, variantes y validaciones | Pendiente |
| RFC-0006 | Pruebas y aceptación | Pendiente |
| RFC-0007 | Despliegue y operación | Pendiente |

La documentación funcional está en `docs/rfc/`. Cada RFC se completa con pruebas antes de
avanzar a la siguiente; si una decisión posterior cambia un contrato, se crea una RFC de
enmienda en lugar de modificar en silencio un flujo ya implementado.

## Stack

- Laravel 12, PHP 8.3+, MySQL 8
- Filament 4 como backoffice (Blade/Livewire cuando haga falta interfaz a medida)
- Redis para colas y caché en producción (`database` en local)
- `spatie/laravel-permission` para roles y permisos
- HTMLPurifier para sanitizar el HTML de contenido
- Shopify Admin GraphQL API y OpenAI Responses API, **aún no integradas**

## Puesta en marcha

Requisitos: PHP 8.3+ con `pdo_mysql`, `mbstring`, `gd`, `intl`, `zip`, `fileinfo`; Composer;
MySQL 8. Redis es opcional en local.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Crea la base de datos y ajusta las credenciales en `.env`:

```sql
CREATE DATABASE shopify_product_studio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

El panel queda en `http://localhost:8000/admin`.

`php artisan migrate --seed` crea los roles y permisos y genera el primer administrador
técnico. Si no defines `PRODUCT_STUDIO_ADMIN_PASSWORD`, se genera una contraseña aleatoria
que **se muestra una única vez por consola**; guárdala, no se vuelve a mostrar. Para
fijarla de antemano (mínimo 12 caracteres):

```bash
PRODUCT_STUDIO_ADMIN_PASSWORD='...' PRODUCT_STUDIO_ADMIN_EMAIL='tu@correo' php artisan db:seed --class=AdminUserSeeder
```

En local también se ejecuta `DemoProductSeeder`, que crea una ficha ficticia con variantes,
medios y contenido de ejemplo. Nunca se ejecuta fuera de `local`.

## Usuarios y permisos

| Rol | Puede |
|---|---|
| Operadora | Crear y editar sus fichas, subir medios, generar propuestas y enviar borradores |
| Responsable de catálogo | Todo lo anterior sobre cualquier ficha, aprobar contenido y configurar plantillas |
| Administrador técnico | Credenciales, reglas, usuarios, reintentos y auditoría |

La autorización se resuelve con **Policies**, no ocultando botones: una operadora no puede
publicar ni aunque invoque la acción directamente por HTTP. Un usuario sin rol, o
desactivado, no entra al panel.

El segundo factor (TOTP con códigos de recuperación) es obligatorio para los administradores
técnicos y opcional para el resto. El secreto se guarda cifrado con `APP_KEY`, por lo que
**rotar `APP_KEY` obliga a reconfigurar el 2FA de cada administrador**.

## Colas

Los trabajos de generación y sincronización (fases posteriores) usan colas separadas:
`ai`, `shopify` y `media`. En local basta con:

```bash
php artisan queue:work
```

En producción se usa Redis:

```env
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
```

`REDIS_CLIENT=predis` es el valor por defecto porque la extensión `phpredis` no está
disponible en Windows; en servidores Linux puede usarse `phpredis`.

Laravel Horizon **no** está instalado: requiere `ext-pcntl`, que no existe en Windows. Es una
decisión de operación que corresponde a RFC-0007.

## Pruebas

```bash
composer test          # o: php artisan test
php artisan test --filter=ProductPolicyTest
```

La suite usa SQLite en memoria (ver `phpunit.xml`) y no necesita MySQL ni Redis. Para
validarla contra MySQL, crea una base de datos de pruebas y ejecuta:

```bash
DB_CONNECTION=mysql DB_DATABASE=shopify_product_studio_test DB_USERNAME=root php artisan test
```

## Seguridad

- Ningún secreto se versiona ni se guarda en base de datos: los tokens de Shopify y OpenAI
  se leen del entorno y los placeholders de `.env.example` están vacíos.
- Los canales de log llevan un procesador que elimina credenciales, prompts con imágenes y
  HTML completo (`App\Support\Audit\SecretRedactingProcessor`).
- La auditoría registra actor, evento, entidad, diff estructurado e IP, y pasa siempre por el
  mismo redactor de secretos.
- El HTML de contenido se sanitiza con una lista blanca estricta
  (`p`, `ul`, `ol`, `li`, `strong`, `em`, `br`, `h2`, `h3`) mediante un cast de Eloquent, de
  modo que no se puede guardar HTML sucio ni desde la IA ni desde una edición manual.
- Los medios admiten JPG, PNG y WebP hasta 20 MB; **SVG se rechaza** y el MIME se valida por
  contenido, no por extensión.

## Documentación de la fase

`docs/implementation/RFC-0001.md` describe qué se implementó, las decisiones tomadas y lo que
queda pendiente.