# API pública, webhooks y servidor MCP (M19)

> Guía para quien integra otro sistema con Imagina Growth: Zapier, Make, n8n, un CRM, un ERP, los productos de Imagina o un agente de IA. La referencia de cada endpoint (parámetros y respuestas) la genera Scramble en `https://growth.imagina.cloud/api/docs` (solo la ven el dueño y los administradores del espacio de trabajo).

---

## 1. Autenticación

- Cada token pertenece a **un espacio de trabajo** y se crea en **API e integraciones → Tokens** (dueño o administrador, permiso `manageApi`).
- Se envía en cada petición: `Authorization: Bearer igr_…`.
- El token completo se muestra **una sola vez**. El hub guarda solo su hash y los primeros caracteres (`igr_12|abCD…`) para reconocerlo en la lista.
- Revocar un token lo apaga al instante (`401 token_revoked`).
- Un token no es de una persona: sigue funcionando aunque quien lo creó salga del equipo. Crea un token por integración.

## 2. Alcances

| Alcance | Permite |
|---|---|
| `contacts:read` | Listar, buscar y ver contactos (datos personales enmascarados) |
| `contacts:write` | Crear o unir contactos y registrar sus consentimientos |
| `contacts:pii` | Ver correos, teléfonos y nombres completos; recibir datos personales en webhooks creados por la API |
| `events:write` | Enviar eventos del servidor y métricas web |
| `orders:read` | Ver pedidos |
| `segments:read` | Ver segmentos y su número de miembros |
| `campaigns:write` | Crear **borradores** de campaña (y, en MCP, de segmento) |
| `leads:read` | Ver leads |
| `leads:write` | Crear leads y cambiar su etapa |
| `webhooks:manage` | Suscribir y borrar webhooks (REST hooks de Zapier, Make y n8n) |
| `analytics:read` | Consultar métricas e indicadores |
| `mcp` | Usar el servidor MCP (se agrega solo a los tokens de tipo MCP) |

Sin el alcance, la respuesta es `403 missing_scope`.

## 3. Plan y límites

- El plan debe incluir el módulo `api` (y `mcp` para el servidor MCP). Si no, `402 plan_required`.
- Límite por token y minuto: `api_requests_per_minute` del plan, **120** por defecto. Cada respuesta trae `X-RateLimit-Limit` y `X-RateLimit-Remaining`; al pasarlo, `429 rate_limited` con `Retry-After`.
- Cada petición autenticada suma al uso mensual `api_requests` del espacio de trabajo (M21).

## 4. Formato

- JSON en UTF-8, fechas ISO 8601, montos como texto decimal (`"282000.00"`) en la moneda del negocio salvo que el campo diga otra.
- IDs con prefijo: `ws_`, `st_`, `ct_`, `ord_`, `sg_`, `cmp_`, `ld_`, `whk_`.
- **Paginación por cursor:** `?limit=` (1 a 100, 25 por defecto) y `?cursor=` con el `next_cursor` de la respuesta anterior. Lo más nuevo primero. `next_cursor: null` = última página.

```json
{ "data": [ … ], "next_cursor": "eyJpZCI6IjAxSkQ…" }
```

## 5. Errores

Siempre con la misma forma; `code` es estable (para programas) y `message` va en español (para personas):

```json
{ "error": { "code": "validation_failed", "message": "Revisa los datos enviados.", "fields": { "email": ["El correo no es válido."] } } }
```

| HTTP | `code` |
|---|---|
| 400 | `bad_request` |
| 401 | `unauthenticated`, `token_revoked` |
| 402 | `plan_required` |
| 403 | `missing_scope`, `forbidden` |
| 404 | `not_found` |
| 409 | `idempotency_conflict`, `idempotency_in_progress`, `leads_disabled` |
| 422 | `validation_failed`, `no_site`, `invalid_query`, `events_rejected` |
| 429 | `rate_limited` |
| 501 | `vitals_not_enabled` |

## 6. Idempotencia

Las escrituras (`POST contacts`, `events`, `campaigns`, `leads`, `webhooks`, `PATCH leads/{id}`) aceptan `Idempotency-Key: <tu-clave>` (hasta 255 caracteres). Durante 24 h:

- la misma clave con el mismo cuerpo devuelve la respuesta guardada con `Idempotent-Replayed: true`;
- la misma clave con otro cuerpo responde `409 idempotency_conflict`.

Los eventos además son idempotentes por `event_id`.

## 7. Endpoints (`/api/v1`)

| Método y ruta | Alcance | Qué hace |
|---|---|---|
| `GET me` | — | Espacio de trabajo, alcances del token y límite por minuto |
| `GET contacts` | `contacts:read` | Lista o busca: `email`, `phone` (exactos, con índice ciego), `updated_since` |
| `GET contacts/{ct_…}` | `contacts:read` | Contacto con sus consentimientos |
| `POST contacts` | `contacts:write` | Crea o une por correo/teléfono (`email`, `phone`, `first_name`, `last_name`, `city`, `country`, `renewal_at` (AAAA-MM-DD, flujo 20 de renovación; `null` la borra), `tags`, `overwrite`, `site_id`, `consents`) |
| `POST events` | `events:write` | Hasta 100 eventos: `custom.*`, `form.submitted`, `identify` |
| `POST vitals` | `events:write` | Métricas web de Imagina Turbo (501 hasta que exista `page.vitals`) |
| `GET orders` · `GET orders/{ord_…}` | `orders:read` | Pedidos; filtros `contact_id`, `status`, `paid`, `created_since`, `updated_since`; el detalle trae las líneas |
| `GET segments` · `GET segments/{sg_…}` | `segments:read` | Segmentos con `member_count` |
| `POST campaigns` | `campaigns:write` | Borrador de campaña (`name`, `channel`, `segment_ids`) con `panel_url` para revisarla |
| `GET leads` · `GET leads/{ld_…}` | `leads:read` | Leads; filtros `stage`, `contact_id`, `temperature`, `updated_since` |
| `POST leads` | `leads:write` | Crea el lead (`contact_id` o `email`/`phone`, `service`, `value`, `message`, `external_id`, `source`) |
| `PATCH leads/{ld_…}` | `leads:write` | `stage` (+ `lost_reason`), `value`, `next_step`, `next_step_at`, `external_crm_id` |
| `GET analytics/catalog` | `analytics:read` | Métricas y dimensiones con su definición |
| `POST analytics/query` | `analytics:read` | `metrics` (1–4), `dimensions` (0–2), `from`, `to`, `compare`, `granularity`, `channel`, `device`, `city`, `limit` |
| `GET analytics/kpis` | `analytics:read` | KPI del tablero por `month=AAAA-MM` o `from`/`to`, con comparación |
| `GET/POST webhooks` · `DELETE webhooks/{whk_…}` | `webhooks:manage` | Suscripciones REST hooks (§10) |

### Contactos y consentimiento

- Sin `contacts:pii`, `email`, `phone`, `first_name` y `last_name` vuelven enmascarados (`a***@ejemplo.com`).
- Los consentimientos **solo** se registran si vienen en `consents`: `[{ "channel": "whatsapp", "action": "granted", "legal_text_version": "v3", "occurred_at": "…", "page_url": "…" }]`. Quedan con origen `api`, el token como evidencia y la versión del texto legal (si no se envía, la vigente del canal). Quien llama responde por haber mostrado ese texto (cumplimiento.md §3.1).
- Se necesita un sitio conectado (o `site_id`): la identidad se resuelve igual que en la ingesta.

### Eventos

```json
POST /api/v1/events
{ "events": [ { "event": "custom.cotizacion_enviada", "event_id": "0193a7b2-…", "occurred_at": "2026-11-17T18:05:00-05:00",
                "identity": { "email": "ana@ejemplo.com" }, "properties": { "valor": 120000 } } ] }
→ 202 { "accepted": 1, "rejected": [] }
```

El hub arma el sobre de eventos.md §2 (`source.type = server`, `sdk = hub/api-v1`), valida sobre y propiedades contra el contrato y lo manda a la misma ingesta que el plugin. Motivos de rechazo: `event_not_allowed`, `properties`, `envelope`, `site_not_found`, `site_mismatch`, `too_large`.

## 8. Ecosistema Imagina

En **API e integraciones → Ecosistema Imagina**, «Conectar» crea un token con los alcances del producto y, si se da su URL, el webhook con sus eventos:

| Producto | Alcances | Webhook |
|---|---|---|
| Imagina CRM | contactos (con datos personales), leads, pedidos | `contact.created`, `form.submitted`, `lead.*`, `appointment.*`, `quote.requested`, `order.paid` |
| Imagina Base | contactos, pedidos, segmentos | `order.*`, `customer.registered` |
| Imagina Proposals | contactos, leads | `lead.stage_changed`, `lead.won`, `quote.requested` |
| Imagina Reports | `analytics:read` (`GET analytics/kpis?month=`), segmentos, pedidos | — |
| Imagina Search | `analytics:read`, `events:write` | — |
| Imagina Turbo | `events:write` (`POST vitals`) | — |

[verificar] Nombres y URL de cada producto con su equipo.

## 9. Webhooks salientes

### 9.1 Suscripción

En **API e integraciones → Webhooks** (o por la API, §10): URL **HTTPS pública**, eventos exactos (`order.paid`), por grupo (`lead.*`) o todos (`*`), y si incluye datos personales. El secreto `whsec_…` se muestra una sola vez; se puede rotar.

Eventos: los de eventos.md §4 que llegan a la ingesta o genera el hub (pedidos, carritos, contactos, leads, citas, cotizaciones, mensajes, reseñas, fidelización, suscripciones, `custom.*`), más `contact.created` (contacto nuevo, una vez por contacto) y `webhook.ping` (prueba).

### 9.2 Cuerpo

```json
{
  "id": "0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b7a",
  "event": "order.paid",
  "occurred_at": "2026-11-17T18:05:00-05:00",
  "workspace_id": "ws_01JD…",
  "site_id": "st_01JD…",
  "contact_id": "ct_01JD…",
  "data": { "order": { "order_id": "1234", "totals": { "total": "282000.00" }, "billing": { "city": "Manizales" } } }
}
```

Sin «incluir datos personales», `data` va sin correos, teléfonos, nombres ni direcciones y no hay bloque `contact`. Con la opción: `data` completo y `"contact": { "email", "phone", "first_name", "last_name" }`. `id` es el `event_id`: úsalo para no procesar dos veces.

### 9.3 Cabeceras y firma

| Cabecera | Valor |
|---|---|
| `X-IGR-Signature` | `t=<unix>,v1=<hex>` con `v1 = HMAC-SHA256(secreto, "<t>.<cuerpo>")` (mismo esquema que el connector, api-connector.md) |
| `X-IGR-Event` | `order.paid` |
| `X-IGR-Delivery` | `whd_123` (cada intento tiene el suyo) |
| `X-IGR-Attempt` | `1`, `2`… |

Verifica la firma sobre los bytes recibidos, con comparación de tiempo constante, y rechaza `t` con más de 5 minutos de diferencia.

```php
[$t, $v1] = /* separar "t=…,v1=…" */;
$ok = abs(time() - $t) <= 300 && hash_equals(hash_hmac('sha256', $t.'.'.$body, $secret), $v1);
```

### 9.4 Entrega, reintentos y pausa

- Éxito: respuesta 2xx en menos de 10 s. No se siguen redirecciones.
- Si falla: reintentos a **1 min, 5 min, 30 min, 2 h y 12 h** (6 intentos en total).
- Tras **15 fallos seguidos** la suscripción se **pausa** y los dueños reciben un correo. Se reactiva desde el panel.
- El registro de entregas (30 días) muestra cada intento con su código y duración; se puede **reenviar** cualquier entrega (mismo cuerpo, mismo `id`) y **probar** con `webhook.ping`.

## 10. Zapier, Make y n8n

- **Zapier y Make (REST hooks):** con un token con `webhooks:manage`, al activar el disparador hacen `POST /api/v1/webhooks` con `{ "url": "<su URL>", "events": ["lead.created"] }` (guardan el `secret` y el `id`) y al apagarlo `DELETE /api/v1/webhooks/{id}`. Para recibir datos personales, `"include_pii": true` y el token con `contacts:pii`.
- **n8n:** nodo *Webhook* como URL de una suscripción creada en el panel; verifica la firma en un nodo *Code* con el secreto. Para acciones, nodo *HTTP Request* con `Authorization: Bearer igr_…`.
- Acciones típicas: crear o actualizar contacto (`POST contacts`), crear lead (`POST leads`), mover etapa (`PATCH leads/{id}`), enviar evento (`POST events`).

## 11. Servidor MCP

- **URL:** `https://growth.imagina.cloud/api/mcp` (transporte HTTP «streamable»).
- **Token:** créalo en **API e integraciones → Servidor MCP** (tipo MCP). Necesita además los alcances de las herramientas que usará: `analytics:read` (`get_kpis`, `run_metric_query`, `list_insights`), `segments:read` (`list_segments`), `contacts:read` (+ `contacts:pii` para ver datos completos en `get_contact_summary`) y `campaigns:write` (`draft_campaign`, `draft_segment`). `get_setup_status` (cómo está configurado el negocio: web, canales y su tipo, anuncios, agenda, estrategias, lo que falta y señales de operación; `Workspaces\Queries\SetupStatus`) y `list_delivery_issues` (mensajes salientes fallidos, rebotados, negados o aplazados de los últimos 1 a 30 días, agrupados por canal, origen y motivo, sin destinatarios y con el detalle del proveedor sin correos ni teléfonos) no piden alcance extra: sirven para revisar la configuración de un cliente desde Claude. El plan debe incluir `mcp`.
- **Escrituras solo en borrador:** `draft_campaign` crea una campaña en borrador (se revisa y envía en Campañas); `draft_segment` valida la definición y deja una propuesta que alguien con permiso de marketing aprueba en **API e integraciones → Servidor MCP**. Ambas devuelven el enlace de aprobación.
- **Claude (claude.ai o app de escritorio):** Ajustes → Conectores → agregar conector personalizado con la URL y la cabecera `Authorization: Bearer igr_…`. [verificar] Mientras no haya OAuth, solo los clientes que permiten cabeceras personalizadas pueden conectarse.
- **Claude Code:**

```bash
claude mcp add --transport http imagina-growth https://growth.imagina.cloud/api/mcp --header "Authorization: Bearer igr_…"
```

- Las cifras salen de la capa semántica (las mismas definiciones del tablero). El servidor le pide al agente no inventarlas.
