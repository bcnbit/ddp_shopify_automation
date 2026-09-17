# RFC-0002 — Flujo de alta, edición y aprobación

**Estado:** Propuesto  
**Depende de:** RFC-0001

## Objetivo

Crear una experiencia rápida para alta de productos, sin trasladar a la usuaria el modelo técnico de Shopify.

## Pantalla “Nueva ficha”

Campos obligatorios:

- Referencia interna o SKU base.
- Nombre provisional o descripción breve.
- Precio de venta, IVA incluido.
- Tipo de prenda.
- Al menos una foto.
- Al menos una variante vendible o confirmación de producto sin variantes.

Campos recomendados: marca, composición, ajuste/tallaje, colores, tallas, cuidados, colección/campaña, observaciones y categoría interna.

El formulario permite arrastrar fotos, ordenar con drag-and-drop y marcar foto principal. Guardado automático cada pocos segundos y al cambiar de sección.

## Pantalla “Propuesta IA”

Dividir en tres columnas o pestañas: datos verificados, contenido comercial y SEO/Shopify. Cada campo generado tiene acciones `Aceptar`, `Editar`, `Regenerar este campo` y `Restaurar propuesta`.

Mostrar advertencias claras: composición pendiente, foto de baja resolución, SKU repetido, título demasiado largo, meta description fuera de rango y variante incompleta.

## Reglas de aprobación

- No enviar a Shopify si falta precio, SKU por variante, foto principal, título o descripción.
- No enviar si hay errores bloqueantes.
- La operadora puede enviar como borrador; un Responsable publica desde el panel después de comprobar el producto de Shopify.
- Si Shopify ya conoce `shopify_product_gid`, el botón pasa a `Actualizar borrador`; no crea otro producto.

## Listado de trabajo

Filtros por estado, fecha, usuaria, tipo, colección y errores. Métricas: fichas creadas, pendientes, tiempo medio a borrador y fallos de sincronización.

## Criterios de aceptación

- Crear una ficha, abandonar el navegador y retomarla conserva contenido y medios.
- Las variantes se generan a partir de la matriz color × talla y pueden eliminarse individualmente.
- Cada acción crítica exige confirmación y deja rastro en auditoría.
