# Handoff — Shopify Product Studio

Documento de traspaso para una sesión nueva. Resume el estado real del repositorio,
las decisiones tomadas, los errores ya corregidos y lo que queda pendiente.

- **Repositorio:** `D:\laragon\www\ddpshopify`
- **Fecha del traspaso:** 2026-09-17
- **Estado de la suite:** `322 passed (697 assertions)` en SQLite en memoria.

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
| Laravel | 12 |
| PHP | 8.3.27 |
| MySQL | 8.4.3 |
| Backoffice | Filament 4 |
| Colas | Redis en producción; `database` en local |
| IA | **OpenRouter** (sustituye a OpenAI), encapsulada tras `App\Contracts\Ai\AiClient` |
| Shopify | Admin GraphQL API, **versión `2026-07`** |

### Avisos del entorno (importantes)

- **La herramienta `apply_patch` NO está disponible.** Editar con PowerShell:
  `[IO.File]::WriteAllText($path, $content, (New-Object System.Text.UTF8Encoding($false)))`
  usando here-strings `@'...'@`. Verificar el ancla con `$raw.Contains($old)` antes de
  reemplazar y pasar `php -l` después.
- **Los archivos usan finales de línea LF, no CRLF.** Un ancla con `` `r`n `` falla.
- **Shell:** `powershell` 5.1. `pwsh` 7.6.5 disponible con el parámetro `shell: pwsh`.
  `foreach (Get-ChildItem ...)` es error de sintaxis; hay que escribir
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

## 3. Estado de las fases

| Fase | Contenido | Estado |
|---|---|---|
| RFC-0000 | Visión, alcance y decisiones | Redactado |
| RFC-0001 | Fundación, seguridad y modelo de datos | **Implementado** (`docs/implementation/RFC-0001.md`) |
| RFC-0002 | Flujo de alta, edición y aprobación | **Implementado** (falta doc de fase) |
| RFC-0003 | IA, SEO y contenido (OpenRouter) | **Implementado** (falta doc de fase) |
| RFC-0004 | Integración Shopify e idempotencia | **En curso (~85%)** |
| RFC-0005 | Medios, variantes y validaciones | Parcial (dentro de RFC-0001/0002) |
| RFC-0006 | Pruebas y aceptación | No iniciado |
| RFC-0007 | Despliegue y observabilidad | No iniciado |

---

## 4. Trabajo realizado en esta sesión (RFC-0004)

### 4.1 Hallazgos verificados de la API de Shopify

Se consultó la documentación vigente antes de implementar. Estos datos **cambian el
diseño** y no deben asumirse de memoria:

1. **`SHOPIFY_API_VERSION=2025-01` estaba retirada** (dejó de ser soportada en enero de
   2026). La última estable es **`2026-07`**. Se cambió el valor por defecto.
   Shopify retira una versión cada trimestre y, ante una versión no soportada, responde
   con la más antigua accesible: el contrato dejaría de ser predecible.
2. **`productSet` es la mutación vigente recomendada** para crear o actualizar: sustituye
   al conjunto `productCreate` + `productUpdate` + `productVariantsBulkCreate`.
   - Admite `identifier: {id: ...}` o `{handle: ...}` → resuelve la idempotencia sin
     consulta previa.
   - **Es destructiva con los campos de lista**: hay que reenviar *todas* las variantes y
     *todos* los medios en cada llamada, o Shopify borra los que falten.
3. **`productCreate` sólo crea la primera variante** — motivo adicional para usar
   `productSet`.
4. **Medios en dos pasos:** `stagedUploadsCreate` → subir los bytes → `fileCreate` →
   asociar con `productSet(files: [{originalSource: <gid>, alt: ...}])`.
   - El archivo debe subirse **el último** en el multipart: la firma de la URL temporal
     sólo es válida si el resto de campos la preceden.
   - `duplicateResolutionMode: APPEND_UUID` es necesario para que el ALT se aplique.
5. **El filtrado de metafields por servidor sólo es fiable si el metafield está declarado
   como `adminFilterable`.** Si no lo está, Shopify **ignora el filtro y devuelve
   productos cualesquiera** → riesgo de falso positivo que haría que una ficha
   sobrescribiese el borrador de otra. Por eso el valor se **verifica por cliente**.
6. Los metafields propiedad de la app (`$app`) quedan ocultos de la Storefront API por
   defecto, lo que satisface «no visible para la tienda».

### 4.2 Arquitectura implementada

- `ShopifyOperations` — **todos** los documentos GraphQL en un único sitio, para que un
  cambio de API no afecte al dominio.
- `ShopifyGraphQlClient` — transporte: versión de API, autenticación y cabeceras de
  límite de llamadas. Traduce 401/403/404/429/430/5xx y `THROTTLED`.
- `ShopifyFileUploader` — subida de medios en dos pasos y espera a `READY`.
- `ShopifyProductGatewayImpl` — implementa el contrato `ShopifyProductGateway`.
- `ProductSyncService` — orquesta el flujo completo y escribe `sync_attempts`.
- `SyncProductToShopifyJob` — trabajo en cola `shopify`.
- `SyncAttemptsRelationManager` — panel «Sincronizaciones con error» con reintento.

### 4.3 Idempotencia en tres capas

1. Si se conoce el GID, se **actualiza** ese producto (`identifier.id`).
2. Si no, se busca por el metafield privado `product_studio_id` y, como comprobación
   secundaria, por **handle**.
3. Un medio cuyo `sha256` ya tiene GID **no se vuelve a subir**.

El estado se fija dentro del gateway (`'status' => 'DRAFT'`), no se recibe de fuera: es la
garantía de que ninguna ruta puede publicar por accidente.

---

## 5. Errores encontrados y corregidos

Errores reales de lógica, no sólo de pruebas:

1. **URL con barra final → HTTP 404.** `Http::baseUrl($endpoint)->post('')` producía
   `.../graphql.json/` y Shopify respondía 404. Se descubrió con
   `Http::preventStrayRequests()`. → Se añadió `endpoint()` y `post($this->endpoint(), …)`.
2. **`findByStudioId` pasaba `studioId` como handle** en el respaldo. → El respaldo por
   handle se movió a `findProductGid()` y usa `$payload->handle`.
3. **La verificación del metafield era un no-op** (volvía a consultar el mismo producto).
   → Se eliminó; ahora la consulta pide `metafield.value` y se compara directamente.
4. **`mediaGids()` devolvía `[]`**, así que los GID de medios nunca se persistían. →
   Ahora `prepareMedia()` devuelve `byMedia` + `byChecksum` y el resultado usa `byChecksum`.
5. **`array_unique` sobre arrays anidados** → «Array to string conversion». → Se deduplica
   por cadena y luego se mapea a `['name' => $value]`.
6. **`HtmlSanitizer` usaba el nombre de caché inválido `'SerializerCache'`.** HTMLPurifier
   avisaba y caía a un caché sin directorio, reconstruyendo su definición en cada proceso
   (varios segundos por petición). → Corregido a `'Serializer'`, con `mkdir` si falta el
   directorio y comprobación de `is_writable`.
7. **Firma incompatible de `canViewForRecord`**: el padre exige `Model`, no `Product`.
8. **`Storage::fake()` dentro del trait**: las pruebas no materializaban los bytes reales
   de las imágenes, así que la subida fallaba con «no se encuentra el archivo».

Errores de pruebas / utilidades:

9. `Http::fake` con `Http::sequence()` posicional era frágil → se sustituyó por enrutado
   por **nombre de operación GraphQL** en `fakeShopify()`.
10. `Request::data()` devuelve `stdClass` en objetos anidados → helper `graphQlVariables()`
    que decodifica el cuerpo con `json_decode($request->body(), true)`.
11. Una closure no capturaba `$product` → `use ($reference)`.

---

## 6. Archivos creados o modificados

### Creados en esta sesión

| Archivo | Contenido |
|---|---|
| `app/Services/Shopify/ShopifyOperations.php` | Documentos GraphQL |
| `app/Services/Shopify/ShopifyFileUploader.php` | Subida de medios en dos pasos |
| `app/Services/Shopify/ShopifyProductGatewayImpl.php` | Implementación del conector |
| `app/Services/Shopify/ProductSyncService.php` | Orquestación + `sync_attempts` |
| `app/Jobs/SyncProductToShopifyJob.php` | Trabajo en cola `shopify` |
| `app/DataObjects/Shopify/ShopifyUploadedFile.php` | DTO de archivo subido |
| `app/Filament/Resources/Products/RelationManagers/SyncAttemptsRelationManager.php` | Panel de errores + reintento |
| `tests/Support/BuildsSyncableProducts.php` | Trait: ficha sincronizable + dobles HTTP |
| `tests/Feature/Shopify/ShopifyGatewayTest.php` | 17 pruebas del conector |

### Modificados en esta sesión

| Archivo | Cambio |
|---|---|
| `config/product-studio.php` | `api_version` → `2026-07`; `connect_timeout`, `retry_times`, `retry_backoff_ms`, `upload_timeout`, `media_poll_attempts`, `media_poll_sleep_ms`, `metafield_namespace` |
| `app/Services/Shopify/ShopifyGraphQlClient.php` | `endpoint()` público; sin `baseUrl` (evita la barra final) |
| `app/DataObjects/Shopify/ShopifyProductPayload.php` | Medios con `disk`/`path`/`mimeType`/`mediaId`/`shopifyMediaGid`; `isDraft()` |
| `app/Providers/AppServiceProvider.php` | Registro del singleton `ShopifyProductGateway` |
| `app/Filament/.../Pages/EditProduct.php` | La acción real llama a `ProductSyncService::request()` |
| `app/Filament/.../ProductResource.php` | Registra `SyncAttemptsRelationManager` |
| `app/Support/Security/HtmlSanitizer.php` | Corrección del caché de HTMLPurifier |

### De fases anteriores (reutilizados)

`app/Contracts/Shopify/ShopifyProductGateway.php`, `app/Exceptions/Shopify/ShopifyRequestFailed.php`,
`app/DataObjects/Shopify/ShopifyProductPayload.php`, `app/Models/SyncAttempt.php`,
`app/Support/Products/IdempotencyKey.php`, `app/Support/Products/ProductLock.php`,
`app/Enums/SyncOperation.php`, `app/Enums/SyncStatus.php`.

---

## 7. Comandos

```bash
# Instalación
composer install
cp .env.example .env
php artisan key:generate

# Base de datos (NO usar migrate:fresh)
php artisan migrate --seed
php artisan storage:link

# Ejecución
php artisan serve              # panel en http://localhost:8000/admin
php artisan queue:work         # trabajos ai / shopify / media

# Pruebas
php artisan test --compact
php artisan test --filter=ShopifyGatewayTest
DB_CONNECTION=mysql DB_DATABASE=shopify_product_studio_test DB_USERNAME=root php artisan test

# Formato
php vendor\bin\pint
```

Notas:

- El administrador se crea con `AdminUserSeeder`; imprime la contraseña **una sola vez**.
  Respeta `PRODUCT_STUDIO_ADMIN_PASSWORD` (mínimo 12 caracteres).
- En pruebas, un admin sin 2FA es redirigido: usar
  `User::factory()->adminTecnico()->withTwoFactor()->create()`.
- Helpers de prueba: `$this->operadora()`, `$this->responsable()`, `$this->admin()`.

---

## 8. Pruebas

- **Suite completa: `322 passed (697 assertions)`** (antes de esta sesión: 305 / 670).
- `ShopifyGatewayTest`: **17 pruebas, 27 aserciones**, todas en verde.

Cobertura del conector: estado siempre `DRAFT`; título/descripción/handle/tags/SEO;
opciones y variantes con SKU y precio; metafield privado; subida de medios conservando el
ALT; no re-subida cuando ya hay GID; actualización del producto conocido; reutilización si
se perdió el GID; ignorar un resultado de búsqueda ajeno; rechazo de algo que no sea
borrador; token inválido; límite de llamadas; errores de usuario legibles; no configurado;
versión de API en la URL; token sólo en cabecera; resolución del contrato.

### Anomalía conocida (benigna, sin resolver)

Una prueba de la suite tarda **~15,1 s**. Se instrumentó y se acotó a la **primera llamada
a `Storage::fake()`** del proceso. Evidencia:

- Es **dependiente de la posición**, no de la prueba: primero apareció en la 4.ª prueba y
  después en la 5.ª.
- **En aislamiento tarda 0,7 s** (`--filter=test_sube_los_medios_y_conserva_el_alt`).
- Un `PerfProbeTest` dedicado no lo reprodujo (`FAKE#1=0.35s`).
- El directorio `storage/framework/testing/disks/media` está prácticamente vacío, así que
  **no es** `cleanDirectory()`.

Hipótesis: coste de arranque en frío del proceso en Windows (antivirus / primera E/S).
**No afecta a la corrección** (todas las pruebas pasan), pero conviene entenderlo antes de
montar CI. La instrumentación temporal ya se ha retirado.

---

## 9. Pendientes

### Para cerrar RFC-0004

- [ ] Pruebas de `ProductSyncService` (orquestación): doble clic → **un solo** producto
      remoto; actualizar el mismo producto remoto; reanudar tras fallo parcial de medios;
      legibilidad del error conservando la referencia de soporte; **cero publicaciones
      accidentales**.
- [ ] **Job de publicación separado** (la Policy ya existe): exige `products.publish`,
      rol Responsable y confirmación; debe fallar cerrado.
- [ ] Añadir las claves `SHOPIFY_*` nuevas a `.env.example`
      (sigue con `SHOPIFY_API_VERSION=2025-01` y sin `connect_timeout`, `retry_times`,
      `retry_backoff_ms`, `upload_timeout`, `media_poll_attempts`, `media_poll_sleep_ms`,
      `metafield_namespace`).
- [ ] Documento de fase `docs/implementation/RFC-0004.md`.

### Fases restantes

- **RFC-0005:** detección de duplicados por checksum perceptual, miniaturas y copias
  optimizadas, comprobación de orientación, ALT obligatorio antes de sincronizar.
- **RFC-0006:** E2E (camiseta y sudadera hasta borrador en Shopify), piloto de 15 productos.
- **RFC-0007:** comprobaciones de salud, logs estructurados
  (`request_id`/`product_id`/`sync_attempt_id`/`job_id`), alertas, despliegue, copias.
- **Documentos:** `docs/implementation/RFC-0002.md`, `RFC-0003.md`, `RFC-0004.md`.
- **`README.md`** raíz: actualizar la tabla de estado, mencionar OpenRouter y los comandos.

---

## 10. Decisiones que no deben revertirse sin querer

- **La autorización vive en Policies**, no en ocultar botones. Una operadora no puede
  publicar ni aunque invoque la acción directamente por HTTP.
- **`Permission::byRole()`** es la única fuente de verdad de los permisos.
- **El estado `DRAFT` se fija en el gateway**, no se recibe como parámetro.
- **Los originales de medios nunca se borran**; los derivados son regenerables.
- **`sha256` es la identidad del archivo**: evita duplicados y re-subidas.
- **El reintento continúa, no reinicia**: reutiliza los GID ya guardados.
- **La IA no aprueba nada**: sólo propone; una persona revisa campo a campo.
- **`sync_attempts` es append-only**: un reintento crea un intento nuevo con la misma
  clave de idempotencia.
- **Ningún secreto se versiona ni se persiste**: los tokens se leen del entorno y el
  redactor de secretos limpia logs, auditoría y `sync_attempts`.

---

## 11. Contexto de la petición original

El usuario pidió iniciar el proyecto trabajando **sólo en RFC-0001**, con entrega final de
archivos, comandos, pruebas, decisiones, pendientes y confirmación explícita antes de
continuar. Después añadió dos modificaciones:

1. Usar **OpenRouter** para la conexión con la IA (en lugar de OpenAI).
2. **«Puedes continuar con el resto de las fases sin mi aprobación»** — ya no hay puerta de
   aprobación entre fases; continuar hasta completar el trabajo.