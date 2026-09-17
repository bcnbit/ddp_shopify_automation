# RFC-0001 — Fundación técnica, seguridad y modelo de datos

**Estado:** Propuesto  
**Depende de:** RFC-0000

## Objetivo

Crear el proyecto Laravel, el acceso al panel, almacenamiento, cola y el modelo que permite preparar fichas sin depender de Shopify ni de IA.

## Entidades

### `products`

Campos principales: `id`, `status`, `internal_reference`, `source_name`, `brand`, `product_type`, `audience`, `price`, `compare_at_price`, `currency`, `composition`, `fit`, `care_instructions`, `collection_context`, `notes`, `shopify_product_gid`, `shopify_handle`, `last_synced_at`, `created_by`, `approved_by`, timestamps.

### `product_variants`

`id`, `product_id`, `sku`, `barcode`, `option1_name`, `option1_value`, `option2_name`, `option2_value`, `price`, `compare_at_price`, `inventory_policy`, `shopify_variant_gid`, `position`.

El MVP usa como máximo dos opciones: Color y Talla. No permitir variantes duplicadas por combinación de opciones.

### `product_media`

`id`, `product_id`, `disk`, `path`, `original_filename`, `mime_type`, `bytes`, `sha256`, `width`, `height`, `alt_text`, `sort_order`, `shopify_media_gid`, `upload_status`.

### `product_content`

Una fila por idioma y versión de propuesta: `product_id`, `locale`, `title`, `handle`, `html_description`, `seo_title`, `seo_description`, `tags_json`, `product_category_taxonomy_id`, `ai_model`, `prompt_version`, `generated_at`, `approved_at`.

### Auditoría y tareas

- `activity_log`: actor, tipo de evento, entidad, diff estructurado e IP.
- `sync_attempts`: operación, idempotency key, petición saneada, respuesta, estado y error.
- Usar Laravel Jobs con `product_id` y un bloqueo único por producto.

## Seguridad

- Autenticación mediante Laravel Fortify o equivalente, con 2FA para administradores.
- Tokens en variables de entorno/gestor de secretos; nunca en tablas sin cifrar ni en logs.
- Cifrar a nivel de aplicación los campos de credenciales que excepcionalmente se persistan.
- Validar MIME, tamaño, dimensiones y checksum de cada archivo. Rechazar SVG en MVP.
- Autorización por Policies, no sólo por ocultación de botones.
- Sanitizar HTML generado antes de guardar y antes de enviar a Shopify: lista blanca de etiquetas `p`, `ul`, `ol`, `li`, `strong`, `em`, `br`, `h2`, `h3`.

## Criterios de aceptación

- Migraciones, factories y seeders para todas las entidades.
- Usuario Operadora no puede configurar conexiones ni publicar.
- Se puede ejecutar una cola local y una cola Redis en producción.
- Los logs no contienen secretos, prompts con imágenes en base64 ni HTML completo.
