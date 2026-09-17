# Handoff — Shopify Product Studio

Documento de traspaso para una sesión nueva. Resume el estado **real y verificado**
del repositorio, las decisiones tomadas, los errores ya corregidos y lo pendiente.

- **Repositorio:** `D:\laragon\www\ddpshopify`
- **Fecha del traspaso:** 2026-09-17
- **Acceso local:** http://ddpshopify.test/admin
- **Suite:** `347 passed (772 assertions)`
- **Último commit:** `d86777e Update` — hay **7 archivos modificados y 2 nuevos sin commitear** (§11)

---

## 1. Objetivo del proyecto

Backoffice web en Laravel para preparar fichas de producto de **Dies de Platja** y
enviarlas a Shopify **siempre como borrador**. La aplicación ayuda a generar
descripciones, SEO, metadatos, tags y ALT de imágenes mediante IA, pero **los datos
comerciales reales los introduce o valida una persona**.

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
| PHP (Apache) | **8.4.25** (distinto del CLI, ver aviso) |
| MySQL | 8.4.3 |
| Backoffice | Filament 4.13.2 |
| Colas | Redis en producción; `database` en local |
| IA | **OpenRouter** (sustituye a OpenAI), tras `App\Contracts\Ai\AiClient` |
| Shopify | Admin GraphQL API, **versión `2026-07`** |

### Acceso local

Hay dos usuarios en la base de datos de desarrollo:

| Usuario | Rol |
|---|---|
| `admin@diesdeplatja.test` | Administrador técnico |
| `rosa51@example.com` | Operadora |

**Las contraseñas NO se documentan aquí a propósito**: este archivo está versionado en
git y el proyecto exige que ningún secreto llegue a un archivo versionado. La del
administrador se fijó a mano durante el desarrollo y la de la operadora proviene de la
factory del seeder de demo.

Para fijar una contraseña conocida del administrador (mínimo 12 caracteres):

```powershell
$env:PRODUCT_STUDIO_ADMIN_PASSWORD='<tu-clave>'
php artisan db:seed --class=AdminUserSeeder --force
```

Sin esa variable, el seeder genera una aleatoria que **se muestra una única vez por
consola**.

> El admin exige configurar **2FA** (TOTP) antes de dejarte ver nada más: te redirige al
> perfil y el resto del panel queda cerrado. Para trastear sin eso, usa la operadora.

### Avisos del entorno (importantes)

- **Apache usa PHP 8.4.25 y el CLI 8.3.27.** Son binarios distintos: un fallo puede
  reproducirse en web y no en consola, o al revés. Si algo sólo falla en el navegador,
  sospecha de esta diferencia.
- **La herramienta `apply_patch` NO está disponible.** Editar con PowerShell:
  `[IO.File]::WriteAllText($path, $content, (New-Object System.Text.UTF8Encoding($false)))`
  con here-strings `@'...'@`. Verificar el ancla con `$raw.Contains($old)` antes de
  reemplazar y pasar `php -l` después.
- **Los archivos usan finales de línea LF, no CRLF.** Un ancla con `` `r`n `` falla.
- **En Laravel, una variable de entorno definida pero VACÍA anula el valor por defecto del
  código**, no lo hereda. `FOO=` en `.env` hace que `env('FOO', 'default')` devuelva `''`.
  Por eso `PRODUCT_STUDIO_PURIFIER_CACHE_PATH` va **comentada** en `.env.example`: si se
  descomenta sin valor, HTMLPurifier se queda sin caché y reconstruye su definición en cada
  petición (varios segundos por carga, ver §7.3). Al añadir una clave a `.env.example`,
  comprueba si tiene default en `config/` antes de dejarla vacía.
- **Ojo con las secuencias de escape en here-strings.** `` `t `` dentro de una here-string
  PowerShell se interpreta como **tabulador** y corrompe el texto en silencio. Ya ocurrió
  en `config/filesystems.php` y hubo que repararlo. Para texto literal, usar comillas
  simples o concatenar con `[char]9`.
- **Shell:** `powershell` 5.1. `pwsh` 7.6.5 disponible con el parámetro `shell: pwsh`.
  `foreach (Get-ChildItem ...)` es error de sintaxis; escribir
  `foreach ($f in (Get-ChildItem ...))`.
- **Composer:** `php D:\laragon\bin\composer\composer.phar` (no está en el PATH).
- **MySQL:** `D:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe`, usuario `root`,
  contraseña vacía, `127.0.0.1:3306`.
- **Redis no está arrancado** en local (puerto 6379 cerrado) → `QUEUE_CONNECTION=database`.
- **Horizon no se puede instalar** en Windows: requiere `ext-pcntl`. Decisión de RFC-0007.
- Bases de datos: `shopify_product_studio` (desarrollo) y `shopify_product_studio_test`.
- **NO usar `migrate:fresh`** (rechazado por destructivo). Usar
  `php artisan migrate --seed` o `php artisan db:seed --class=X`.
- Las pruebas usan SQLite `:memory:` por defecto (`phpunit.xml`); también se validan
  contra MySQL con variables de entorno.
- Sin extensión `phpredis` → `REDIS_CLIENT=predis`.
- Zona horaria `Europe/Madrid`, locale `es`.
---

## 3. Configuración de Laragon (ya aplicada)

El vhost estaba mal y se corrigió. Situación actual:

```
D:\laragon\etc\apache2\sites-enabled\auto.ddpshopify.test.conf
  define ROOT "D:/laragon/www/ddpshopify/public"     <- DEBE terminar en /public
```

Copia del original (apuntaba a la raíz del proyecto) en
`auto.ddpshopify.test.conf.bak`.

**Por qué importa:** con `DocumentRoot` en la raíz del proyecto y sin `.htaccess` allí,
`http://ddpshopify.test/.env` serviría `APP_KEY`, la contraseña de MySQL y los tokens de
Shopify y OpenRouter. Ahora `/.env` devuelve **404**.

**Apache no recarga los vhosts solo.** Si añades o editas uno, hay que reiniciar. Apache
no está instalado como servicio, así que `-k restart` no funciona; hay que parar y arrancar
el proceso:

```powershell
$exe  = 'D:\laragon\bin\apache\httpd-2.4.68-260617-Win64-VS18\bin\httpd.exe'
$root = 'D:/laragon/bin/apache/httpd-2.4.68-260617-Win64-VS18'
& $exe -d $root -f 'conf/httpd.conf' -t          # validar sintaxis SIEMPRE antes
Get-Process httpd | Stop-Process -Force
Start-Process -FilePath $exe -ArgumentList '-d', $root -WindowStyle Hidden
```

Y `APP_URL=http://ddpshopify.test` en `.env` (antes `localhost:8000`): con el valor antiguo
las URLs firmadas de las imágenes apuntaban a `localhost:8000` y salían rotas.

---

## 4. Estado de las fases

| Fase | Contenido | Estado |
|---|---|---|
| RFC-0000 | Visión, alcance y decisiones | Redactado |
| RFC-0001 | Fundación, seguridad y modelo de datos | **Implementado** (`docs/implementation/RFC-0001.md`) |
| RFC-0002 | Flujo de alta, edición y aprobación | **Implementado** (falta doc de fase) |
| RFC-0003 | IA, SEO y contenido (OpenRouter) | **Implementado** (falta doc de fase) |
| RFC-0004 | Integración Shopify e idempotencia | **Casi completo** — falta el doc de fase y el job de publicación (fuera del MVP) |
| RFC-0005 | Medios, variantes y validaciones | Parcial (dentro de RFC-0001/0002) |
| RFC-0006 | Pruebas y aceptación | No iniciado |
| RFC-0007 | Despliegue y observabilidad | No iniciado |

---

## 5. Hallazgos verificados de la API de Shopify

Se consultó la documentación vigente antes de implementar. Estos datos **cambian el
diseño** y no deben asumirse de memoria:

1. **`SHOPIFY_API_VERSION=2025-01` estaba retirada** (dejó de ser soportada en enero de
   2026). La última estable es **`2026-07`**. Se cambió el valor por defecto. Shopify
   retira una versión cada trimestre y, ante una versión no soportada, responde con la más
   antigua accesible: el contrato dejaría de ser predecible.
2. **`productSet` es la mutación vigente recomendada** para crear o actualizar: sustituye
   al conjunto `productCreate` + `productUpdate` + `productVariantsBulkCreate`.
   - Admite `identifier: {id: ...}` o `{handle: ...}` -> resuelve la idempotencia sin
     consulta previa.
   - **Es destructiva con los campos de lista**: hay que reenviar *todas* las variantes y
     *todos* los medios en cada llamada, o Shopify borra los que falten.
3. **`productCreate` sólo crea la primera variante** — motivo adicional para `productSet`.
4. **Medios en dos pasos:** `stagedUploadsCreate` -> subir los bytes -> `fileCreate` ->
   asociar con `productSet(files: [{originalSource: <gid>, alt: ...}])`.
   - El archivo debe subirse **el último** en el multipart: la firma de la URL temporal
     sólo es válida si el resto de campos la preceden.
   - `duplicateResolutionMode: APPEND_UUID` es necesario para que el ALT se aplique.
5. **El filtrado de metafields por servidor sólo es fiable si el metafield está declarado
   como `adminFilterable`.** Si no lo está, Shopify **ignora el filtro y devuelve productos
   cualesquiera** -> riesgo de falso positivo que haría que una ficha sobrescribiese el
   borrador de otra. Por eso el valor se **verifica por cliente**.
6. Los metafields propiedad de la app (`$app`) quedan ocultos de la Storefront API por
   defecto, lo que satisface «no visible para la tienda».

---

## 6. Arquitectura implementada (RFC-0004)

- `ShopifyOperations` — **todos** los documentos GraphQL en un único sitio, para que un
  cambio de API no afecte al dominio.
- `ShopifyGraphQlClient` — transporte: versión de API, autenticación y cabeceras de límite
  de llamadas. Traduce 401/403/404/429/430/5xx y `THROTTLED`.
- `ShopifyFileUploader` — subida de medios en dos pasos y espera a `READY`.
- `ShopifyProductGatewayImpl` — implementa el contrato `ShopifyProductGateway`.
- `ProductSyncService` — orquesta el flujo completo y escribe `sync_attempts`.
- `SyncProductToShopifyJob` — trabajo en cola `shopify`.
- `SyncAttemptsRelationManager` — panel «Sincronizaciones con error» con reintento.

### Idempotencia en tres capas

1. Si se conoce el GID, se **actualiza** ese producto (`identifier.id`).
2. Si no, se busca por el metafield privado `product_studio_id` y, como comprobación
   secundaria, por **handle**.
3. Un medio cuyo `sha256` ya tiene GID **no se vuelve a subir**.

El estado se fija dentro del gateway (`'status' => 'DRAFT'`), no se recibe de fuera: es la
garantía de que ninguna ruta puede publicar por accidente.

### Dos niveles de prueba, y por qué

| Nivel | Doble | Qué verifica |
|---|---|---|
| Contrato del conector | HTTP falso (`Http::fake`) | GraphQL bien formado, ALT, traducción de errores |
| Flujo / orquestación | `FakeShopifyGateway` | Cuántas veces se crea, con qué GID, qué pasa si un intento falla |

El segundo existe porque el primero **no puede** responder a «¿dos clics crean dos
productos?»: eso no es un problema de GraphQL sino de orquestación. `FakeShopifyGateway`
registra cada llamada y permite simular fallos (`alwaysFailWith`, `failNextWith`) y que el
producto ya exista (`alreadyExists`).

**Importante:** el gateway real resuelve internamente el producto existente cuando no
recibe GID (`$productGid ??= $this->findProductGid($payload)`). El doble **emula ese
comportamiento**; si no lo hiciera, la prueba de «se perdió la respuesta» no reproduciría
lo que pasa de verdad.

---

## 7. Errores encontrados y corregidos

### 7.1 Carga diferida (`LazyLoadingViolationException`) — el más importante

**Síntoma:** el panel fallaba al abrir una ficha y, después, al añadir la segunda imagen:

```
Attempted to lazy load [product] on model [App\Models\ProductVariant]
Attempted to lazy load [product] on model [App\Models\ProductMedia]
```

**Causa:** las Policies autorizan **por fila** (`$user->can('update', $media->product)`) y
Filament carga las filas de las tablas de golpe. Los hijos (`ProductMedia`,
`ProductVariant`, `ProductContent`, `SyncAttempt`) no traían el padre cargado. En local
`Model::preventLazyLoading()` está activo (en producción no), de ahí que reventara.

**Solución:** `chaperone('product')` en las **cuatro** relaciones de `Product`. Enlaza el
padre en los hijos durante la misma consulta, sin consultas extra.

```php
return $this->hasMany(ProductMedia::class)
    ->orderBy('sort_order')
    ->chaperone('product');
```

**Detalle crítico que hay que recordar:** `Builder::hydrate()` (línea 472) sólo propaga la
prohibición de carga diferida **cuando la consulta devuelve más de un registro**:

```php
if (count($items) > 1) {
    $model->preventsLazyLoading = Model::preventLazyLoading();
}
```

Consecuencias:

- Con **una sola** fila el fallo no se reproduce. Eso explica por qué la primera imagen se
  subía bien y la segunda no.
- **Cualquier prueba de regresión de este tipo necesita al menos dos filas**, o no protege
  nada. La primera prueba que se escribió pasaba con el bug presente justo por esto.

### 7.2 Mensaje de error engañoso al enviar una ficha ya en curso

El guard de `ProductSyncService` decía, ante **cualquier** motivo por el que no
procedía enviar:

> «La ficha tiene errores bloqueantes que impiden enviarla.»

Eso era **falso** cuando el motivo real era otro. En concreto, al pulsar dos veces
«Enviar», la ficha ya está en `syncing`: no hay ningún error, simplemente ya se está
enviando. El mensaje llevaba a buscar un problema inexistente.

Ahora distingue los tres casos:

- Ficha archivada.
- Ficha que **ya se está enviando** («Espera a que termine para volver a intentarlo»).
- Bloqueantes reales, e incluye **cuáles** son (hasta tres) en el propio mensaje.

### 7.3 Caché de HTMLPurifier con nombre inválido

`HtmlSanitizer` usaba `'SerializerCache'`, que no existe: HTMLPurifier avisaba y caía a un
caché sin directorio, reconstruyendo su definición en cada proceso (varios segundos por
petición). Corregido a `'Serializer'`, con `mkdir` si falta y comprobación de `is_writable`.

### 7.4 Rutas de almacenamiento en colisión

`local`, `media` y `media-derived` declaraban `'serve' => true` sin `url`, así que **los tres
calculaban la misma URI `/storage`** y competían por la misma ruta. Sobrevivía sólo una
(`storage.media-derived`), y `Storage::disk('media')->temporaryUrl()` lanzaba
`Route [storage.media] not defined`. Como `ImageColumn` cae en silencio al `url()` plano
cuando eso falla, las miniaturas pedían `/storage/...` y la ruta superviviente las servía
desde el disco equivocado con firma obligatoria -> **403 mudo**. Se dio a cada disco su
propia URI en `config/filesystems.php`.

### 7.5 Otros errores corregidos

| Error | Corrección |
|---|---|
| URL con barra final -> HTTP 404 | `Http::baseUrl($endpoint)->post('')` producía `.../graphql.json/`. Se añadió `endpoint()` y `post($this->endpoint(), ...)` |
| `findByStudioId` pasaba `studioId` como handle | El respaldo por handle se movió a `findProductGid()` |
| Verificación del metafield era un no-op | Ahora la consulta pide `metafield.value` y se compara directamente |
| `mediaGids()` devolvía `[]` | Los GID de medios nunca se persistían. Ahora `prepareMedia()` devuelve `byMedia` + `byChecksum` |
| `array_unique` sobre arrays anidados | «Array to string conversion». Se deduplica por cadena y luego se mapea |
| Firma incompatible de `canViewForRecord` | El padre exige `Model`, no `Product` |
| Vhost apuntando a la raíz del proyecto | Riesgo de exponer `.env`. Corregido a `/public` |

---

## 8. Archivos creados o modificados

### Creados (RFC-0004)

| Archivo | Contenido |
|---|---|
| `app/Services/Shopify/ShopifyOperations.php` | Documentos GraphQL |
| `app/Services/Shopify/ShopifyFileUploader.php` | Subida de medios en dos pasos |
| `app/Services/Shopify/ShopifyProductGatewayImpl.php` | Implementación del conector |
| `app/Services/Shopify/ProductSyncService.php` | Orquestación + `sync_attempts` |
| `app/Jobs/SyncProductToShopifyJob.php` | Trabajo en cola `shopify` |
| `app/DataObjects/Shopify/ShopifyUploadedFile.php` | DTO de archivo subido |
| `app/Filament/.../RelationManagers/SyncAttemptsRelationManager.php` | Panel de errores + reintento |
| `tests/Support/BuildsSyncableProducts.php` | Trait: ficha sincronizable + dobles HTTP |
| `tests/Support/FakeShopifyGateway.php` | Doble del conector para probar la orquestación |
| `tests/Feature/Shopify/ShopifyGatewayTest.php` | 17 pruebas del contrato del conector |
| `tests/Feature/Shopify/ProductSyncServiceTest.php` | 23 pruebas de orquestación |

### Modificados

| Archivo | Cambio |
|---|---|
| `app/Models/Product.php` | `chaperone('product')` en `variants()`, `media()`, `contents()`, `syncAttempts()` |
| `config/filesystems.php` | URI propia por disco: `/storage/private`, `/storage/media`, `/storage/media-derived` |
| `config/product-studio.php` | `api_version` -> `2026-07`; `connect_timeout`, `retry_times`, `retry_backoff_ms`, `upload_timeout`, `media_poll_attempts`, `media_poll_sleep_ms`, `metafield_namespace` |
| `app/Services/Shopify/ShopifyGraphQlClient.php` | `endpoint()` público; sin `baseUrl` (evita la barra final) |
| `app/DataObjects/Shopify/ShopifyProductPayload.php` | Medios con `disk`/`path`/`mimeType`/`mediaId`/`shopifyMediaGid`; `isDraft()` |
| `app/Providers/AppServiceProvider.php` | Registro del singleton `ShopifyProductGateway` |
| `app/Filament/.../Pages/EditProduct.php` | La acción real llama a `ProductSyncService::request()` |
| `app/Filament/.../ProductResource.php` | Registra `SyncAttemptsRelationManager` |
| `app/Support/Security/HtmlSanitizer.php` | Corrección del caché de HTMLPurifier |
| `tests/Feature/Models/ProductVariantModelTest.php` | Prueba de regresión de carga diferida |
| `tests/Feature/Products/ProductMediaTest.php` | Prueba de regresión de autorización por fila |
| `app/Services/Shopify/ProductSyncService.php` | Mensajes de error que distinguen el motivo real |
| `.env.example` | `SHOPIFY_API_VERSION` -> `2026-07` + 8 claves nuevas documentadas |

### Configuración de entorno (fuera del repositorio)

- `D:\laragon\etc\apache2\sites-enabled\auto.ddpshopify.test.conf` — `ROOT` -> `/public`
- `.env` — `APP_URL=http://ddpshopify.test`

---

## 9. Comandos

```bash
# Instalación
composer install
cp .env.example .env
php artisan key:generate

# Base de datos (NO usar migrate:fresh)
php artisan migrate --seed
php artisan storage:link

# Ejecución
php artisan serve              # alternativa a Laragon: http://localhost:8000/admin
php artisan queue:work         # trabajos ai / shopify / media

# Pruebas
php artisan test --compact
php artisan test --filter=ShopifyGatewayTest
DB_CONNECTION=mysql DB_DATABASE=shopify_product_studio_test DB_USERNAME=root php artisan test

# Formato
php vendor\bin\pint
```

Notas:

- El administrador se recrea con `AdminUserSeeder`; respeta
  `PRODUCT_STUDIO_ADMIN_PASSWORD` (mínimo 12 caracteres).
- En pruebas, un admin sin 2FA es redirigido: usar
  `User::factory()->adminTecnico()->withTwoFactor()->create()`.
- Helpers de prueba: `$this->operadora()`, `$this->responsable()`, `$this->admin()`.

---

## 10. Pruebas

- **Suite completa: `347 passed (772 assertions)`** (al inicio de la sesión: 305 / 670).
- `ShopifyGatewayTest`: **17 pruebas** — contrato del conector (HTTP falso).
- `ProductSyncServiceTest`: **23 pruebas** — orquestación (gateway falso).
- `pint --test`: pasa.

### Las pruebas de orquestación se verificaron rompiendo el código a propósito

Un test de regresión que pasa **con el bug presente** no protege nada (pasó una vez en esta
sesión, ver §7.1). Para evitarlo, las tres afirmaciones críticas se comprobaron revirtiendo
el código a mano y confirmando que fallan:

| Se rompió | Resultado |
|---|---|
| Pasar `null` en vez del GID conocido | ❌ falla «se actualiza en lugar de crear otra» |
| No persistir los GID de variante | ❌ falla «guarda los gid de las variantes por sku» |
| No marcar `SyncFailed` al fallar | ❌ falla «un error transitorio marca la ficha» |

Conviene repetir este ejercicio al añadir pruebas sobre reglas de negocio.

### Criterios de aceptación de RFC-0004 cubiertos

| Criterio del RFC | Prueba |
|---|---|
| Dos clics seguidos -> un único producto remoto | `test_dos_clics_seguidos_no_duplican_el_producto_remoto` |
| Modificar una ficha y actualizar el mismo producto | `test_una_ficha_ya_sincronizada_se_actualiza_en_lugar_de_crear_otra` |
| Error de API legible con referencia de soporte | `test_un_error_definitivo_no_se_propaga_y_deja_el_error_legible` |
| Reintento que continúa, no reinicia | `test_el_reintento_continua_desde_el_gid_ya_guardado` |
| Nunca publicar por accidente | `test_nunca_envia_algo_que_no_sea_borrador` |

Cobertura del conector: estado siempre `DRAFT`; título/descripción/handle/tags/SEO;
opciones y variantes con SKU y precio; metafield privado; subida de medios conservando el
ALT; no re-subida cuando ya hay GID; actualización del producto conocido; reutilización si
se perdió el GID; ignorar un resultado de búsqueda ajeno; rechazo de algo que no sea
borrador; token inválido; límite de llamadas; errores de usuario legibles; no configurado;
versión de API en la URL; token sólo en cabecera; resolución del contrato.

### Las pruebas NO detectan los fallos de carga diferida por defecto

`AppServiceProvider.php:88` desactiva `preventLazyLoading` en pruebas. Los errores de carga
diferida de esta sesión **sólo aparecían en la aplicación real**. Las pruebas de regresión
que los cubren lo activan a propósito:

```php
Model::preventLazyLoading();
try { ... } finally { Model::preventLazyLoading(false); }
```

Y necesitan **al menos dos filas** (ver §7.1). Si añades una relación nueva con Policy por
fila, aplica `chaperone('product')` desde el principio.

### Anomalía conocida (benigna, sin resolver)

Una prueba de la suite tarda **~15,1 s**. Se acotó a la **primera llamada a
`Storage::fake()`** del proceso. Es **dependiente de la posición**, no de la prueba
(primero salió en la 4.ª y luego en la 5.ª), y **en aislamiento tarda 0,7 s**. Un
`PerfProbeTest` dedicado no lo reprodujo. El directorio
`storage/framework/testing/disks/media` está prácticamente vacío, así que **no es**
`cleanDirectory()`. Hipótesis: arranque en frío del proceso en Windows (antivirus / primera
E/S). **No afecta a la corrección**, pero conviene entenderlo antes de montar CI.

---

## 11. Pendientes

### Inmediato

- [ ] **Commitear**: 7 archivos modificados y 2 nuevos sin commitear (ver §8).
      `git diff --stat -- . ':(exclude)handoff.md'` -> 6 archivos, +210 / -13.

### Para cerrar RFC-0004

- [x] ~~Pruebas de orquestación de `ProductSyncService`~~ — **hecho**, 23 pruebas.
- [x] ~~Claves `SHOPIFY_*` en `.env.example`~~ — **hecho**, `2026-07` + 8 claves.
- [ ] Documento de fase `docs/implementation/RFC-0004.md`.
- [ ] **Job de publicación separado.** Va con matiz: el RFC-0004 lo sitúa **fuera del MVP**
      («El MVP sólo sincroniza `DRAFT`»). No hay todavía ningún borrador que publicar, así
      que implementarlo ahora sería construir la vía de publicación antes de que exista un
      caso de uso. Recomendación: dejarlo para cuando haya fichas reales en Shopify.
      La Policy (`ProductPolicy::publish`) ya existe y ya exige permiso de Responsable.

### Cabos sueltos menores

- [ ] `ShopifyOperations` **no tiene** todavía la mutación de publicación
      (`productUpdate(status: ACTIVE)` / `publishablePublish`). Habrá que verificarla
      contra la documentación vigente cuando se implemente, como se hizo con `productSet`.
- [ ] El reordenado de imágenes en el panel (`MediaRelationManager`, `reorderable`) no se
      ha probado con clic real: la prueba cubre el servicio, no el gesto de arrastrar.

### Fases restantes

- **RFC-0005:** detección de duplicados por checksum perceptual, miniaturas y copias
  optimizadas, comprobación de orientación, ALT obligatorio antes de sincronizar.
- **RFC-0006:** E2E (camiseta y sudadera hasta borrador en Shopify), piloto de 15 productos.
- **RFC-0007:** comprobaciones de salud, logs estructurados
  (`request_id`/`product_id`/`sync_attempt_id`/`job_id`), alertas, despliegue, copias.
- **Documentos:** `docs/implementation/RFC-0002.md`, `RFC-0003.md`, `RFC-0004.md`.
- **`README.md`** raíz: actualizar la tabla de estado, mencionar OpenRouter y los comandos.

### Datos de demo actuales

| id | Referencia | Estado | Imágenes | Variantes |
|---|---|---|---|---|
| 1 | `DDP-57151` | review | 0 | 0 |
| 2 | `DDP-14666` | review | 1 | 4 |
| 10 | `DDP-TS-03` | draft | 2 | 0 |

---

## 12. Decisiones que no deben revertirse sin querer

- **La autorización vive en Policies**, no en ocultar botones. Una operadora no puede
  publicar ni aunque invoque la acción directamente por HTTP.
- **`Permission::byRole()`** es la única fuente de verdad de los permisos.
- **El estado `DRAFT` se fija en el gateway**, no se recibe como parámetro.
- **Los originales de medios nunca se borran**; los derivados son regenerables.
- **`sha256` es la identidad del archivo**: evita duplicados y re-subidas.
- **El reintento continúa, no reinicia**: reutiliza los GID ya guardados.
- **La IA no aprueba nada**: sólo propone; una persona revisa campo a campo.
- **`sync_attempts` es append-only**: un reintento crea un intento nuevo con la misma clave
  de idempotencia.
- **Ningún secreto se versiona ni se persiste**: los tokens se leen del entorno, el
  redactor de secretos limpia logs, auditoría y `sync_attempts`, y **este propio documento
  no contiene credenciales**.
- **Las relaciones padre->hijo que alimentan Policies llevan `chaperone('product')`.**

---

## 13. Contexto de la petición original

El usuario pidió iniciar el proyecto trabajando **sólo en RFC-0001**, con entrega final de
archivos, comandos, pruebas, decisiones, pendientes y confirmación explícita antes de
continuar. Después añadió dos modificaciones:

1. Usar **OpenRouter** para la conexión con la IA (en lugar de OpenAI).
2. **«Puedes continuar con el resto de las fases sin mi aprobación»** — ya no hay puerta de
   aprobación entre fases; continuar hasta completar el trabajo.
