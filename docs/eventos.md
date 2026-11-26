# Contrato de eventos y datos que se recogen

> Fuente de verdad del dato. El hub valida cada evento contra su JSON Schema en `hub/resources/schemas/events/<evento>.json`. El connector y el tracker tienen pruebas de contrato contra esos mismos esquemas.
> `schema_version` actual: **8** (el hub acepta de la 1 a la 8; ver §8)

---

## 1. Qué vamos a saber (inventario)

Para orientar bien las estrategias, cada espacio de trabajo debe responder estas preguntas sin trabajo manual.

### 1.1 Sobre cada persona (perfil 360)

| Bloque | Datos | Fuente |
|---|---|---|
| Identidad | nombre, correo, teléfono (E.164), ciudad, departamento o estado, país, idioma, zona horaria, cumpleaños (si lo da) | checkout, registro, formularios, WhatsApp |
| Origen | primera fuente (canal, campaña, anuncio, página de entrada), fuente de la primera compra, respuesta a «¿cómo nos conociste?», quién lo refirió | tracker, atribución nativa de WooCommerce, encuesta, referidos |
| Compras | pedidos, productos, categorías, marcas, ticket promedio, gasto total, primera y última compra, frecuencia, intervalo entre compras, métodos de pago, cupones usados, reembolsos y cancelaciones | WooCommerce |
| Comportamiento | visitas, sesiones, páginas, productos vistos, búsquedas, carritos (activos y abandonados), clics en WhatsApp, teléfono o correo, descargas, tiempo y profundidad de lectura, dispositivo | tracker y eventos de servidor |
| Relación | mensajes recibidos, leídos, clics y respuestas por canal, conversaciones, reseñas, NPS, tickets, citas | hub |
| Preferencias declaradas (zero-party) | respuestas a quiz y encuestas, centro de preferencias (temas, canales, frecuencia), talla, tipo de piel, mascota, etc. (atributos personalizados) | formularios del hub |
| Fidelización | puntos, nivel VIP, saldo, código de referido, referidos logrados | hub |
| Calculado | segmento RFM, CLV histórico y previsto, riesgo de abandono, fecha esperada de próxima compra, afinidad por categoría, sensibilidad a descuentos, rango de precio preferido, mejor hora de contacto, puntaje de interacción, puntaje de lead | `docs/inteligencia.md` |
| Consentimiento | por canal y finalidad, con fuente, versión del texto legal y evidencia | hub |

### 1.2 Sobre cada negocio

- **Ventas:** ingresos, pedidos, ticket promedio, nuevos frente a recurrentes, tasa de recompra, cohortes, estacionalidad, ventas por ciudad.
- **Embudo:** sesiones → vista de producto → carrito → checkout → compra, por canal, dispositivo y método de pago.
- **Productos:** más vistos frente a más vendidos, abandono por producto, productos de entrada, combinaciones, inventario contra velocidad de venta.
- **Búsquedas:** términos más buscados, búsquedas sin resultados (con Imagina Search) y conversión por búsqueda.
- **Canales:** tráfico, conversión, LTV por canal de adquisición, CAC y ROAS (con el gasto importado), asistentes de IA como fuente.
- **Sitios de servicios:** leads por fuente, conversión por formulario, clics a WhatsApp, llamada o correo, tiempo de respuesta y cierre por fuente.
- **Salud técnica:** cambios en el sitio (plugins, tema, versiones) correlacionados con caídas de conversión, y Web Vitals si Imagina Turbo está activo.
- **Mensajería:** rendimiento por flujo y campaña, costo por mensaje e ingreso por mensaje.

---

## 2. Sobre del evento (envelope)

```jsonc
{
  "schema_version": 1,
  "event_id": "0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b7a",   // UUID v7 generado en origen; idempotencia
  "event": "order.paid",                                // nombre del catálogo (§4)
  "occurred_at": "2026-11-17T18:05:00.123-05:00",       // ISO 8601 con zona
  "source": {
    "type": "browser | server | hub | import",          // import = backfill, NO dispara flujos
    "platform": "woocommerce | wordpress | hub",
    "site_id": "st_01J...",
    "sdk": "connector/1.0.0 | tracker/1.0.0"
  },
  "identity": {
    "anonymous_id": "c3b0f4a2-...",                     // cookie _igr_aid
    "session_id": "s_8f2a...",                          // cookie _igr_sid (30 min)
    "customer_ref": "wc:1234",                          // id en la plataforma, si existe
    "email": "ana@ejemplo.com",                         // solo en eventos de servidor
    "phone": "+573001234567",                           // E.164, solo en servidor
    "link_token": "lt_..."                              // _igr de un enlace del hub, si vino de un mensaje
  },
  "context": {
    "page": { "url": "...", "path": "/producto/x", "title": "...", "referrer": "https://l.wl.co/..." },
    "page_type": "home | product | category | cart | checkout | thankyou | search | account | blog | landing | other",
    "utm": { "source": "", "medium": "", "campaign": "", "content": "", "term": "", "id": "" },
    "click_ids": { "fbclid": "", "gclid": "", "gbraid": "", "wbraid": "", "ttclid": "", "msclkid": "" },
    "ad_cookies": { "fbp": "", "fbc": "", "ga_client_id": "", "ttp": "" },
    "first_touch": { /* mismo formato utm + click_ids + referrer + landing, persistido 90 días */ },
    "last_touch": { /* último contacto no directo, mismo formato (cookie _igr_src) */ },
    "device": { "type": "mobile | tablet | desktop", "os": "", "browser": "" },  // el hub lo deriva del UA
    "consent": { "analytics": true, "marketing": false, "source": "wp-consent-api | complianz | cookieyes | none" },
    "locale": "es-CO",
    "currency": "COP",
    "ip": "x.x.x.x",       // solo en tránsito: el hub deriva el geo y la descarta; nunca se guarda
    "user_agent": "..."    // solo en tránsito
  },
  "properties": { /* específicas del evento (§4) */ },
  "encrypted": { "alg": "...", "data": "..." }          // obsoleto desde la v6: nadie lo envía (se acepta por compatibilidad)
}
```

### Reglas

- `event_id`: UUID v7 generado en origen. Para eventos ligados a un objeto se usa un ID determinista, para que los reintentos no dupliquen: `uuidv5(site_id + ':order:' + order_id + ':paid')`.
- Los eventos de navegador **nunca** traen correo ni teléfono. La identidad se resuelve en el servidor.
- Los eventos de servidor leen `_igr_aid`, `_igr_sid`, `_igr_src` (primer y último contacto), `_fbp`, `_fbc`, `_ga` y `_ttp` de las cookies de la petición, cuando existen, y los adjuntan.
- `source.type = import` marca el backfill: se guarda y alimenta la analítica, pero no dispara flujos ni conversiones hacia anuncios.
- Eventos personalizados: prefijo `custom.` (por ejemplo, `custom.cotizacion_solicitada`). Las propiedades son libres pero con un máximo de 50 claves y 8 KB.
- Tamaño máximo por evento: 32 KB. Lote de servidor: hasta 500 eventos o 1 MB comprimido.
- Los esquemas viven en `hub/resources/schemas/events/`: `_envelope.json` (sobre), `_defs.json` (tipos compartidos, incluido el objeto `order` de §5) y un archivo por evento que describe sus `properties`, con `examples` válidos y `x-invalid-examples` para las pruebas de contrato. Los `custom.*` usan `custom.json`.
- El detalle de transporte, firma y respuestas está en `docs/api-connector.md`.

---

## 3. Endpoints de ingesta

| Endpoint | Quién llama | Autenticación | Notas |
|---|---|---|---|
| `POST https://growth.imagina.cloud/v1/b` (o el host de `IGR_INGEST_URL`) | tracker | clave pública del sitio + verificación de `Origin` contra los dominios del sitio | CORS, `sendBeacon`, lote ≤ 20 eventos, límite por IP y por sitio |
| `POST https://growth.imagina.cloud/v1/s` (o el host de `IGR_INGEST_URL`) | connector | `X-IGR-Site`, `X-IGR-Signature: t=<unix>,v1=<hex hmac_sha256(secret, t + "." + body)>`, ±300 s | gzip, lote ≤ 500 |
| `POST https://t.<dominio-cliente>/v1/b` | tracker | igual que `/b` | dominio de seguimiento propio por CNAME (Fase 3). Caddy emite el TLS bajo demanda |

**Respuesta:** `202 {"accepted": n, "rejected": [{"event_id": "...", "reason": "schema"}]}`. Un rechazo por esquema no se reintenta; un 5xx sí.

**Qué acepta `/v1/b`:** la clave pública está en el HTML de cada página, así que el navegador solo informa navegación:
los eventos de §4.1 (`page.viewed`, `page.engaged`, `product.viewed`, `category.viewed`, `search.performed`,
`cta.clicked`, `widget.shown`, `widget.submitted`, `widget.closed`, `identify`) y `custom.*`. Cualquier otro se rechaza
con `not_allowed`, también en el worker si ya estaba en el stream (`App\Ingest\Schema\BrowserEvents`). Pedidos,
carritos, clientes, formularios, reseñas y catálogo solo llegan firmados por `/v1/s`. `checkout.contact_captured`
(v6) también: lo envía el plugin desde el servidor con el dato en `identity` (§4.2).

**Fechas:** un `occurred_at` más de 5 minutos en el futuro (reloj adelantado) se reemplaza por la hora de recepción,
en cualquier origen. Del navegador, un evento de hace más de 7 días se rechaza con `stale`; la importación del plugin
sí trae historia.

---

## 4. Catálogo de eventos

Convenciones: `objeto.acción` en inglés y en pasado. Montos en la moneda del pedido, como decimal en texto (`"159900.00"`). Los IDs de productos y pedidos son los externos de la plataforma, en texto.

### 4.1 Navegación (tracker)

| Evento | Cuándo | Propiedades |
|---|---|---|
| `page.viewed` | cada página | `page_type`, `object_id?`, `object_type?` (`product`, `product_cat`, `post`…) |
| `page.engaged` | una vez por página: ≥ 30 s visible o ≥ 50% de scroll | `seconds_visible`, `max_scroll_pct` |
| `product.viewed` | página de producto | `product_id`, `variation_id?`, `sku`, `name`, `category_ids[]`, `price`, `currency`, `in_stock` |
| `category.viewed` | página de categoría | `category_id`, `name` |
| `search.performed` | búsqueda en el sitio (`?s=` o Imagina Search) | `query`, `results_count?`, `engine` (`wp`, `wc`, `imagina_search`) |
| `cta.clicked` | clic en `tel:`, `mailto:`, `wa.me`, `api.whatsapp.com`, `whatsapp://`, descargas (pdf, doc, xls, zip) o un elemento con `data-igr-cta` | `kind` (`whatsapp`, `phone`, `email`, `download`, `custom`), `target`, `label`, `wa_ref?` (token de atribución, ver §6) |
| `widget.shown` / `widget.submitted` / `widget.closed` | popups, formularios, quiz y barras del hub | `widget_id`, `variant`, `kind` |
| `identify` | llamada explícita (login detectado, enlace con `_igr`) | `method` (`login`, `link_token`, `form`), `token?` (v2: token firmado por el sitio, `api-connector.md` §7) |

### 4.2 Comercio (connector, servidor)

| Evento | Hook de origen (ver `connector/CLAUDE.md`) | Propiedades clave |
|---|---|---|
| `cart.item_added` | `woocommerce_add_to_cart` | `cart_token`, `product_id`, `variation_id?`, `qty`, `price`, `cart_total` |
| `cart.item_removed` | `woocommerce_cart_item_removed` | `cart_token`, `product_id`, `qty` |
| `cart.updated` | `woocommerce_cart_updated` (con *throttle* de 30 s) | `cart_token`, `items[]`, `subtotal`, `coupons[]`, `currency` |
| `checkout.started` | carga del checkout clásico o de bloques | `cart_token`, `subtotal`, `items_count` |
| `checkout.contact_captured` | v6. Un invitado escribe un correo o un teléfono válido en el checkout: clásico en `woocommerce_checkout_update_order_review`, bloques en `woocommerce_store_api_cart_update_customer_from_request`. Solo si el espacio de trabajo activó la captura temprana (`cumplimiento.md` §3.2) y con el aviso junto al campo. *Debounce* de 20 s (el último valor gana) y una vez por carrito y dato | `has_email`, `has_phone`, `cart_token?`, `checkout?` (`classic`, `blocks`). El correo y el teléfono van en `identity`, **nunca** en `properties`. El hub lo rechaza (`not_allowed`) si la opción está apagada, une el carrito con el contacto (la captura más nueva gana) y no crea consentimiento de marketing |
| `order.created` | `woocommerce_new_order` / `woocommerce_store_api_checkout_order_processed` | `order` (§5) |
| `order.paid` | pasa a un estado pagado (`processing`, `completed` o estados pagados configurables) por primera vez | `order` (§5) |
| `order.status_changed` | `woocommerce_order_status_changed` | `order_id`, `from`, `to`, `order?` |
| `order.completed` | estado `completed` | `order_id`, `order?` |
| `order.cancelled` / `order.failed` | estados correspondientes | `order_id`, `reason?`, `order?` |
| `order.refunded` | `woocommerce_order_refunded` | `order_id`, `refund_id`, `amount`, `is_partial`, `reason?`, `refunded_at?`, `items[]?`, `order?` |
| `order.payment_pending` | queda `on-hold` o `pending` con método offline (transferencia, Efecty, Baloto, PSE pendiente, contraentrega por confirmar) | `order_id`, `payment_method`, `total`, `currency?`, `pay_url?`, `order?` |
| `coupon.applied` | `woocommerce_applied_coupon` | `cart_token`, `code` |
| `customer.registered` | `user_register` (rol cliente) / `woocommerce_created_customer` | `customer_ref`, `first_name`, `last_name`, `email`, `phone?`, `marketing_optin?`, `billing?` (ciudad, estado, país), `registered_at?`, `locale?` |
| `customer.updated` | `profile_update`, `woocommerce_customer_save_address` | `customer_ref`, `changed[]` y solo los campos que cambiaron |
| `customer.logged_in` | `wp_login` | `customer_ref` |
| `review.submitted` | comentario `review` aprobado (`comment_post`, `wp_set_comment_status`) | `product_id`, `rating`, `verified_owner`, `review_id` |
| `subscription.created` / `.renewed` / `.renewal_failed` / `.cancelled` / `.expired` | hooks de WooCommerce Subscriptions | `subscription_id`, `status`, `next_payment_at`, `total` |

### 4.3 Catálogo (connector)

| Evento | Propiedades |
|---|---|
| `catalog.product_upserted` | producto completo: `product_id`, `parent_id?`, `type`, `sku`, `name`, `slug`, `permalink`, `image_url`, `category_ids[]`, `brand?`, `price`, `regular_price`, `sale_price?`, `stock_status`, `stock_qty?`, `rating_avg`, `rating_count`, `status`, `cost?` (COGS nativo de WooCommerce o un plugin conocido), `hash` |
| `catalog.product_deleted` | `product_id` |
| `catalog.stock_changed` | `product_id`, `from_status`, `to_status`, `qty?` |
| `catalog.price_changed` | `product_id`, `from`, `to` |
| `catalog.category_upserted` | `category_id`, `parent_id?`, `name`, `slug`, `hash?` (del contenido; el hub lo devuelve en la conciliación de `/v1/s/catalog/hashes`) |
| `catalog.category_deleted` | `category_id` |

### 4.4 Formularios y servicios (connector)

| Evento | Origen | Propiedades |
|---|---|---|
| `form.submitted` | CF7, WPForms, Gravity, Fluent Forms, Elementor Forms, JetFormBuilder, Formidable, formularios del hub | `form_plugin`, `form_id`, `form_name`, `fields` (mapeados: `email`, `phone`, `first_name`, `last_name`, `company`, `city`, `message`, `service`, `budget`), `page_path`, `consents{}`. Las **páginas de captura del hub** (`/p/{slug}`, M12) lo emiten desde el hub con `source.type = hub`, `form_plugin = igr_landing`, `form_id = lp_…` y `consents` con `email_marketing`, `whatsapp_marketing` y `sms_marketing`: dispara flujos, segmentos y webhooks como cualquier formulario, pero no crea un lead de origen `form` (la página ya crea el suyo con origen `landing`) ni sale como conversión a anuncios |
| `lead.created` | hub, a partir de `form.submitted` o de un chat entrante (WhatsApp, Messenger o Instagram) con intención de compra | `lead_source`, `score` |
| `appointment.booked` / `.rescheduled` / `.cancelled` / `.completed` / `.no_show` | agenda del hub | `appointment_id`, `service_id`, `starts_at` |
| `quote.requested` | cotizador del hub | `quote_id`, `items`, `estimated_total` |

### 4.5 Sitio (connector)

| Evento | Hook | Propiedades |
|---|---|---|
| `site.changed` | `activated_plugin`, `deactivated_plugin`, `upgrader_process_complete`, `switch_theme`, versión de WP o WC | `change` (`plugin_activated`…), `name`, `from_version?`, `to_version?` |
| `site.heartbeat` | cada hora | `wp_version`, `wc_version`, `php_version`, `plugin_version`, `outbox_pending`, `last_error?` |

### 4.6 Mensajería, consentimiento y relación (generados en el hub)

Los que ya tienen esquema (Fase 1): `survey.answered` (`survey_id`, `kind`, `answer`, `other?`, `score?`, `order_id?`), `segment.entered` / `segment.exited` (`segment_id`, `segment_name`, `is_system`), `consent.granted` / `consent.revoked` (`channel`, `purpose`, `source`, `legal_text_version?`) y `conversion.delivered` (`platform`, `status`, `source_event_id`, `conversion`). **Ningún sitio puede enviarlos**: el hub los rechaza con `unknown_event` en `/v1/s` y `/v1/b`.

Los de la Fase 2 (v3): `cart.abandoned` (`cart_id`, `cart_token`, `items[]`, `subtotal`, `currency`, `items_count`, `checkout_started`, `minutes_inactive`, `restore_url`), `message.*` (`message_id`, `channel`, `kind`, `source_type`, `source_id?`, `flow_id?`, `campaign_id?`, `template_id?`, `provider?`, más `is_mpp` en `opened`, `url` y `link_code` en `clicked`, `button_payload` o `keyword` en `replied`, `reason` y `error_code` en `failed`, `bounce_type` en `bounced`, `scope` y `via` en `unsubscribed`), `whatsapp.inbound` (`message_id`, `type`, `button_payload?`, `keyword?`, `wa_ref?`, `ctwa_clid?`, `referral_source_type?`, `referral_source_id?`; **nunca el texto**), `flow.entered` y `flow.exited` (`flow_id`, `run_id`, `version`, `is_holdout`, `reason` al salir). Los mensajes y las respuestas no guardan su texto en ClickHouse.

Los de la Fase 3 (v4):
- `points.earned`: `ledger_id`, `points`, `balance_after`, `status` (pending o confirmed), `reason`, `ref_type`, `ref_id` y `expires_at`.
- `points.redeemed`: `points`, `reward_kind` y `coupon_code`.
- `points.expired`.
- `tier.changed`: `from`, `to` y `direction`.
- `wallet.credited` y `wallet.debited`: `amount`, `currency`, `balance_after` y `reason`.
- `referral.shared`: `channel`.
- `referral.converted`: `referral_id`, `order_id` y `referrer_contact_id`.
- `review.requested`: `request_id`, `order_id` y `channel`.
- `review.collected`: `review_id`, `rating`, `has_media` y `source`. **Sin el texto de la reseña.**
- `nps.answered`: `score` y `category`.
- `product_watch.created` y `product_watch.notified`: `product_id` y `kind`.

Los de la Fase 5 (v5), solo del hub (ningún sitio los puede enviar):
- `lead.created`: `lead_id`, `origin` (form, whatsapp, messenger, instagram, quote, booking, manual, api, landing, meta_lead_ads o google_lead_form; `messenger` e `instagram` desde la v7; `landing`, `meta_lead_ads` y `google_lead_form` desde la v8), `service`, `score`, `temperature` (cold, warm o hot), `channel` y `form_id` (`landing:lp_…`, `meta:<id del formulario>` o `google:<id del formulario>` en los orígenes de la v8).
- `lead.stage_changed`: `lead_id`, `from`, `to` y `by` (user, system, ai o api).
- `lead.won`: `lead_id`, `value`, `currency`, `days_to_win` y `service`. Es la **conversión offline** que va a Meta y a Google (M18).
- `lead.lost`: `lead_id` y `reason`.
- `appointment.booked`: `appointment_id`, `service_id`, `service_name`, `staff_id`, `starts_at`, `duration_min`, `location`, `channel` y `lead_id`.
- `appointment.rescheduled`: `from_starts_at` y `starts_at`. `appointment.cancelled`: `by` y `reason`. `appointment.completed` y `appointment.no_show`.
- `quote.requested`: `quote_id`, `calculator_id`, `calculator_name`, `estimate_min`, `estimate_max`, `currency` y `lead_id`.

También en la v5, del lado del servidor pero **no** exclusivo del hub:
- `page.vitals`: `page` (URL), `lcp_ms`, `inp_ms`, `cls` y opcionales `ttfb_ms`, `device` (mobile, tablet o desktop) y `samples`. Métricas web reales (p75) que envía Imagina Turbo por la API pública (`POST /api/v1/vitals`, M19), para cruzar la lentitud con la conversión.

Lista completa: `cart.abandoned` (lo marca el job de carritos), `message.queued`, `message.sent`, `message.delivered`, `message.read` (WhatsApp), `message.opened` (correo, con `is_mpp` para las aperturas automáticas de Apple), `message.clicked`, `message.replied`, `message.failed`, `message.bounced`, `message.complained`, `message.unsubscribed`, `whatsapp.inbound` (con `referral` y `ctwa_clid` si viene de un anuncio de clic a WhatsApp, y `wa_ref` si trae token), `consent.granted`, `consent.revoked`, `segment.entered`, `segment.exited`, `points.earned`, `points.redeemed`, `points.expired`, `tier.changed`, `wallet.credited`, `wallet.debited`, `referral.shared`, `referral.converted`, `review.requested`, `review.collected`, `nps.answered`, `survey.answered`, `product_watch.created`, `product_watch.notified`, `flow.entered`, `flow.exited`, `lead.created`, `lead.stage_changed`, `lead.won`, `lead.lost`, `appointment.booked`, `appointment.rescheduled`, `appointment.cancelled`, `appointment.completed`, `appointment.no_show`, `quote.requested`, `page.vitals` (de Imagina Turbo por la API pública), `conversion.delivered` (a una plataforma de anuncios).

---

## 5. Objeto `order`

```jsonc
{
  "order_id": "1234", "number": "1234", "status": "processing",
  "created_at": "...", "paid_at": "...", "created_via": "checkout | admin | rest-api | store-api",
  "currency": "COP",
  "totals": { "subtotal": "300000.00", "discount": "30000.00", "shipping": "12000.00",
              "tax": "0.00", "total": "282000.00", "refunded": "0.00" },
  "payment_method": { "id": "wompi", "title": "Wompi - PSE", "is_offline": false },
  "shipping_method": { "id": "flat_rate", "title": "Envío nacional" },
  "coupons": ["BIENVENIDA10"],
  "items": [{
    "item_id": "55", "product_id": "88", "variation_id": "91", "sku": "PNT-AZ-1G",
    "name": "Pintura Azul 1 galón", "category_ids": ["12"], "brand": "Pintuco",
    "qty": 2, "unit_price": "150000.00", "line_total": "300000.00", "cost": "90000.00"   // cost: costo UNITARIO (COGS)
  }],
  "billing": { "first_name": "", "last_name": "", "email": "", "phone": "",
               "city": "Manizales", "state": "CO-CAL", "country": "CO", "postcode": "" },
  "shipping": { "city": "", "state": "", "country": "" },
  "modified_at": "...",                     // última modificación (para no pisar una proyección más nueva)
  "customer_ref": "wc:77",                 // null si es invitado
  "wc_attribution": {                       // atribución nativa de WooCommerce (_wc_order_attribution_*)
    "source_type": "utm | organic | referral | typein | admin",
    "utm_source": "", "utm_medium": "", "utm_campaign": "",
    "session_entry": "", "session_pages": 4, "session_count": 2, "device_type": "Mobile"
  },
  "consents": { "email_marketing": true, "whatsapp_marketing": false, "text_version": "v3" },
  "meta": { /* claves permitidas por el espacio de trabajo, p. ej. campos personalizados del checkout */ }
}
```

**`order?` en los eventos de estado:** los eventos `order.status_changed`, `.completed`, `.cancelled`, `.failed`, `.refunded` y `.payment_pending` llevan opcionalmente la foto completa del pedido. El connector siempre la envía, así la proyección del hub queda al día aunque un evento llegue tarde. El hub no reemplaza una proyección por otra con `modified_at` más viejo.

**Importación histórica:** cada pedido se importa como `order.created` (con `occurred_at` = fecha de creación) y, si ya se pagó, `order.paid` (con `occurred_at` = fecha de pago); cada reembolso como `order.refunded`. Todos con `source.type = import` y la foto actual del pedido.

**Estados pagados por defecto:** `processing`, `completed`. Cada sitio puede mapear estados personalizados como pagados o no pagados (por ejemplo, `wc-despachado`).

**Contraentrega:** el pedido cuenta como venta cuando pasa a `completed` o a un estado configurado como «entregado y cobrado». Mientras tanto se reporta aparte como «venta por confirmar».

---

## 6. Identidad

### 6.1 Cookies del tracker

| Cookie | Contenido | Duración |
|---|---|---|
| `_igr_aid` | ID anónimo (UUID v4). También en `localStorage` como respaldo | 395 días |
| `_igr_sid` | ID de sesión | 30 min deslizantes |
| `_igr_src` | primer y último contacto (UTM, click IDs, referrer, landing) en JSON compacto | 90 días |

Safari (ITP) limita a 7 días las cookies creadas con JS. Por eso el connector expone un **refresco desde el servidor**: el tracker llama a `/?igr_ping=1` como máximo una vez cada 6 días. El sitio responde `204` con `Set-Cookie` del mismo valor, `Cache-Control: no-store` y la ruta excluida de LiteSpeed, WP Rocket y Cloudflare. Como el sitio usa su propio dominio e IP, la cookie queda como de primera parte.

### 6.2 Resolución (en el hub)

Solo es **determinista**: nunca se unen perfiles por probabilidad. Los identificadores, en orden de fuerza, son:
1. `customer_ref` (cliente registrado en WooCommerce)
2. `email_hash` (SHA-256 del correo normalizado)
3. `phone_hash` (SHA-256 del E.164)
4. `wa_id` (WhatsApp)
5. `link_token` (enlace de un mensaje; enlaza el `anonymous_id` con el contacto)
6. `anonymous_id`

- Un `anonymous_id` que aparece junto a un identificador fuerte se asocia a ese contacto. Los eventos anónimos pasados se atribuyen por la tabla `identity_map` en ClickHouse; no se reescriben.
- Dos contactos que comparten un identificador fuerte se **fusionan** (`merged_into_id`). La fusión queda auditada y se puede revertir hasta 30 días.
- Un `link_token` nunca muestra datos personales en el sitio. Solo vincula para analítica y atribución. Los datos sensibles en pantalla (puntos, saldo) se muestran solo con sesión iniciada.

### 6.3 Atribución de clics a WhatsApp

El tracker agrega al texto prellenado de los enlaces de WhatsApp un token corto, por ejemplo `Hola, me interesa Pintura Azul (ref: K7Q2)`, y envía `cta.clicked` con `wa_ref`. Cuando el mensaje entra por la Cloud API (o por un webhook de Zender), el hub detecta el token y enlaza el número con el `anonymous_id`, la sesión y la fuente. Así las ventas que se cierran por WhatsApp quedan atribuidas al anuncio o la campaña que trajo a la persona. El texto del token se puede configurar y desactivar.

---

## 7. Normalización y hashing para plataformas de anuncios

Según la especificación de Meta y Google, se aplica SHA-256 en minúsculas y sin espacios. El correo se recorta y pasa a minúsculas. El teléfono va en E.164 sin `+` (con `libphonenumber` y el país del espacio de trabajo por defecto). Nombre y apellido van en minúsculas, sin tildes según la plataforma. La ciudad va en minúsculas y sin espacios. El estado o departamento usa el código ISO. El país usa ISO-2 en minúsculas.

**Diferencias por plataforma (implementadas en `App\Domain\Measurement`):**

| Dato | Meta | Google (GA4 / Data Manager) | TikTok |
|---|---|---|---|
| Teléfono | E.164 **sin** `+` | E.164 **con** `+` | E.164 **con** `+` |
| Nombres | minúsculas, **conserva** tildes (UTF-8) | minúsculas, sin tildes | minúsculas, sin tildes |
| Departamento | sufijo ISO 3166-2 en minúsculas (`CO-CAL` → `cal`) | igual | igual |
| `external_id` | SHA-256 del ID del contacto | igual | igual |

**[verificar]** con cuentas reales antes del lanzamiento: versiones de API, nombres de campos de Data Manager, nombres de evento de TikTok (`CompletePayment`, `SubmitForm`, `Pageview`) y los eventos que acepta Meta para mensajes de negocio.

**Deduplicación con el píxel:** el `event_id` de la compra se comparte con el navegador mediante una variable inline en la página de gracias (no es dato personal), para que el píxel y la conversión desde el servidor usen el mismo ID.

---

## 8. Versiones del contrato

| Versión | Fecha | Cambios |
|---|---|---|
| 1 | 2026-11-18 | Contrato inicial (cierre de la Fase 0). |
| 2 | 2026-11-19 | `identify.token` (token firmado de `window.igrUser`). Esquemas de los eventos del hub de §4.6 que se usan en la Fase 1. Solo agrega campos opcionales: un evento v1 sigue siendo válido. |
| 3 | 2026-11-20 | Fase 2. `order.cart_token` (el carrito del que salió el pedido) y `cart.updated.restored_from` (carrito restaurado desde un enlace). Esquemas de los eventos del hub de la Fase 2: `cart.abandoned`, `message.*` (11), `whatsapp.inbound`, `flow.entered` y `flow.exited`. Los sitios siguen enviando v2 hasta actualizar el plugin; un evento v2 sigue siendo válido. |
| 4 | 2026-11-21 | Fase 3. `context.referral_code` y `referral_code` en el primer y el último contacto (`?ref=` de referidos y afiliados). Esquemas de los eventos del hub de la Fase 3: `points.earned`, `points.redeemed`, `points.expired`, `tier.changed`, `wallet.credited`, `wallet.debited`, `referral.shared`, `referral.converted`, `review.requested`, `review.collected`, `nps.answered`, `product_watch.created` y `product_watch.notified`. Un evento v3 sigue siendo válido. |
| 5 | 2026-11-23 | Fase 5. Esquemas de los eventos del hub de servicios: `lead.created`, `lead.stage_changed`, `lead.won`, `lead.lost`, `appointment.booked`, `appointment.rescheduled`, `appointment.cancelled`, `appointment.completed`, `appointment.no_show` y `quote.requested`, más `page.vitals` (métricas web de Imagina Turbo por la API pública, M19; se agregó durante la Fase 5, antes de cerrarla, por eso no abre una versión 6). Los sitios no envían nada nuevo: el connector y el tracker siguen en la 4, que sigue siendo válida. |
| 6 | 2026-11-25 | Captura temprana del checkout (auditoría F4): `checkout.contact_captured` pasa a ser un evento del plugin, firmado por `/v1/s`, con el correo o el teléfono en `identity` y los opcionales `cart_token` y `checkout`. Ya no viaja cifrado desde el navegador (`encrypted` queda obsoleto). El connector envía la 6 en todos sus eventos; el tracker sigue en la 4. Un evento v5 o anterior sigue siendo válido. |
| 7 | 2026-11-26 | Leads desde Messenger e Instagram (lote 4): `lead.created.origin` acepta `messenger` e `instagram` (el canal del chat que creó el lead; `channel` lleva el mismo valor). Solo cambia un evento del hub: ningún sitio envía nada nuevo, el connector sigue en la 6 y el tracker en la 4, y un evento v6 o anterior sigue siendo válido. |
| 8 | 2026-11-28 | Captación sin tienda (rediseño v2 §11.3): `lead.created.origin` acepta `landing` (página de captura alojada en el hub, `/p/{slug}`), `meta_lead_ads` (formularios de clientes potenciales de Meta, Facebook e Instagram) y `google_lead_form` (formularios de clientes potenciales de Google Ads por webhook); `form_id` lleva `landing:lp_…`, `meta:<formulario>` o `google:<formulario>`. Las páginas de captura emiten además `form.submitted` desde el hub (`source.type = hub`, `form_plugin = igr_landing`), sin cambiar su esquema. Solo cambia un evento del hub: ningún sitio envía nada nuevo, el connector sigue en la 6 y el tracker en la 4, y un evento v7 o anterior sigue siendo válido. **Para fusionar con otro cambio de la v8:** este cambio son solo los tres valores nuevos del `enum` de `origin` en `lead.created.json` (con sus ejemplos) y el 8 en `_envelope.json`. |

**Regla de identidad para el navegador (v2):** en eventos con `source.type = browser`, el hub **ignora** `identity.customer_ref` salvo en un `identify` con `token` válido. Así nadie puede atribuirse la identidad de otro cliente desde el navegador.

