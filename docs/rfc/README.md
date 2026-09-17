# Shopify Product Studio — documentación inicial

Proyecto para acelerar el alta de productos de Dies de Platja en Shopify sin publicar contenido de forma automática ni inventar datos de producto.

## Objetivo de la primera versión

Una usuaria crea una ficha con datos verificados y fotografías. El sistema genera una propuesta SEO completa, permite revisarla y crea el producto en Shopify con estado **borrador**. La publicación definitiva se realiza sólo tras una revisión humana en el panel.

## Principios no negociables

- No reutilizar código ni depender de PureBasic.
- La información física y comercial confirmada prevalece sobre cualquier sugerencia de IA.
- La IA no puede inventar composición, certificaciones, origen, medidas, disponibilidad, precio ni condiciones de envío.
- Toda sincronización inicial con Shopify termina en `DRAFT`.
- Un error de integración debe ser recuperable e idempotente: no se duplican productos, imágenes ni variantes.
- El panel está pensado para una operadora no técnica y se usará principalmente en castellano.

## Alcance del MVP

Incluye camisetas, sudaderas, bolsas y prendas simples con variantes por talla y color. Genera textos, SEO, ALT, variantes y subida de imágenes. Excluye inicialmente inventario multi-almacén, traducciones publicadas, Google Merchant Center, descuentos, bundles y edición masiva de catálogo existente.

## Orden de ejecución

1. RFC-0000 — Visión, decisiones y criterios de aceptación.
2. RFC-0001 — Base Laravel, seguridad y modelos.
3. RFC-0002 — Flujo de alta y revisión.
4. RFC-0003 — Generación IA y normas SEO.
5. RFC-0004 — Conector Shopify e idempotencia.
6. RFC-0005 — Medios, variantes y validaciones.
7. RFC-0006 — Pruebas y aceptación operativa.
8. RFC-0007 — Despliegue, observabilidad y operación.

Cada RFC debe completarse con pruebas antes de avanzar a la siguiente. Si una decisión posterior cambia un contrato, se crea una RFC de enmienda; no se modifica silenciosamente un flujo ya implementado.

## Stack decidido

- Laravel 12, PHP 8.3+, MySQL 8 y Redis.
- Filament 4 para el backoffice; Blade/Livewire cuando haga falta una interfaz a medida.
- Cola Laravel + Horizon para generación IA y sincronizaciones.
- Storage S3-compatible para originales y derivados temporales.
- Shopify Admin GraphQL API, mediante una aplicación personalizada con token restringido.
- OpenAI Responses API con capacidad de texto e imagen, encapsulada detrás de una interfaz propia.

## Resultado funcional esperado

Alta de un producto en menos de dos minutos: seleccionar fotos, confirmar datos esenciales, pulsar “Generar propuesta”, ajustar si procede y “Enviar como borrador a Shopify”.
