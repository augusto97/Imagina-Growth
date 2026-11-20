# connector/ — Plugin de WordPress «Imagina Growth Connector»

> Lee antes: `/CLAUDE.md` (principios 1 a 3), `/docs/eventos.md` y `/tracker/CLAUDE.md`.
> Slug: `imagina-growth-connector` · Namespace: `ImaginaGrowth\Connector\` · Prefijo de funciones, opciones y tablas: `igr_` · REST: `imagina-growth/v1` · Text domain: `imagina-growth`.

---

## 1. Propósito

Ser un **puente liviano** entre el sitio y el hub: recoger eventos de servidor, servir el tracker, sincronizar el catálogo, importar el historial, ejecutar comandos firmados del hub y pintar los espacios para los widgets. **No guarda lógica de negocio ni analítica.** Todo eso vive en el hub.

Funciona en tiendas WooCommerce y también en **sitios WordPress sin WooCommerce** (corporativos, de servicios, landings). En ese caso solo activa formularios, navegación, CTA y widgets.

## 2. Requisitos y compatibilidad

- PHP 8.1+ (`declare(strict_types=1)`), WordPress 6.4+, WooCommerce 8.5+ (opcional).
- Declarar compatibilidad en `before_woocommerce_init`: `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)` (HPOS) y `'cart_checkout_blocks'`.
- **Siempre** se usa la API CRUD de WooCommerce (`wc_get_order`, `wc_get_orders`, `$order->get_meta()`), nunca `get_post_meta` sobre pedidos.
- Multisitio: cada subsitio se conecta como un sitio aparte.
- Dependencias de Composer **prefijadas con Strauss** (`ImaginaGrowth\Connector\Vendor\`) para no chocar con otros plugins.

## 3. Estructura

```
imagina-growth-connector/
├── imagina-growth-connector.php      ← cabecera, constantes, autoload, arranque
├── src/
│   ├── Plugin.php                    ← contenedor simple y registro de módulos
│   ├── Connection/                   ← handshake, claves, salud (§4)
│   ├── Outbox/                       ← tabla, repositorio, flusher (§5)
│   ├── Collectors/                   ← Woo, Forms/*, Users, Reviews, Site, Subscriptions (§6)
│   ├── Catalog/                      ← sincronización y conciliación (§7)
│   ├── Backfill/                     ← importación histórica (§7)
│   ├── Commands/                     ← endpoint REST firmado y handlers (§8)
│   ├── Tracking/                     ← carga del tracker, ping de cookie, contexto de página (§9)
│   ├── Onsite/                       ← bloques, shortcodes, Elementor, Mi cuenta, checkout (§10)
│   ├── Consent/                      ← casillas, WP Consent API, banners, privacidad (§11)
│   ├── Admin/                        ← páginas y REST para la UI de React (§12)
│   ├── Cli/                          ← comandos WP-CLI
│   └── Support/                      ← Hmac, Pii, Uuid, Http, Logger, Cookies
├── assets/ (tracker.min.js, widgets loader, admin build)
├── admin-app/                        ← React + TS + Vite (@kucrut/vite-for-wp)
├── languages/
└── tests/
```

## 4. Conexión y seguridad

- **Handshake:** ver `docs/modulos.md` M02. El código de un solo uso se canjea con `POST hub/api/connect/exchange` y devuelve `site_id`, `public_key` y `secret`.
- El **secreto** se guarda cifrado en `igr_connection` (`autoload = no`) con `sodium_crypto_secretbox`, usando una clave derivada de `wp_salt('auth')`.
- **Todas las llamadas al hub** van firmadas (`Support\Http::signed`, docs/api-connector.md §2): `X-IGR-Site` y
  `X-IGR-Signature`. Desde la 0.1.4, en **v2** (`t=<unix>,n=<nonce>,v2=<hmac>` sobre método, ruta y consulta canónicas,
  hora, nonce y SHA-256 del cuerpo; `Support\Hmac::header_v2`) si el hub la anunció en el canje o en `/v1/s/config`
  (`signature.versions`, guardado en `igr_connection['hub_signature']`; `Connection::signature_version()`); si no, en
  v1 (`t=<unix>,v1=<hmac_sha256(secret, t.body)>`). Si el hub rechaza una v2 con `401` y todavía anuncia la v1, se
  reintenta una vez en v1 (un hub que volvió atrás).
- **Comandos entrantes:** v2 o v1 (la v1 hasta que el hub anuncie `[2]`, `Connection::accepts_v1()`), ventana de
  ±300 s y **nonce** guardado 10 minutos (transient; el del cuerpo y el de la firma v2) para impedir repeticiones. La
  v2 se verifica contra `REQUEST_URI` y contra `rest_url( 'imagina-growth/v1/commands' )` (`CommandsController::targets`).
- **Versión del plugin hacia el hub:** va en el canje, en el latido y en `GET /v1/s/config?plugin_version=`; al cargar
  una versión nueva (`igr_state['pv']`), `Installer::maybe_upgrade` pide la configuración en una tarea para que el hub
  pase a firmarle en v2 sin esperar al día siguiente.
- **Rotación:** el hub puede pedir una rotación con un comando; durante 24 h valen los dos secretos.
- **Desconectar:** borra las claves y opcionalmente las tablas locales.

## 5. Outbox y envío

- **Tabla `{prefix}igr_outbox`:** `id BIGINT AI`, `event_id CHAR(36) UNIQUE`, `event VARCHAR(64)`, `payload LONGTEXT` (JSON), `status ENUM('pending','sending','sent','dead')`, `attempts TINYINT`, `next_attempt_at DATETIME`, `created_at`, `sent_at`, con índices en (status, next_attempt_at).
- **Escritura:** un solo `INSERT` preparado por evento. **Nunca** se hace HTTP en la petición del visitante.
- **Envío:** acción recurrente de Action Scheduler `igr_flush_outbox` cada minuto (grupo `imagina-growth`):
  - Toma hasta 500 eventos o 1 MB, los comprime con `gzencode` y hace `wp_remote_post` (timeout de 15 s).
  - Con un 202 marca `sent`. Los rechazos por esquema pasan a `dead` con el motivo. Los 5xx y los timeouts se reintentan con espera de 1 m, 5 m, 30 m, 2 h y 12 h; a los 10 intentos pasan a `dead`.
  - Los `sent` se purgan a los 7 días y los `dead` a los 30 (visibles en Diagnóstico).
- **Si WP-Cron no corre a tiempo** (sitios con poco tráfico), Diagnóstico avisa cuando el retraso pasa de 10 min y explica cómo poner un cron real (`wp action-scheduler run` o `wp cron event run --due-now` cada minuto).

## 6. Recolectores de eventos (hooks)

**Contexto de navegación en eventos de servidor:** `Tracking\RequestContext` lee de la petición las cookies `_igr_aid`, `_igr_sid`, `_igr_src`, `_fbp`, `_fbc`, `_ga` y `_ttp`. **Al crear el pedido** ese contexto se guarda en la meta `_igr_ctx` del pedido, porque los cambios de estado a «pagado» suelen llegar por el webhook de la pasarela (Wompi, ePayco, Mercado Pago, PayU, Bold…), donde no hay cookies del navegador. La IP y el user agent salen de los campos nativos de WooCommerce (`get_customer_ip_address()`, `get_customer_user_agent()`) y solo viajan en tránsito.

| Evento | Hook(s) | Notas |
|---|---|---|
| `cart.item_added` | `woocommerce_add_to_cart` | incluye los AJAX y la Store API |
| `cart.item_removed` | `woocommerce_cart_item_removed` | |
| `cart.updated` | `woocommerce_cart_updated` | *throttle* de 30 s por sesión (transient) |
| `checkout.started` | `template_redirect` con `is_checkout() && !is_wc_endpoint_url('order-received')` | una vez por sesión de carrito |
| `checkout.contact_captured` | `woocommerce_checkout_update_order_review` (clásico; el plugin pide `update_checkout` al salir del correo o del teléfono) y `woocommerce_store_api_cart_update_customer_from_request` (bloques) | v6, `Collectors\EarlyCapture`. Solo con `settings.early_capture` del hub, para invitados y con un correo o un teléfono E.164 completos. Aviso «Usaremos tu correo o WhatsApp para ayudarte a terminar tu compra» en la descripción del campo (clásico) o debajo del correo (bloques, script en línea). Una fila en el outbox que sale 20 s después; si en ese tiempo llega otro valor del mismo carrito, se reemplaza (`Outbox::replace_pending`). Una vez por carrito y dato, máximo 5 por carrito. El dato va en `identity`. Los `cart.*` de un invitado ya no llevan el correo de la sesión de WooCommerce (solo el de la cuenta) |
| `order.created` | `woocommerce_checkout_order_processed` (clásico), `woocommerce_store_api_checkout_order_processed` (bloques), `woocommerce_new_order` (admin y REST) | guarda `_igr_ctx`; `event_id` determinista |
| `order.paid` | `woocommerce_order_status_changed` hacia un estado pagado + `woocommerce_payment_complete` | la primera vez que pasa a pagado (meta `_igr_paid_sent`) |
| `order.status_changed` / `.completed` / `.cancelled` / `.failed` | `woocommerce_order_status_changed` | |
| `order.payment_pending` | estado `on-hold` o `pending` con una pasarela marcada como offline | la lista de pasarelas offline se configura (BACS, cheque, COD, Efecty, Baloto, PSE pendiente…) |
| `order.refunded` | `woocommerce_order_refunded` | |
| `coupon.applied` | `woocommerce_applied_coupon` | |
| `customer.registered` | `woocommerce_created_customer`, `user_register` | |
| `customer.updated` | `profile_update`, `woocommerce_customer_save_address` | solo los campos que cambiaron |
| `customer.logged_in` | `wp_login` | también define la identidad para el tracker (§9) |
| `review.submitted` | `comment_post` y `transition_comment_status` con `comment_type = review` | rating desde la meta `rating`; `verified` |
| `subscription.*` | `woocommerce_subscription_status_updated`, `woocommerce_subscription_renewal_payment_complete`, `woocommerce_subscription_renewal_payment_failed` | solo si WC Subscriptions está activo |
| `catalog.*` | §7 | |
| `form.submitted` | ver la tabla de formularios | |
| `site.changed` | `activated_plugin`, `deactivated_plugin`, `upgrader_process_complete`, `switch_theme`, cambio de versión de WP o WC detectado en `admin_init` | |
| `site.heartbeat` | acción horaria de Action Scheduler | |

**Formularios** (un adaptador por plugin en `Collectors/Forms/`, con la interfaz `FormAdapter::register()` y `normalize(): FormSubmission`):

| Plugin | Hook |
|---|---|
| Contact Form 7 | `wpcf7_submit` con estado `mail_sent` o `mail_failed` (el lead existe aunque falle el correo de aviso) + `WPCF7_Submission::get_instance()->get_posted_data()` |
| WPForms | `wpforms_process_complete` |
| Gravity Forms | `gform_after_submission` |
| Fluent Forms | `fluentform/submission_inserted` (v5+), `fluentform_submission_inserted` (versiones anteriores) |
| Elementor Pro Forms | `elementor_pro/forms/new_record` |
| JetFormBuilder | el hook posterior al envío exitoso (**verifica el nombre exacto en la versión instalada**) |
| Formidable | `frm_after_create_entry` |
| Ninja Forms | `ninja_forms_after_submission` |

- **Mapeo automático** de campos por tipo y nombre (`email`, `tel`, `phone`, `nombre`, `name`, `empresa`, `ciudad`, `mensaje`…). El mapeo se puede editar en la UI por formulario.
- La casilla de consentimiento se mapea a `consents{}`.
- Los campos de contraseña, tarjeta o archivos **nunca** se envían.

## 7. Catálogo e importación histórica

- **Productos en tiempo real:** `woocommerce_new_product`, `woocommerce_update_product`, `woocommerce_new_product_variation`, `woocommerce_update_product_variation`, `before_delete_post` y `wp_trash_post` (tipo producto) emiten `catalog.product_upserted` o `catalog.product_deleted`.
  - Un hash del contenido relevante evita reenviar lo que no cambió.
  - El cambio de precio se detecta comparando con el hash anterior y emite `catalog.price_changed`.
- **Stock:** `woocommerce_product_set_stock`, `woocommerce_variation_set_stock`, `woocommerce_product_set_stock_status` y `woocommerce_variation_set_stock_status` emiten `catalog.stock_changed`.
- **Categorías:** `created_product_cat`, `edited_product_cat` y `delete_product_cat`.
- **Costo (COGS):** el campo nativo de costo de WooCommerce si está activo, o las metas de plugins conocidos (configurable).
- **Conciliación nocturna:** se recorre el catálogo por páginas, se comparan los hashes con los que el hub devuelve (`GET /v1/s/catalog/hashes`) y se reenvían las diferencias.
- **Importación histórica** (`Backfill\Runner`):
  - Al conectar, o con `wp igr backfill --from=YYYY-MM-DD`, se encolan lotes de 200 con `wc_get_orders(['date_created' => '>=…', 'paginate' => true, 'orderby' => 'ID'])`.
  - Orden de importación: productos → categorías → clientes → pedidos → reembolsos → reseñas → suscripciones.
  - Un lote cada 10 s (configurable). Se pausa si la carga del servidor es alta (`sys_getloadavg()` cuando está disponible).
  - El progreso se guarda en `igr_backfill_state` y se reanuda solo si se corta.
  - Los eventos llevan `source.type = import`.

## 8. Comandos del hub (REST firmado)

`POST /wp-json/imagina-growth/v1/commands` con `{ "command": "...", "args": {...}, "request_id": "..." }`. Cada comando es una clase en `Commands/` que valida los argumentos y devuelve JSON. Las respuestas son idempotentes por `request_id` (se guarda 24 h).

| Comando | Qué hace |
|---|---|
| `coupon.create` | cupón único (`WC_Coupon`): tipo, monto, vencimiento, `usage_limit=1`, `email_restrictions`, `individual_use`, productos o categorías, monto mínimo. Devuelve el código y el ID. **Fase 2:** idempotente por código (hash de los datos en la meta `igr_coupon_hash`): los mismos datos devuelven el mismo cupón; otros datos, o un cupón del dueño con ese código, responden `409 coupon_exists`. Vence al final del día de `expires_at` en la hora de la tienda. Metas `igr_coupon=1` e `igr_source` |
| `coupon.disable` | lo deja en borrador. Solo los cupones con `igr_coupon=1`; los del dueño responden `409 coupon_not_managed` |
| `product.lookup` | por IDs o búsqueda: precio, precio de oferta, stock en vivo, URL, imagen y variaciones. Lo usa el agente de IA. **Fase 2:** solo productos publicados, sin contraseña ni ocultos del catálogo; máximo 20 resultados y 20 variaciones por producto |
| `order.status` | por número de pedido + correo o teléfono que coincida: estado, envío y seguimiento si existe. **Fase 2:** también busca `_order_number` (plugins de números secuenciales); el teléfono se compara en E.164; un dato que no coincide responde igual que un pedido inexistente (`404 not_found`); el seguimiento sale de WooCommerce Shipment Tracking si está |
| `order.create_payment_link` | crea un pedido pendiente con los ítems indicados y devuelve `get_checkout_payment_url()`. **Fase 4** (lo usan la bandeja y el agente): hasta 20 ítems `{product_id, variation_id?, qty}` solo de productos publicados, comprables y con stock suficiente (`422 product_unavailable` u `out_of_stock`, sin dejar pedidos a medias); precio de la tienda en ese momento; `billing` (correo, teléfono, nombre) para que el cliente invitado pueda pagar; `customer_ref` y `coupon` opcionales; `created_via = imagina-growth` y la meta `igr_payment_link`. Devuelve `order_id`, `number`, `url`, `total` y `currency` |
| `review.publish` | crea la reseña nativa (comentario `review` con `rating`, `verified=1` si aplica, la meta `igr_review_id` y las URLs de medios). **Fase 3:** aprobada, con `igr_title` e `igr_media` (JSON, hasta 10, solo http/https), fecha del hub y cliente del pedido si viene `order_id`; recalcula el promedio del producto. Idempotente por `review_id` (si se había despublicado, la vuelve a aprobar) |
| `review.reply` | respuesta del dueño como comentario hijo. **Fase 3:** comentario aprobado con la meta `igr_reply`; la misma respuesta devuelve el mismo comentario; solo responde reseñas (`404` si no) |
| `review.unpublish` | **Fase 3:** deja la reseña pendiente (`hold`) y recalcula el promedio |
| `order.add_note` | nota privada (por ejemplo «Pedido recuperado por Imagina Growth»); con `customer_note` se la envía al cliente |
| `customer.set_tier` | meta `igr_tier` y rol opcional mapeado (para precios VIP con el plugin de precios por rol del cliente). **Fase 3:** también `igr_tier_name`; el rol se agrega sin quitar los otros y el del nivel anterior (`igr_tier_role`) se quita. Seguridad: nunca asigna roles del sistema (administrator, editor, author, contributor, subscriber, customer, shop_manager) ni un rol que dé algo más que `read` (`422 role_not_allowed`); si el rol no existe lo crea solo con `read`; a administradores y gestores de la tienda solo les guarda la meta. Sin `tier_key` borra el nivel |
| `catalog.resync` | fuerza la conciliación |
| `site.diagnostics` | versiones, estado del outbox y de Action Scheduler, capacidades |
| `privacy.erase_local` | borra del outbox y de las metas locales los datos de un contacto |
| `secret.rotate` | §4 |

**Carrito restaurable** (sin comando): el hub genera enlaces `https://cliente.com/?igr_cart=<payload>.<firma>`, con el payload en base64url (ítems, cantidades, cupón opcional y vencimiento) firmado con HMAC del secreto del sitio. En `template_redirect` el plugin verifica la firma y el vencimiento, vacía o fusiona el carrito (configurable), agrega los ítems, aplica el cupón y redirige al checkout. **No hace llamadas al hub** en esa petición.

Implementación (Fase 2, `Onsite\CartRestore`, api-connector.md §6.1):
- Acepta el secreto actual y el anterior durante la rotación.
- Omite los productos que ya no existen, no se pueden comprar o no tienen stock, y limita la cantidad al stock disponible.
- Redirige con un 302 `no-store` (`DONOTCACHEPAGE` y `litespeed_control_set_nocache`) al checkout, conservando solo `_igr` y `utm_*`.
- Un enlace adulterado, vencido o de otro sitio, o sin productos disponibles, lleva a la tienda sin errores y sin tocar el carrito.
- El ID del carrito del hub queda en la sesión (`igr_restored_from`) y sale ya en `cart.updated.restored_from`, sin esperar el límite de 30 s.
- El pedido guarda el `cart_token` de la sesión en la meta `_igr_cart_token` al crearse (checkout clásico y de bloques) y lo envía en `order.cart_token` (v3) en todos los eventos del pedido, también en los que llegan después por el webhook de la pasarela.

## 9. Tracker, cookies y caché

- **Carga del tracker:** `wp_enqueue_script('igr-tracker', …, ['strategy' => 'defer', 'in_footer' => true])` con la configuración inline `window.igrConfig = {siteKey, endpoint, consentMode, cookieDomain, waRef: {...}}`. **No depende del visitante**, así que se puede cachear.
- **Contexto de página** (cacheable porque no es personal): `window.igrPage = {type, objectId, product: {id, sku, price, categories, inStock}}`, impreso en `wp_footer`.
- **Exclusiones en los optimizadores:**
  - LiteSpeed Cache: filtros de exclusión de JS combinado y diferido.
  - WP Rocket: `rocket_exclude_js` y `rocket_delay_js_exclusions`.
  - Autoptimize, Perfmatters y FlyingPress: sus filtros equivalentes.
  - **El tracker no se retrasa hasta la interacción**, porque se perderían las vistas de página. Su peso (≤ 6 KB) lo permite.
- **Ping de cookie:** `/?igr_ping=1` se atiende en `plugins_loaded` con prioridad 0: valida el formato del valor, envía `Set-Cookie` para `_igr_aid` (395 días, `SameSite=Lax`, `Secure`) y responde `204` con `Cache-Control: no-store, private`. Además define `DONOTCACHEPAGE` y llama a `do_action('litespeed_control_set_nocache')`. Documentar la regla de Cloudflare si el sitio usa «cache everything».
- **Identidad de usuario con sesión:** en las páginas que no se cachean (Mi cuenta, checkout, gracias) se imprime `window.igrUser = {ref: "wc:77", token: <HMAC(customer_ref + exp)>}` para que el tracker llame a `identify`. **Nunca** se imprime en páginas cacheables.
- **Página de gracias:** `window.igrOrder = {eventId, value, currency}` para que un píxel del navegador use el mismo `event_id` que la conversión desde el servidor.

## 10. Widgets y elementos en el sitio

- **Cargador:** el tracker pide `widgets.json` (caché de CDN, igual para todos) en toda página que no sea el checkout y descarga `igr-widgets.js` (el runtime) solo si trae widgets o la página tiene un slot. El runtime descarga el paquete de cada tipo solo cuando un widget aplica (`tracker/CLAUDE.md` §8).
- **Espacios (slots)** cacheables con espacio reservado para no causar CLS: `<div class="igr-slot igr-slot-reco" data-igr-slot="reco" data-igr-ctx='{"kind":"trending","limit":4}' style="min-height:360px"></div>` (`Onsite\Slots`, api-connector.md §7). El HTML nunca depende del visitante; sin conexión o con el tracker apagado no se pinta nada. Alturas por defecto: `form` 320, `quiz` 360, `reco` 360, `reviews` 420 (lista), 300 (carrusel), 280 (galería), 22 (insignia), `bis` 44, `points` 24, `loyalty` 480, `banner` 56 en la cuenta y 0 arriba (flotante); todos aceptan `height`.
- Se exponen como:
  - **Bloques de Gutenberg** dinámicos (`Onsite\Blocks`, editor en JS plano `assets/igr-blocks.js`): `imagina-growth/form`, `/quiz`, `/recommendations`, `/reviews`, `/loyalty`. Pintan exactamente lo mismo que el shortcode.
  - **Shortcodes** (`Onsite\Shortcodes`, registrados siempre para que nunca se vean como texto): `[igr_form id="wg_…"]`, `[igr_quiz id="wg_…"]`, `[igr_reco type="para_ti|bought_together|recently_viewed|best_sellers_category|trending|complete_cart" product_id="" category_id="" limit="4" title=""]`, `[igr_reviews product_id="" layout="list|carousel|gallery|badge" title=""]`, `[igr_loyalty]` y `[igr_banner placement="account|top"]`. **Fase 5** (`Onsite\ServiceSlots`, también sin WooCommerce): `[igr_quote id="qc_…" height="420"]` (slot `quote`) y `[igr_booking service="slug" label="Agendar cita" style="button|link" new_tab="1|0"]` (enlace fijo a `{hub}/p/{clave}/reservar[/{slug}]` con `data-igr-cta="booking"`); bloques `imagina-growth/quote` y `imagina-growth/booking` (editor en `assets/igr-blocks-services.js`) y widgets de Elementor «Cotizador» y «Botón de reservas». En la ficha toman el producto actual y en una categoría, la categoría; `complete_cart` en el carrito (página que no se cachea) lleva los productos del carrito en `cart`.
  - **Widgets de Elementor** (`Onsite\Elementor\*`): se registran en `elementor/widgets/register` solo si Elementor está activo.
- **Automáticos en WooCommerce** (`Onsite\Store`), con interruptores en la pestaña «En el sitio» y, para lo que depende de un módulo del hub, `onsite` de `/v1/s/config` (`loyalty`, `wallet`, `product_watch`). Los interruptores efectivos se copian en `igr_state['trk']['ons']` para no consultar la base en páginas cacheables:
  - «Avísame cuando vuelva» en `woocommerce_single_product_summary` (35) si el producto está agotado; «Avísame si baja de precio» si tiene stock y está activo (apagado por defecto). Los avisos por variación quedan pendientes.
  - «Gana N puntos» debajo del precio (11) y en el carrito clásico (`woocommerce_proceed_to_checkout`, 5), solo con la fidelización activa en el hub.
  - Banner por segmento arriba (`wp_body_open`, apagado por defecto); es flotante y no reserva espacio.
  - Cookie de sesión `_igr_ct` con el subtotal del carrito (con impuestos) cuando WooCommerce escribe sus cookies: la usa la regla `cart_value_min` de los widgets. No identifica a nadie.
- **Mi cuenta → «Mis recompensas»** (`Onsite\Account`): endpoint `recompensas` por `woocommerce_get_query_vars` (WooCommerce registra la regla), entrada en `woocommerce_account_menu_items` antes de «Salir» y contenido con el slot `banner` (cuenta) y el slot `loyalty`. Mi cuenta no se cachea, así que ahí está `window.igrUser` (§9). Las reglas de reescritura se refrescan solo cuando el endpoint aparece o desaparece (`Account::sync_rewrite`, bandera `rw_flushed` en `igr_state`).
- **Saldo en el checkout** (`Onsite\Wallet`, api-connector.md §5.5), solo para clientes con sesión y con `wallet` activo:
  - Clásico: en `woocommerce_review_order_before_payment` (no se repinta con cada actualización). Bloques: antes del bloque `woocommerce/checkout` (fuera del árbol de React).
  - El slot va oculto; `assets/igr-wallet.js` (compilado desde `tracker/widgets/wallet.ts`) pide el saldo a `/me` con `igrUser.token` y muestra «Tienes $35.000 de saldo a favor» y el botón «Usar saldo» solo si hay saldo.
  - El clic llama a `?wc-ajax=igr_wallet_redeem` (nonce `igr-wallet`), que hace la **única llamada síncrona** al hub (`POST /v1/s/wallet/redeem`, firmada, 3 s) y aplica el cupón al carrito de la sesión; el checkout clásico se actualiza con `update_checkout` y el de bloques invalidando el store `wc/store/cart`. Con `409` el mensaje depende de `error.code`: sin saldo (`no_balance`), «No tienes saldo disponible por ahora»; otro canje en curso (`busy`), «Estamos aplicando tu saldo…»; carrito en otra moneda (`currency_mismatch`), «Tu saldo no se puede usar con la moneda de este carrito». Con el hub caído, «No pudimos usar tu saldo ahora». El código aplicado queda en la sesión (`igr_wallet_coupon`) para no pedir otro.
- **Reseñas publicadas desde el hub:** el título (`igr_title`) va antes del texto (`woocommerce_review_before_comment_text`) y las fotos y videos (`igr_media`, solo http/https, imágenes con `loading="lazy"`) debajo (`woocommerce_review_after_comment_text`). `ReviewCollector` no las devuelve al hub como `review.submitted` (ni en vivo ni en la importación).
- **Referidos:** el tracker guarda `?ref=` en `_igr_src`; `RequestContext::touch()` lo valida (`^[A-Za-z0-9_-]{3,32}$`) y el pedido lo lleva en `context.last_touch.referral_code` (v4). El plugin no lee `?ref=` en PHP: una página cacheada no correría ese código.
- **Encuesta «¿cómo nos conociste?»:** slot en la página de gracias (`woocommerce_thankyou`).

## 11. Consentimiento y privacidad

- **Casillas de marketing en el checkout:**
  - Clásico: `woocommerce_review_order_before_submit` para mostrarlas y `woocommerce_checkout_create_order` para guardar la meta `_igr_consents`.
  - Bloques: `woocommerce_register_additional_checkout_field()` (WC 8.9+) con un campo por canal.
  - Van sin marcar, con el texto y la versión que da el hub (en caché diaria).
- **Banner de cookies:**
  1. WP Consent API (`wp_has_consent('marketing')`) cuando existe.
  2. Si no, adaptadores para Complianz, CookieYes, Cookiebot y Real Cookie Banner.
  3. Si no hay ninguno, el banner ligero propio (opcional).
  El estado se pasa al tracker y a cada evento (`context.consent`).
- **Herramientas de privacidad de WordPress:** se registran `wp_privacy_personal_data_exporters` y `wp_privacy_personal_data_erasers`. Al exportar o borrar desde WordPress se crea la solicitud equivalente en el hub.
- **Registros y logs:** con `Pii::mask()` y sin payloads completos salvo en modo depuración temporal (se apaga solo a las 24 h).

## 12. Interfaz de administración (React)

Menú «Imagina Growth» con estas pestañas:

| Pestaña | Contenido |
|---|---|
| **Estado** | conexión, cuenta, lo que pasó en la tienda en las últimas 24 horas (con nombres como «Pedidos pagados», no `order.paid`), último envío, datos esperando y los que no se pudieron enviar, el último aviso explicado (`Admin\Wording::error`: qué pasó y qué hacer, sin `HTTP 401`), versión del plugin; aviso si las tareas programadas van atrasadas; botón **«Importar y enviar ahora»** (`Admin\SyncNow`) |
| **Datos** | qué se envía (activar o desactivar por tipo), excluir roles (administradores por defecto), páginas excluidas, estados pagados, pasarelas offline |
| **Formularios** | detectados, mapeo de campos y prueba de envío |
| **Consentimiento** | textos, casillas del checkout y modo de banner |
| **En el sitio** | interruptores de los elementos automáticos («Avísame», «Avísame si baja», puntos, «Mis recompensas», «Usar saldo», banner superior; los de fidelización también dependen del hub) y cómo poner los slots (bloques, shortcodes y Elementor) |
| **Diagnóstico** | tareas programadas, «Datos de tu tienda por enviar» (en espera, enviándose, enviados y los que no se pudieron enviar, con reintento manual y el motivo en palabras), y el informe técnico para soporte plegado en un `<details>` con el código del sitio. Sin jerga (auditoría U5): nada de «outbox», `event_id` ni `pending` a la vista |
| **Avanzado** | dominio de seguimiento, modo de píxel, importación histórica, desconexión |

**«Importar y enviar ahora»** (`Admin\SyncNow`, AJAX `igr_sync_now`, solo `manage_options` con nonce): si Action Scheduler no corre (WP-Cron sin visitas o `DISABLE_WP_CRON` sin cron real), la importación y el envío no avanzan. El botón hace el mismo trabajo en tramos de ~15 s (lotes de `Runner::step(false)` + `Flusher::run`) que la página repite hasta terminar, con el avance por entidad y el error exacto del hub si lo hay. La primera llamada arranca la importación si nunca corrió o falló, la reanuda si estaba en pausa y adelanta los reintentos (`Outbox::expedite()`). Es la única excepción a «nada de HTTP en la petición del admin»: la pide el administrador y nunca corre en la de un visitante.

**WP-CLI:** `wp igr status`, `wp igr flush`, `wp igr backfill`, `wp igr resync-catalog`, `wp igr test-event`, `wp igr demo-store` (solo con `WP_ENVIRONMENT_TYPE=local`).

## 13. Presupuesto de rendimiento

- **Páginas cacheables:** cero consultas extra. En las no cacheadas, como máximo 2 consultas y menos de 3 ms.
- **Checkout:** menos de 10 ms añadidos (una inserción en el outbox por evento y la lectura de cookies).
- **Opciones:** ninguna opción autocargada de más de 1 KB. Todas con prefijo `igr_` y `autoload = no` salvo la mínima de configuración.
- **Assets:** el tracker pesa como máximo 6 KB gzip. El script de widgets solo se carga si hay widgets activos. El admin solo carga en sus propias páginas.
- **CI:** prueba de rendimiento que mide con Query Monitor (en modo de prueba) las consultas y el tiempo añadido en la portada, el producto, el carrito y el checkout de la tienda de prueba.

## 14. Pruebas y matriz de compatibilidad

- PHPUnit + WP test suite, una prueba por recolector (el evento generado se valida contra el JSON Schema del hub) y Playwright con `wp-env`.
- **Matriz mínima antes de cada versión:**

| Categoría | Probar con |
|---|---|
| Temas | Astra, Kadence, Woodmart, Flatsome, Blocksy, Hello + Elementor |
| Constructores | Gutenberg, Elementor, Bricks |
| Caché | LiteSpeed Cache (OpenLiteSpeed), WP Rocket, Cloudflare APO |
| Checkout | clásico y de bloques |
| Pasarelas | Wompi, ePayco, Mercado Pago, PayU, Bold, contraentrega, transferencia |
| Formularios | todos los de §6 |
| Ecosistema | JetEngine y JetFormBuilder (Crocoblock), Imagina Search, Imagina Turbo |

### 14.1 Cómo correr las pruebas

- **Unitarias** (sin WordPress: firma v1 y v2 con los vectores compartidos de `hub/tests/fixtures/signature/vectors.json`, UUID, JSON, montos, cifrado, PII, backoff y contrato del sobre): `vendor/bin/phpunit`.
- **Con WordPress y WooCommerce reales** (contrato de cada evento contra `hub/resources/schemas/events`, outbox y envío, comandos, importación y conciliación). Usan una base de datos dedicada que la suite vacía:

  ```bash
  WP_CORE_DIR=/ruta/wordpress WC_PLUGIN_DIR=/ruta/wordpress/wp-content/plugins/woocommerce \
  WP_TESTS_DB_NAME=wp_igr_tests WP_TESTS_DB_USER=root WP_TESTS_DB_PASSWORD=root WP_TESTS_DB_HOST=127.0.0.1:3306 \
  vendor/bin/phpunit -c phpunit-wp.xml.dist
  ```
- **PHPCS:** `vendor/bin/phpcs` (WordPress-Extra, `phpcs.xml.dist`; los avisos se muestran y solo los errores cortan la CI). Cuidado con `phpcbf`: `CapitalPDangit` cambia el valor del contrato `'wordpress'` por `'WordPress'`; esas líneas llevan `phpcs:ignore`.
- **Plugins de formularios en la suite de WordPress:** si `contact-form-7` y `wpforms-lite` están instalados junto a WooCommerce (`WC_PLUGIN_DIR/..`), el arranque los carga y `FormsTest` hace envíos reales con cada uno; si faltan, esas pruebas se saltan (`IGR_TESTS_NO_FORMS=1` los ignora).
- **Navegador de punta a punta (Playwright):** desde `tracker/`, `IGR_E2E_WP_DIR=/ruta/wordpress IGR_E2E_WP_CLI="wp --allow-root" npm run e2e -- --trace off`. Levanta la tienda (8080) y el hub simulado (8090), conecta el sitio, prueba vistas, `_igr`, WhatsApp, banner, encuesta, `identify` y el píxel de compra, y al final lo desconecta. La tienda necesita enlaces permanentes `/%postname%/`, el connector con las dependencias de desarrollo (el hub simulado valida con `opis/json-schema`) y en `wp-config.php` `if ( getenv( 'IGR_HUB_URL' ) ) { define( 'IGR_HUB_URL', getenv( 'IGR_HUB_URL' ) ); }`: el plugin no lee variables de entorno, y así la prueba lo apunta al hub simulado (así la arma la CI).
- **Hub simulado** para probar de punta a punta sin el hub real: `MOCK_HUB_DATA=/tmp/mockhub php -S 127.0.0.1:8090 tests/fixtures/mock-hub.php`. Verifica la firma v1 o v2 como el hub (nonce v2 de un solo uso; anuncia `signature`, que se cambia con `POST /_config`, y cuenta las firmas por versión en `signature_versions`), valida cada evento contra los esquemas y expone `GET /_state` (eventos, pedidos por estado, conciliación recibida) y `POST /_reset`. **Fase 3:** sirve los endpoints públicos de §8.3 con datos de prueba (`visitor.json`, `widgets/{id}/submit`, `reco`, `watch`, `me` con `redeem` y `share`, `reviews` con `badges`, `points`, `program`, `banner`) con la misma forma que el hub real (la fija `hub/tests/Contract/PublicEndpointsContractTest.php`), y `POST /v1/s/wallet/redeem`, que crea el cupón en el sitio con `coupon.create` firmado antes de responder. `POST /_config` reemplaza claves de primer nivel (`widgets`, `reco`, `wallet_balance`, `onsite`, `consent`, `measurement`).
- **Recorrido local:** `IGR_HUB_URL=http://127.0.0.1:8090 wp igr connect IGR-XXXX-XXXX-XXXX`, `wp igr backfill --now --flush --delay=0`, `wp igr status`, `wp igr flush --all`, `wp igr disconnect --wipe`. `wp igr demo-store` (solo con `WP_ENVIRONMENT_TYPE=local`) crea la tienda de prueba.
- **Objetos JSON vacíos:** `identity`, `context`, `properties`, `order.meta`, `billing`, `shipping`… deben viajar como `{}`. Usa `Json::obj()` o `new \stdClass()` y valida siempre el JSON exacto del outbox (decodificarlo como arreglo convierte `{}` en `[]`).

## 15. Distribución y actualizaciones

- Distribución y actualizaciones por **Imagina Updater** (el sistema propio de licencias y actualizaciones remotas).
- **Mientras tanto:** cada release del hub en GitHub publica el zip del plugin (`connector/bin/build-zip.sh`):
  `imagina-growth-connector-<versión>.zip` y `imagina-growth-connector.zip` (nombre fijo, para
  `releases/latest/download/imagina-growth-connector.zip`). Se instala en WordPress con Plugins → Añadir nuevo →
  Subir plugin. El paquete del hub también lo trae en `public/downloads/imagina-growth-connector.zip`: el botón
  «Descargar plugin» de Sitios (y el paso 1 de «Conectar con un código») baja el de la misma versión del hub.
- El plugin es gratis de instalar y solo funciona con una cuenta del hub.
- Versionado semántico. El hub conoce la versión mínima compatible y avisa en el panel si un sitio está desactualizado.
- **Aviso en el admin de WordPress** (`Admin\UpdateNotice`): `/v1/s/config` trae `plugin` (`min_version`,
  `latest_version`, `download_url` = `{hub}/downloads/imagina-growth-connector.zip`) y queda guardado con el resto de la
  configuración, así que el aviso no hace ninguna llamada al hub al cargar el admin. Con una versión menor que la mínima:
  aviso de error en todo el admin para quien puede actualizar plugins. Con una más nueva: aviso informativo en el
  Escritorio, en Plugins y en la página del plugin, que se puede ocultar hasta la siguiente versión (meta del usuario
  `igr_dismissed_update`). Un enlace que no sea http(s) se cambia por el del hub conectado. `config/igr.php`
  (`plugin.latest_version`) sube junto con la cabecera del plugin: lo revisa `tests/Unit/Config/PluginReleaseTest.php`.
