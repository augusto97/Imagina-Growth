# Guías paso a paso de las conexiones (lote 0.3.0)

Quien configura la plataforma ya no tiene que salir del panel para saber qué hacer en la consola de cada proveedor. Cada
sección de **Plataforma → Ajustes** que pide credenciales de un tercero trae una **«Guía paso a paso»**, y cada tarjeta
de **Configuración → Conexiones** trae **«Antes de conectar»** para el cliente final. La guía completa para crear las apps
de Meta, Google y TikTok sigue en [`guia-apps-oauth.md`](guia-apps-oauth.md): las guías del panel la resumen en pasos y
ponen a mano lo que hay que copiar.

## 1. Plataforma → Ajustes

Secciones con guía (las que tienen un secreto que no genera la plataforma; una prueba del hub lo exige):
**Correo de la plataforma, Amazon SES, Meta, Google, TikTok, Inteligencia artificial y Monitoreo**. Registro y seguridad
(el token de `/health`) y Avisos en el celular (las llaves VAPID) no la necesitan: la plataforma genera esos valores.

Cada guía muestra, en un panel arriba de los campos:

- **Para qué sirve** la sección y **cuánto toma** (sin contar las revisiones del proveedor).
- **Antes de empezar:** lo que hay que tener a mano (portafolio de Meta, dominio verificado, páginas legales, cuenta de AWS…).
- **Los pasos numerados**, en lenguaje simple, con los botones a la consola exacta (se abren en otra pestaña) y los
  **valores para copiar ya calculados por el hub**, cada uno con «Copiar» (las listas con «Copiar todo», una por línea).
- **El avance:** un paso queda «Listo» cuando los campos que llena están guardados (y su señal propia, si la tiene:
  Meta con al menos un número o cuenta conectados, SES con «Preparar ahora» en «Listo», la IA encendida con clave).
  Los pasos que solo se hacen en la consola del proveedor no tienen estado. La guía se abre sola mientras la sección no
  está lista y empieza plegada cuando ya funciona.
- **Si algo falla:** lo que se ve y cómo se arregla (por ejemplo, «wrong version number» → el cifrado no va con el puerto).
- **Cómo saber que funcionó:** la prueba que ya existe en el panel (enviar un correo de prueba, «Preparar ahora», la
  tarjeta de Conexiones que deja de decir «No disponible», una pregunta en «Pregúntale a tus datos»).

**Valores que calcula el hub** (`App\Domain\Platform\Settings\SetupGuideValues`, en `GET /api/platform/settings` →
`sections[].info.guide`; nunca un secreto):

| Sección | Valores |
|---|---|
| Meta | URL del sitio, dominio, URI de redireccionamiento (`ConnectionProvider::Meta->callbackUrl()`), URL del webhook, campos del webhook de WhatsApp, del objeto Page (Messenger y `leadgen`) y del objeto Instagram, y los permisos de las dos configuraciones de Login for Business |
| Google | URL del sitio, URI de redireccionamiento, dominio autorizado (el dominio principal del panel: Google no acepta subdominios ahí), los alcances que pide el hub (`igr-connections.google.scopes`) y, si existe, la URI del cliente anterior de la agenda |
| TikTok | «Advertiser redirect URL» |
| Amazon SES | URL del webhook de SNS, configuration set, la **política de IAM** del usuario y la **declaración de la política del tema de SNS**, con la región guardada y la cuenta sacada del ARN del tema (`policies_complete = false` y `<región>`, `<cuenta>` mientras falten) |

Los campos de los webhooks de Messenger e Instagram de la guía son las mismas constantes que muestra el canal
(`SetupGuideValues::META_PAGE_FIELDS` sin `leadgen` y `META_INSTAGRAM_FIELDS` en `ChannelAccountPresenter`).

## 2. Configuración → Conexiones

Cada tarjeta (tu web, WhatsApp, Messenger, Instagram, correo, SMS, Meta, Google, TikTok y Google Calendar) tiene arriba
de su hoja un bloque plegado **«Antes de conectar»**: lo que la persona necesita tener listo, el paso a paso corto, lo
que conviene saber (WhatsApp no oficial) y **lo que suele fallar** con su arreglo: no ser administrador del Business
Manager o de la página, la cuenta publicitaria sin pago, el número todavía en la app normal de WhatsApp, el PIN de dos
pasos, la cuenta de Instagram que no es profesional o no permite el acceso a los mensajes, el dominio que no se verifica,
la cuenta de Google Ads bajo un administrador (MCC), los términos de Customer Match, etc.

## 3. Dónde vive

- **Hub:** `app/Domain/Platform/Settings/SetupGuideValues.php` (y `PlatformSettingsPresenter`, que lo agrega a `info.guide`).
  Pruebas: `tests/Feature/Platform/PlatformSettingsGuideTest.php`.
- **SPA:** `resources/js/features/guides/` — `model.ts` (las guías, los enlaces, el avance y los valores, lógica pura),
  `SettingsGuide.tsx` (Ajustes, enganchado en `SettingsSectionCard`), `ConnectionGuide.tsx` (Conexiones, enganchado en
  `ConnectionsPage`), `GuideParts.tsx` y `guides.test.tsx`. Textos en `locales/es-CO/guides.json`
  (`guides.platform.<sección>`, `guides.connections.<tarjeta>`, `guides.links`, `guides.values`); es-MX y es-VE no lo
  sobrescriben.
- **Agregar una guía:** la sección en `GUIDE_SECTIONS` y `PLATFORM_GUIDES` (pasos con `links`, `values`, `fields` y
  `check`), sus textos en `guides.json` y, si necesita valores calculados, su caso en `SetupGuideValues::for()`. La prueba
  del hub falla si una sección con un secreto nuevo no tiene guía.

## 4. Revisado y por verificar

Revisado el 26 de noviembre de 2026 contra la documentación oficial (además de lo de `guia-apps-oauth.md`, revisado el
20 de noviembre):

- **Emailit:** `smtp.emailit.com`, 587 STARTTLS, 465 TLS implícito, 2525 o 2587 STARTTLS; usuario `emailit`, contraseña
  una clave de API que empieza por `secret_` ([docs](https://emailit.com/docs/guides/sending-using-smtp/)).
- **Brevo:** `smtp-relay.brevo.com`, 587; usuario = el «Login» de la página SMTP (o una dirección `…@smtp-brevo.com`),
  contraseña = una clave SMTP, no de API ([docs](https://developers.brevo.com/docs/smtp-integration),
  [535](https://help.brevo.com/hc/en-us/articles/115000188150)).
- **Gmail / Workspace:** `smtp.gmail.com`, 587 STARTTLS o 465 SSL, contraseña de aplicación con la verificación en dos
  pasos; en Workspace puede estar bloqueada por el administrador.
- **Amazon SES:** identidades con Easy DKIM, sandbox (solo destinatarios verificados y pocos correos al día), acceso a
  producción por región ([docs](https://docs.aws.amazon.com/ses/latest/dg/request-production-access.html)).
- **Claude (Anthropic):** la consola es `platform.claude.com`; las claves en Configuración → Claves de API (por espacio
  de trabajo) y los límites de gasto por espacio de trabajo
  ([ayuda](https://support.claude.com/en/articles/9796807-creating-and-managing-workspaces-in-the-claude-console)).
- **Google Ads API:** el developer token se retiró el 9 de septiembre de 2026 y el nivel de acceso es del proyecto de
  Cloud ([niveles](https://developers.google.com/google-ads/api/docs/access-levels)); hay reportes de
  `AUTHORIZATION_ERROR` en proyectos recién subidos de nivel.
- **Google Auth Platform:** en «Dominios autorizados» solo el dominio principal, verificado en Search Console.
- **Meta:** para el borrado de datos basta una página con instrucciones o una devolución de llamada
  ([docs](https://developers.facebook.com/docs/development/create-an-app/app-dashboard/data-deletion-callback)).
- **Better Stack:** heartbeats con frecuencia y margen; quedan «Pending» hasta el primer aviso.

**[verificar]** (no se pudo confirmar con una fuente oficial que cargara):

- Las rutas exactas de Google Auth Platform que usan los botones: `console.cloud.google.com/auth/branding`,
  `/auth/audience`, `/auth/scopes` (Acceso a los datos) y `/auth/clients`.
- `app.brevo.com/settings/keys/smtp` (página de claves SMTP de Brevo) y `platform.claude.com/settings/workspaces` y
  `/settings/billing` en la consola de Claude.
- El texto exacto del menú de Instagram para permitir el acceso a los mensajes desde herramientas conectadas.
- Los tiempos de revisión de TikTok (2 a 7 días hábiles) y los nombres de sus permisos (ver `guia-apps-oauth.md` §3).
- Lo que ya estaba marcado en `guia-apps-oauth.md` (vencimiento del token de la configuración de WhatsApp, permisos de
  Lead Ads en la configuración, verificación de acceso de Meta).

## 5. Páginas legales que faltan

Meta y Google piden **política de privacidad**, **términos del servicio** y (Meta) **cómo pedir el borrado de los
datos**, en un dominio verificado. **La plataforma no las trae** (no hay rutas públicas para eso en el hub): la guía de
Meta lo dice en «Antes de empezar» y en su primer paso, y la de Google en «Antes de empezar». Hay que publicarlas en el
sitio de la empresa (o agregarlas al hub en un lote aparte, con un ajuste para su URL).
