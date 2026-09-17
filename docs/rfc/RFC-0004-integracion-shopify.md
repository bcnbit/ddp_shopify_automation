# RFC-0004 — Integración Shopify y sincronización segura

**Estado:** Propuesto  
**Depende de:** RFC-0001, RFC-0002, RFC-0003

## Objetivo

Crear y actualizar productos de Shopify con estado borrador, sin duplicados y con trazabilidad completa.

## Autenticación y permisos

Usar una aplicación personalizada instalada en una única tienda. Solicitar sólo scopes necesarios: lectura/escritura de productos, archivos y publicaciones si se habilita la publicación posterior. El token queda exclusivamente en backend.

Centralizar llamadas en `ShopifyProductGateway`; ninguna pantalla llama directamente a la API. Configurar versión de API por variable de entorno y registrar cabeceras de límite de llamadas.

## Operaciones

1. Validar la ficha local.
2. Calcular `idempotency_key = hash(product_id + content_version + operation)`.
3. Crear o actualizar el producto con estado `DRAFT`.
4. Crear/actualizar opciones y variantes.
5. Subir/adjuntar medios y ALT.
6. Actualizar SEO, handle, tags, tipo, proveedor y categoría Shopify cuando estén aprobados.
7. Guardar GIDs devueltos en local y registrar el intento.

El orden exacto debe seguir las mutaciones vigentes de Shopify Admin GraphQL. Encapsularlas para que un cambio de API no afecte al dominio de la aplicación.

## Idempotencia y recuperación

- Antes de crear, buscar por metafield privado de `product_studio_id` y por SKU/handle como comprobación secundaria.
- Todo producto sincronizado recibe el metafield `product_studio_id` no visible para tienda.
- Si falla un paso, la tarea queda reintentable y muestra el recurso ya creado.
- Nunca repetir una carga de medio con mismo `sha256` y mismo producto.
- El botón de reintento continúa, no reinicia, la sincronización.

## Publicación

El MVP sólo sincroniza `DRAFT`. La acción futura de publicación debe ser un job separado, con confirmación, permiso de Responsable y comprobación de campos obligatorios.

## Criterios de aceptación

- Dos clics seguidos sobre “Enviar a Shopify” terminan con un único producto remoto.
- Se puede modificar una ficha enviada y actualizar el mismo producto remoto.
- Un error de API es legible para la usuaria y conserva el identificador de soporte técnico.
