# Handoff — Shopify Product Studio

Documento de traspaso. Resume el estado **real y verificado** del repositorio, cómo
funciona lo que ya existe, y qué falta.

Está pensado para **revisar funcionalidades y planificar nuevas**: la **sección 5** inventaría
lo que hace hoy la aplicación, la **6** mapea el código, y la **12** lista los huecos por
donde ampliarla.

- **Repositorio:** `D:\laragon\www\ddpshopify`
- **Fecha:** 2026-09-17
- **Acceso local:** http://ddpshopify.test/admin
- **Suite:** `360 passed (814 assertions)`
- **Rama:** `main`, sincronizada con `origin/main`
- **Último commit:** `f676a23 Update con publicación en Shopify`
- **Árbol de trabajo:** solo `handoff.md` modificado (esta actualización), pendiente de commit

---

## 1. Objetivo del proyecto

Backoffice web en Laravel para preparar fichas de producto de **Dies de Platja** y
enviarlas a Shopify **siempre como borrador**. La aplicación ayuda a generar
descripciones, SEO, metadatos, tags y ALT de imágenes mediante IA, pero **los datos
comerciales reales los introduce o valida una persona**.

El sistema propone; una persona aprueba. La publicación automática queda fuera del alcance.

Principios no negociables (RFC-0000):

- No se reutiliza código PureBasic ni se asume que exista una aplicación anterior.
- La información física y comercial confirmada prevalece sobre cualquier sugerencia de IA.
- La IA **no puede inventar** composición, certificaciones, origen, medidas,
  disponibilidad, precio ni condiciones de envío.
- **Toda sincronización inicial con Shopify termina en `DRAFT`.**
- Un error de integración debe ser recuperable e **idempotente**: no se duplican
  productos, imágenes ni variantes.
- El panel está pensado para una operadora no técnica y se usa en castellano.

---

## 2. Stack y entorno

| Elemento | Valor |
|---|---|
| Laravel | 12.69.2 |
| PHP (CLI) | 8.3.27 |
| PHP (Apache) | **8.4.25** (distinto del CLI, ver avisos) |
| MySQL | 8.4.3 |
| Backoffice | Filament 4.13.2 |
| Colas | Redis en producción; `database` en local |
| IA | **OpenRouter**, tras `App\Contracts\Ai\AiClient` |
| Shopify | Admin GraphQL API, **versión `2026-07`** |

### Acceso local

| Usuario | Rol |
|---|---|
| `admin@diesdeplatja.test` | Administrador técnico |
| `rosa51@example.com` | Operadora |

**Las contraseñas no se documentan aquí a propósito**: este archivo está versionado en git
y el proyecto exige que ningún secreto llegue a un archivo versionado.

Para fijar una contraseña conocida del administrador (mínimo 12 caracteres):

```powershell
$env:PRODUCT_STUDIO_ADMIN_PASSWORD='<tu-clave>'
php artisan db:seed --class=AdminUserSeeder --force
```

Sin esa variable, el seeder genera una aleatoria que **se muestra una única vez por consola**.

> El admin exige configurar **2FA** (TOTP) antes de dejarte ver nada más: te redirige al
> perfil y el resto del panel queda cerrado. Para trastear sin eso, usa la operadora.

### Avisos del entorno (importantes)

- **Apache usa PHP 8.4.25 y el CLI 8.3.27.** Un fallo puede reproducirse en web y no en
  consola, o al revés. Si algo solo falla en el navegador, sospecha de esta diferencia.
- **La herramienta `apply_patch` NO está disponible.** Editar con PowerShell:
  `[IO.File]::WriteAllText($path, $content, (New-Object System.Text.UTF8Encoding($false)))`
  con here-strings `@'...'@`. Verificar el ancla con `$raw.Contains($old)` antes de
  reemplazar y pasar `php -l` después.
- **Los archivos usan finales de línea LF, no CRLF.** Un ancla con `` `r`n `` falla.
- **Cuidado con los escapes en here-strings.** `` `t `` dentro de una here-string de
  PowerShell se interpreta como **tabulador** y corrompe el texto en silencio. Para texto
  literal, usar comillas simples o concatenar con `[char]9`.
- **En Laravel, una variable de entorno definida pero VACÍA anula el valor por defecto del
  código**, no lo hereda. `FOO=` hace que `env('FOO', 'default')` devuelva `''`. Por eso
  `PRODUCT_STUDIO_PURIFIER_CACHE_PATH` va comentada en `.env.example`. Al añadir una clave,
  comprueba si tiene default en `config/` antes de dejarla vacía.
- **Shell:** `powershell` 5.1. `pwsh` 7.6.5 disponible con el parámetro `shell: pwsh`.
  `foreach (Get-ChildItem ...)` es error de sintaxis; escribir `foreach ($f in (...))`.
- **Composer:** `php D:\laragon\bin\composer\composer.phar` (no está en el PATH).
- **MySQL:** `D:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe`, usuario `root`,
  contraseña vacía, `127.0.0.1:3306`.
- **Redis no está arrancado** en local (puerto 6379 cerrado) → `QUEUE_CONNECTION=database`.
- **Horizon no se puede instalar** en Windows: requiere `ext-pcntl`. Decisión de RFC-0007.
- Bases de datos: `shopify_product_studio` (desarrollo) y `shopify_product_studio_test`.
- **NO usar `migrate:fresh`** (rechazado por destructivo). Usar `php artisan migrate --seed`
  o `php artisan db:seed --class=X`.
- Las pruebas usan SQLite `:memory:` por defecto (`phpunit.xml`); también se validan contra
  MySQL con variables de entorno.
- Zona horaria `Europe/Madrid`, locale `es`.

---

## 3. Configuración de Laragon (ya aplicada)

El vhost estaba mal y se corrigió:

```
D:\laragon\etc\apache2\sites-enabled\auto.ddpshopify.test.conf
  define ROOT "D:/laragon/www/ddpshopify/public"     <- DEBE terminar en /public
```

Copia del original en `auto.ddpshopify.test.conf.bak`.

**Por qué importa:** con `DocumentRoot` en la raíz del proyecto y sin `.htaccess` allí,
`http://ddpshopify.test/.env` serviría `APP_KEY`, la contraseña de MySQL y los tokens de
Shopify y OpenRouter. Ahora `/.env` devuelve **404**.

**Apache no recarga los vhosts solo.** Apache no está instalado como servicio, así que
`-k restart` no funciona; hay que parar y arrancar el proceso:

```powershell
$exe  = 'D:\laragon\bin\apache\httpd-2.4.68-260617-Win64-VS18\bin\httpd.exe'
$root = 'D:/laragon/bin/apache/httpd-2.4.68-260617-Win64-VS18'
& $exe -d $root -f 'conf/httpd.conf' -t          # validar sintaxis SIEMPRE antes
Get-Process httpd | Stop-Process -Force
Start-Process -FilePath $exe -ArgumentList '-d', $root -WindowStyle Hidden
```

Y `APP_URL=http://ddpshopify.test` en `.env`: con el valor anterior las URLs firmadas de las
imágenes apuntaban a `localhost:8000` y salían rotas.

---

## 4. Estado de las fases

| Fase | Contenido | Estado |
|---|---|---|
| RFC-0000 | Visión, alcance y decisiones | Redactado |
| RFC-0001 | Fundación, seguridad y modelo de datos | **Implementado** (`docs/implementation/RFC-0001.md`) |
| RFC-0002 | Flujo de alta, edición y aprobación | **Implementado** (falta doc de fase) |
| RFC-0003 | IA, SEO y contenido (OpenRouter) | **Implementado** (falta doc de fase) |
| RFC-0004 | Integración Shopify e idempotencia | **Implementado** (`docs/implementation/RFC-0004.md`) |
| RFC-0008 | Mantenimientos de ficha técnica | **Implementado** (`docs/rfc/RFC-0008-…`) |
| RFC-0009 | Conexión con Shopify por OAuth | **Implementado** (`docs/implementation/RFC-0009.md`) |
| RFC-0005 | Medios, variantes y validaciones | Parcial (dentro de RFC-0001/0002) |
| RFC-0006 | Pruebas y aceptación | No iniciado |
| RFC-0007 | Despliegue y observabilidad | No iniciado |

Falta solo: el **job de publicación** (que el propio RFC-0004 sitúa fuera del MVP) y los
documentos de fase de RFC-0002 y RFC-0003.
---

## 5. Inventario de funcionalidades

Lo que la aplicación hace **hoy**, para revisarlo y decidir qué ampliar.

### 5.1 Panel de fichas (`/admin/products`)

**Listado** con pestañas: Todas · Pendientes · En curso · Aprobadas · Enviadas a Shopify ·
Con errores. Filtros por estado, tipo, público, creada por, colección, fecha, con errores y
listas para enviar. Métricas arriba: fichas creadas, pendientes, tiempo medio a borrador y
con errores.

**Formulario en tres pestañas:**

- *Datos verificados* — identificación (referencia, nombre, tipo, público, marca), datos
  comerciales (precio, precio anterior, moneda) y ficha técnica (composición, ajuste,
  colección, cuidados, observaciones).
- *Contenido comercial* — la propuesta generada, con avisos.
- *SEO y Shopify* — handle y GID del producto remoto.

**Guardado automático** cada 5 segundos y al cambiar de sección. Es idempotente y no crea
versiones de contenido nuevas.

**Acciones de la ficha** (todas con confirmación y rastro en auditoría):

| Acción | Qué hace | Quién |
|---|---|---|
| Generar propuesta | Encola la generación con IA | Operadora |
| Propuesta sin IA | Crea una propuesta local desde los datos confirmados | Operadora |
| Regenerar un campo | Reescribe un solo campo sin tocar los demás | Operadora |
| Restaurar propuesta | Recupera una versión aprobada como versión nueva | Operadora |
| Aprobar ficha | Habilita el envío como borrador | Responsable |
| Enviar como borrador | Encola el envío a Shopify | Operadora |
| Exportar JSON | Descarga la ficha completa, sin credenciales | Operadora |

El botón de envío pasa a **«Actualizar borrador»** cuando la ficha ya tiene producto remoto.

**Pestañas de relación:** Imágenes (subir, ordenar arrastrando, marcar principal, editar ALT,
borrar), Variantes (matriz Color × Talla) y Sincronizaciones con Shopify (historial de
intentos con causa y botón de reintento).

### 5.2 Generación con IA (`app/Services/Ai`)

Envía a OpenRouter **solo** los datos confirmados y hasta 4 fotos redimensionadas. La
respuesta se valida contra un esquema JSON estricto; si no cumple, se rechaza.

- Perfiles de contenido por familia (`ContentProfile`): camiseta, sudadera, bolso, infantil.
- Comprobador de afirmaciones prohibidas: «algodón orgánico», «hecho en España», «unisex»,
  medidas, certificados… si no constan en los datos confirmados.
- Límite diario de generaciones por ficha (10 por defecto), configurable.
- Guarda modelo, versión de prompt, tokens y latencia por ejecución.
- **No aprueba nada**: deja la ficha en *En revisión*.

### 5.3 Sincronización con Shopify (`app/Services/Shopify`)

- Estado siempre `DRAFT`, fijado **dentro del gateway**, no recibido de fuera.
- Idempotencia en tres capas: GID conocido → metafield `product_studio_id` → handle.
- Medios en dos pasos (`stagedUploadsCreate` → subir → `fileCreate` → asociar), con ALT.
- Un medio cuyo `sha256` ya tiene GID no se vuelve a subir.
- Cada intento queda en `sync_attempts` con causa legible y referencia de soporte.
- El reintento **continúa** desde donde quedó; no reinicia.
- Errores transitorios (límite de llamadas, 5xx) se propagan para que la cola aplique
  backoff; los definitivos (token inválido) no.

### 5.4 Comandos de consola

```bash
> **Desde RFC-0009 el token ya no se escribe en `.env`.** La vía normal es el panel:
> **Configuración → Conexión con Shopify → Conectar con Shopify**. Ahí se instala por OAuth, se
> guarda el token cifrado y se comprueba la conexión de sólo lectura (dominio, token, scopes y
> acceso a productos). `shopify:check` sigue existiendo para soporte y despliegues sin navegador.

php artisan shopify:check                        # diagnostica sin modificar nada
php artisan shopify:sync DDP-14666 --dry-run     # ver qué enviaría
php artisan shopify:sync DDP-14666               # enviar como borrador
```

`shopify:sync` existe porque el panel **encola**, y en local sin worker el trabajo se queda
en la tabla `jobs`. Ejecuta el **mismo servicio** que el job, no una vía alternativa.

### 5.5 Seguridad y auditoría

- Autorización **solo** por Policies (ocultar botones no es la barrera).
- Tres roles: operadora, responsable de catálogo, administrador técnico.
- Auditoría propia (`activity_log`) con diff estructurado, IP y redacción de secretos.
- HTML sanitizado con lista blanca estricta antes de guardar.
- 2FA obligatorio para administradores técnicos.
- Ningún secreto se versiona ni se persiste: todo se lee del entorno.

---

## 6. Mapa del código

### Puntos de extensión habituales

| Quiero… | Tocar |
|---|---|
| Añadir un campo a la ficha | Migración + `$fillable` + `casts()` en `Product`, `ProductForm`, y `ProductResource::editableProductAttributes()` |
| Añadir una acción al panel | `EditProduct::getHeaderActions()` |
| Cambiar el prompt o las reglas de la IA | `app/Support/Ai/PromptBuilder.php`, `ContentProfile.php`, `ProhibitedClaimsChecker.php` |
| Añadir una validación | `app/Support/Products/ProductValidator.php` (bloqueante o aviso) |
| Añadir una llamada a Shopify | `ShopifyOperations` (el documento) + el gateway |
| Añadir un permiso | `app/Enums/Permission.php` (`byRole()` es la única fuente de verdad) |
| Añadir un rol | `app/Enums/Role.php` + `Permission::byRole()` |
| Cambiar textos del panel | `app/Filament/**` y `resources/views/filament/**` |

### Estructura

```
app/
  Console/Commands/      shopify:check, shopify:sync
  Contracts/             AiClient, ShopifyProductGateway  (fronteras del dominio)
  DataObjects/           DTOs de IA y Shopify
  Enums/                 ProductStatus, Permission, Role, SyncStatus…
  Exceptions/            AiRequestFailed, ShopifyRequestFailed (retryable/permanent)
  Filament/              recurso de fichas: páginas, esquema, tabla, widgets, paneles
  Http/Middleware/       EnsurePrivilegedUsersHaveTwoFactor, AssignsRequestId
  Jobs/                  GenerateProductContentJob, SyncProductToShopifyJob
  Models/                Product, ProductVariant, ProductMedia, ProductContent,
                         SyncAttempt, ActivityLog, User
  Policies/              autorización (la única fuente de verdad)
  Services/
    Ai/                  OpenRouterClient, NullAiClient, ProductGenerationService
    Products/            ProductService, ProductContentService, ProductMediaService,
                         ProductVariantService
    Shopify/             ShopifyOperations (GraphQL), ShopifyGraphQlClient (transporte),
                         ShopifyFileUploader, ShopifyProductGatewayImpl, ProductSyncService
  Support/
    Ai/                  PromptBuilder, ContentProfile, GenerationLimiter, ImagePreparer…
    Audit/               ActivityRecorder, SecretRedactor, SecretRedactingProcessor
    Media/               MediaRules
    Products/            ProductValidator, ProductReadiness, VariantMatrix, ProductLock,
                         IdempotencyKey, SkuNormalizer
    Security/            HtmlSanitizer, SanitizesHtml
```

### Dónde vive cada regla de negocio

- **Estados y transiciones** → `app/Enums/ProductStatus.php` (`allowedTransitions()`).
- **Qué impide enviar** → `app/Support/Products/ProductValidator.php`.
- **Desde qué estados se puede enviar** → `app/Support/Products/ProductReadiness.php`.
- **Quién puede hacer qué** → `app/Policies/*` + `app/Enums/Permission.php`.

Las cuatro están separadas a propósito: el botón, la validación y la Policy no pueden
discrepar porque beben de las mismas fuentes.

---

## 7. Errores encontrados y corregidos

### 7.1 Carga diferida (`LazyLoadingViolationException`) — el más importante

**Síntoma:** el panel fallaba al abrir una ficha y, después, al añadir la segunda imagen:

```
Attempted to lazy load [product] on model [App\Models\ProductVariant]
Attempted to lazy load [product] on model [App\Models\ProductMedia]
```

**Causa:** las Policies autorizan **por fila** (`$user->can('update', $media->product)`) y
Filament carga las filas de las tablas de golpe. Los hijos no traían el padre cargado. En
local `Model::preventLazyLoading()` está activo (en producción no).

**Solución:** `chaperone('product')` en las **cuatro** relaciones de `Product`:
`variants()`, `media()`, `contents()`, `syncAttempts()`.

**Detalle crítico:** `Builder::hydrate()` (línea 472) sólo propaga la prohibición de carga
diferida **cuando la consulta devuelve más de un registro**:

```php
if (count($items) > 1) {
    $model->preventsLazyLoading = Model::preventLazyLoading();
}
```

Consecuencias: (a) con **una sola** fila el fallo no se reproduce, lo que explica por qué la
primera imagen se subía bien y la segunda no; y (b) **cualquier prueba de regresión de este
tipo necesita al menos dos filas**, o no protege nada.

### 7.2 Mensaje de error engañoso al enviar una ficha ya en curso

El guard de `ProductSyncService` decía «La ficha tiene errores bloqueantes que impiden
enviarla» ante **cualquier** motivo. Era falso cuando la ficha ya estaba en `syncing`: no hay
ningún error, simplemente ya se está enviando. Ahora distingue archivada / ya enviándose /
bloqueantes reales (indicando cuáles).

### 7.3 Caché de HTMLPurifier con nombre inválido

`HtmlSanitizer` usaba `'SerializerCache'`, que no existe: HTMLPurifier avisaba y caía a un
caché sin directorio, reconstruyendo su definición en cada proceso (varios segundos por
petición). Corregido a `'Serializer'`, con `mkdir` si falta y comprobación de `is_writable`.

### 7.4 Rutas de almacenamiento en colisión

`local`, `media` y `media-derived` declaraban `'serve' => true` sin `url`, así que **los tres
calculaban la misma URI `/storage`**. Sobrevivía una (`storage.media-derived`) y
`Storage::disk('media')->temporaryUrl()` lanzaba `Route [storage.media] not defined`. Como
`ImageColumn` cae en silencio al `url()` plano, las miniaturas pedían `/storage/...` y la
ruta superviviente las servía desde el disco equivocado con firma obligatoria → **403 mudo**.
Se dio a cada disco su propia URI en `config/filesystems.php`.

### 7.5 El seeder de demo creaba medios sin archivo

`DemoProductSeeder` creaba la fila en `product_media` con una ruta inventada, pero **nunca
escribía el archivo**. La ficha parecía completa en el panel, pero al enviarla fallaba con
«no se encuentra el archivo de imagen». Ahora genera un JPEG real de 1200×1200.

### 7.6 Versión de API retirada en `.env`

`.env` fijaba `SHOPIFY_API_VERSION=2025-01`, **retirada**. El default del código ya era
`2026-07`, pero `.env` lo sobrescribía. Corregido, más 7 claves que faltaban.

### 7.7 Otros

| Error | Corrección |
|---|---|
| URL con barra final → HTTP 404 | `Http::baseUrl($endpoint)->post('')` producía `.../graphql.json/`. Se añadió `endpoint()` |
| `findByStudioId` pasaba `studioId` como handle | El respaldo por handle se movió a `findProductGid()` |
| Verificación del metafield era un no-op | Ahora la consulta pide `metafield.value` y se compara |
| `mediaGids()` devolvía `[]` | Los GID de medios nunca se persistían |
| `array_unique` sobre arrays anidados | «Array to string conversion» |
| Firma incompatible de `canViewForRecord` | El padre exige `Model`, no `Product` |
| Vhost apuntando a la raíz del proyecto | Riesgo de exponer `.env` |

---

## 8. Operación diaria

### El worker de cola no está corriendo (causa habitual de «no pasa nada»)

`QUEUE_CONNECTION=database` y **no hay ningún worker activo**. Tanto «Generar propuesta»
como «Enviar como borrador» **encolan**: sin worker, el trabajo se queda en la tabla `jobs`
y la ficha se queda en *Generando* o *Sincronizando* indefinidamente.

```bash
php artisan queue:work --queue=ai,shopify,media
```

**`queue:work` sin `--queue` escucha solo `default`**, y los trabajos van a `ai`, `shopify` y
`media`. Es el fallo más fácil de cometer aquí.

Para ejecutar uno suelto:

```bash
php artisan queue:work --queue=ai --once
```

Para IA y Shopify hay alternativa síncrona sin worker: `shopify:sync` (Shopify) y la
generación se puede lanzar con `php artisan tinker` llamando a `ProductGenerationService`.

### Comprobar el estado

```bash
php artisan shopify:check
```

```sql
-- trabajos pendientes y fallidos
SELECT id, queue, attempts FROM jobs;
SELECT id, queue, LEFT(exception, 120) FROM failed_jobs;
```

### Comandos

```bash
# Instalación
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed            # NO usar migrate:fresh
php artisan storage:link

# Ejecución
php artisan serve                     # alternativa a Laragon
php artisan queue:work --queue=ai,shopify,media

# Pruebas
php artisan test --compact
php artisan test --filter=ShopifyGatewayTest
DB_CONNECTION=mysql DB_DATABASE=shopify_product_studio_test DB_USERNAME=root php artisan test

# Formato
php vendor\bin\pint
```

---

## 9. Pruebas

- **Suite completa: `360 passed (814 assertions)`** (al inicio de la sesión: 305 / 670).
- `ShopifyGatewayTest` — **17 pruebas**: contrato del conector (HTTP falso).
- `ProductSyncServiceTest` — **23 pruebas**: orquestación (gateway falso).
- `ShopifyCommandsTest` — **13 pruebas**: comandos de consola.
- `pint --test`: pasa.

### Las pruebas NO detectan los fallos de carga diferida por defecto

`AppServiceProvider.php:88` desactiva `preventLazyLoading` en pruebas. Los errores de carga
diferida **solo aparecían en la aplicación real**. Las pruebas de regresión lo activan a
propósito:

```php
Model::preventLazyLoading();
try { ... } finally { Model::preventLazyLoading(false); }
```

Y necesitan **al menos dos filas** (ver §7.1). Si añades una relación nueva con Policy por
fila, aplica `chaperone('product')` desde el principio.

### Verificar que una prueba nueva protege de verdad

Un test que pasa **con el bug presente** no protege nada. Ocurrió una vez en este proyecto
(ver §7.1). El método: revertir el código a mano y confirmar que falla.

Se hizo con las afirmaciones críticas y las tres fallaron como debían: pasar `null` en vez
del GID, no persistir los GID de variante, y no marcar `SyncFailed`. **Repite este ejercicio
al añadir reglas de negocio nuevas.**

### Anomalía conocida (benigna, sin resolver)

Una prueba de la suite tarda **~15,1 s**. Se acotó a la **primera llamada a
`Storage::fake()`** del proceso. Es **dependiente de la posición**, no de la prueba, y en
aislamiento tarda 0,7 s. Hipótesis: arranque en frío del proceso en Windows (antivirus /
primera E/S). No afecta a la corrección, pero conviene entenderlo antes de montar CI.

---

## 10. Datos de demo actuales

| id | Referencia | Estado | Variantes | Imágenes | Contenido |
|---|---|---|---|---|---|
| 1 | `DDP-57151` | En revisión | 0 | 0 | 0 |
| 2 | `DDP-14666` | Aprobada | 4 | 1 | 1 |
| 10 | `DDP-TS-03` | En revisión | 0 | 3 | 1 |

Colas: **0 pendientes, 0 fallidos**.

### Las imágenes de `DDP-TS-03` no son de una camiseta

Se verificó abriendo el archivo: son **gafas de sol** (1280×744, con la etiqueta «LENTES
POLARIZADAS»). Son fotos de otro producto que quedaron en esa ficha al probar la subida.

Merece la pena saberlo porque la generación con IA **se comportó correctamente**: lo detectó
y lo puso como primer aviso de la propuesta.

> «Las 3 imágenes adjuntas muestran gafas de sol, no una camiseta: no coinciden con el
> producto de la ficha.»

Además de otros tres avisos: sin color confirmado, sin colección y sin tallas. La descripción
generada habla de la camiseta (que es lo que dicen los datos confirmados) sin inventar sobre
lo que ve en las fotos. Es exactamente el comportamiento que pide el RFC-0003.

---

## 11. Hallazgos verificados de la API de Shopify

Se consultó la documentación vigente antes de implementar. **No asumir de memoria:**

1. **`2025-01` está retirada.** La última estable es **`2026-07`**. Shopify retira una
   versión cada trimestre y, ante una no soportada, responde con la más antigua accesible:
   el contrato dejaría de ser predecible.
2. **`productSet` es la mutación vigente recomendada**: sustituye a `productCreate` +
   `productUpdate` + `productVariantsBulkCreate`.
   - Admite `identifier: {id}` o `{handle}` → idempotencia sin consulta previa.
   - **Es destructiva con los campos de lista**: hay que reenviar *todas* las variantes y
     *todos* los medios en cada llamada, o Shopify borra los que falten.
3. **`productCreate` solo crea la primera variante** — motivo adicional para `productSet`.
4. **Medios en dos pasos:** `stagedUploadsCreate` → subir → `fileCreate` →
   `productSet(files: [{originalSource: <gid>, alt: ...}])`.
   - El archivo debe subirse **el último** en el multipart: la firma solo es válida si el
     resto de campos la preceden.
   - `duplicateResolutionMode: APPEND_UUID` es necesario para que el ALT se aplique.
5. **El filtrado de metafields por servidor solo es fiable si el metafield está declarado
   `adminFilterable`.** Si no, Shopify **ignora el filtro y devuelve productos
   cualesquiera** → una ficha podría sobrescribir el borrador de otra. Por eso el valor se
   **verifica por cliente**.
6. Los metafields de la app (`$app`) quedan ocultos de la Storefront API por defecto, lo que
   satisface «no visible para la tienda».

### Credenciales y OAuth (RFC-0009)

7. **Prefijos de credenciales de Shopify.** Los *access token* —offline y online— empiezan por
   `shpat_`; los de delegado, por `shppa_`. La `shpss_` es la **API secret key** de la
   aplicación y **no** se envía en `X-Shopify-Access-Token`: la Admin API la rechaza.
8. **Token offline:** en la URL de autorización se **omiten** `grant_options[]`. Añadirlos con
   `per-user` devolvería un token *online*, que caduca con la sesión de la persona.
9. **Canje:** `POST https://{shop}.myshopify.com/admin/oauth/access_token` con `client_id`,
   `client_secret`, `code` y `expiring` opcional.
10. **Tokens expirables:** son obligatorios para las **apps públicas** antes del 1 de enero de
    2027, y la exigencia **no aplica a las *custom apps***. Por eso el valor por defecto aquí es
    un token no expirable; `SHOPIFY_OAUTH_EXPIRING` lo cambia, pero **el refresco no está
    implementado** (ver §12.4).
11. **Validación del callback:** quitar `hmac`, ordenar los parámetros alfabéticamente como
    `k=v`, unirlos con `&` y calcular `HMAC-SHA256` en hexadecimal con la API secret key,
    comparando en tiempo constante. El `state` se compara además contra el de la sesión.
12. **Scopes de archivos:** `stagedUploadsCreate` + `fileCreate` exigen `write_files`.

---

## 12. Por dónde ampliar

Huecos identificados, ordenados por relación valor/esfuerzo. **Nada de esto está
implementado.**

### 12.1 Coherencia entre producto y fotos (detectado hoy)

La IA detectó que las fotos eran de gafas y la ficha era una camiseta, **pero eso se
descubrió después de gastar una llamada**. Se podría validar antes:

- Comparar `product_type` con lo que la IA ve en las fotos antes de generar.
- O simplemente, en el panel, avisar cuando las dimensiones son anómalas para una prenda
  (las tres fotos eran 1280×744, apaisadas).

Encaja en RFC-0005.

### 12.2 Diferencias entre lo que pide el RFC y lo implementado

- **RFC-0005 — miniaturas y copias optimizadas:** `config/media.php` ya define
  `thumbnails` y `optimized` (400×400 y 2048 px), y el disco `media-derived` existe, pero
  **no hay código que los genere**. Hoy se sube a Shopify el original tal cual.
- **RFC-0005 — duplicado perceptual:** el índice único `(product_id, sha256)` detecta
  duplicados exactos, no visualmente similares.
- **RFC-0005 — orientación:** no se comprueba.
- **RFC-0003 — FAQ opcionales** por perfil de contenido: mencionados, no implementados.
- **RFC-0003 — regeneración parcial:** existe `regenerateField`, pero conviene revisar si
  cubre todos los campos.
- **RFC-0002 — guardado automático:** se guarda cada 5 s y al cambiar de sección, pero no
  hay indicador visual de «guardado»/«guardando».

### 12.3 Funcionalidad nueva que no está en ningún RFC

- **Inventario:** el MVP no envía cantidades (`initial_inventory_quantity` es `null`). Si se
  quiere stock real, hay que decidir política y añadir la llamada.
- **Traducciones:** `product_content` ya tiene `locale` (es/ca/en/fr) y `Product` tiene
  `audience`, pero **solo se genera en español**.
- **Edición masiva:** excluida del MVP explícitamente.
- **Duplicar ficha:** útil para crear variantes de un mismo producto (otro color). No existe.
- **Categoría de Shopify** (`product_category_taxonomy_id`): el campo existe y se envía, pero
  no hay interfaz para elegirla.
- **Poda de `sync_attempts`:** sin retención; crecerá sin límite.

### 12.4 Deuda técnica conocida

- **Refresco de tokens expirables (RFC-0009):** `SHOPIFY_OAUTH_EXPIRING=true` pide un token que
  caduca en 60 minutos y **no hay job de refresco**. Con el valor por defecto (`false`) el token
  no expira y no hace falta. Bloquea sólo una futura distribución pública de la aplicación.
  El `refresh_token` tampoco se guarda: añadirlo es una columna más.
- **Webhook `app/uninstalled` (RFC-0009):** si se desinstala desde Shopify, la fila local
  permanece; la pantalla seguirá diciendo «conectada» hasta que una comprobación devuelva 401.
- **Job de publicación:** fuera del MVP por decisión del RFC. La Policy ya existe
  (`ProductPolicy::publish`) pero `ShopifyOperations` **no tiene** la mutación
  (`productUpdate(status: ACTIVE)` / `publishablePublish`). Habría que verificarla contra la
  documentación vigente, como se hizo con `productSet`.
- **Reordenado de imágenes:** la prueba cubre el servicio, no el gesto de arrastrar.
- **Sin CI:** no hay pipeline. El primer paso sería el `15 s` de §9.
- **`README.md` raíz:** desactualizado (dice que RFC-0002/0003/0004 están pendientes).

### 12.5 Pendientes menores

- [ ] Documentos de fase `docs/implementation/RFC-0002.md` y `RFC-0003.md`.
- [ ] Actualizar `README.md`.
- [ ] Los 3 avisos de la generación de `DDP-TS-03` siguen sin resolver (fotos equivocadas).

---

## 13. Decisiones que no deben revertirse sin querer

- **La autorización vive en Policies**, no en ocultar botones. Una operadora no puede
  publicar ni aunque invoque la acción directamente por HTTP.
- **`Permission::byRole()`** es la única fuente de verdad de los permisos.
- **El estado `DRAFT` se fija en el gateway**, no se recibe como parámetro.
- **Los originales de medios nunca se borran**; los derivados son regenerables.
- **`sha256` es la identidad del archivo**: evita duplicados y re-subidas.
- **El reintento continúa, no reinicia**: reutiliza los GID ya guardados.
- **La IA no aprueba nada**: solo propone; una persona revisa campo a campo.
- **`sync_attempts` es append-only**: un reintento crea un intento nuevo con la misma clave
  de idempotencia.
- **Ningún secreto se versiona**: las credenciales de aplicación (`shpss_`) y las claves de IA
  viven en el entorno, y el redactor las enmascara en logs y auditoría.
  **Excepción deliberada (RFC-0009):** el access token de Shopify (`shpat_`) **sí** se persiste,
  **cifrado con `APP_KEY`**, porque lo produce un flujo OAuth y pertenece a una instalación.
  de secretos limpia logs y auditoría, y **este documento no contiene credenciales**.
- **Las relaciones padre→hijo que alimentan Policies llevan `chaperone('product')`.**

---

## 14. Contexto de la petición original

El usuario pidió iniciar el proyecto trabajando **solo en RFC-0001**, con entrega final de
archivos, comandos, pruebas, decisiones, pendientes y confirmación explícita antes de
continuar. Después añadió:

1. Usar **OpenRouter** para la conexión con la IA (en lugar de OpenAI).
2. **«Puedes continuar con el resto de las fases sin mi aprobación»** — no hay puerta de
   aprobación entre fases.
3. Que el envío a Shopify se haga **como borrador** en la tienda.
4. Actualmente revisa funcionalidades y **planifica implementar nuevas próximamente**.
