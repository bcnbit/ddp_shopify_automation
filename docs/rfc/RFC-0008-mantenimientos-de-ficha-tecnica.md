# RFC-0008 — Mantenimientos de ficha técnica

**Estado:** Aceptado  
**Tipo:** Enmienda  
**Depende de:** RFC-0001, RFC-0002, RFC-0003, RFC-0004  
**Modifica:** RFC-0002 (sección «Ficha técnica»), RFC-0003 (contrato de entrada a IA),
RFC-0004 (composición del `descriptionHtml`)

## 1. Motivo

Hoy la composición, el ajuste/tallaje y los cuidados se escriben a mano en cada ficha, campo a
campo. Eso produce tres problemas concretos y verificables:

1. **Se repite trabajo**: dos camisetas del mismo tejido se escriben dos veces, y con el tiempo
   divergen («100 % algodón» en una, «100% algodón peinado» en otra).
2. **Se pierde el dato confirmado**: al regenerar o reescribir, es fácil introducir una
   afirmación que nadie ha validado. La regla de RFC-0000 dice que la IA no puede inventar
   composición, y un campo libre tampoco debería permitir que una persona la improvisen por
   descuido.
3. **No hay reutilización de guías de tallas**, que hoy se escriben (cuando se escriben) dentro
   de la descripción comercial, mezcladas con el texto de venta.

Esta enmienda añade **mantenimientos maestros** reutilizables y una **copia congelada** en cada
ficha, de modo que reutilizar sea más rápido que escribir y que un cambio futuro en el maestro
nunca altere una ficha ya creada, aprobada o sincronizada.

## 2. Alcance

### Dentro del alcance

- Cuatro mantenimientos: composiciones, perfiles de ajuste/tallaje, perfiles de cuidados y guías
  de tallas.
- Un apartado de administración «Mantenimientos de ficha técnica» con cuatro recursos Filament.
- Selectores con búsqueda en la ficha, con previsualización del contenido elegido.
- **Copia congelada** (snapshot) del contenido y la versión usados por cada ficha.
- Campo opcional `Descripción base para IA`.
- Composición del `descriptionHtml` que se envía a Shopify en el orden que fija el apartado 7.
- Migraciones, modelos, relaciones, factories, seeders, Policies y pruebas.

### Fuera del alcance (explícito)

- **No se reescriben productos que ya existen en Shopify.** Esta ampliación se aplica a fichas
  nuevas o a una sincronización explícita posterior. No hay *backfill* ni reenvío automático.
- **No se inventa stock ni traducciones** (siguen fuera del MVP, ver `handoff.md` §12.3).
- **No se retiran todavía las columnas libres** `composition`, `fit` y `care_instructions`
  (ver 5.4).
- No hay aviso automático de «existe una versión más nueva del mantenimiento».
- No se permite editar la versión del mantenimiento a mano (ver 4.4).

## 3. Principios que respeta

- **El maestro propone, la persona elige.** Un mantenimiento es contenido ya validado por una
  persona; la ficha sólo lo referencia.
- **La IA no puede inventar.** Sigue recibiendo sólo datos confirmados; el snapshot es un dato
  confirmado más.
- **Nada se publica solo.** Shopify sigue recibiendo `DRAFT`; esta RFC no toca el estado.
- **Idempotencia y trazabilidad**: misma clave de idempotencia, mismos intentos, misma auditoría.
- **Una ficha antigua no cambia sola.** El snapshot es la garantía estructural de esta frase.

## 4. Modelo de datos

### 4.1 Cuatro tablas de mantenimiento

Se eligieron **cuatro tablas** y no una sola con un discriminador `kind` porque:

- Cada recurso Filament mapea a un modelo único: es imposible abrir una guía de tallas a través
  de la URL de una composición, y no hace falta defender ese caso en cada capa.
- Las columnas de contenido son distintas en la guía de tallas (nota inicial, HTML, nota final)
  frente a los tres textos, así que la base de datos impone la forma en lugar de un `if`.
- Coincide con la estructura ya existente del proyecto: un recurso por modelo.

| Tabla | Modelo | Contenido |
|---|---|---|
| `technical_sheet_compositions` | `TechnicalSheetComposition` | `content_text` |
| `technical_sheet_fits` | `TechnicalSheetFit` | `content_text` |
| `technical_sheet_cares` | `TechnicalSheetCare` | `content_text` |
| `technical_sheet_size_guides` | `TechnicalSheetSizeGuide` | `intro_note`, `content_html`, `closing_note` |

### 4.2 Columnas comunes

Todas las tablas comparten:

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint | |
| `code` | string(64), único | Código legible. Se normaliza con `SkuNormalizer` (mayúsculas, guiones). |
| `name` | string(255) | Nombre interno. |
| `product_type` | string(64), nullable | Tipo de prenda aplicable. Nulo = cualquiera. |
| `audience` | string(32), nullable | Público aplicable. Nulo = cualquiera. |
| `is_active` | boolean, por defecto `true` | Un mantenimiento inactivo no se ofrece en fichas nuevas. |
| `version` | unsigned int, por defecto 1 | Se incrementa sola al cambiar el contenido (4.4). |
| `created_at`, `updated_at` | timestamps | |

La guía de tallas añade además `intro_note` (texto nullable), `content_html` (longText) y
`closing_note` (texto nullable).

### 4.3 Copia congelada en la ficha

`products` recibe **cuatro claves ajenas anulables**:

- `technical_sheet_composition_id` → `technical_sheet_compositions`
- `technical_sheet_fit_id` → `technical_sheet_fits`
- `technical_sheet_care_id` → `technical_sheet_cares`
- `technical_sheet_size_guide_id` → `technical_sheet_size_guides`

Las cuatro son `nullOnDelete()`: borrar un mantenimiento no rompe la ficha.

El contenido congelado vive en su propia tabla, `product_technical_sheets`:

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint | |
| `product_id` | FK `products`, cascada | |
| `slot` | string(32) | `composition`, `fit`, `care` o `size_guide` |
| `entry_id` | unsigned bigint, nullable | **Sin clave ajena, a propósito** (ver abajo) |
| `entry_code` | string(64) | Código congelado, para poder leer el snapshot sin el maestro |
| `entry_name` | string(255) | Nombre congelado |
| `entry_version` | unsigned int | Versión del maestro en el momento de copiar |
| `content_text` | text, nullable | Texto congelado (composición, ajuste, cuidados) |
| `content_html` | longText, nullable | HTML congelado (guía de tallas) |
| `intro_note`, `closing_note` | text, nullable | Notas congeladas (guía de tallas) |
| `captured_at` | timestamp | Cuándo se copió |

`unique(product_id, slot)`: como mucho una copia por hueco y ficha.

**`entry_id` no lleva clave ajena a propósito.** El snapshot existe precisamente para sobrevivir
al mantenimiento: si el maestro se borra, la ficha debe seguir enviando el mismo texto a
Shopify. Una clave ajena con `nullOnDelete` borraría el vínculo y dejaría la copia huérfana de
sentido. El código y el nombre se copian también para que el snapshot sea legible por sí solo.

### 4.4 La versión se gestiona sola

`version` se incrementa en un enganche `saving` del modelo cuando cambian los campos de
contenido (o el nombre y el código, que identifican lo que se copió). No se puede editar a mano:
una versión escrita a mano puede repetirse o retroceder, y entonces el snapshot dejaría de
distinguir dos contenidos distintos. En el panel se muestra como campo de sólo lectura.

### 4.5 `Descripción base para IA`

`products.ai_base_description` (texto nullable): contexto comercial o creativo —diseño,
inspiración, mensaje, ocasión de uso, acabado o rasgos diferenciadores— que se envía a la IA
como contexto y **no se publica literalmente** en Shopify. La ficha lo explica con una ayuda
visible.

## 5. La ficha de producto

### 5.1 Campos que cambian en «Ficha técnica»

| Antes | Ahora |
|---|---|
| `Textarea` composición | `Select` con búsqueda → `technical_sheet_composition_id` |
| `TextInput` ajuste/tallaje | `Select` con búsqueda → `technical_sheet_fit_id` |
| `Textarea` cuidados | `Select` con búsqueda → `technical_sheet_care_id` |
| — | `Select` con búsqueda → `technical_sheet_size_guide_id` |
| — | `Textarea` «Descripción base para IA» |

`Observaciones internas` (`notes`) **no cambia**: sigue sin enviarse a la IA ni a Shopify.

Debajo de cada selector se muestra la previsualización del contenido elegido. Los cuatro
selectores se pueden dejar vacíos y la ficha sigue funcionando exactamente igual que antes.

### 5.2 Qué se ofrece en cada selector

Opciones = mantenimientos **activos** cuyo `product_type` y `audience` sean nulos o coincidan con
los de la ficha. Si la ficha ya tiene uno seleccionado que después se desactivó, ese valor se
sigue mostrando y conservando: ocultarlo haría que un guardado automático borrase la selección
en silencio.

### 5.3 Previsualización

Se compone con el **mismo** generador que usa el envío a Shopify (7), leyendo la selección
actual del formulario. Así lo que se ve es lo que se enviará, y no hay dos plantillas que puedan
divergir. La previsualización refleja la selección *pendiente de guardar*; el envío usa la copia
congelada, que se actualiza en el guardado automático.

### 5.4 Las columnas libres se conservan como respaldo

`composition`, `fit` y `care_instructions` **no se borran**. Hay fichas de demostración y datos
ya existentes que las usan, y tirarlas obligaría a un *backfill* destructivo que esta RFC no
necesita. Dejan de mostrarse en el formulario y `ProductFactSheet` las usa **sólo si no hay
snapshot**, de modo que:

- Las fichas nuevas usan exclusivamente mantenimientos.
- Las fichas antiguas siguen generando propuesta sin intervención.
- Una RFC futura puede retirarlas cuando ya no queden fichas que las usen.

## 6. Integración con IA (enmienda a RFC-0003)

`ProductFactSheet` incorpora:

- La composición, el ajuste/tallaje y los cuidados del **snapshot** (respaldo: columna libre).
- El texto congelado de la guía de tallas (nota inicial + contenido + nota final), como contexto
  técnico confirmado.
- `ai_base_description`.

`PromptBuilder` recibe la instrucción explícita de usar la descripción base como contexto para
mejorar el texto, **sin copiarla literalmente** y sin ampliarla con datos que no estén
confirmados. La versión del prompt sube a `v2` porque el contrato de entrada cambia (RFC-0003
exige registrar la versión usada en cada generación).

## 7. Integración con Shopify (enmienda a RFC-0004)

`ShopifyProductPayload` deja de enviar `product_content.html_description` en crudo y pasa a
enviar el resultado de componer, **en este orden**:

1. Descripción comercial (`product_content.html_description`).
2. Composición.
3. Ajuste / tallaje.
4. Guía de tallas (su HTML).
5. Cuidados.

Cada bloque se omite si está vacío. Los encabezados de bloque los genera la aplicación
(`<h2>`), no el usuario: la whitelist de la guía de tallas se aplica al HTML *almacenado*, no a
los títulos que añade el compositor. Se usa `<h2>` porque es la convención de sección que ya
siguen las descripciones del proyecto (`<h2>Tejido y tallaje</h2>`, `<h2>Cuidado</h2>`).

El HTML compuesto es seguro por construcción: la descripción comercial ya pasó por el *cast*
`SanitizesHtml` al guardarse, el HTML de la guía de tallas pasa por su propio *cast* al
guardarse, y la composición, el ajuste y los cuidados son texto plano que se escapa al
componerse. No se aplica un segundo saneado al vuelo, que sólo podría mutilar HTML ya válido.

**No se toca ningún producto existente en Shopify**: el cambio sólo afecta a la construcción de
la carga útil, así que una ficha ya enviada conserva su borrador remoto hasta que alguien la
reenvíe explícitamente.

## 8. Whitelist de la guía de tallas

HTML permitido, y nada más: `table`, `thead`, `tbody`, `tr`, `th`, `td`, `p`, `strong`, `em`,
`br`, `ul`, `ol`, `li`.

Se implementa como una **segunda whitelist**, no ampliando la de RFC-0001: la de descripciones
sigue siendo `p, ul, ol, li, strong, em, br, h2, h3` y sigue rechazando `<table>`. Ampliarla
mezclaría dos superficies distintas y debilitaría la descripción comercial sin necesidad.

Decisiones concretas derivadas de probar HTMLPurifier con tablas reales:

- **Atributos**: no se permite ninguno, tal y como pide la lista. `colspan` y `rowspan` se
  pierden al sanear, lo que puede cambiar el significado de una tabla. Para que eso no ocurra en
  silencio, el panel **avisa** cuando el HTML pegado los contiene.
- **Celdas vacías**: se conservan. Una celda vacía es información legítima («esta talla no
  tiene medida») y borrarla desplazaría la fila. Se permite `&nbsp;` en celdas para que una celda
  vacía sobreviva al saneado.
- Se eliminan `script`, `style`, `onclick`, `iframe` y cualquier atributo de estilo o clase.

## 9. Seguridad y autorización

- Cuatro Policies nuevas, una por modelo, con dos permisos: `technical_sheets.view` (leer el
  catálogo de mantenimientos) y `technical_sheets.manage` (crear, editar y borrar).
- Reparto: los tres roles tienen `view` y `manage`. La operadora gestiona el catálogo porque es
  quien prepara las fichas y quien detecta que falta una composición o un perfil de cuidados;
  obligarla a pedirlo a otra persona sólo añadía una espera.
- Que eso sea seguro no depende de quién edita, sino de la **copia congelada**: cambiar un
  mantenimiento no altera las fichas que ya lo usan, y borrarlo tampoco. «Desactivar» es la
  alternativa al borrado: lo retira de las fichas nuevas sin tocar las existentes.
- La navegación se agrupa bajo «Mantenimientos de ficha técnica». Como recuerda el propio
  Filament, ocultar del menú **no** autoriza: la barrera son las Policies.
- Todo el HTML de la guía pasa por la whitelist del apartado 8 **al guardarse**, mediante un
  *cast* del modelo, de modo que no exista ninguna ruta que pueda persistir HTML sucio.

## 9.bis Un permiso nuevo no puede tumbar el panel

`Permission::byRole()` es código, pero los permisos viven en la base de datos: añadir un
permiso al enum **no** llega a la base hasta ejecutar `RoleAndPermissionSeeder`. En ese
intervalo, `hasPermissionTo()` lanza `PermissionDoesNotExist` y, como Filament consulta las
Policies al pintar la barra lateral, el error aparecía en **todas** las pantallas —incluido el
dashboard justo después del login— con un 500 y sin más salida que resembrar.

Ocurrió al desplegar esta fase en desarrollo y se corrige en dos frentes:

1. **La autorización deniega y sigue.** `ChecksPermissions` trata un permiso que no consta como
   no concedido y deja un aviso en el log. Denegar es la respuesta segura —un permiso que no
   existe no está concedido— y mantiene el panel en pie para poder trabajar en lo demás. No
   afecta al caso de «el permiso existe pero el rol no lo tiene», que spatie resuelve solo.
2. **El desfase se hace visible y reparable en un paso** con `php artisan studio:permissions`
   (informa) y `--sync` (aplica el reparto del enum). El comando no reimplementa el reparto:
   delega en el seeder, único origen de verdad.

El comando comprueba además el **reparto a roles**, no sólo que los permisos existan: todos los
nombres pueden estar presentes y el reparto estar vacío, que es exactamente el estado que hay
que detectar.

## 9.ter Gestión del catálogo (CRUD)

Cada mantenimiento es un recurso Filament con las cuatro operaciones, desde el listado o desde
la ficha del mantenimiento:

| Operación | Dónde | Nota |
|---|---|---|
| Listar | Listado con búsqueda por nombre y código | Filtros por estado, tipo de prenda y público |
| Crear | «Nuevo mantenimiento» | Código único validado |
| Editar | «Editar» en la fila | Cambiar el contenido sube la versión |
| Duplicar | «Duplicar» en la fila | Parte de uno existente; pide código nuevo y nace **inactivo** |
| Borrar | Desde la ficha del mantenimiento | La copia congelada de las fichas se conserva |
| Activar/desactivar | En el formulario | Alternativa al borrado: deja de ofrecerse en fichas nuevas |

«Duplicar» existe porque el caso real no es crear de cero, sino escribir una variante cercana
(otro porcentaje de composición, un ajuste parecido). El código es único, así que la copia no
puede heredarlo: se pide uno nuevo con una sugerencia ya calculada (`-COPIA`, `-COPIA-2`…) y la
copia nace **inactiva** para que una versión a medias no llegue a las fichas mientras se revisa.

## 10. Criterios de aceptación

| # | Criterio | Cómo se comprueba |
|---|---|---|
| 1 | Seleccionar una guía de tallas inserta su tabla en la previsualización de la ficha | Prueba Livewire de la página de edición |
| 2 | Modificar una guía de tallas **no** altera el snapshot de una ficha ya creada | Prueba de servicio y de composición |
| 3 | El HTML no permitido se elimina (`script`, `iframe`, atributos) | Prueba unitaria del saneador + modelo |
| 4 | Un mantenimiento inactivo no aparece en fichas nuevas | Prueba de opciones del selector |
| 5 | La descripción base llega a la IA y **no** aparece literalmente en el HTML enviado a Shopify | Pruebas de `ProductFactSheet`/`PromptBuilder` y del compositor |
| 6 | Una ficha sin ningún mantenimiento seleccionado sigue funcionando | Prueba de generación y de envío |
| 7 | El orden de bloques en Shopify es descripción, composición, ajuste, guía, cuidados | Prueba del `ShopifyProductPayload` |
| 8 | Un permiso que falta en la base no tumba el panel: se deniega y se avisa | `MissingPermissionResilienceTest` |
| 9 | El desfase de permisos se detecta y se repara en un paso | `SyncPermissionsCommandTest` |

## 11. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Editar un maestro cambia textos ya enviados | Copia congelada + versión en el snapshot (criterio 2) |
| El snapshot y la clave apuntan a versiones distintas | La clave sólo decide *qué* está seleccionado; **lo que se envía es siempre el snapshot**. El capturador reescribe ambos en la misma transacción |
| Un mantenimiento borrado deja la ficha sin composición | `nullOnDelete` en la clave y snapshot independiente: el texto sigue saliendo |
| HTML hostil pegado por descuido | *Cast* de saneado en el modelo (criterio 3) |
| Tablas con `colspan` que se degradan en silencio | Aviso en el panel al detectarlos (apartado 8) |
| Fichas antiguas sin mantenimiento | Respaldo a las columnas libres (5.4) y criterio 6 |