# RFC-0006 — Pruebas, aceptación y piloto

**Estado:** Propuesto  
**Depende de:** RFC-0001 a RFC-0005

## Estrategia de pruebas

- Unitarias: normalización SEO, reglas de variantes, validadores, sanitización y cálculo de idempotencia.
- Integración: gateway Shopify con respuestas simuladas, creación/actualización/reintento y errores de límite.
- Feature: permisos, flujo de ficha, guardado, generación y aprobación.
- E2E: alta de una camiseta y una sudadera hasta borrador Shopify en tienda de desarrollo.

## Casos críticos

1. Producto con colores y tallas genera las variantes correctas y sus SKU.
2. Doble envío simultáneo no crea duplicados.
3. Fallo al subir la tercera foto se reanuda correctamente.
4. IA intenta afirmar un material no proporcionado: aparece advertencia y no se integra en contenido aprobado.
5. Usuario Operadora no puede publicar ni leer credenciales.
6. Actualizar el precio de un borrador remoto modifica el mismo producto.
7. Una ficha con meta deficiente puede enviarse si no hay errores bloqueantes, pero queda señalada.

## Piloto

Trabajar con 15 productos reales de categorías distintas. Medir tiempo desde ficha vacía a borrador, número de correcciones, errores de sincronización y calidad percibida. Ajustar perfiles y prompts sólo tras revisar esos resultados.

## Criterios de salida

- 100% de los productos piloto creados como borrador sin duplicados.
- 0 publicaciones accidentales.
- Tiempo mediano de alta inferior a 3 minutos excluyendo la selección/carga de fotos.
- Todas las fichas tienen título, descripción, SEO y ALT aprobados.
