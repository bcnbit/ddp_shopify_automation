# RFC-0010 — Despliegue en producción

**Estado:** Propuesto  
**Tipo:** Nueva (desarrolla RFC-0007)  
**Depende de:** RFC-0001, RFC-0006, RFC-0007, RFC-0009  
**Desarrolla:** RFC-0007 (que queda como declaración de intenciones; esta RFC fija el
procedimiento concreto)

## 1. Motivo

RFC-0007 describía los entornos, la observabilidad y la operación diaria, pero no fijaba un
procedimiento ejecutable. Al preparar el primer despliegue real aparecen dos hechos que obligan a
concretarlo:

1. **El flujo OAuth de RFC-0009 exige una URL de redirección HTTPS alcanzable desde internet.**
   En local eso obliga a un túnel; en un hosting real se resuelve de forma natural. Es el motivo
   inmediato del despliegue.
2. **La protección de `.env` que existe hoy es configuración del servidor local, no del
   repositorio.** No hay ningún `.htaccess` en la raíz del proyecto. En un hosting con el
   `DocumentRoot` mal puesto, `/.env` se sirve como texto plano.

Esta RFC fija **qué tiene que cumplir el entorno, cómo se comprueba y qué queda fuera**, con
especial atención a lo que puede filtrar credenciales.

## 2. Alcance

### Dentro del alcance

- Requisitos de plataforma (PHP, base de datos, procesos en segundo plano, HTTPS).
- Procedimiento de despliegue, ordenado y repetible.
- Variables de entorno que **cambian** respecto a local y cuáles **no deben tocarse**.
- Protección verificable de `.env` y de los ficheros sensibles.
- Comprobaciones previas y posteriores al despliegue.
- Registro del `redirect_uri` de OAuth (RFC-0009).
- Riesgos conocidos y su mitigación.

### Fuera del alcance (explícito)

- **No se implanta observabilidad** (alertas, métricas, trazas). Sigue pendiente.
- **No se migra a S3.** Los medios siguen en disco local, con la limitación declarada en §8.
- **No se monta CI/CD.** El despliegue es manual y documentado.
- **No se cambia el flujo de publicación**: todo sigue terminando en `DRAFT`.
- No se define política de copias de seguridad más allá de exigir que existan (§8).

## 3. Requisitos de plataforma

| Requisito | Valor | Por qué |
|---|---|---|
| PHP | **8.3 o superior** | `composer.json` declara `^8.2`, pero la suite se verifica en 8.3 y 8.4 |
| Extensiones | Las de Laravel 12 (`mbstring`, `openssl`, `pdo_mysql`, `curl`, `fileinfo`) + **`gd`** | `fileinfo` lo usa la validación de medios; `gd` lo usa `ImagePreparer` |
| Base de datos | **MySQL 8** (o MariaDB equivalente) | Producción usa MySQL, no SQLite |
| `DocumentRoot` | **`public/`** | Único modo en que `.env`, `storage/` y `vendor/` quedan fuera del alcance web |
| HTTPS | **Obligatorio** | OAuth de Shopify lo exige; además hay sesiones y credenciales |
| Proceso en segundo plano | **Necesario** | `queue:work` no es opcional: sin él la generación de IA no ocurre |

**Sobre `gd`:** si falta, `ImagePreparer` **no falla**: devuelve la imagen original sin reducir
(`imagecreatefromstring` no existe y lo detecta). La consecuencia no es un error visible, sino
**llamadas a la IA más caras y lentas** de lo previsto, porque se envían fotos de tamaño completo.
Es un fallo silencioso: conviene comprobar `php -m | grep gd` al preparar el servidor.

**Sobre `imagick`:** no se usa. No hace falta instalarlo.

### 3.1 El worker es un requisito, no un extra

La generación de contenido con IA es **siempre** asíncrona (`GenerateProductContentJob`).
En local se puede sobrevivir sin worker porque existe `shopify:sync` para el envío, pero
**no existe comando equivalente para la IA**: sin proceso de cola, pulsar «Generar propuesta» no
produce nada y la ficha se queda en `generando`.

Consecuencia práctica para elegir hosting: **un hosting compartido sin procesos permanentes deja
la aplicación a medias**. Hacen falta `supervisor`, `systemd` o el equivalente del
proveedor.

## 4. Variables de entorno

### 4.1 Las que cambian respecto a local

| Variable | Local | Producción | Nota |
|---|---|---|---|
| `APP_ENV` | `local` | **`production`** | Ver §4.2: no es cosmético |
| `APP_DEBUG` | `true` | **`false`** | Con `true` se filtran rutas, consultas y variables |
| `APP_URL` | `http://ddpshopify.test` | **`https://…`** | De aquí sale el `redirect_uri` de OAuth |
| `LOG_LEVEL` | `debug` | `warning` o `error` | `debug` escribe mucho y puede incluir datos |
| `SESSION_SECURE_COOKIE` | `false` | **`true`** | La cookie de sesión no debe viajar por HTTP |
| `DB_*` | root sin contraseña | Usuario dedicado con contraseña | Nunca `root` |
| `QUEUE_CONNECTION` | `database` | `database` o `redis` | `database` es válido y ya está probado |
| `PRODUCT_STUDIO_MEDIA_DRIVER` | `local` | `local` o `s3` | Ver §8 |
| `MAIL_MAILER` | `log` | Un transporte real | Con `log` no sale ningún correo |

### 4.2 Por qué `APP_ENV=production` importa de verdad

No es una etiqueta. `AppServiceProvider` la consulta para tres cosas:

- `URL::forceScheme('https')`: **es lo que hace que el `redirect_uri` de OAuth se
  construya con HTTPS** aunque la petición llegue por HTTP detrás de un proxy.
- Desactivar `Model::preventLazyLoading()`.
- Desactivar `Model::preventSilentlyDiscardingAttributes()`.

Las dos últimas están **activas en local a propósito** para que un error se vea en desarrollo.
Desplegar con `APP_ENV=local` deja esas comprobaciones encendidas en producción, y
cualquier carga diferida no prevista —en una pantalla que no se ejercitó en pruebas— lanzará una
excepción **en la cara de la operadora**. Es un fallo que no aparece hasta que aparece.

### 4.3 Las que **no** cambian

- `SHOPIFY_SHOP_DOMAIN`: es la tienda (`*.myshopify.com`), no la web. **No se toca.**
- `SHOPIFY_API_KEY` / `SHOPIFY_API_SECRET`: las mismas de la aplicación.
- `SHOPIFY_API_VERSION`: la versión de la API de Shopify.
- `PRODUCT_STUDIO_*` de contenido y validación: son reglas de negocio, no de entorno.

### 4.4 Un aviso sobre variables vacías

Una variable **definida y vacía** en `.env` **anula** el valor por defecto del código, no lo
hereda. `SHOPIFY_API_VERSION=` dejaría la aplicación sin versión de API. Al copiar la
plantilla, las líneas que no se usen se **borran**, no se dejan vacías.

### 4.5 Dónde vive el `.env` de producción

**Nunca en el repositorio.** `.gitignore` ya excluye `.env`, `.env.production`,
`/auth.json` y `/storage/*.key`, y `.env.example` no contiene ningún valor real
(comprobado). El `.env` de producción se crea en el servidor y se protege con permisos de
archivo (`600`) y con el `DocumentRoot` en `public/`.

## 5. Procedimiento de despliegue

Se ejecuta en este orden. Los pasos marcados **[verificar]** tienen comprobación asociada y no se
continúa si fallan.

### 5.1 Preparación

1. Subir el código (`git clone` o `git pull`) a una ruta **fuera** del docroot web.
2. Crear el `DocumentRoot` apuntando a `/public`. **[verificar]** §6.1.
3. Crear la base de datos y un usuario dedicado con contraseña (no `root`).

### 5.2 Instalación

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env      # y editar: ver §4
php artisan key:generate
php artisan migrate --force
php artisan db:seed --class=RoleAndPermissionSeeder --force
php artisan studio:permissions
```

**`--no-dev`** deja fuera PHPUnit, Pint y Faker. No es sólo limpieza: son dependencias que no
deben estar accesibles en producción.

**`RoleAndPermissionSeeder` es obligatorio.** Sin él no existe ningún permiso en la base de
datos, y el panel entero falla al pintar la navegación (es el fallo que documenta RFC-0008).

**`studio:permissions`** confirma que el reparto de `Permission::byRole()` coincide con
la base. Es la comprobación barata de que los permisos están bien.

**Sólo los seeders de catálogo.** `DatabaseSeeder` ejecuta `DemoProductSeeder` cuando el
entorno es `local`. **No usar `db:seed` a secas en producción**: mete fichas ficticias
de demostración en la base real. Sembrar por clase.

### 5.3 Cierre

```bash
php artisan filament:upgrade      # publica los assets del panel (y limpia cachés: ver aviso)
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

**El orden importa.** `filament:upgrade` **no sólo publica assets**: internamente ejecuta
`config:clear`, `route:clear` y `view:clear`. Si se lanza **después** de cachear, borra las cachés
que se acaban de generar y el despliegue queda sin cachear (funciona, pero más lento y sin el
efecto buscado). Por eso va **antes** de los `*:cache`.

**Los assets del panel no están versionados.** `.gitignore` excluye `/public/css/filament`,
`/public/js/filament` y `/public/fonts/filament`. Es correcto, pero significa que en un despliegue
limpio **hay que ejecutar `filament:upgrade`** o el panel se verá sin estilos. Es un paso
obligatorio, no opcional.

**Con la configuración cacheada, cambiar el `.env` no tiene efecto** hasta volver a ejecutar
`config:cache` (o `config:clear`). Es la causa número uno de «he cambiado la variable y
sigue igual».

**`route:cache` y los ficheros servidos.** Se comprobó en este proyecto que las rutas de los
discos con `serve => true` (`storage/media`, `storage/media-derived`, `storage/private`), el
callback de OAuth y las páginas del panel **sobreviven a `route:cache`**: siguen apareciendo en
`route:list` con la caché activa. No hay que evitar la caché de rutas por miedo a perderlas.
Lo que sí conviene es **comprobar el callback después de cachear**, con `php artisan route:list --path=shopify`: si algo cambió en el registro de rutas, un 404 al conectar es el síntoma.
 Las rutas de OAuth se registran dentro del grupo
autenticado del panel (RFC-0009 §6.2), así que se cachean con el resto. Si al desplegar el callback
devuelve 404, el orden correcto es: `route:clear`, comprobar, `route:cache`.

### 5.4 Procesos en segundo plano

`systemd` o `supervisor` con **reintento automático**. El comando:

```bash
php artisan queue:work --queue=ai,shopify,media --tries=3 --timeout=150
```

Sin `--queue` el worker escucha sólo `default` y **no procesa nada**: los trabajos van a
`ai`, `shopify` y `media`. Es el error más fácil de cometer.

Dos parámetros que deben ir en pareja:

- `--timeout` debe superar la duración real del trabajo más largo (hasta 120 s por la
  configuración de IA y de subida de medios).
- el `retry_after` de la cola debe ser **mayor** que ese tiempo, o el trabajo se considerará
  perdido y otro worker lo recogerá **en paralelo**. En local vale el valor por defecto de 90 s y
  eso ya es un riesgo; en producción se fija explícitamente (`DB_QUEUE_RETRY_AFTER=180`).

Tras cada despliegue: `php artisan queue:restart`, para que los workers tomen el código nuevo.

## 6. Protección verificable de ficheros sensibles

### 6.1 Comprobaciones obligatorias

Con el despliegue terminado, y **antes de conectar Shopify**, comprobar:

| Comprobación | Resultado correcto |
|---|---|
| `https://dominio/.env` | **404 o 403** |
| `https://dominio/storage/logs/laravel.log` | **404 o 403** |
| `https://dominio/vendor/` | **404 o 403** |
| `https://dominio/composer.json` | **404 o 403** |
| `https://dominio/.git/config` | **404 o 403** |

Si `/.env` devuelve contenido, **el despliegue se detiene y se corrige el `DocumentRoot`**.
No es una recomendación de estilo: expone `APP_KEY` (con el que se descifra el access token de
Shopify y los secretos TOTP), la contraseña de la base de datos y las claves de IA.

### 6.2 Por qué la protección actual no basta

Hoy la protección de `/.env` existe **en el vhost de Apache de Laragon**, que es
configuración local y no viaja en el repositorio. No hay `.htaccess` en la raíz del proyecto.
Un despliegue que confíe en esa configuración se queda sin ninguna barrera.

La defensa correcta es estructural —`DocumentRoot` en `public/`— y no depende de recordar
crear un fichero. Las comprobaciones de §6.1 son su verificación.

## 7. Conexión con Shopify en producción

Con el despliegue en pie, el flujo de RFC-0009 funciona sin túnel:

1. Verificar que `APP_URL` es `https://dominio` y que `url('/')` devuelve HTTPS.
2. Registrar en el **Dev Dashboard** el `redirect_uri` exacto:
   `https://dominio/admin/shopify/callback`
   — coincidencia literal: sin barra final, con el mismo `www` que `APP_URL`.
3. Comprobar que el administrador técnico tiene el **segundo factor configurado**. Sin él,
   `EnsurePrivilegedUsersHaveTwoFactor` lo redirige a su perfil y **no llega** a la pantalla de
   conexión.
4. Entrar como **administrador técnico** y abrir **Configuración → Conexión con Shopify**.
   Sólo ese rol tiene `settings.manage`; ni la operadora ni el responsable lo tienen (§10).
5. Pulsar **Conectar con Shopify** y aceptar los cuatro permisos.
6. Confirmar el resultado con `php artisan shopify:check`: dominio, token, permisos y acceso a
   productos.

### 7.1 `state` y sesión

El `state` de OAuth viaja en la sesión. En producción, con balanceador y varias instancias,
**la sesión debe ser compartida** (`SESSION_DRIVER=database` o `redis`, nunca `file`),
o el callback fallará al no encontrar el `state`. Con una sola instancia y
`SESSION_DRIVER=database` —la configuración actual— es correcto.

## 8. Riesgos conocidos y limitaciones aceptadas

| Riesgo | Impacto | Mitigación en esta fase |
|---|---|---|
| **Medios en disco local** | Las fotos se pierden si se reconstruye el servidor o se escala a varias instancias | Exigir disco persistente; S3 queda para una fase posterior (`media-s3` ya está configurado) |
| **Sin observabilidad** | Una cola detenida no avisa: las fichas se quedan «generando» | Comprobar `queue:monitor` a mano; el worker con reinicio automático reduce el riesgo |
| **Sin copias de seguridad verificadas** | Pérdida de fichas y de originales | Exigir al proveedor copias de base de datos **y** de `storage/app/media` y `storage/app/media-derived` |
| **Token OAuth caducado** | `SHOPIFY_OAUTH_EXPIRING=true` sin refresco rompe la conexión en 60 min | Mantenerlo en `false` (custom app); documentado en RFC-0009 §4.3 |
| **Desinstalación desde Shopify** | La fila local sobrevive y la pantalla dice «conectada» | Detectable con «Comprobar conexión»; webhook pendiente (RFC-0009) |
| **`APP_KEY` rotada** | Invalida el token OAuth cifrado y los secretos TOTP | Documentado: rotar `APP_KEY` obliga a reinstalar la aplicación |
| **Sin CI** | Nada impide desplegar con la suite en rojo | Ejecutar `php artisan test` antes de desplegar (paso manual) |
| **Sesión no compartida** | Con varias instancias, el callback de OAuth falla por `state` perdido | Exigir un driver de sesión compartido si se escala |

## 9. Comprobaciones posteriores al despliegue

En este orden. Si una falla, no se sigue:

```bash
php artisan test                      # la suite completa, en el servidor o en local contra el mismo commit
php artisan studio:permissions        # debe decir «sincronizados»
php artisan shopify:check             # dominio, token, permisos y acceso a productos
php artisan queue:monitor ai,shopify,media
php artisan migrate --pretend         # ninguna migración pendiente inesperada
```

Y a mano, en el navegador:

1. `https://dominio/up` responde 200.
2. `/.env` responde 404/403 (§6.1).
3. Se entra al panel y se ve el menú **Mantenimientos de ficha técnica** (prueba de que los permisos
   están sembrados).
4. La pantalla **Conexión con Shopify** carga para el administrador técnico.

**Alcance real de `/up`:** en este proyecto **nadie escucha el evento `DiagnosingHealth`**,
así que `/up` sólo confirma que la aplicación arranca. **No comprueba base de datos, cola ni
Shopify**, aunque RFC-0007 lo pedía. La comprobación real de base de datos es el propio panel, y la
de Shopify es `shopify:check`. Añadir esas comprobaciones es trabajo pendiente.

## 10. Autorización en producción

Se reproduce el reparto de `Permission::byRole()` para dejar claro quién puede qué, porque
afecta a la puesta en marcha:

| Acción | Operadora | Responsable | Admin técnico |
|---|---|---|---|
| Crear y editar fichas | Sí | Sí | Sí |
| Generar con IA | Sí | Sí | Sí |
| Aprobar ficha | No | **Sí** | Sí |
| Enviar a Shopify (borrador) | Sí | Sí | Sí |
| Publicar | **No** | **No** | Sí (no implementado) |
| Gestionar mantenimientos | Sí | Sí | Sí |
| **Conectar Shopify** (`settings.manage`) | **No** | **No** | **Sí** |
| Gestionar usuarios | No | No | Sí |

Consecuencia operativa: **el primer acceso debe ser el del administrador técnico**, porque es el
único que puede configurar el 2FA obligatorio y conectar la tienda. Los demás usuarios se crean
después.

## 11. Criterios de aceptación

1. El panel responde por HTTPS y `/.env`, `storage/logs` y `vendor` devuelven 404/403.
2. `php artisan studio:permissions` informa de que el reparto está sincronizado.
3. La pantalla de conexión carga para el administrador técnico **con el 2FA configurado**.
4. El callback de OAuth completa la instalación y `shopify:check` confirma dominio, token,
   permisos y acceso a productos.
5. El worker procesa un trabajo de la cola `ai` de principio a fin: una ficha pasa de
   `generando` a `en revisión` sin intervención manual.
6. Un reinicio del servidor **no** pierde trabajos en cola.
7. Cambiar una variable del `.env` y ejecutar `config:cache` tiene efecto; sin ese paso,
   no lo tiene (comportamiento esperado y documentado).

## 12. Trabajo pendiente que esta RFC deja escrito

Ordenado por relación riesgo/esfuerzo. Nada de esto se implementa aquí:

1. **Comando de verificación previa al despliegue** (`studio:deploy-check`): comprobaría
   `APP_ENV`, `APP_DEBUG`, HTTPS, `.env` inaccesible, permisos sembrados y worker
   activo. Hoy es una lista manual; automatizarlo evita el fallo que más caro sale.
2. **`DiagnosingHealth`** para que `/up` compruebe base de datos y cola de verdad.
3. **Migración de medios a S3** (`media-s3` ya configurado).
4. **Observabilidad y alertas** de RFC-0007.
5. **Policy de retención de `sync_attempts`** y de logs.
6. **CI** que ejecute `pint --test` y la suite antes de permitir el despliegue.
