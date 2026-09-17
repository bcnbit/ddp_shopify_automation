# RFC-0005 — Medios, variantes y validación de catálogo

**Estado:** Propuesto  
**Depende de:** RFC-0002, RFC-0004

## Objetivo

Evitar los dos errores más caros del alta: imágenes deficientes y variantes incorrectas.

## Medios

- Admitir JPG, PNG y WebP; límite inicial 20 MB por archivo.
- Generar miniaturas y una copia optimizada sin borrar el original.
- Comprobar orientación, resolución mínima configurable y posible duplicado por checksum perceptual.
- La foto principal debe ser el producto claramente visible; el sistema puede sugerirla pero no cambiarla sin confirmación.
- Cada imagen debe tener ALT aprobado antes de sincronizar.

## Variantes

- La fuente de verdad es la tabla local de variantes, no el texto de descripción.
- Combinaciones por Color y Talla; precio general heredado salvo excepción explícita.
- SKU obligatorio y único dentro del catálogo que gestione la aplicación.
- El MVP crea inventario sin cantidad inicial o con la política definida en configuración; no inventa stock.
- Validar que opciones, valores y variantes no queden desalineados.

## Validaciones SEO y comerciales

| Regla | Severidad |
|---|---|
| Precio ausente o no positivo | Bloqueante |
| SKU vacío/duplicado | Bloqueante |
| Sin foto principal | Bloqueante |
| Sin título o descripción | Bloqueante |
| Meta title fuera de rango | Aviso |
| Meta description fuera de rango | Aviso |
| Handle duplicado | Bloqueante |
| Composición no confirmada pero mencionada | Bloqueante |
| Imagen de baja resolución | Aviso |

## Criterios de aceptación

- La matriz de variantes no permite guardar duplicados.
- Las imágenes preservan orden y ALT al llegar a Shopify.
- La aplicación bloquea la sincronización ante cualquier validación bloqueante.
