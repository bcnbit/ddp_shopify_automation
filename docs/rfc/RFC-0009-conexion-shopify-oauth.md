# RFC-0009 — Conexión con Shopify por OAuth

**Estado:** Aceptado  
**Tipo:** Enmienda  
**Depende de:** RFC-0001, RFC-0004, RFC-0007  
**Modifica:** RFC-0004 (sección «Autenticación y permisos»), RFC-0007 (puesta en marcha)

## 1. Motivo

La integración de RFC-0004 se diseñó para una **aplicación personalizada con token
pre-generado** en el entorno (`SHOPIFY_ACCESS_TOKEN=shpat_…`). Ese supuesto ya no se
sostiene: la aplicación se ha creado en el **Shopify Dev Dashboard** y la credencial
disponible empieza por `shpss_`.

`shpss_` **no es un Admin API access token**: es la **client secret** de la aplicación. La
API de administración la rechaza como cabecera `X-Shopify-Access-Token`, y publicarla o
versionarla sería además una fuga de la credencial con la que se firman los intercambios
OAuth. Los access token que sí acepta la Admin API empiezan por `shpat_`.

Esta enmienda sustituye el token estático en el entorno por un **flujo de instalación OAuth**
que obtiene un *offline access token*, lo guarda **cifrado** y lo entrega al transporte de
GraphQL como única fuente de autenticación. Añade además una **pantalla de conexión** con una
comprobación **de sólo lectura** que confirma dominio, token, scopes y acceso a productos.

## 2. Alcance

### Dentro del alcance

- Flujo OAuth de *authorization code grant* contra una tienda `*.myshopify.com`.
- Credenciales de aplicación (`client_id` / `client_secret`) en el entorno, nunca
  en Git ni en el navegador.
- Tabla `shopify_installations` con el access token **cifrado** (cast `encrypted`),
  los scopes concedidos y las marcas de tiempo de instalación y de última comprobación.
- Pantalla Filament «Conexión con Shopify» con estado, dominio, scopes concedidos, botón de
  instalación/reinstalación y botón de comprobación de sólo lectura.
- Comprobación de sólo lectura por GraphQL que valida: dominio `*.myshopify.com`, token
  válido, scopes concedidos y acceso a productos.
- Refactor de `ShopifyGraphQlClient` para leer el token de la instalación y **exigir** el
  prefijo `shpat_`.
- Actualización de `shopify:check` y de la documentación de puesta en marcha.

### Fuera del alcance (explícito)

- **No se implementa la publicación de productos.** Sigue sin existir la mutación de
  publicación y ninguna sincronización cambia de comportamiento.
- **No se modifican los productos ya existentes en Shopify.** No hay *backfill*.
- **No se implementan webhooks** ni la revocación de la instalación por `app/uninstalled`.
- No se añade *embedded app* ni App Bridge: la aplicación corre fuera del admin de Shopify,
  que es justo el caso del *authorization code grant*.

## 3. Verificación documental previa

Consultado antes de implementar. **No asumir de memoria:**

| Punto | Lo que dice la documentación vigente |
|---|---|
| Prefijo de credenciales | «Offline and online access tokens are opaque strings that begin with `shpat_`»; los *delegate* empiezan por `shppa_`. La `shpss_` es la client secret. |
| URL de autorización | `https://{shop}/admin/oauth/authorize?client_id=…&scope=…&redirect_uri=…&state=…` |
| Token offline | «For an offline access token, omit `grant_options[]`». Añadirlo con `per-user` devolvería un token **online**. |
| Intercambio | `POST https://{shop}.myshopify.com/admin/oauth/access_token` con `client_id`, `client_secret`, `code` y `expiring` opcional. |
| Tokens expirables | Las **apps públicas** deben usar tokens expirables antes del 1 de enero de 2027. «It doesn't apply to **custom apps** or apps created by merchants». |
| Scopes concedidos | `currentAppInstallation { accessScopes { handle } }` devuelve los handles concedidos. |
| Validación del callback | Quitar `hmac`, ordenar los parámetros alfabéticamente como `k=v`, unirlos con `&` y calcular `HMAC-SHA256` hex con la client secret; comparación en **tiempo constante**. |
| Scopes de archivos | La subida en dos pasos de RFC-0004 (`stagedUploadsCreate` + `fileCreate`) exige `write_files`. |

## 4. Decisiones de arquitectura

### 4.1 Dos credenciales distintas, con papeles distintos

| Variable | Qué es | Dónde vive | ¿Secreto? |
|---|---|---|---|
| `SHOPIFY_API_KEY` | Client ID de la aplicación | Entorno | No (identifica, no autoriza) |
| `SHOPIFY_API_SECRET` | Client secret (`shpss_…`) | Entorno | **Sí** |
| `SHOPIFY_SHOP_DOMAIN` | Dominio esperado, para prellenar la instalación | Entorno | No |
| — | Offline access token (`shpat_…`) | **Base de datos, cifrado** | **Sí** |

El access token **deja de leerse del entorno**. Un token que vive en `.env` no se puede
rotar sin desplegar, no se puede auditar y acaba copiado a un `.env.example` por
descuido. El token que produce un flujo OAuth pertenece a una instalación concreta, y por eso
se guarda como parte de ella.

### 4.2 Por qué el token se guarda cifrado

El cast `encrypted` de Eloquent usa `APP_KEY`. Esto protege el token frente a una
copia de la base de datos (un *dump* de soporte, un backup en un bucket) sin protegerlo frente
a la ejecución de código en el servidor — que es la frontera realista. Se elige frente a un KMS
externo porque añadir un proveedor de claves al MVP no cambia la frontera de confianza, sólo la
desplaza.

**Consecuencia operativa que hay que documentar:** rotar `APP_KEY` **invalida** el token
guardado y obliga a reinstalar la aplicación. Es el mismo efecto que ya tiene sobre el secreto
TOTP de los administradores, así que se documentan juntos.

### 4.3 Tokens expirables: configurables, no expirables por defecto

La documentación exime explícitamente a las **custom apps** de la exigencia de tokens
expirables. La aplicación de este proyecto es de una sola tienda
(`diesdeplatja.myshopify.com`), así que el valor por defecto es un token **no
expirable**: sin `refresh_token`, sin *job* de refresco y sin la ventana de fallo de 60
minutos.

Aun así, el parámetro se expone en `SHOPIFY_OAUTH_EXPIRING` porque `expiring=1`
funciona en ambos tipos de aplicación mientras que `expiring=0` sólo en *custom*. Si la
aplicación se distribuye como pública, activar la variable es suficiente para pedir un token
expirable en la siguiente instalación.

> Limitación conocida y aceptada: con `SHOPIFY_OAUTH_EXPIRING=true` el token caduca en
> 60 minutos y esta fase **no implementa el refresco**, porque el escenario real (custom app)
> no lo necesita. Activar esa variable sin implementar el refresco deja la conexión rota en
> una hora; la RFC lo deja escrito y la pantalla lo advierte. Es deuda explícita, no silenciosa.

### 4.4 Una sola fila, y la instalación manda

La aplicación sirve a una tienda. `shopify_installations` guarda **una** instalación
activa; reinstalar la reemplaza en lugar de acumular filas. El dominio del transporte GraphQL
pasa a ser el de la instalación, no el de `.env`: son la misma cosa, pero sólo uno de los
dos lo ha confirmado Shopify.

## 5. Modelo de datos

### 5.1 `shopify_installations`

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint | |
| `shop_domain` | string(255), único | `*.myshopify.com`. Lo confirma Shopify, no lo teclea nadie. |
| `access_token` | text, `encrypted` | Offline token `shpat_…`. Cifrado con `APP_KEY`. |
| `scopes` | json, nullable | Handles concedidos, tal y como los devolvió el intercambio. |
| `is_expiring` | boolean | Si el token procede de `expiring=1`. |
| `expires_at` | timestamp, nullable | Sólo si `is_expiring`. |
| `installed_at` | timestamp | |
| `last_checked_at` | timestamp, nullable | Última comprobación de sólo lectura correcta. |
| `last_check_error` | string(255), nullable | Motivo del último fallo, legible y sin credenciales. |
| `created_at`, `updated_at` | timestamps | |

Sin `refresh_token`: no se implementa el refresco (4.3). Añadirlo más adelante es una
migración aditiva de una columna, no un rediseño.

### 5.2 Invariantes

- `access_token` **nunca** se serializa hacia el navegador ni hacia `activity_log`.
  El modelo lo declara oculto y `SecretRedactor` ya enmascara las claves
  `access_token`, `shopify_access_token` y `client_secret`, además de los
  patrones `shpat_`/`shpss_`.
- Sólo se guarda una instalación: `ShopifyInstallation::current()` devuelve la primera
  fila.
- El token se valida **al guardarlo** y **al usarlo**: si no empieza por `shpat_`, se
  rechaza con un mensaje que nombra el caso `shpss_` en lugar de fallar contra la API.

## 6. Flujo OAuth

### 6.1 Pasos

1. La persona autorizada abre **Conexión con Shopify** y pulsa «Conectar con Shopify».
2. `GET /admin/shopify/install` (autenticado, permiso `settings.manage`) construye
   la URL de autorización con un `state` aleatorio y guarda ese `state` en la sesión.
3. Shopify redirige a `GET /admin/shopify/callback`. Antes de canjear nada se valida: el
   `state` contra el de la sesión, la firma `hmac`, el dominio
   `*.myshopify.com` y que el `shop` coincida con `SHOPIFY_SHOP_DOMAIN` si
   está definido.
4. Se intercambia el `code` por el token con
   `POST /admin/oauth/access_token`.
5. Se guarda la instalación con el token cifrado y los scopes devueltos.
6. Se ejecuta la comprobación de sólo lectura y se muestra el resultado.

### 6.2 Por qué las rutas no usan el middleware del panel

El callback lo invoca **Shopify**, no el navegador con sesión del panel. Exigirle el middleware
del panel lo rompería. Van fuera del grupo de Filament, pero:

- La ruta de **instalación** sí exige sesión y permiso `settings.manage`: es la persona
  quien la pulsa desde el panel.
- El **callback** se protege con `state` + `hmac`, que es el mecanismo que Shopify
  prescribe para una petición que no puede llevar sesión. La comprobación de permiso del
  callback es el `state` firmado, no el usuario autenticado.
- Ninguna de las dos devuelve jamás el token, la client secret ni la `shpss_`.

### 6.3 El `state` sobrevive al viaje de ida y vuelta

Se guarda en la sesión antes de salir hacia Shopify y se compara al volver. La sesión debe
estar viva en el retorno: el callback cae dentro del mismo dominio, así que la cookie de sesión
viaja. Si el `state` no coincide, se rechaza con `403` y **no se canjea el código**.

## 7. Comprobación de sólo lectura

Una única consulta, sin mutaciones, que responde a las cuatro preguntas de la petición:

```graphql
query ConnectionCheck {
  shop {
    name
    myshopifyDomain
  }
  currentAppInstallation {
    accessScopes {
      handle
    }
  }
  products(first: 1) {
    nodes {
      id
    }
  }
}
```

| Pregunta | Cómo se responde |
|---|---|
| ¿El dominio es `*.myshopify.com`? | Se valida el formato antes de llamar, y `shop.myshopifyDomain` lo confirma. |
| ¿El token está instalado correctamente? | Un `401`/`403` de Shopify se traduce a «token no válido o sin permisos». |
| ¿Qué scopes se han concedido? | `currentAppInstallation.accessScopes`. Se contrasta con los exigidos por la aplicación. |
| ¿Hay acceso a productos? | `products(first: 1)` exige `read_products`; si falta, Shopify devuelve error de scope. |

El resultado se muestra como cuatro comprobaciones con su estado, y se persiste
`last_checked_at`/`last_check_error`.

## 8. Scopes

| Scope | ¿Por qué? |
|---|---|
| `read_products` | Leer productos para la idempotencia de RFC-0004. |
| `write_products` | Crear el borrador. Necesario aunque esta fase no publique. |
| `read_files` | Leer el estado de los medios subidos (`files`/`fileStatus`). |
| `write_files` | `stagedUploadsCreate` + `fileCreate` de la estrategia de imágenes ya implementada. |

`read_files`/`write_files` **sí eran necesarios**: no son un extra especulativo,
los exige el código que ya existe en `ShopifyFileUploader`. Se piden ambos porque
`write_files` sin `read_files` impediría comprobar que un archivo llegó a
`READY`.

## 9. Cambios en el transporte GraphQL

`ShopifyGraphQlClient` deja de leer
`config('product-studio.shopify.access_token')`:

- `isConfigured()` pasa a ser «hay aplicación configurada **y** hay instalación con token
  válido».
- La cabecera `X-Shopify-Access-Token` se rellena **exclusivamente** con el token de la
  instalación.
- Antes de enviar, el token se valida contra el prefijo `shpat_`. Un `shpss_`
  produce un error permanente y explícito («esa credencial es la client secret, no un access
  token»), no un `401` opaco de Shopify.
- El endpoint usa el dominio de la instalación.

## 10. Pantalla de conexión

Página Filament «Conexión con Shopify», con acceso restringido por `canAccess()` a
`settings.manage` (hoy sólo el administrador técnico). Muestra:

- Estado de la conexión y, si la hay, dominio, scopes concedidos y última comprobación.
- Botón **Conectar con Shopify** / **Reinstalar**, que lleva a la ruta de instalación.
- Botón **Comprobar conexión**, de sólo lectura, que ejecuta el apartado 7.
- Qué falta si la aplicación no está configurada (qué variables rellenar, sin valores).
- Aviso cuando `SHOPIFY_OAUTH_EXPIRING` está activo sin refresco implementado.

La pantalla **nunca** recibe ni muestra el token, la client secret ni la `shpss_`. Lo
único que cruza al navegador es el dominio, los handles de scope y si la comprobación pasó.

## 11. Pruebas

| Criterio | Prueba |
|---|---|
| El callback rechaza un `state` que no coincide | `test_el_callback_rechaza_un_state_que_no_coincide` |
| El callback rechaza una firma `hmac` inválida | `test_el_callback_rechaza_una_firma_hmac_invalida` |
| Se acepta una firma `hmac` correcta | `test_el_callback_acepta_una_firma_hmac_valida` |
| Un dominio que no es `*.myshopify.com` se rechaza | `test_el_callback_rechaza_un_dominio_que_no_es_myshopify` |
| El token se guarda cifrado y no en claro | `test_el_token_se_guarda_cifrado` |
| El token nunca se serializa | `test_el_token_no_se_serializa` |
| Un `shpss_` se rechaza como access token | `test_un_token_shpss_se_rechaza_con_un_mensaje_explicito` |
| La cabecera lleva el token de la instalación | `test_la_cabecera_lleva_el_token_de_la_instalacion` |
| Sin instalación, el conector no está configurado | `test_sin_instalacion_el_conector_no_esta_configurado` |
| La comprobación es de sólo lectura | `test_la_comprobacion_no_envia_ninguna_mutacion` |
| La comprobación informa de los scopes concedidos | `test_la_comprobacion_devuelve_los_scopes_concedidos` |
| La pantalla no expone secretos al navegador | `test_la_pantalla_de_conexion_no_expone_secretos` |
| Los scopes pedidos son los mínimos | `test_los_scopes_solicitados_son_los_minimos` |

La suite existente de RFC-0004 debe seguir verde: el cambio de origen del token no puede
alterar el contrato del conector.

## 12. Criterios de aceptación

1. La instalación termina con un offline access token `shpat_` guardado **cifrado**.
2. Las llamadas GraphQL usan **exclusivamente** ese token en `X-Shopify-Access-Token`.
3. La comprobación de sólo lectura confirma dominio, token, scopes y acceso a productos.
4. `shpss_`, la client secret y el access token no aparecen en el navegador, ni en los
   logs, ni en ningún archivo versionado.
5. Un token que no sea `shpat_` se rechaza con un mensaje que explica por qué.
6. **No se publica nada**: no existe mutación de publicación y ninguna ficha cambia de estado.
