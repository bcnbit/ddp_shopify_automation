# RFC-0007 — Despliegue, observabilidad y operación

**Estado:** Propuesto (procedimiento concreto en RFC-0010)  
**Desarrollada por:** RFC-0010 — Despliegue en producción  
**Depende de:** RFC-0006

## Entornos

- `local`: desarrollo y datos ficticios.
- `staging`: Shopify development store y credenciales de prueba.
- `production`: tienda real y acceso restringido.

No se prueban mutaciones de catálogo contra la tienda real antes de validar en staging.

## Despliegue

Contenedores o servidor Laravel gestionado, proceso de despliegue con migraciones seguras, cache de configuración, workers de cola y scheduler. Añadir comprobación de salud para web, base de datos, Redis, cola y conectividad Shopify.

## Observabilidad

- Registro estructurado con `request_id`, `product_id`, `sync_attempt_id` y `job_id`.
- Alertas para cola detenida, fallos de sincronización repetidos, token inválido y porcentaje de errores de IA.
- Panel de “Sincronizaciones con error” con causa, último intento y botón de reintento autorizado.
- Copias de seguridad verificadas de base de datos y media original.

## Operación diaria

La operadora trabaja en `Pendientes`. El responsable revisa `Enviados a Shopify`. El administrador revisa a diario los errores y semanalmente el consumo de IA. Las credenciales se rotan ante cambios de personal o incidente.

## Criterios de aceptación

- Un fallo de worker o proveedor externo no deja fichas en estado ambiguo.
- Se puede recuperar una sincronización fallida sin intervención de base de datos.
- El despliegue no interrumpe trabajos en curso ni pierde tareas de cola.
