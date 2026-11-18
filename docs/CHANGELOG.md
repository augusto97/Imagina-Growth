# Historial de la especificación y de las fases

## 2026-11-18 — Cierre de la Fase 0 (Cimientos)

### Criterio «Listo cuando» (medido con `infra/e2e/fase0.sh`)

Tienda WooCommerce 11.3 (HPOS) con `wp igr demo-store`: 2.000 clientes (1.296 registrados), 10.000 pedidos en COP de 24 meses, 511 reembolsos, 334 reseñas, pagos PSE, Nequi, contraentrega y transferencia. Hub con MariaDB 11.4, ClickHouse 25.8 y Redis 7.

| Criterio | Resultado |
|---|---|
| Se conecta en menos de 2 minutos | **1 s** (código manual y `wp igr connect`) |
| El backfill completo termina sin errores | **Sí**: el plugin envió todo en 47 s; el hub terminó de procesar 20.917 eventos a los 224 s, con **0 rechazos** |
| Conteos de pedidos y venta total coinciden al 100% | **Sí**, en los 7 estados: 10.000 / 10.000 pedidos y $1.916.485.780 / $1.916.485.780 |

Comprobaciones adicionales: 0 contactos con contadores desfasados, 0 pedidos sin contacto y ninguna propiedad con correos en ClickHouse. La misma prueba con `php artisan igr:seed-demo` (sin WordPress) también concilia al 100%.

### Qué se entregó
- **Contrato v1:** 43 esquemas JSON con ejemplos válidos e inválidos y el contrato en el cable `docs/api-connector.md` (firma, handshake, ingesta, configuración, conciliación, comandos e IDs deterministas).
- **Hub (Laravel 13):** tenancy con pruebas de aislamiento, M01 (cuentas, espacios de trabajo, roles, agencia, 2FA obligatoria, invitaciones, auditoría), M02 (conexión, rotación, desconexión, conciliación), ingesta `/v1/s` y `/v1/b` con Redis Streams, worker con dedupe, esquemas, canal (inteligencia §2), identidad determinista, proyecciones en MariaDB y ClickHouse sin PII, festivos de Colombia y México, `igr:seed-demo`, `/health` y el `ask` de TLS bajo demanda.
- **SPA:** acceso con 2FA, onboarding, `/connect` para el plugin, sitios con progreso y conciliación, Inicio, Contactos, Equipo, ajustes y auditoría.
- **Connector 0.1.0:** conexión, outbox con reintentos, recolectores de WooCommerce, catálogo con conciliación por hash, backfill con conciliación, comandos firmados, admin en PHP, WP-CLI y la tienda demo.
- **Infraestructura:** Docker Compose local y de producción, imagen FrankenPHP, Caddyfile, copias de seguridad con guía de restauración y CI (hub, SPA y connector).

### Pruebas al cierre
Hub: 151 de Pest (unidad, contrato, integración y tenancy) contra bases reales, Larastan nivel 8 y Pint sin errores. SPA: TypeScript estricto, ESLint y 16 de Vitest. Connector: 15 unitarias y 27 con WordPress + WooCommerce reales que validan cada evento contra los esquemas del hub; PHPCS sin errores.

### Cambios a la especificación (con su motivo, en el mismo commit que el código)
- `eventos.md`: `last_touch`, foto opcional del pedido en eventos de estado, `modified_at`, `catalog.category_deleted`, datos de cliente para la importación, `hash` en `catalog.category_upserted` y `items[].cost` como costo unitario. Se hicieron antes de publicar el contrato v1, por eso no sube `schema_version`.
- `modelo-de-datos.md`: `ch_id` numérico para ClickHouse, `site_connect_codes`, `workspace_invitations`, columnas de sitios y pedidos, y `email_hash`/`phone_hash` como HMAC por espacio de trabajo (un SHA-256 plano se revierte con diccionario).
- `hub/CLAUDE.md`: auditoría con tabla propia, Fortify, opis/json-schema, cliente propio de ClickHouse y el worker de ingesta fuera de Horizon, con transacción por lote.

### Pendiente y desviaciones conocidas
- **Recorrido manual grabado** (CLAUDE.md §6): lo debe grabar el equipo con el entorno local de `infra/README.md`. Hay capturas de la SPA de la prueba automática, pero no un video.
- La prueba de punta a punta usa WP-CLI contra WooCommerce real, no Playwright con `wp-env`; la matriz de temas, cachés y pasarelas de `connector/CLAUDE.md` §14 queda para antes de la primera versión pública.
- El admin del plugin es una página PHP; el admin en React (`admin-app/`) llega después.
- `infra/Caddyfile` no se validó con `caddy validate` (no hubo acceso al binario en este entorno).
- Ingesta medida en ~117 eventos/s por shard; la prueba de carga con k6 (500 eventos/s) queda para la Fase 1.
- Fusiones de contactos (auditadas y reversibles), tracker y todo lo de la Fase 1 siguen pendientes.

## 2026-11-17 — Especificación inicial
- Visión, arquitectura (plugin ligero + hub en Laravel + ClickHouse), hoja de ruta en 6 fases.
- Contrato de eventos v1, modelo de datos, 22 módulos, inteligencia (RFM adaptado a LATAM, predicciones, 23 detectores de hallazgos, IA), cumplimiento (Ley 1581, Ley 2300, LFPDPPP de México, políticas de WhatsApp), análisis de competencia.
