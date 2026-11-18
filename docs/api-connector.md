# Contrato de la API entre el connector y el hub

> Detalle «en el cable» de lo que resumen `hub/CLAUDE.md` §13 y `connector/CLAUDE.md` §4, §5, §7 y §8.
> Si cambias algo aquí, cambia el hub y el connector en el mismo commit y agrega una prueba de contrato en ambos.
> Versión del contrato: **1** (va junto con `schema_version` de `eventos.md`).

---

## 1. Identificadores y formatos

| Dato | Formato | Ejemplo |
|---|---|---|
| ID de sitio | `st_` + ULID (26 caracteres Crockford) | `st_01JD3QG7Y2V8K6N4M5P9R0S1T2` |
| ID de espacio de trabajo | `ws_` + ULID | `ws_01JD3QF0A1B2C3D4E5F6G7H8J9` |
| Clave pública del sitio | `pk_` + 32 caracteres base32 en minúscula | `pk_7f3k...` |
| Secreto del sitio | `sk_` + 43 caracteres base64url (32 bytes) | `sk_Zm9v...` |
| Código de conexión | `IGR-XXXX-XXXX-XXXX` (Crockford base32, sin I, L, O, U) | `IGR-7K2Q-M9XD-4H8T` |

En la base de datos del hub los ULID se guardan sin prefijo (`CHAR(26)`); el prefijo solo existe en la API.

### 1.1 `event_id` deterministas

Los eventos ligados a un objeto usan **UUID v5** con el espacio de nombres `4a9293ae-2f42-5b83-905c-3b8de1f000cb` (= `uuid5(NAMESPACE_DNS, "growth.imagina.cloud")`) y el nombre `"{site_id}:{clave}"`. La importación histórica usa **las mismas claves** que los eventos en vivo, así un pedido que ya llegó en vivo no se cuenta dos veces.

| Evento | Clave |
|---|---|
| `order.created` | `order:{order_id}:created` |
| `order.paid` | `order:{order_id}:paid` |
| `order.status_changed` | `order:{order_id}:status:{from}:{to}:{modified_unix}` |
| `order.completed` / `.cancelled` / `.failed` | `order:{order_id}:{completed\|cancelled\|failed}` |
| `order.payment_pending` | `order:{order_id}:payment_pending` |
| `order.refunded` | `refund:{refund_id}` |
| `customer.registered` | `customer:{user_id}:registered` |
| `catalog.product_upserted` | `product:{product_id}:{hash}` |
| `catalog.category_upserted` | `category:{category_id}:{hash}` |
| `review.submitted` | `review:{comment_id}:{status}` |

Los demás eventos usan **UUID v7**. `customer_ref` de WooCommerce es `wc:{user_id}`.

### 1.2 Qué pedidos cuentan

La importación y la conciliación (§5.3) incluyen los pedidos de tipo `shop_order` con `date_created ≥ from`, excepto los estados `checkout-draft`, `auto-draft` y `trash`. Los estados se envían sin el prefijo `wc-`. Los montos van con 2 decimales y punto (`number_format($v, 2, '.', '')`).

## 2. Firma de las peticiones

Toda petición del sitio al hub (salvo el canje del código, §3.3) y todo comando del hub al sitio (§6) va firmada:

```
X-IGR-Site:      st_01JD3QG7Y2V8K6N4M5P9R0S1T2     (solo sitio → hub)
X-IGR-Signature: t=1763420700,v1=5d41402abc4b2a76b9719d911017c592...
```

- `v1 = hex( HMAC-SHA256( secreto, t + "." + cuerpo ) )`.
- `cuerpo` son **los bytes exactos que viajan** (si van comprimidos con gzip, los bytes comprimidos). En un `GET` el cuerpo es la cadena vacía. Así el hub verifica la firma **antes** de descomprimir.
- `t` es la hora Unix del emisor. Se rechaza si `|ahora − t| > 300 s`.
- El secreto completo (`sk_…`) es la clave del HMAC, tal cual, como texto.
- Comparación en tiempo constante (`hash_equals`).
- **Rotación:** durante 24 h después de rotar se aceptan el secreto nuevo y el anterior (§6, `secret.rotate`).

Respuestas de error comunes: `401 {"error":"signature"}` (firma inválida o fuera de ventana), `403 {"error":"site_disconnected"}`, `429` (límite), `5xx` (reintentar).

## 3. Conexión (handshake, M02)

### 3.1 Desde el plugin (flujo normal)

1. La persona pulsa **Conectar con Imagina Growth**. El plugin genera un `nonce` (32 hex), lo guarda 15 minutos en un transient y redirige el navegador a:

   ```
   {hub}/connect?site={home_url}&nonce={nonce}&return={admin_url}&platform=woocommerce&name={nombre del sitio}
   ```

2. En el hub la persona inicia sesión (con 2FA si aplica), elige o crea el espacio de trabajo y confirma. La SPA llama a `POST /api/w/{workspace}/sites/connect-codes` con `{site_url, nonce, return_url, platform, name}`. El hub exige que el host de `return_url` coincida con el de `site_url` y devuelve `{code, redirect_url}`.
3. El navegador vuelve a `return_url` con `igr_code={code}&igr_nonce={nonce}`. El plugin comprueba que el nonce coincide con su transient y canjea el código (§3.3) **de servidor a servidor**.

### 3.2 Manual (alternativa)

En el hub, **Sitios → Conectar manualmente** genera un código ligado al espacio de trabajo, sin URL ni nonce. La persona lo pega en el plugin, que lo canjea igual (§3.3) sin `nonce`.

### 3.3 Canje del código

`POST {hub}/api/connect/exchange` (JSON, sin firma: el código es el secreto de un solo uso)

```jsonc
{
  "code": "IGR-7K2Q-M9XD-4H8T",
  "nonce": "9f1c...",                       // obligatorio si el código se creó con nonce
  "site_url": "https://tienda.co",
  "rest_url": "https://tienda.co/wp-json/imagina-growth/v1/",
  "platform": "woocommerce",                // o "wordpress" si no hay WooCommerce
  "name": "Pinturas La 14",
  "plugin_version": "0.1.0", "wp_version": "6.8.1", "wc_version": "9.5.0", "php_version": "8.3.6",
  "timezone": "America/Bogota", "currency": "COP", "locale": "es_CO",
  "capabilities": { "hpos": true, "checkout_blocks": true, "subscriptions": false, "cogs": false,
                    "forms": ["cf7"], "cookie_banner": "complianz", "cache": "litespeed",
                    "gateways": ["wompi", "cod", "bacs"], "imagina_search": false }
}
```

Respuesta `200`:

```jsonc
{
  "site_id": "st_01JD...", "public_key": "pk_...", "secret": "sk_...",
  "workspace": { "id": "ws_01JD...", "name": "Pinturas La 14", "country": "CO", "timezone": "America/Bogota", "currency": "COP" },
  "endpoints": {
    "ingest_server": "https://in.growth.imagina.cloud/v1/s",
    "ingest_browser": "https://in.growth.imagina.cloud/v1/b",
    "api": "https://growth.imagina.cloud/"
  }
}
```

Errores: `422 {"error":"invalid_code"}` (no existe, vencido o ya usado; mismo mensaje para no filtrar cuál), `422 {"error":"nonce_mismatch"}`, `422 {"error":"site_mismatch"}` (la URL no coincide con la del código). El código vence a los **10 minutos** y se borra al usarse. Se guarda con hash, nunca en claro.

Si la URL ya pertenece a un sitio del mismo espacio de trabajo, el canje **reconecta** ese sitio (nuevo secreto) en lugar de crear otro.

## 4. Ingesta de eventos

### 4.1 Servidor: `POST {ingest_server}` (= `/v1/s`)

- Cabeceras: firma (§2), `Content-Type: application/json`, `Content-Encoding: gzip`.
- Cuerpo (antes de comprimir): `{"sent_at": "<ISO 8601>", "events": [<sobre>, ...]}` con hasta **500 eventos** y **1 MB comprimido**.
- Cada evento es un sobre completo de `eventos.md` §2 con `source.site_id` igual al `X-IGR-Site`.

Respuesta `202`:

```json
{ "accepted": 498, "rejected": [ { "event_id": "0193...", "reason": "envelope" } ] }
```

| `reason` | Significado | ¿Reintentar? |
|---|---|---|
| `envelope` | el sobre no cumple `_envelope.json` | no → `dead` |
| `too_large` | el evento supera 32 KB | no → `dead` |
| `unknown_event` | el nombre no está en el catálogo ni empieza por `custom.` | no → `dead` |
| `site_mismatch` | `source.site_id` distinto del sitio firmante | no → `dead` |

- El hub responde `202` **después** de dejar el lote en la cola (Redis Stream). El esquema de `properties` se valida en el worker: esos rechazos van a `ingest_rejections` y se ven en el panel del sitio (y en el Diagnóstico del plugin con `site.diagnostics`).
- Los duplicados (mismo `event_id`) se aceptan y se descartan en silencio: el hub es idempotente.
- `413` si el lote supera el tamaño; `401`/`403` como en §2; `5xx` o *timeout* → reintento con la espera de `connector/CLAUDE.md` §5.

### 4.2 Navegador: `POST {ingest_browser}` (= `/v1/b`)

- Lo usa el tracker (Fase 1). Sin firma: clave pública + `Origin` contra `sites.domains`.
- `Content-Type: text/plain;charset=UTF-8` (así `sendBeacon` no exige *preflight*); el cuerpo es JSON: `{"k": "pk_...", "events": [...]}`, hasta **20 eventos**.
- `source.type` debe ser `browser`. Los eventos de navegador **nunca** traen correo ni teléfono (lo hace cumplir `_envelope.json`).
- Misma respuesta que §4.1. Límite por IP y por sitio.

## 5. Otros endpoints del sitio (firmados)

### 5.1 `GET /v1/s/config`

Configuración que el plugin guarda en caché un día:

```jsonc
{
  "site_id": "st_...",
  "workspace": { "id": "ws_...", "name": "...", "country": "CO", "timezone": "America/Bogota", "currency": "COP", "locale": "es-CO" },
  "settings": {
    "paid_statuses": ["processing", "completed"],
    "cod_gateways": ["cod"],                       // contraentrega: cuenta como venta solo en cod_paid_statuses
    "cod_paid_statuses": ["completed"],
    "offline_gateways": ["bacs", "cheque", "cod"],
    "excluded_roles": ["administrator", "shop_manager"],
    "backfill_months": 24,
    "early_capture": false
  },
  "consent": {
    "mode": "auto",                                // auto | strict | informed, elegido en el panel (cumplimiento.md §3.3)
    "privacy_policy_url": "https://tienda.co/privacidad",
    "texts": [                                     // casillas del checkout y formularios, sin marcar (cumplimiento.md §3.1)
      { "kind": "marketing_consent", "channel": "whatsapp", "version": "v1", "label": "Quiero recibir ofertas por WhatsApp…" },
      { "kind": "marketing_consent", "channel": "email",    "version": "v1", "label": "Quiero recibir ofertas por correo…" }
    ]
  },
  "measurement": {                                // M06: solo destinos activos en modo «completo»
    "mode": "full",                                // server_only | with_pixel | full
    "pixels": { "meta": "123456789012345", "tiktok": "C9ABCDEF12345", "google": "G-ABC123" }
  },
  "min_plugin_version": "0.1.0"
}
```

- **`measurement.mode = full`:** el connector pasa `pixels` a `igrConfig.pixels` y el tracker carga los píxeles (solo con consentimiento de marketing) y dispara los eventos estándar con `eventID` igual al `event_id` del evento de Imagina Growth (en la compra, `igrOrder.eventId`). La conversión desde el servidor usa el mismo `event_id`: así Meta, TikTok y Google deduplican.
- **`with_pixel`:** ya hay otro píxel (p. ej. PixelYourSite); solo se deduplica la compra si ese plugin usa `igrOrder.eventId`. **`server_only`:** sin píxel.

### 5.2 `GET /v1/s/catalog/hashes?type=product|category&cursor=&limit=1000`

Conciliación nocturna (`connector/CLAUDE.md` §7): `{"items": [{"id": "88", "hash": "9f2c..."}], "next_cursor": "..."|null}`. El plugin reenvía los productos cuyo hash difiere o que faltan, y emite `catalog.product_deleted` para los que el hub tiene y el sitio ya no.

### 5.3 `PUT /v1/s/backfill`

Estado y progreso de la importación histórica. El plugin lo envía al empezar, cada lote (como máximo cada 10 s) y al terminar. El hub lo guarda en `sites.backfill_state` y lo muestra.

```jsonc
{
  "status": "running",                           // queued | running | paused | completed | failed
  "from": "2024-11-18",                          // fecha inicial (24 meses por defecto)
  "started_at": "2026-11-18T10:00:00Z", "finished_at": null,
  "phase": "orders",                             // products → categories → customers → orders → refunds → reviews → subscriptions
  "entities": {
    "products":   { "total": 340,   "sent": 340 },
    "categories": { "total": 25,    "sent": 25 },
    "customers":  { "total": 2000,  "sent": 2000 },
    "orders":     { "total": 10000, "sent": 4200 },
    "refunds":    { "total": 61,    "sent": 0 }
  },
  "reconciliation": {                           // calculado en WooCommerce sobre el mismo rango de fechas
    "currency": "COP",
    "generated_at": "2026-11-18T10:00:00Z",
    "orders_by_status": { "completed": { "count": 8120, "total": "1532000000.00" }, "processing": { "count": 950, "total": "..." } },
    "refunds": { "count": 61, "total": "..." }
  },
  "error": null
}
```

El hub compara `reconciliation` con su tabla `orders` (por estado: número de pedidos y suma de `total`) y muestra la conciliación. Es la prueba del criterio de la Fase 0: **los conteos y la venta total coinciden al 100%**.

### 5.4 `POST /v1/s/privacy/requests`

Lo llaman los *exporters* y *erasers* de WordPress (`connector/CLAUDE.md` §11) para crear la solicitud equivalente en el hub (`cumplimiento.md` §9):

```json
{ "kind": "access", "email": "ana@ejemplo.com", "wp_request_id": 42 }
```

`kind`: `access` o `delete`. Respuesta `202 {"request_id": "dr_…", "status": "pending", "due_at": "…"}`. El hub busca al contacto por índice ciego y no confirma si existe.

## 6. Comandos del hub al sitio

`POST {rest_url}commands` (`/wp-json/imagina-growth/v1/commands`), firmado con §2 (sin `X-IGR-Site`).

```json
{ "command": "site.diagnostics", "args": {}, "request_id": "01JD...", "nonce": "b7e2...", "issued_at": "2026-11-18T10:00:00Z" }
```

- El plugin rechaza un `nonce` ya visto en los últimos 10 minutos (`409 {"ok":false,"error":{"code":"replay"}}`).
- Idempotencia: la respuesta se guarda 24 h por `request_id`; si se repite, devuelve la misma respuesta sin ejecutar otra vez.
- Respuesta: `200 {"ok": true, "data": {...}}` o `{"ok": false, "error": {"code": "...", "message": "..."}}` con 4xx.

| Comando (Fase 0) | `args` | `data` |
|---|---|---|
| `site.diagnostics` | — | versiones, capacidades, outbox (`pending`, `sending`, `dead`, el más antiguo), Action Scheduler (atrasadas), últimos errores enmascarados, estado del backfill |
| `catalog.resync` | — | `{"queued": true}` |
| `backfill.start` | `{"from": "YYYY-MM-DD"?, "restart": bool}` | `{"queued": true}` |
| `secret.rotate` | `{"secret": "sk_..."}` | `{"rotated": true}`. El plugin guarda el nuevo como actual y el anterior 24 h. El hub firma este comando con el secreto **anterior** |

| Comando (Fase 2) | `args` | `data` |
|---|---|---|
| `coupon.create` | `{"code": "VUELVE-7K2Q", "kind": "percent\|fixed_cart\|fixed_product\|free_shipping", "amount": "10.00", "expires_at": "YYYY-MM-DD"?, "usage_limit": 1, "email_restrictions": ["ana@…"]?, "individual_use": true, "minimum_amount": "100000.00"?, "product_ids": ["88"]?, "category_ids": ["12"]?, "free_shipping": false, "description": "Flujo «Carrito abandonado»"}` | `{"coupon_id": "991", "code": "VUELVE-7K2Q"}`. Si el código ya existe con los mismos datos, devuelve el mismo (idempotente); si existe distinto, `409 coupon_exists` |
| `coupon.disable` | `{"code": "VUELVE-7K2Q"}` | `{"disabled": true}` (queda en borrador) |
| `order.add_note` | `{"order_id": "1234", "note": "Pedido recuperado por Imagina Growth", "customer_note": false}` | `{"note_id": "77"}` |
| `product.lookup` | `{"ids": ["88"]?, "search": "pintura"?, "limit": 10}` | `{"products": [{"id", "name", "sku", "price", "regular_price", "sale_price", "stock_status", "stock_qty", "url", "image", "variations": []}]}` con stock y precio **en vivo** |
| `order.status` | `{"number": "1234", "email": "…"? , "phone": "…"?}` (debe coincidir uno) | `{"status", "status_label", "total", "currency", "created_at", "tracking"?}` o `404 not_found` |

El resto de comandos de `connector/CLAUDE.md` §8 se agregan en su fase.

### 6.1 Enlace de carrito restaurable (Fase 2)

El hub arma `https://tienda.co/?igr_cart=<payload>.<firma>` con el formato de los tokens de §7.1: `payload` es base64url de un JSON y la firma es `hex(HMAC-SHA256(secreto_del_sitio, payload))`.

```json
{ "site": "st_…", "cart": "crt_…", "items": [{"product_id": "88", "variation_id": null, "qty": 2}], "coupon": "VUELVE-7K2Q", "mode": "replace", "exp": 1795000000 }
```

En `template_redirect`, el plugin:
1. Verifica la firma con el secreto actual o el anterior, y el vencimiento. Si algo falla, redirige a la tienda sin error visible.
2. Vacía el carrito (`replace`) o le suma los ítems (`merge`). Omite los productos que ya no existen o no tienen stock.
3. Aplica el cupón si lo hay.
4. Redirige al checkout conservando `_igr` y los `utm_*` de la URL.

**No hace ninguna llamada al hub** en esa petición, y el nuevo carrito guarda `cart` (el ID del hub) en la sesión para enviarlo como `restored_from` en `cart.updated`.

El pedido que sale de ese checkout lleva `order.cart_token` (v3), con lo que el hub marca el carrito como recuperado.

## 7. Datos en la página (connector → tracker)

**Modo de consentimiento efectivo** (`cumplimiento.md` §3.3): manda `consent.mode`, que se elige en el panel. Con `auto` (por defecto) el plugin decide según el sitio: **estricto** si hay un banner de cookies (WP Consent API, Complianz, CookieYes, Cookiebot o el banner propio) e **informado** si no hay ninguno. Con `strict` y sin banner, el plugin muestra el suyo. Con `informed`, `igrConfig.consent` trae analítica aceptada por defecto, aunque haya banner, y se respeta el rechazo. El hub aplica el mismo criterio a cada evento con su `context.consent.source`: en `auto`, `none` es informado y cualquier banner (o la falta de señal) es estricto. Los errores del tracker llegan como `custom.tracker_error` en una muestra del 1%.

Nunca dependen del visitante en páginas cacheables (`connector/CLAUDE.md` §9).

| Variable | Dónde | Contenido |
|---|---|---|
| `window.igrConfig` | todas | `{ siteKey: "pk_…", siteId: "st_…", platform: "woocommerce", currency: "COP", endpoint: "<ingest_browser>", consentMode: "strict", consent: {analytics, marketing, source}, cookieDomain, pingUrl: "/?igr_ping=1", waRef: {enabled, format: "{text} (ref: {token})", numbers: ["573001234567"]}, widgetsUrl: "<hub>/p/pk_…/widgets.json", widgetsJs, pixelsJs, pixels: {meta, tiktok, google}, banner, earlyCapture: false }`. `siteId` y `platform` llenan `source` de cada evento; `widgetsJs`, `pixelsJs` y `banner` son los paquetes que se cargan solo cuando hacen falta |
| `window.igrPage` | todas | `{ type: "product", objectId: "88", product: {id, sku, name, price, currency, categories: ["12"], inStock} }` |
| `window.igrUser` | **solo** páginas no cacheables (Mi cuenta, checkout, gracias) con sesión | `{ ref: "wc:77", token }` |
| `window.igrOrder` | página de gracias | `{ eventId, orderId: "1234", value: "282000.00", currency: "COP", paid: true, token }`. El píxel de compra del navegador solo se dispara con `paid: true`; un pedido pendiente (PSE, contraentrega) lo cuenta después el servidor al pagarse |

### 7.1 Tokens firmados

`token = <payload> "." <firma>` con `payload = base64url(JSON)` sin relleno y `firma = hex(HMAC-SHA256(secreto_del_sitio, payload))`.

| Token | JSON del payload | Vence |
|---|---|---|
| `igrUser.token` | `{"ref":"wc:77","site":"st_…","exp":<unix>}` | 1 hora |
| `igrOrder.token` | `{"order":"1234","site":"st_…","exp":<unix>}` | 24 horas |

- El tracker envía `igrUser.token` en un evento `identify` (`properties: {method: "login", token}`); el hub lo verifica (firma con el secreto actual o el anterior durante la rotación, `site` y `exp`) y entonces vincula el `anonymous_id` con el cliente.
- `igrOrder.eventId` es el `event_id` de `order.paid` (o de `order.created` si el pedido aún no se paga): el píxel del navegador lo usa como `eventID` para la deduplicación con la conversión desde el servidor (`eventos.md` §7).
- `igrOrder.token` autoriza la respuesta a la encuesta de la página de gracias (§8).

## 8. Endpoints públicos de widgets (tracker → hub)

Sin firma; identifican al sitio por la clave pública. CORS abierto a los dominios del sitio.

### 8.1 `GET /p/{public_key}/widgets.json?page_type=thankyou`

Configuración de los widgets activos para ese tipo de página. Cacheable en CDN 5 minutos (`Cache-Control: public, max-age=300`). En la Fase 1 solo existe la encuesta «¿cómo nos conociste?» en la página de gracias:

```jsonc
{
  "widgets": [{
    "id": "sv_01JD…", "kind": "hdyhau_survey", "slot": "thankyou",
    "title": "Una pregunta rápida", "question": "¿Cómo nos conociste?",
    "options": [ { "key": "instagram", "label": "Instagram" }, { "key": "google", "label": "Google" }, { "key": "recomendacion", "label": "Me lo recomendaron" }, … ],
    "allow_other": true, "other_label": "Otro", "thanks": "¡Gracias por contarnos!"
  }]
}
```

### 8.2 `POST /p/{public_key}/surveys/{survey_id}/answers`

`Content-Type: text/plain;charset=UTF-8` (para `sendBeacon`) con cuerpo JSON:

```json
{ "order_token": "<igrOrder.token>", "answer": "instagram", "other": null, "anonymous_id": "c3b0f4a2-…" }
```

`answer` es la clave de una opción, o `"otro"` (con `other`, texto libre de hasta 300 caracteres) si la encuesta permite «Otro». Respuesta `202 {"ok": true}`. `422` si el token no es válido o la opción no existe; `409` si ese pedido ya respondió. El hub guarda la respuesta, la asocia al pedido y al contacto, llena `contacts.hdyhau` y emite `survey.answered`.

