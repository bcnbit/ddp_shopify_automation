# RFC-0003 — IA, SEO y contenido de producto

**Estado:** Propuesto  
**Depende de:** RFC-0002

## Objetivo

Generar una propuesta comercial consistente, útil para buscadores y fiel a la información confirmada.

## Contrato de entrada a IA

Enviar únicamente: datos confirmados de la ficha, estilo de marca, idioma, variantes y hasta cuatro fotos representativas redimensionadas. Nunca incluir credenciales ni datos de clientes.

## Salida JSON obligatoria

La IA debe responder JSON validable contra esquema:

```json
{
  "title": "string",
  "short_benefit": "string",
  "html_description": "string",
  "seo_title": "string",
  "seo_description": "string",
  "handle_suggestion": "string",
  "tags": ["string"],
  "alt_texts": [{"media_id": 1, "text": "string"}],
  "facts_detected": ["string"],
  "warnings": ["string"]
}
```

El servidor valida el esquema, escapa HTML y normaliza las etiquetas antes de persistir. No usar una respuesta de texto libre.

## Normas editoriales

- Tono: cercano, concreto y sin promesas exageradas.
- Descripción: 120–220 palabras para prenda estándar; estructura con beneficio, diseño/uso, tejido/tallaje sólo si está confirmado y cuidado.
- SEO title: objetivo 50–60 caracteres; incluir tipo de producto y diferenciador real.
- Meta description: objetivo 140–160 caracteres; no repetir palabras de forma artificial.
- Handle: minúsculas, guiones, sin fechas ni caracteres especiales; se verifica unicidad contra Shopify.
- ALT: describe exactamente la imagen y el producto, sin listas de keywords.
- Tags: entre 5 y 12; separar tipo, color, colección, público y atributo confirmado.

## Prohibiciones de la IA

No afirmar “algodón orgánico”, “hecho en España”, “edición limitada”, “unisex”, “oversize”, medidas, certificados, disponibilidad, rebajas, tiempos de envío o compatibilidades si no constan en los datos confirmados. Ante ausencia de un dato, emite una advertencia o no lo menciona.

## Plantillas por familia

Implementar `ContentProfile` versionado para: camiseta, sudadera, bolso/neceser y prenda infantil. Cada perfil aporta instrucciones, estructura HTML, reglas de tags y FAQ opcionales. El usuario puede elegirlo, pero no editar prompts desde el flujo operativo.

## Coste y resiliencia

- Una generación por ficha usa una tarea asíncrona y muestra progreso.
- Reintentar fallos transitorios hasta dos veces con backoff.
- Guardar modelo, versión de prompt, tokens/coste estimado y latencia por ejecución.
- Limitar regeneraciones por ficha y día, configurable por administrador.

## Criterios de aceptación

- La salida se rechaza si no cumple JSON Schema.
- Las advertencias de datos no confirmados se presentan a la usuaria antes de aprobación.
- Un cambio manual no se sobrescribe al regenerar otro campo.
