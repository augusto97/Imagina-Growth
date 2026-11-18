# Historial de la especificación y de las fases

## 2026-11-18 — Cierre de la Fase 1 (Conocer al cliente)

### Criterio «Listo cuando» (medido con `infra/e2e/fase1.sh`)

La tienda demo de la Fase 0 (WooCommerce 11.3, 10.000 pedidos en COP de 24 meses) se conecta a un hub aislado e importa su historial en 66 s. Después, en Chromium real, una visitante llega desde un anuncio de Facebook (`utm_source=facebook&utm_medium=paid_social&fbclid=…`), acepta el banner propio, mira un producto, busca, compra con tarjeta por la Store API (la del checkout de bloques) marcando WhatsApp y correo, y responde «¿cómo nos conociste?». Meta se simula: `fbevents.js` en el navegador y la Graph API en el servidor.

| Criterio | Resultado |
|---|---|
| El tablero responde en menos de 1,5 s con un año de datos | **Sí.** Los 12 tableros, pedidos por HTTP con la sesión y el 2FA de la dueña, sin caché y con 12 meses comparados con los 12 anteriores: máximo **649 ms** (Clientes), Inicio 121 ms. Con navegación (`igr:seed-demo --browsing`: 10.000 pedidos, 1,78 millones de eventos de navegación y 342.025 sesiones), el p95 más lento fue Canales con 592 a 820 ms |
| Meta muestra la deduplicación correcta entre píxel y servidor | **Sí, contra Meta simulado.** El `Purchase` del píxel y el de la Conversions API llevan el mismo `event_id` (el UUIDv5 de `order.paid`), con el mismo valor y la misma moneda, `fbc` del clic, `fbp`, y correo y teléfono en SHA-256. El píxel dispara una sola vez aunque se recargue la página, y cada `PageView` tiene su `page.viewed` con el mismo `event_id`. **Falta verlo en el Administrador de eventos con una cuenta real** (ver «Pendiente») |
| El informe semanal por correo llega al dueño | **Sí.** Se entregó por SMTP a la dueña: «Tu semana en Pinturas La 14: $22.267.950 en ventas». Queda registrado en `report_deliveries` y no se envía dos veces la misma semana |

El mismo recorrido comprueba 36 puntos más, todos en verde:
- Identidad: la cookie del navegador queda en el mismo contacto que el pedido.
- Atribución: el pedido queda en `paid_meta`.
- Perfil 360: la línea de tiempo tiene navegación, carrito, checkout, pedido, encuesta y consentimiento.
- Consentimiento: queda con su texto legal versionado y la huella de la IP.
- Datos personales: ningún evento guarda el correo en claro.
- Segmentos: la compradora entra a «Nuevos» con su `segment.entered`.
- RFM: queda calculado para 1.823 clientes.

El perfil abre en 869 ms con la SPA incluida.

### Qué se entregó
- **Contrato v2:**
  - `identify.token` firmado por el sitio.
  - Eventos que solo emite el hub: `survey.answered`, `segment.entered`/`exited`, `consent.granted`/`revoked` y `conversion.delivered`. Si llegan desde un sitio, se rechazan.
  - En el navegador, la identidad solo se acepta con un token firmado.
- **Tracker (5,6 KB gzip):**
  - Registra vistas, UTM y `_igr`, clics a WhatsApp con token, búsquedas e `identify`.
  - Funciona en modo estricto o informado según haya banner o no.
  - Carga aparte, solo cuando hacen falta, el paquete de píxeles (Meta, TikTok y Google con el `event_id` del servidor; la compra solo con `igrOrder.paid`), el banner y los widgets.
- **Connector:** contexto de página, tokens firmados, banner propio y adaptadores de banners, casillas de consentimiento en los checkouts clásico y de bloques, y el espacio para la encuesta.
- **Hub:**
  - Identidad con fusiones auditadas y reversibles por 30 días, perfil 360, importación CSV con declaración del origen del consentimiento, registro de consentimiento con evidencia, encuesta «¿cómo nos conociste?» y solicitudes de acceso y borrado con plazos legales.
  - Segmentación (M04): vista previa entre 137 ms y 1,45 s con 100.000 contactos y 5 millones de eventos, recálculo en tiempo real y nocturno, y biblioteca de 17 segmentos.
  - Analítica (M05): capa semántica y 12 tableros. También RFM, 5 detectores de hallazgos e informe semanal.
  - Medición (M06): Conversions API de Meta, Data Manager de Google y Events API de TikTok con reintentos, atribución con respaldo y gasto en anuncios.
- **SPA:** Analítica, Hallazgos, Contactos, Perfil 360, Importar, Segmentos (constructor visual), Medición, Consentimiento y Encuesta, con es-CO, es-MX y es-VE.

### Errores que encontró el recorrido (corregidos con prueba)
- El perfil de un contacto con RFM daba 500 porque `rfm_computed_at` no tenía cast a fecha.
- `survey.answered` se guardaba sin el `anonymous_id`, así que no salía en el perfil. Además, la respuesta que llega antes que el pedido solo se ligaba por un job que reintenta varios minutos después. Ahora se liga al proyectar el pedido.
- Los comandos `igr:*` leían `--workspace` cada uno a su manera. Con un ID que no reconocían, unos terminaban en 0 sin hacer nada y `install-library` aplicaba a todos los espacios de trabajo. Ahora `WorkspaceOption` es uno solo y falla con un mensaje claro.
- La segmentación usaba `potential_loyalists` y el cálculo RFM `potential_loyal`. Una prueba amarra ahora las claves.
- El perfil mezclaba zonas horarias: la actividad salía en hora de Bogotá y la cabecera en la del navegador. Toda la SPA usa ahora la zona del espacio de trabajo.
- Horizon no atendía las colas `analytics` ni `triggers`, y los jobs largos tenían un `retry_after` de 90 s, menor que su timeout. Ahora hay supervisores para esas colas y la conexión `redis-long`.

### Pruebas al cierre
- **Hub:** 297 pruebas de Pest, Larastan nivel 8 sin errores y Pint.
- **SPA:** 64 pruebas de Vitest, TypeScript estricto y ESLint.
- **Tracker:** 22 pruebas de Vitest, límite de tamaño y 9 pruebas de Playwright contra WooCommerce real y un hub simulado que valida cada evento contra los esquemas.
- **Connector:** 15 pruebas unitarias y 55 con WordPress, WooCommerce, Contact Form 7 y WPForms reales; PHPCS sin errores.
- **CI:** nuevos trabajos para el tracker y su prueba de navegador.

### Cambios a la especificación (con su motivo, en el mismo commit que el código)
- `eventos.md`: contrato v2 (sube `schema_version`), regla de identidad del navegador, esquemas de los eventos del hub y tabla de hash por plataforma.
- `api-connector.md`:
  - §5.1: textos de consentimiento y píxeles en la configuración.
  - §5.4: solicitudes de privacidad desde WordPress.
  - §7: datos de la página, tokens firmados, campos de `igrConfig`, `igrOrder.paid` y modo de consentimiento efectivo.
  - §8: widgets y la respuesta «otro» de la encuesta.
- `modelo-de-datos.md` §11: columnas de la Fase 1, detalle de `conversion_deliveries` e índices bloom.
- `inteligencia.md` §3: claves RFM y segmentos del sistema `rfm_<clave>`.
- `modulos.md` M04: estado medido.
- `hub/CLAUDE.md`:
  - Decisiones de implementación.
  - §6: segmentos (retardo de 15 s, primer cálculo sin eventos y vista previa con tabla temporal).
  - §9: respaldo de la atribución.
  - §15: colas y `redis-long`.

### Pendiente y desviaciones conocidas
- **Modo de consentimiento (decidido después del cierre):** se elige en el panel entre «Según el sitio» (por defecto), «Estricto» e «Informado». El plugin y el hub lo aplican con el mismo criterio (`cumplimiento.md` §3.3, `api-connector.md` §7).
- **Verificar con cuentas reales** (`[verificar]` en `eventos.md` §7 y `config/igr-measurement.php`):
  - La deduplicación en el Administrador de eventos de Meta.
  - Las versiones de las API.
  - Los campos de Data Manager de Google.
  - Los nombres de evento de TikTok.
- **Recorrido manual grabado** (CLAUDE.md §6): lo graba el equipo. El recorrido automático deja video del panel y capturas, pero no reemplaza esa grabación.
- **Lo que llega en otras fases:**
  - Que una entrada o salida de segmento dispare un flujo en menos de 2 minutos se mide en la Fase 2, cuando existan los flujos. Hoy el recálculo corre 15 s después del evento.
  - Las condiciones de mensajería, fidelización y servicios muestran la fase en que llegan.
- **Sin probar:**
  - Gravity Forms y Elementor Pro, que son de pago.
  - Safari con ITP en un navegador real.
  - El espacio de la página de gracias frente al CLS en temas reales.
  - La regla de Cloudflare para el ping en sitios con «cache everything».
- **Otros pendientes:**
  - Agregar o quitar a mano miembros sueltos de una lista fija.
  - El admin del plugin en React.
  - La prueba de carga con k6 (500 eventos/s, pendiente desde la Fase 0).
  - `caddy validate` sobre `infra/Caddyfile`.
- **Limitaciones conocidas:**
  - El filtro por dispositivo solo acota las métricas de tráfico, porque los pedidos no traen dispositivo.
  - La tienda demo no trae historial de navegación. Por eso, en el recorrido, «Sesiones» y «Conversión» de 12 meses salen en 0; el rendimiento con navegación se midió con `igr:seed-demo --browsing`.

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
