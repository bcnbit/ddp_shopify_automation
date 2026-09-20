# RFC-0000 — Visión, alcance y decisiones

**Estado:** Propuesto  
**Prioridad:** Bloqueante  
**Dependencias:** Ninguna

## Problema

Dar de alta un producto en Shopify requiere repetir textos, SEO, imágenes, variantes y etiquetas. Este trabajo absorbe tiempo de atención en tienda y provoca fichas desiguales.

## Decisión

Crear Shopify Product Studio, un backoffice independiente que prepara y sincroniza productos con Shopify. No sustituye Shopify como tienda: es el sistema interno de preparación y control de calidad.

## Usuarios y permisos

| Rol | Permisos |
|---|---|
| Operadora | Crear/editar fichas, subir medios, generar IA, enviar borradores, consultar errores propios. |
| Responsable catálogo | Lo anterior, aprobar publicación y configurar plantillas. |
| Administrador técnico | Credenciales, reglas, usuarios, reintentos y auditoría. |

## Estados de una ficha

`draft` → `generating` → `review` → `approved` → `syncing` → `shopify_draft` → `published`.

Estados alternativos: `generation_failed`, `validation_failed`, `sync_failed`, `archived`.

No se permite pasar a `published` desde una ficha que no tenga una sincronización correcta con Shopify.

## Decisiones de producto

- Idioma de contenido inicial: español (ES). Se almacenará preparado para CA/EN/FR en una fase posterior.
- Las fotos originales se conservan mientras la ficha exista; Shopify recibe una copia optimizada
  cuando corresponda. Al **eliminar la ficha entera** desde el panel, sus archivos (original y
  derivados) se borran del almacenamiento: ver `handoff.md` §18.
- El sistema propone, el humano aprueba. La publicación automática queda expresamente fuera del MVP.
- Los datos procedentes de IA se distinguen visualmente de los datos confirmados por una persona.

## Criterios de aceptación

- Una operadora puede crear y retomar una ficha sin pérdida de datos.
- La ficha conserva quién modificó cada dato relevante y cuándo.
- El sistema nunca expone tokens de Shopify u OpenAI en el navegador.
- El sistema nunca publica una ficha sin una acción explícita de un rol autorizado.
