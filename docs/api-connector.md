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
    "ingest_server": "https://growth.imagina.cloud/v1/s",
    "ingest_browser": "https://growth.imagina.cloud/v1/b",
    "api": "https://growth.imagina.cloud/"
  }
}
```

Los endpoints salen de `IGR_INGEST_URL` y `IGR_APP_URL`; por defecto, todo en el dominio del panel. Si el host de
ingesta no existe en el DNS (`cURL error 6`), el plugin pasa al host de `api`, que atiende las mismas rutas, y lo
guarda (`Connection::use_hub_host_for_ingest()`).

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
| `not_allowed` | solo en `/v1/b`: el evento no es de navegación (`docs/eventos.md` §3) | no |
| `stale` | solo en `/v1/b`: el evento es de hace más de 7 días | no |

- El hub responde `202` **después** de dejar el lote en la cola (Redis Stream). El esquema de `properties` se valida en el worker: esos rechazos van a `ingest_rejections` y se ven en el panel del sitio (y en el Diagnóstico del plugin con `site.diagnostics`).
- Los duplicados (mismo `event_id`) se aceptan y se descartan en silencio: el hub es idempotente.
- `413` si el lote supera el tamaño; `401`/`403` como en §2; `5xx` o *timeout* → reintento con la espera de `connector/CLAUDE.md` §5.

### 4.2 Navegador: `POST {ingest_browser}` (= `/v1/b`)

- Lo usa el tracker (Fase 1). Sin firma: clave pública + `Origin` contra `sites.domains`.
- `Content-Type: text/plain;charset=UTF-8` (así `sendBeacon` no exige *preflight*); el cuerpo es JSON: `{"k": "pk_...", "events": [...]}`, hasta **20 eventos**.
- `source.type` debe ser `browser`. Los eventos de navegador **nunca** traen correo ni teléfono (lo hace cumplir `_envelope.json`).
- Solo entran eventos de navegación (§4.1 de `docs/eventos.md` y `custom.*`); pedidos, clientes, formularios, reseñas y catálogo se rechazan con `not_allowed`.
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
    "early_capture": false                         // captura temprana del checkout: la decide el espacio de trabajo (cumplimiento.md §3.2)
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
  "onsite": {                                     // Fase 3: módulos que el plugin pinta en la tienda
    "loyalty": true,                               // programa de fidelización activo: «Gana N puntos» y «Mis recompensas»
    "wallet": true,                                // «Usar saldo» en el checkout (el saldo es parte del programa)
    "product_watch": true                          // «Avísame cuando vuelva» / «si baja de precio» (M16)
  },
  "min_plugin_version": "0.1.0",
  "plugin": {                                     // versiones que conoce el hub (connector/CLAUDE.md §15)
    "min_version": "0.1.0",                        // la mínima compatible
    "latest_version": "0.1.3",                     // la que trae este paquete del hub en public/downloads
    "download_url": "https://growth.imagina.cloud/downloads/imagina-growth-connector.zip",
    "status": "current"                            // current | update_available | outdated, según la versión del último heartbeat
  }
}
```

- **`early_capture`:** con `true`, el plugin envía `checkout.contact_captured` (v6, `eventos.md` §4.2) cuando un
  invitado escribe un correo o un teléfono válido en el checkout, y muestra el aviso junto al campo. El hub vuelve a
  revisar la opción en cada evento: con la opción apagada lo rechaza (`not_allowed`) aunque el plugin tenga la
  configuración vieja.
- **`plugin`:** el plugin compara su propia versión con `min_version` y `latest_version` y muestra el aviso en el admin
  de WordPress con el enlace de descarga, sin pedir nada más al hub (la configuración ya está en caché).

- **`onsite`:** sin `loyalty` el plugin no reserva espacio para puntos ni recompensas, y sin `wallet` no muestra «Usar saldo». Cada elemento también tiene su interruptor local en la pestaña «En el sitio» del plugin (`connector/CLAUDE.md` §10).

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

### 5.5 `POST /v1/s/wallet/redeem` (Fase 3)

La **única llamada síncrona** del plugin al hub, y la inicia la persona: el botón «Usar saldo» del checkout (M13). Va firmada (§2) y tiene un *timeout* de 3 s; si el hub no responde, el checkout sigue igual y muestra «No pudimos usar tu saldo ahora».

```json
{ "customer_ref": "wc:77", "cart_total": "282000.00", "currency": "COP", "cart_token": "a3f9c2e1b7d84f0e9c1a2b3c4d5e6f70" }
```

- **`cart_token`:** el token de carrito del plugin (hexadecimal, el mismo de `order.cart_token`), opcional. El mismo carrito que lo pide otra vez recibe el mismo cupón.
- **Respuesta `200`:** `{"coupon": {"code": "SALDO-7K2Q9XAB", "amount": "35000.00", "expires_at": "2026-11-19T15:04:05+00:00"}, "balance": "35000.00"}`. El hub crea un cupón de un solo uso (`coupon.create`, `source_type: wallet`) por `min(saldo, cart_total)` y lo deja como débito **pendiente**. El cupón **ya existe en el sitio** cuando el hub responde (el `coupon.create` corre dentro de la misma llamada), así que el plugin solo lo aplica al carrito.
- **Respuesta `409`:** `{"ok": false, "error": {"code": "no_balance" | "busy" | "currency_mismatch"}}`: sin saldo, otro canje del mismo cliente en curso, o un carrito en otra moneda que no se puede convertir. El plugin muestra un mensaje distinto para cada uno.
- **`422 invalid_args`** con datos incompletos y **`502 coupon_failed`** si el sitio no pudo crear el cupón (el saldo no se toca).
- **Confirmación:** el débito se confirma cuando llega un `order.paid` que usa ese cupón. Se devuelve el saldo si el pedido se cancela o reembolsa, o si el cupón vence sin usarse (24 h).

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
| `coupon.create` | `{"code": "VUELVE-7K2Q", "kind": "percent\|fixed_cart\|fixed_product\|free_shipping", "amount": "10.00", "expires_at": "YYYY-MM-DD"?, "usage_limit": 1, "email_restrictions": ["ana@…"]?, "individual_use": true, "minimum_amount": "100000.00"?, "product_ids": ["88"]?, "category_ids": ["12"]?, "free_shipping": false, "description": "Flujo «Carrito abandonado»"}` | `{"coupon_id": "991", "code": "VUELVE-7K2Q"}`. Si el código ya existe con los mismos datos, devuelve el mismo (idempotente); si existe distinto o es un cupón del dueño, `409 coupon_exists`. Vale hasta el final del día de `expires_at` en la hora de la tienda, con `usage_limit_per_user = 1`. `free_shipping` se guarda como `fixed_cart` de monto 0 con envío gratis |
| `coupon.disable` | `{"code": "VUELVE-7K2Q"}` | `{"disabled": true}` (queda en borrador). Solo los que creó Imagina Growth: con uno del dueño, `409 coupon_not_managed` |
| `order.add_note` | `{"order_id": "1234", "note": "Pedido recuperado por Imagina Growth", "customer_note": false}` | `{"note_id": "77"}` |
| `product.lookup` | `{"ids": ["88"]?, "search": "pintura"?, "limit": 10}` | `{"products": [{"id", "name", "sku", "price", "regular_price", "sale_price", "stock_status", "stock_qty", "url", "image", "variations": []}]}` con stock y precio **en vivo** |
| `order.status` | `{"number": "1234", "email": "…"? , "phone": "…"?}` (debe coincidir uno) | `{"status", "status_label", "total", "currency", "created_at", "tracking"?}`. Si no coincide, responde igual que un pedido inexistente: `404 not_found`. Sin correo ni teléfono, `422 invalid_args` |

El resto de comandos de `connector/CLAUDE.md` §8 se agregan en su fase.

| Comando (Fase 3) | `args` | `data` |
|---|---|---|
| `review.publish` | `{"review_id": "rv_…", "product_id": "88", "rating": 5, "title": "…"?, "body": "…", "author_name": "Ana R.", "author_email": "…"?, "verified": true, "media": [{"url": "https://…", "type": "image\|video", "thumb": "…"?}], "created_at": "…", "order_id": "1234"?}` | `{"comment_id": "551"}`. Crea la reseña nativa (comentario `review` aprobado, meta `rating`, `verified`, `igr_review_id` y `igr_media`). Idempotente por `review_id` |
| `review.reply` | `{"comment_id": "551", "body": "…", "author_name": "Pinturas La 14"}` | `{"comment_id": "552"}` (respuesta del dueño como comentario hijo) |
| `review.unpublish` | `{"comment_id": "551"}` | `{"ok": true}` (la deja pendiente; la moderación la rechazó después de publicada) |
| `customer.set_tier` | `{"customer_ref": "wc:77", "tier_key": "tr_plata", "tier_name": "Plata", "role": "cliente_plata"?}` | `{"ok": true}`. Guarda la meta `igr_tier` y, si viene `role`, se lo agrega al usuario sin quitarle sus otros roles; el rol anterior de un nivel lo quita. Sin `tier_key` borra el nivel. Nunca asigna roles del sistema ni uno que dé más que `read`: `422 role_not_allowed` |

### 6.1 Enlace de carrito restaurable (Fase 2)

El hub arma `https://tienda.co/?igr_cart=<payload>.<firma>` con el formato de los tokens de §7.1: `payload` es base64url de un JSON y la firma es `hex(HMAC-SHA256(secreto_del_sitio, payload))`.

```json
{ "site": "st_…", "cart": "crt_…", "items": [{"product_id": "88", "variation_id": null, "qty": 2}], "coupon": "VUELVE-7K2Q", "mode": "replace", "exp": 1795000000 }
```

En `template_redirect`, el plugin:
1. Verifica la firma con el secreto actual o el anterior, el sitio y el vencimiento. Si algo falla, o no queda ningún producto, redirige al inicio (`home_url('/')`, conservando `_igr` y `utm_*`) sin error visible y sin tocar el carrito.
2. Vacía el carrito (`replace`) o le suma los ítems (`merge`). Omite los productos que ya no existen o no tienen stock, y limita la cantidad al stock disponible.
3. Aplica el cupón si lo hay.
4. Redirige al checkout (302, sin caché) conservando `_igr` y los `utm_*` de la URL.

**No hace ninguna llamada al hub** en esa petición, y el nuevo carrito guarda `cart` (el ID del hub) en la sesión para enviarlo como `restored_from` en `cart.updated`.

El pedido que sale de ese checkout lleva `order.cart_token` (v3), con lo que el hub marca el carrito como recuperado.

## 7. Datos en la página (connector → tracker)

**Modo de consentimiento efectivo** (`cumplimiento.md` §3.3): manda `consent.mode`, que se elige en el panel. Con `auto` (por defecto) el plugin decide según el sitio: **estricto** si hay un banner de cookies (WP Consent API, Complianz, CookieYes, Cookiebot o el banner propio) e **informado** si no hay ninguno. Con `strict` y sin banner, el plugin muestra el suyo. Con `informed`, `igrConfig.consent` trae analítica aceptada por defecto, aunque haya banner, y se respeta el rechazo. El hub aplica el mismo criterio a cada evento con su `context.consent.source`: en `auto`, `none` es informado y cualquier banner (o la falta de señal) es estricto. Los errores del tracker llegan como `custom.tracker_error` en una muestra del 1%.

Nunca dependen del visitante en páginas cacheables (`connector/CLAUDE.md` §9).

| Variable | Dónde | Contenido |
|---|---|---|
| `window.igrConfig` | todas | `{ siteKey: "pk_…", siteId: "st_…", platform: "woocommerce", currency: "COP", endpoint: "<ingest_browser>", consentMode: "strict", consent: {analytics, marketing, source}, cookieDomain, pingUrl: "/?igr_ping=1", waRef: {enabled, format: "{text} (ref: {token})", numbers: ["573001234567"], widgets}, widgetsUrl: "<hub>/p/pk_…/widgets.json", widgetsJs, pixelsJs, pixels: {meta, tiktok, google}, banner }`. `siteId` y `platform` llenan `source` de cada evento; `widgetsJs`, `pixelsJs` y `banner` son los paquetes que se cargan solo cuando hacen falta |
| `window.igrPage` | todas | `{ type: "product", objectId: "88", product: {id, sku, name, price, currency, categories: ["12"], inStock} }` |
| `window.igrUser` | **solo** páginas no cacheables (Mi cuenta, checkout, gracias) con sesión | `{ ref: "wc:77", token }` |
| `window.igrOrder` | página de gracias | `{ eventId, orderId: "1234", value: "282000.00", currency: "COP", paid: true, token }`. El píxel de compra del navegador solo se dispara con `paid: true`; un pedido pendiente (PSE, contraentrega) lo cuenta después el servidor al pagarse |

**Espacios (slots) de la Fase 3** (`connector/CLAUDE.md` §10): el plugin los imprime con su espacio reservado (sin CLS) desde bloques, *shortcodes* y widgets de Elementor, y el tracker los llena:

| `data-igr-slot` | `data-igr-ctx` | Dónde |
|---|---|---|
| `form` | `{"widgetId": "wg_…"}` | formulario incrustado (bloque, *shortcode* `[igr_form id=""]`) |
| `quiz` | `{"widgetId": "wg_…"}` | quiz incrustado (`[igr_quiz id=""]`) |
| `reco` | `{"kind": "para_ti\|bought_together\|recently_viewed\|best_sellers_category\|trending\|complete_cart", "productId"?, "categoryId"?, "limit": 4}` | recomendaciones (`[igr_reco type=""]`) |
| `reviews` | `{"productId"?, "layout": "carousel\|gallery\|list\|badge"}` | reseñas (`[igr_reviews]`) |
| `bis` | `{"productId": "88", "kind": "back_in_stock\|price_drop"}` | ficha del producto: agotado (automático) o «Avísame si baja» |
| `points` | `{"productId"?, "price"?, "cartTotal"?}` | ficha y carrito: «Gana N puntos» |
| `loyalty` | `{}` | Mi cuenta → «Mis recompensas» (usa `igrUser.token`) |
| `banner` | `{"placement": "top\|account"}` | banner personalizado por segmento |
| `wallet` | `{}` | checkout: «Usar saldo» (el clic llama al plugin, que llama a §5.5) |
| `quote` | `{"calculatorId": "qc_…"}` | cotizador (Fase 5, `[igr_quote id=""]`, bloque `imagina-growth/quote`, widget de Elementor «Cotizador»; reserva 420 px) |

`window.igrUser` (§7.1) también se imprime en Mi cuenta → «Mis recompensas» para que el tracker pida los datos del programa.

`waRef.widgets` es el interruptor de atribución de WhatsApp del plugin: con él, el widget `wa_widget` del hub agrega la referencia corta aunque su número no esté en la lista local `numbers`.

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

### 8.3 Fase 3: captación, fidelización, reseñas y comercio en el sitio

Todos van sin firma, identifican al sitio por la clave pública y aceptan CORS de los dominios del sitio. Los `POST` usan `Content-Type: text/plain;charset=UTF-8` con cuerpo JSON (§8.2), tienen límite por IP con hash y por `anonymous_id` (`429`), y **nunca devuelven datos personales** salvo con un `igrUser.token` válido.

Errores comunes: `404` con una clave pública desconocida o un sitio desconectado; `429` con `{"error": "rate_limited"}` (o `{"ok": false, "error": …}`). La forma exacta de cada respuesta la fija la prueba de contrato `hub/tests/Contract/PublicEndpointsContractTest.php`, y el hub simulado del plugin (`connector/tests/fixtures/mock-hub.php`) responde igual.

#### `GET /p/{public_key}/widgets.json?page_type=&path=` (amplía §8.1)
Público, en caché de CDN 5 min, y el mismo para todos los visitantes. Trae los widgets activos para ese tipo de página y ruta (filtrados también en el hub; nunca en el checkout), la encuesta de la Fase 1 en la página de gracias y los textos legales vigentes:

```jsonc
{ "widgets": [{
    "id": "wg_01JD…",                               // wg_ + ULID
    "kind": "popup|embedded_form|bar|spin_wheel|teaser|two_step|quiz|wa_widget|referral_welcome",
    "slot": null,                                   // "form" (embedded_form) o "quiz" (quiz incrustado); null = flotante
    "priority": 100,
    "gives_coupon": true,
    "variants": [{ "key": "a", "weight": 50, "design": {…}, "content": {…} }, { "key": "b", … }],
    "design": {…}, "content": {…},                  // los de la primera variante
    "targeting": {
      "trigger": { "type": "exit_intent|time|scroll|immediate|click", "value": 8 },
      "pages": { "include": ["/", "/tienda/*"], "exclude": ["/carrito*"] }, "page_types": ["home", "product"],
      "devices": ["mobile", "desktop"], "new_visitors": null, "utm": { "source": "instagram" }, "referral": false,
      "countries": ["CO"], "segments": ["sg_…"], "cart_value_min": "100000.00",
      "hide_for_subscribers": true, "frequency": { "max_views": 2, "cooldown_days": 7 }
    },
    "quiz": { "questions": [{ "id": "q1", "text": "…", "options": [{ "key": "a", "label": "…", "next": "q2" }] }] },   // solo quiz
    "wa": { "number": "573001234567", "text": "Hola, quiero información", "position": "right" }                      // solo wa_widget
  }],
  "consents": [{ "channel": "whatsapp", "label": "Quiero recibir ofertas por WhatsApp…", "version": "v3" },
               { "channel": "email", "label": "…", "version": "v2" }] }
```

- **`design`:** `layout` (`center`, `corner` o `fullscreen` en los popups; `null` si no es popup), `position` (el borde o lado: `top`/`bottom` en la barra, `left`/`right` en el teaser, el botón de WhatsApp y la esquina), `display` (solo quiz: `popup` o `embedded`), colores planos `bg`, `text`, `accent` y `accent_text`, `image` (URL o `null`) e `image_layout` (`text_only`, `image_top` o `image_left`).
- **`content`:** `title`, `body`, `fields` (`[{key, type, required, label}]` con `key` en `email`, `phone`, `first_name`, `last_name`; `type` `email`, `tel` o `text`), `consents` (`[{channel, label, version}]`: las casillas del widget con el texto legal vigente, sin marcar), `cta`, `success` (puede llevar `{{codigo}}` y `{{premio}}`; el hub ya lo resuelve en `message`) y `footer` (letra pequeña debajo del formulario). Según el tipo:
  - `two_step`: `steps` (2 pasos, cada uno con `title`, `body`, `fields`, `consents` (la de correo con el correo; las de WhatsApp y SMS con el celular), `cta` y `success`; el segundo trae `no`, el botón para saltárselo, que muestra el resultado del primero).
  - `spin_wheel`: `slices` (`[{key, label}]`, **sin probabilidades**) y `lose`.
  - `bar`: `url` (sin campos, el botón lleva ahí). `teaser`: `teaser` (texto de la pestaña).
  - `quiz`: `start` y `contact_optional` (con `true`, los campos llegan con `required: false` y la persona puede ver su resultado sin dejar datos).
- **`consents` de primer nivel:** los textos vigentes de cada canal con la misma forma que las casillas de los widgets. Los usan los formularios que no son widgets, como «avísame» (M16).
- **`targeting.referral`:** `true` exige un código de referido en la visita; `false` o `null` es «sin condición». `referral_welcome` siempre lo exige y además que el visitante no sea conocido (`visitor.json`).

El tracker elige la variante de forma determinista con el `anonymous_id`, evalúa en el navegador el disparador, la página, el dispositivo, las UTM, el valor del carrito y la frecuencia, y pide `visitor.json` solo si algún widget usa país, segmento, `hide_for_subscribers`, `new_visitors` o es `referral_welcome`. **Nunca se muestra un widget durante el checkout.**

#### `GET /p/{public_key}/visitor.json?aid=<anonymous_id>`
Privado (`Cache-Control: private, no-store`). Responde siempre `{"known": false, "returning": true, "subscribed": {"email": false, "whatsapp": true}, "country": "CO", "segments": ["sg_…"]}`. En `segments` solo vienen los que usa algún widget activo. No trae nombres, correos ni teléfonos. Sin consentimiento de analítica el tracker lo pide **sin `aid`**: entonces `known`, `returning` y `subscribed` van en `false` y solo `country` sale de la petición (cabecera de país de Cloudflare; la IP no se guarda).

#### `POST /p/{public_key}/widgets/{widget_id}/submit`
```json
{ "anonymous_id": "…", "variant": "a", "page_url": "https://…", "step": 1,
  "fields": { "phone": "300 123 4567", "email": "…", "first_name": "Ana" },
  "consents": { "whatsapp": true, "email": false }, "answers": { "q1": "a" }, "referral_code": "ANA7K2" }
```
- **`200`:** `{"ok": true, "message": "…", "coupon": {"code", "description", "expires_at": "2026-12-01"}?, "referral_coupon": {…}?, "prize": {"key", "label"}?, "products": [tarjetas como /reco]?, "double_opt_in": false, "next_step": 2?}`.
  - El hub crea o une el contacto (`CaptureContact`) y registra cada consentimiento marcado con su evidencia (`widget:<id>`).
  - Crea el cupón único (`coupon.create`, `source_type: widget`) si el widget lo da. El premio de la ruleta lo decide el hub. `expires_at` es la fecha (sin hora) en la zona de la tienda.
  - Guarda las respuestas del quiz como atributos y responde con los productos del resultado.
  - Con `referral_code`, da el regalo del amigo (M14). Si el widget ya da su propio cupón, el del amigo llega aparte en `referral_coupon`.
  - Con doble opt-in, `message` ya pide confirmar el correo. En dos pasos, el primero responde `next_step: 2` y el segundo no repite el cupón.
- **`422`:** `{"ok": false, "message": "…", "errors": {"phone": ["…"]}}`.
- **`409`:** `{"ok": false, "error": "already_claimed|already_played", "message": "…"}`: ese contacto ya recibió el cupón (o ya giró la ruleta).
- **`404`:** `{"ok": false, "error": "unknown_widget", "message": "…"}`.

#### `GET /p/{public_key}/reco?kind=&product_id=&category_id=&cart=88,91&aid=&limit=4`
Recomendaciones (M16): `{"kind": "…", "products": [{"id", "name", "price", "regular_price", "sale_price", "currency", "image", "url", "rating_avg", "rating_count"}], "fallback": false}`. `fallback: true` cuando no hubo señal y se respondió con los más vendidos. Solo productos publicados y con stock. Los tipos que no dependen de la persona (`bought_together`, `best_sellers_category`, `trending`) van en caché pública 60 s; `para_ti`, `recently_viewed` y `complete_cart` con `aid`, en caché privada 60 s. El plugin manda `cart` solo con `complete_cart` en el carrito. Un `kind` desconocido responde `422`.

#### `POST /p/{public_key}/watch`
`{"anonymous_id", "product_id": "88", "kind": "back_in_stock|price_drop", "email"?, "phone"?, "consents": {"email": true}, "consent_version": "v2"?}`:
- **`200`:** `{"ok": true, "message": "Listo. Te avisaremos cuando vuelva."}`, o `{"ok": true, "available": true, "message": "…"}` si el producto ya está disponible (no suscribe). Crea `product_watches` y emite `product_watch.created`.
- **`422`:** `{"error": "invalid", "message": "Escribe un correo o un WhatsApp válido.", "errors": {"email": ["…"]}}`.

`consent_version` es la versión del texto que vio la persona (`consents` de primer nivel de `widgets.json`); el hub registra la vigente.

#### `GET /p/{public_key}/me?token=<igrUser.token>`
Mis recompensas (M13 y M14). Privado, sin caché y **solo con un token válido**; si no, `401 {"ok": false, "error": {"code": "invalid_token"}}` sin ningún dato:

```json
{ "program": { "active": true, "currency_name": "Puntos Pintor" },
  "points": { "balance": 1532, "pending": 282, "currency_name": "Puntos Pintor", "expiring": { "points": 120, "at": "2026-12-31" } },
  "tier": { "name": "Plata", "next_name": "Oro", "progress_pct": 64, "benefits": ["Envío gratis"] },
  "wallet": { "balance": "35000.00", "currency": "COP" },
  "rewards": [{ "id": "rw_…", "name": "$20.000 de descuento", "points": 500, "kind": "coupon_fixed|coupon_percent|free_shipping|free_product|wallet", "affordable": true }],
  "referral": { "code": "ANA7K2", "link": "https://tienda.co/?ref=ANA7K2", "share_text": "Te regalo 10% en Pinturas La 14: https://tienda.co/?ref=ANA7K2", "friend_reward": "10% en tu primera compra", "referrer_reward": "$20.000 de saldo" } }
```

`expiring` y `tier` pueden ser `null`. `referral` solo viene con el programa de referidos activo (lo aporta M14 con `MeSection`). `share_text` ya trae el enlace cuando el texto del programa usa `{enlace}`; si no, el tracker lo agrega al final.

- `POST /p/{public_key}/me/redeem` `{"token", "reward_id"}`:
  - **`200`** con cupón: `{"ok": true, "balance": 1032, "coupon": {"code", "description", "expires_at": "2027-01-31"}}`; con una recompensa de saldo: `{"ok": true, "balance": 1032, "wallet": {"balance": "45000.00", "currency": "COP"}}`.
  - **`401`** `{"ok": false, "error": {"code": "invalid_token"}}`; **`422`** `{"ok": false, "error": {"code": "unknown_reward|insufficient_points|not_redeemed", "message": "…"}}`.
- `POST /p/{public_key}/me/share` `{"token", "channel": "whatsapp"}` → `202 {"ok": true}` (emite `referral.shared`); `401 {"error": "invalid_token"}`; `404 {"error": "program_inactive"}`.

#### `GET /p/{public_key}/reviews?product_id=&page=1&per_page=10&with_media=0&sort=recent|rating&scope=all`
Público, caché 5 min:
- `summary`: `{"avg", "count", "distribution": {"5": 40, …}}` (`avg` es `null` sin reseñas).
- `reviews`: cada una con `id`, `product_id`, `rating`, `title`, `body`, `author` («Ana R.»), `verified`, `media` (`[{url, type: "image|video", thumb}]`), `created_at` y `reply` (texto o `null`). `title`, `body` y `reply` son **texto plano**: el tracker los escapa.
- `next_page` (`null` en la última).

Sin `product_id` responde las reseñas de la tienda; con `scope=all`, todas (para una página de «todas las reseñas»). `GET /p/{public_key}/reviews/badges?product_ids=88,91` → `{"88": {"avg": 4.8, "count": 23}}` (solo los que tienen reseñas).

#### `GET /p/{public_key}/points?product_id=&price=&cart_total=`
Público, caché 5 min: `{"points": 150, "label": "Gana 150 Puntos Pintor"}`, con la regla base sin el multiplicador del nivel (`label` es `null` con 0 puntos o sin programa activo).

#### `GET /p/{public_key}/program`
Público, caché 5 min: la página explicativa del programa: `{"active": true, "currency_name", "explainer", "tiers": [{"name", "benefits"}], "rewards": [{"id", "name", "points", "kind"}]}`, o `{"active": false}`.

#### `GET /p/{public_key}/banner?placement=top|account&aid=&token=`
Privado y sin caché: `{"banner": {"id", "text", "cta", "url", "style": "brand|info|success|warning"} | null}`. Los datos sensibles, como los puntos o el nombre, solo aparecen con `token`; sin él se usa el texto público del banner.

#### `GET /p/{public_key}/quotes/{calculator_id}` (Fase 5)
Público, en caché de CDN 5 min. Solo calculadoras `active` (y del sitio, si tienen uno). Sin notas internas; si el precio se da a cambio del contacto (`capture.required`), tampoco trae precios ni multiplicadores:

```jsonc
{ "ok": true, "calculator": { "id": "qc_…", "name": "Pintura de interiores", "kind": "service|area|package", "currency": "COP",
    "definition": { "fields": [{ "key": "area", "label": "…", "type": "number|select|checkbox", "required": true, "help": null,
                                 "unit": "m²", "min": 5, "max": 2000, "step": 1, "default": null, "options": [{ "value": "vinilo", "label": "Vinilo" }] }],
                    "packages": [{ "key": "basico", "label": "Básico", "description": null }], "note": "Incluye materiales." },
    "capture": { "required": true, "fields": ["first_name", "phone", "email"], "require": "phone|email|phone_or_email",
                 "consents": [{ "channel": "whatsapp", "label": "…", "version": "v3" }], "title": "…", "cta": "Ver mi cotización" } } }
```
`404` `{"ok": false, "error": {"code": "unknown_calculator"}}`.

#### `POST /p/{public_key}/quotes/{calculator_id}/estimate` (Fase 5)
```json
{ "anonymous_id": "…", "page_url": "https://…", "answers": { "area": 40, "tipo": "esmalte", "extras": ["techo"], "package": "basico" },
  "fields": { "first_name": "Ana", "phone": "300 123 4567", "email": "…" }, "consents": { "whatsapp": true }, "utm": { "utm_source": "google", "gclid": "…" } }
```
- **`200`:** `{"ok": true, "quote_id": "qt_…", "estimate": {"min": "686000.00", "max": "839000.00", "currency": "COP", "text": "$686.000 – $839.000 COP"}, "lines": [{"label", "amount"}], "note", "message", "whatsapp": "https://wa.me/…" | null}`. Sin datos personales.
- **`422`:** `{"ok": false, "message": "…", "errors": {"answers.area": ["…"], "fields.phone": ["…"]}}`: respuesta fuera de rango u opción que no existe, o falta el contacto cuando se pide.
- **`429`:** 20 por minuto por IP (con hash) y 10 por `anonymous_id`.

#### Agenda de citas (Fase 5, páginas del hub)
Sin tracker ni CORS: páginas Blade del hub, enlazadas desde el sitio con `[igr_booking service=""]` (bloque `imagina-growth/booking`, widget de Elementor «Botón de reservas»), que pinta `<a class="igr-booking igr-booking-button" data-igr-cta="booking" href="{hub}/p/{public_key}/reservar/{servicio}">`. La clave también puede ser el ID del espacio de trabajo (`ws_…`).
- `GET /p/{key}/reservar` (servicios), `GET /p/{key}/reservar/{servicio}?desde=&dia=&persona=` (días y horarios), `POST /p/{key}/reservar/{servicio}` (formulario: `start` ISO UTC, `persona`, `name`, `phone`, `email`, `notes`, `consent[canal]`, UTM y `aid`): `303` a la gestión de la cita, `422` con errores, `409` si el horario se ocupó. 10 envíos por minuto por IP.
- `GET /p/{key}/cita/{token}` (40 caracteres), `POST /p/{key}/cita/{token}/confirmar|reprogramar|cancelar` y `GET /p/{key}/cita/{token}/calendario.ics`.
- `GET /p/_google/calendar/callback`: vuelta del consentimiento de Google Calendar de una persona (state cifrado, vence en 1 hora).

#### Páginas que sirve el hub (no pasan por el tracker)
- `/r/{token}`: formulario de reseña de 1 toque, con fotos y video y sin iniciar sesión. El token va firmado por pedido y contacto.
- `/p/{public_key}/confirmar/{token}`: doble opt-in por correo (bajo `/p/` porque la SPA atiende las demás rutas del hub; el token va firmado con la clave de la app, por contacto y widget, y vence a los 7 días).
- `/creadores/{token}`: panel del creador (afiliados), con enlace mágico.

#### Referidos y afiliados en el sitio
El enlace es `https://tienda.co/?ref=CODIGO` (3 a 32 caracteres `[A-Za-z0-9_-]`). El tracker guarda el código en `_igr_src` (`referral_code` en el primer y el último contacto, v4) y lo manda en `context.referral_code` del evento de aterrizaje, también sin consentimiento de analítica: identifica a quien invita, no al visitante. El parámetro `?ref=` se queda en la URL (no es un dato personal y otros plugins de afiliados también lo leen). El plugin lo pasa en `last_touch` del pedido. Si la persona no está identificada, un widget `referral_welcome` le ofrece el regalo del amigo a cambio de su correo o WhatsApp.

