# tracker/ — Script de seguimiento del navegador

> Lee antes: `/docs/eventos.md` §2, §4.1 y §6, y `/connector/CLAUDE.md` §9.
> Salida de la compilación: `connector/assets/tracker.min.js` (y `igr-widgets.js` desde `tracker/widgets/`).

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
| `cta.clicked` | delegación de clics en `document` (captura) para `tel:`, `mailto:`, `wa.me`, `api.whatsapp.com`, `whatsapp://`, extensiones descargables y `[data-igr-cta]` |
| `checkout.contact_captured` | solo si `igrConfig.earlyCapture === true`; escucha los campos de correo y teléfono del checkout con *debounce* de 1,5 s y validación; el dato viaja **cifrado** al hub (clave pública del sitio, `crypto.subtle`) en un campo aparte |

## 4. Identificadores y fuentes

- `_igr_aid`: UUID v4 (`crypto.randomUUID`, con un respaldo). Si falta la cookie se lee de `localStorage`, y viceversa (se reparan entre sí). Ping al servidor cada 6 días como máximo para renovar la cookie de primera parte (ver connector §9).
- `_igr_sid`: sesión de 30 min deslizante. Hay sesión nueva si cambia el `utm_campaign` o llega un click ID nuevo.
- `_igr_src`: al entrar, se capturan UTM, click IDs (`fbclid`, `gclid`, `gbraid`, `wbraid`, `ttclid`, `msclkid`), referrer y landing. Se guarda el **primer contacto** (no se sobrescribe en 90 días) y el **último contacto no directo**.
- **Cookies de anuncios** que se leen si existen: `_fbp`, `_fbc` (si hay `fbclid` y no existe `_fbc`, se crea con el formato de Meta `fb.1.<ts>.<fbclid>`), `_ga` (client_id) y `_ttp`.
- **Parámetro `_igr`** (enlaces de mensajes): se llama `identify({token})` y **se borra de la URL** con `history.replaceState` para que nadie lo comparta por error.

## 5. Atribución de WhatsApp

- Cuando `igrConfig.waRef.enabled`, en los clics a enlaces de WhatsApp se agrega al parámetro `text` un token corto (`ref: K7Q2`, 4 a 6 caracteres sin ambigüedades). El formato es configurable: `"{text} (ref: {token})"`.
- Se envía `cta.clicked` con `wa_ref` **antes** de navegar (`sendBeacon`).
- No se modifica el enlace si el sitio lo desactivó o si el número de destino no está en la lista de números conectados (`igrConfig.waRef.numbers`).

## 6. Transporte

- Lotes en memoria y envío cada 2 s, o al llegar a 10 eventos, o en `visibilitychange: hidden` / `pagehide`.
- Primero `navigator.sendBeacon(endpoint, Blob JSON)`. Si falla, `fetch(..., {keepalive: true})`. Si eso también falla, un reintento con almacenamiento en `sessionStorage` (máximo 50 eventos).
- Endpoint: `igrConfig.endpoint` (`in.growth.imagina.cloud/v1/b` o el dominio de seguimiento propio del cliente).

## 7. Consentimiento

- Estado inicial desde `igrConfig.consent` (lo resuelve el connector a partir de la WP Consent API o el banner), actualizado por los eventos del banner (`wp_listen_for_consent_change` o las API de Complianz, CookieYes y Cookiebot) o por `igr.push(['consent', …])`.
- **Sin consentimiento de analítica en modo estricto:** no se crean cookies ni `localStorage`. Solo se envía `page.viewed` agregado sin identificadores (`anonymous_id` vacío) y sin `click_ids`.
- **Sin consentimiento de marketing:** no se leen ni se crean las cookies de anuncios, y los eventos llevan `consent.marketing = false`. El hub no envía conversiones a anuncios para esos eventos.

## 8. Widgets (`tracker/widgets/`)

- Se cargan solo si `widgets.json` trae widgets activos para la URL o el `page_type`.
- Preact o JS puro con *Shadow DOM* para aislar los estilos del tema. Fuente y colores del kit de marca.
- Se encarga de: popups, formularios, barras, ruleta, quiz, lanzador de fidelización, recomendaciones, reseñas, «avísame» y la encuesta de gracias.
- **Accesibilidad:** foco atrapado en los modales, `Esc` para cerrar, etiquetas, contraste AA y `prefers-reduced-motion`.
- **Sin CLS:** los slots ya traen su altura y los popups son overlays.

## 9. Pruebas

- Vitest para la lógica (sesiones, fuentes, normalización de clics, consentimiento).
- Playwright contra la tienda de prueba: vistas, clics a WhatsApp con token, enlace con `_igr`, consentimiento denegado o concedido, Safari (WebKit) con renovación de cookie.
- **CI:** tamaño gzip, que no haya `eval` ni `new Function`, y compatibilidad con una CSP estricta (sin inline: la configuración la inyecta el connector con `wp_add_inline_script` y admite nonce).
