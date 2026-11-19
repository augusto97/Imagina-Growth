# tracker/ — Script de seguimiento del navegador

> Lee antes: `/docs/eventos.md` §2, §4.1 y §6, y `/connector/CLAUDE.md` §9.
> Salida de la compilación: `connector/assets/tracker.min.js`, `igr-pixels.js`, `igr-banner.js`, el runtime de widgets `igr-widgets.js`, un paquete por familia `igr-w-*.js` y el «Usar saldo» del checkout `igr-wallet.js` (desde `tracker/widgets/`).

## 1. Requisitos

- TypeScript `strict`, **sin dependencias**, compilado con esbuild a un IIFE (ES2018, sin polyfills).
- **Presupuesto: 6 KB gzip** para el tracker. El CI falla si se pasa. Los widgets van en un paquete aparte que carga bajo demanda.
- Nunca bloquea el hilo principal más de 5 ms (todo va en `requestIdleCallback` cuando existe). Nunca lanza errores sin capturar (un `try/catch` global reporta los errores en una muestra del 1% al hub).

## 2. API pública

```js
window.igr = window.igr || [];
igr.push(['track', 'custom.cotizacion_vista', { servicio: 'asesoria' }]);
igr.push(['identify', { token: '...' }]);      // solo tokens firmados, nunca PII en claro
igr.push(['consent', { analytics: true, marketing: true }]);
igr.push(['page']);                            // para SPAs o carga dinámica
```

La cola funciona aunque el script todavía no haya cargado.

## 3. Captura automática

| Evento | Cómo |
|---|---|
| `page.viewed` | al cargar, con `window.igrPage` (lo da el connector) para `page_type` y el objeto |
| `page.engaged` | `IntersectionObserver` + `visibilitychange`: ≥ 30 s visible o ≥ 50% de scroll, una vez por página |
| `product.viewed` | si `igrPage.type === 'product'` |
| `category.viewed` | si `igrPage.type === 'category'` |
| `search.performed` | parámetro `s` o `query` en la URL de resultados, o el evento personalizado que emite Imagina Search |
| `cta.clicked` | delegación de clics en `document` (captura) para `tel:`, `mailto:`, `wa.me`, `api.whatsapp.com`, `whatsapp://`, extensiones descargables y `[data-igr-cta]`. **Fase 5:** en el botón de reservas (`a[data-igr-cta="booking"]`, `[igr_booking]`) se agregan al enlace `aid` y el último contacto (`utm_*` y click IDs) antes de navegar, porque la página de reservas vive en el hub y sin eso la cita perdería el anuncio que la trajo; sin consentimiento de analítica el enlace queda igual |
| `checkout.contact_captured` | solo si `igrConfig.earlyCapture === true`; escucha los campos de correo y teléfono del checkout con *debounce* de 1,5 s y validación; el dato viaja **cifrado** al hub (clave pública del sitio, `crypto.subtle`) en un campo aparte |

## 4. Identificadores y fuentes

- `_igr_aid`: UUID v4 (`crypto.randomUUID`, con un respaldo). Si falta la cookie se lee de `localStorage`, y viceversa (se reparan entre sí). Ping al servidor cada 6 días como máximo para renovar la cookie de primera parte (ver connector §9).
- `_igr_sid`: sesión de 30 min deslizante. Hay sesión nueva si cambia el `utm_campaign` o llega un click ID nuevo.
- `_igr_src`: al entrar, se capturan UTM, click IDs (`fbclid`, `gclid`, `gbraid`, `wbraid`, `ttclid`, `msclkid`), referrer y landing. Se guarda el **primer contacto** (no se sobrescribe en 90 días) y el **último contacto no directo**.
- **Cookies de anuncios** que se leen si existen: `_fbp`, `_fbc` (si hay `fbclid` y no existe `_fbc`, se crea con el formato de Meta `fb.1.<ts>.<fbclid>`), `_ga` (client_id) y `_ttp`.
- **Parámetro `_igr`** (enlaces de mensajes): se llama `identify({token})` y **se borra de la URL** con `history.replaceState` para que nadie lo comparta por error.
- **Parámetro `?ref=`** (referidos y afiliados, v4): si cumple `^[A-Za-z0-9_-]{3,32}$`, el toque de esa visita lleva `referral_code` (en `_igr_src`, primer y último contacto, con consentimiento) y los eventos de esa página llevan `context.referral_code`, también sin consentimiento de analítica porque es el código del referidor y no identifica al visitante. **No se quita de la URL:** no es un dato personal, está hecho para compartirse y otros plugins de afiliados también leen `ref`. El plugin lo pasa al pedido en `last_touch`.
- **Versión del contrato:** los eventos del navegador salen con `schema_version: 4`.

## 5. Atribución de WhatsApp

- Cuando `igrConfig.waRef.enabled`, en los clics a enlaces de WhatsApp se agrega al parámetro `text` un token corto (`ref: K7Q2`, 4 a 6 caracteres sin ambigüedades). El formato es configurable: `"{text} (ref: {token})"`.
- Se envía `cta.clicked` con `wa_ref` **antes** de navegar (`sendBeacon`).
- No se modifica el enlace si el sitio lo desactivó o si el número de destino no está en la lista de números conectados (`igrConfig.waRef.numbers`).
- El **widget de WhatsApp** del hub (`igr-w-wa.js`) usa el mismo formato y el mismo `cta.clicked` con `wa_ref`, aunque su número no esté en la lista local: lo decide `igrConfig.waRef.widgets` (el interruptor de atribución del comerciante). Va en Shadow DOM, así que la delegación de clics del tracker no lo cuenta dos veces.

## 6. Transporte

- Lotes en memoria y envío cada 2 s, o al llegar a 10 eventos, o en `visibilitychange: hidden` / `pagehide`.
- Primero `navigator.sendBeacon(endpoint, Blob JSON)`. Si falla, `fetch(..., {keepalive: true})`. Si eso también falla, un reintento con almacenamiento en `sessionStorage` (máximo 50 eventos).
- Endpoint: `igrConfig.endpoint` (`growth.imagina.cloud/v1/b`, o el host de `IGR_INGEST_URL`, o el dominio de seguimiento propio del cliente).

## 7. Consentimiento

- Estado inicial desde `igrConfig.consent` (lo resuelve el connector a partir de la WP Consent API o el banner), actualizado por los eventos del banner (`wp_listen_for_consent_change` o las API de Complianz, CookieYes y Cookiebot) o por `igr.push(['consent', …])`.
- **Sin consentimiento de analítica en modo estricto:** no se crean cookies ni `localStorage`. Solo se envía `page.viewed` agregado sin identificadores (`anonymous_id` vacío) y sin `click_ids`.
- **Sin consentimiento de marketing:** no se leen ni se crean las cookies de anuncios, y los eventos llevan `consent.marketing = false`. El hub no envía conversiones a anuncios para esos eventos.

## 8. Widgets (`tracker/widgets/`, api-connector.md §7 y §8.3)

- **Carga en tres niveles**, para que la tienda no pague por lo que no usa:
  1. El tracker pide `widgets.json?page_type=&path=` (caché de CDN, igual para todos) en toda página que **no sea el checkout**.
  2. Si trae widgets o la página tiene un `[data-igr-slot]`, carga `igr-widgets.js` (el runtime, ≤ 5 KB gzip).
  3. El runtime descarga el paquete del tipo solo cuando un widget aplica o hay un slot de ese tipo: `igr-w-capture.js` (popup, formulario incrustado, barra, teaser, dos pasos, bienvenida de referido), `igr-w-wheel.js` (ruleta), `igr-w-quiz.js` (quiz), `igr-w-wa.js` (botón de WhatsApp), `igr-w-commerce.js` (recomendaciones, «avísame», puntos y banner), `igr-w-reviews.js` (reseñas), `igr-w-loyalty.js` (Mis recompensas), `igr-w-survey.js` (encuesta de gracias) e `igr-w-quote.js` (cotizador, Fase 5, ≤ 4 KB). Cada paquete pesa ≤ 6 KB gzip y tiene su límite en `scripts/check.mjs`. Se registran en `window.__igrW.r` y reciben del runtime un contexto común (URL del hub, `anonymous_id`, token, eventos, frecuencia).
- **Reglas** (`widgets/lib/targeting.ts`, sin DOM para probarlas solas): tipo de página, rutas con `*` (incluir y excluir), dispositivo (`mobile` < 768 px, `tablet` táctil hasta 1024, `desktop`), UTM de la URL o del último contacto, `referral` (y `referral_welcome` siempre) con un `?ref=` o un código guardado, `cart_value_min` con la cookie `_igr_ct` del plugin y frecuencia. `visitor.json` se pide una sola vez y solo si algún widget usa país, segmentos, `hide_for_subscribers`, `new_visitors` o es `referral_welcome`; sin datos, las reglas de país, segmento y visitante recurrente no se cumplen.
- **Variante determinista:** FNV-1a de `anonymous_id + widget_id` contra los pesos. Sin consentimiento (no hay `anonymous_id`) se elige al azar y se recuerda en la sesión.
- **Disparadores:** inmediato, tiempo, scroll (%), intención de salida (escritorio: el puntero sale por arriba después de 3 s; celular: subir rápido después de haber bajado media pantalla) y clic. Cualquier elemento con `data-igr-open="wg_…"` abre ese widget. Solo un modal a la vez; barras, teaser y WhatsApp conviven.
- **Frecuencia** (`_igr_wf`): quien envió no lo vuelve a ver; `max_views` en total; `cooldown_days` desde la última vista; sin regla, un cierre lo oculta un día. Con consentimiento de analítica va en `localStorage`; sin él, solo en `sessionStorage` (memoria de la sesión, sin identificadores): recordar que la persona cerró un popup es lo que ella pidió.
- **Eventos:** `widget.shown` (al pintar), `widget.submitted` (envío, clic en la barra con enlace o en WhatsApp) y `widget.closed` (cierre sin enviar), con `widget_id`, `variant` y `kind`.
- **Formularios:** cada campo con su `<label>`, `aria-invalid` y el error enlazado con `aria-describedby`; casillas de consentimiento **sin marcar** con el texto y la versión del hub; el envío va a `widgets/{id}/submit` con `anonymous_id` (solo con consentimiento), `variant`, `page_url`, `step`, `fields`, `consents`, `answers` y `referral_code`. Los errores `422` van a su campo; `409` y `429` muestran el mensaje. El mensaje final es el `message` del hub (ya trae el cupón y, con doble opt-in, el aviso de confirmar el correo); sin él, `content.success` con `{{codigo}}` resuelto. El cupón va con «Copiar», su vencimiento (fecha de la tienda) y, si llega, el regalo del amigo (`referral_coupon`). `content.steps` permite varios pasos, cada uno con sus campos y casillas, y se envía con su `step`; un paso con `no` muestra ese botón para saltárselo y ver el resultado de los anteriores. `two_step` es correo y después WhatsApp, sin pregunta previa. `content.footer` va en letra pequeña debajo del formulario.
- **Ruleta:** el premio lo decide el hub; la ruleta solo gira hasta esa casilla (sin animación con `prefers-reduced-motion`). **Quiz:** cada opción lleva a `next` (o a la siguiente pregunta; `null` termina); las respuestas viajan con el formulario final o solas, y se muestran los productos que responde el hub.
- **Slots:** `reco` (`/reco`, con `aid` solo para los tipos personales), `reviews` (lista con resumen, carrusel, galería con visor accesible e insignias de una página en una sola petición a `/reviews/badges`), `bis` (un campo para correo o WhatsApp y, si `widgets.json` trae `consents`, la casilla del canal que se escribió, sin marcar), `points`, `banner` (arriba es flotante y se puede cerrar) y `loyalty` (`/me` con `igrUser.token`: puntos, nivel con barra de progreso, saldo, canje con cupón y enlace para compartir por WhatsApp). `quote` (Fase 5: `/quotes/{id}`, preguntas, contacto y casillas sin marcar si el precio se da a cambio del contacto, `POST …/estimate` con UTM de la URL y el rango que calcula el hub; errores junto a cada campo). Si un slot no tiene qué mostrar se recoge.
- **Aislamiento y accesibilidad:** Shadow DOM abierto en un host `<igr-w>` (o el slot) con `:host{all:initial}` y la fuente del tema; foco atrapado en los modales, `Esc` para cerrar, el foco vuelve a donde estaba, botón «Cerrar», contraste AA, `prefers-reduced-motion`, imágenes con `loading="lazy"`, y los enlaces que vienen del hub solo si son http(s). Números, montos y fechas en español (el idioma de la página si es español; si no, `es-CO`).
- **Sin CLS:** los slots traen su altura y los popups, barras, teaser, banner superior y WhatsApp son flotantes. **Nunca se muestra un widget en el checkout:** ni se pide `widgets.json` ni corre el runtime.
- **«Usar saldo»** (`widgets/wallet.ts` → `igr-wallet.js`): lo encola el plugin solo en el checkout con sesión; ver `connector/CLAUDE.md` §10.

## 9. Pruebas

- Vitest para la lógica (sesiones, fuentes, normalización de clics, consentimiento).
- Vitest de los widgets: reglas, variantes y frecuencia (`targeting.test.ts`), runtime (`runtime.test.ts`), formularios accesibles, ruleta, quiz, WhatsApp y encuesta (`widgets.test.ts`), comercio (`commerce.test.ts`), reseñas, Mis recompensas y saldo (`reviews-loyalty.test.ts`), todo contra un hub simulado con `fetch`.
- Playwright contra la tienda de prueba: vistas, clics a WhatsApp con token, enlace con `_igr`, consentimiento denegado o concedido, Safari (WebKit) con renovación de cookie. **Fase 3** (`e2e/phase3.spec.ts`): popup con casillas y cupón (y sin CLS), quiz incrustado, recomendaciones, reseñas, puntos y «avísame» en la ficha, Mis recompensas con el token, saldo en el checkout de bloques (el hub simulado crea el cupón con `coupon.create`), aterrizaje con `?ref=` y bienvenida de referido, botón de WhatsApp y nada en el checkout. Al terminar la tienda queda desconectada y sin los datos de la prueba.
- Comandos: `npm run check` (tipos, Vitest, compilación y tamaño) y `npm run e2e -- --trace off` con `IGR_E2E_WP_DIR` (WordPress con WooCommerce y el connector) e `IGR_E2E_WP_CLI` (cómo correr WP-CLI); ver `connector/CLAUDE.md` §14.1.
- **CI:** tamaño gzip, que no haya `eval` ni `new Function`, y compatibilidad con una CSP estricta (sin inline: la configuración la inyecta el connector con `wp_add_inline_script` y admite nonce).
