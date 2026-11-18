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
- **Todas las llamadas al hub** van firmadas: `X-IGR-Site`, `X-IGR-Signature: t=<unix>,v1=<hmac_sha256(secret, t.body)>`.
- **Comandos entrantes:** igual que arriba, más una ventana de ±300 s y un **nonce** guardado 10 minutos (transient) para impedir repeticiones.
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
| `coupon.create` | cupón único (`WC_Coupon`): tipo, monto, vencimiento, `usage_limit=1`, `email_restrictions`, `individual_use`, productos o categorías, monto mínimo. Devuelve el código y el ID |
| `coupon.disable` | lo deja en borrador o vencido |
| `product.lookup` | por IDs o búsqueda: precio, precio de oferta, stock en vivo, URL, imagen y variaciones. Lo usa el agente de IA |
| `order.status` | por número de pedido + correo o teléfono que coincida: estado, envío y seguimiento si existe |
| `order.create_payment_link` | crea un pedido pendiente con los ítems indicados y devuelve `get_checkout_payment_url()` |
| `review.publish` | crea la reseña nativa (comentario `review` con `rating`, `verified=1` si aplica, la meta `igr_review_id` y las URLs de medios) |
| `review.reply` | respuesta del dueño como comentario hijo |
| `order.add_note` | nota privada (por ejemplo «Pedido recuperado por Imagina Growth») |
| `customer.set_tier` | meta `igr_tier` y rol opcional mapeado (para precios VIP con el plugin de precios por rol del cliente) |
| `catalog.resync` | fuerza la conciliación |
| `site.diagnostics` | versiones, estado del outbox y de Action Scheduler, capacidades |
| `privacy.erase_local` | borra del outbox y de las metas locales los datos de un contacto |
| `secret.rotate` | §4 |

**Carrito restaurable** (sin comando): el hub genera enlaces `https://cliente.com/?igr_cart=<payload>.<firma>`, con el payload en base64url (ítems, cantidades, cupón opcional y vencimiento) firmado con HMAC del secreto del sitio. En `template_redirect` el plugin verifica la firma y el vencimiento, vacía o fusiona el carrito (configurable), agrega los ítems, aplica el cupón y redirige al checkout. **No hace llamadas al hub** en esa petición.

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

- **Cargador** `igr-widgets.js`: el tracker lo descarga solo si `widgets.json` (desde la CDN del hub) trae widgets activos para esa página.
- **Espacios (slots)** cacheables con espacio reservado para no causar CLS: `<div class="igr-slot" data-igr-slot="reco|reviews|form|loyalty|bis" data-igr-ctx='{"productId":88}' style="min-height:…"></div>`. Se exponen como:
  - Bloques de Gutenberg: `imagina-growth/form`, `/recommendations`, `/reviews`, `/loyalty`, `/quiz`.
  - Shortcodes: `[igr_form id=""]`, `[igr_reco type="para_ti"]`, `[igr_reviews]`, `[igr_quiz id=""]`.
  - Widgets de Elementor (si está activo).
- **«Avísame cuando vuelva»:** se inserta en `woocommerce_single_product_summary` cuando el producto está agotado. No es personal, así que es cacheable.
- **Mi cuenta → «Mis recompensas»:** `add_rewrite_endpoint('recompensas')` + `woocommerce_account_menu_items`. El contenido se carga por JS con el token de usuario de §9.
- **Saldo en el checkout:** un bloque o hook muestra «Usar saldo». El clic hace una petición AJAX al plugin, que llama al hub (`POST /v1/s/wallet/redeem`, firmado, timeout de 3 s), recibe un cupón de un solo uso y lo aplica al carrito. Es la única llamada síncrona al hub, y la inicia la persona.
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
| **Estado** | conexión, espacio de trabajo, eventos de las últimas 24 h por tipo, último envío, retraso y errores |
| **Datos** | qué se envía (activar o desactivar por tipo), excluir roles (administradores por defecto), páginas excluidas, estados pagados, pasarelas offline |
| **Formularios** | detectados, mapeo de campos y prueba de envío |
| **Consentimiento** | textos, casillas del checkout y modo de banner |
| **En el sitio** | widgets activos (solo lectura, con enlace al hub) y slots disponibles |
| **Diagnóstico** | Action Scheduler, cron, outbox (pendientes y fallidos con reintento manual), conflictos detectados, exportar el informe de soporte |
| **Avanzado** | dominio de seguimiento, modo de píxel, importación histórica, desconexión |

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

- **Unitarias** (sin WordPress: firma, UUID, JSON, montos, cifrado, PII, backoff y contrato del sobre): `vendor/bin/phpunit`.
- **Con WordPress y WooCommerce reales** (contrato de cada evento contra `hub/resources/schemas/events`, outbox y envío, comandos, importación y conciliación). Usan una base de datos dedicada que la suite vacía:

  ```bash
  WP_CORE_DIR=/ruta/wordpress WC_PLUGIN_DIR=/ruta/wordpress/wp-content/plugins/woocommerce \
  WP_TESTS_DB_NAME=wp_igr_tests WP_TESTS_DB_USER=root WP_TESTS_DB_PASSWORD=root WP_TESTS_DB_HOST=127.0.0.1:3306 \
  vendor/bin/phpunit -c phpunit-wp.xml.dist
  ```
- **PHPCS:** `vendor/bin/phpcs` (WordPress-Extra, `phpcs.xml.dist`). Cuidado con `phpcbf`: `CapitalPDangit` cambia el valor del contrato `'wordpress'` por `'WordPress'`; esas líneas llevan `phpcs:ignore`.
- **Plugins de formularios en la suite de WordPress:** si `contact-form-7` y `wpforms-lite` están instalados junto a WooCommerce (`WC_PLUGIN_DIR/..`), el arranque los carga y `FormsTest` hace envíos reales con cada uno; si faltan, esas pruebas se saltan (`IGR_TESTS_NO_FORMS=1` los ignora).
- **Navegador de punta a punta (Playwright):** desde `tracker/`, `IGR_E2E_WP_DIR=/ruta/wordpress IGR_E2E_WP_CLI="wp --allow-root" npm run e2e -- --trace off`. Levanta la tienda (8080) y el hub simulado (8090), conecta el sitio, prueba vistas, `_igr`, WhatsApp, banner, encuesta, `identify` y el píxel de compra, y al final lo desconecta.
- **Hub simulado** para probar de punta a punta sin el hub real: `MOCK_HUB_DATA=/tmp/mockhub php -S 127.0.0.1:8090 tests/fixtures/mock-hub.php`. Valida cada evento contra los esquemas y expone `GET /_state` (eventos, pedidos por estado, conciliación recibida) y `POST /_reset`.
- **Recorrido local:** `IGR_HUB_URL=http://127.0.0.1:8090 wp igr connect IGR-XXXX-XXXX-XXXX`, `wp igr backfill --now --flush --delay=0`, `wp igr status`, `wp igr flush --all`, `wp igr disconnect --wipe`. `wp igr demo-store` (solo con `WP_ENVIRONMENT_TYPE=local`) crea la tienda de prueba.
- **Objetos JSON vacíos:** `identity`, `context`, `properties`, `order.meta`, `billing`, `shipping`… deben viajar como `{}`. Usa `Json::obj()` o `new \stdClass()` y valida siempre el JSON exacto del outbox (decodificarlo como arreglo convierte `{}` en `[]`).

## 15. Distribución y actualizaciones

- Distribución y actualizaciones por **Imagina Updater** (el sistema propio de licencias y actualizaciones remotas).
- El plugin es gratis de instalar y solo funciona con una cuenta del hub.
- Versionado semántico. El hub conoce la versión mínima compatible y avisa en el panel si un sitio está desactualizado.
