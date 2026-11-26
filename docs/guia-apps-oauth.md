# Guía para crear las apps de Meta, Google y TikTok

Esta guía es para quien administra la plataforma (el dueño de Imagina Growth). Con estas tres apps, los clientes
conectan WhatsApp, sus anuncios, Messenger, Instagram, Google Ads, Analytics, la agenda de Google y TikTok **con un
clic**, sin copiar ni pegar claves. Sin ellas, cada tarjeta de **Conexiones** dice qué falta y los campos manuales
siguen funcionando.

**Todo se pega en el panel:** **Plataforma → Ajustes**, secciones **Meta**, **Google** y **TikTok** (solo quien
administra la plataforma, con la verificación en dos pasos). Lo que guardes se usa enseguida, sin tocar el servidor
ni reiniciar nada. Cada sección muestra también las direcciones que hay que registrar en la app, listas para copiar.

Revisado contra la documentación oficial el 20 de noviembre de 2026 (enlaces al final de cada sección). Las
pantallas de Meta, Google y TikTok cambian seguido: si un nombre no coincide, busca la opción más parecida.

---

## 0. Antes de empezar

Ten a mano:

1. El dominio del panel con HTTPS: **`https://growth.imagina.cloud`**.
2. Páginas públicas en ese dominio o en el sitio de la empresa: **política de privacidad**, **términos del
   servicio** y **cómo pedir que se borren los datos** (Meta lo pide). Deben explicar qué datos de Meta, Google y
   TikTok usa la plataforma y para qué.
3. Un logo cuadrado (1024 × 1024 px), un correo de soporte y los datos legales de la empresa (nombre, dirección,
   teléfono y NIT o equivalente) para las verificaciones.
4. Tu usuario de administrador de la plataforma, con la verificación en dos pasos activa, para entrar a
   **Plataforma → Ajustes** (§5). No hace falta entrar al servidor.

**URL exactas que vas a registrar** (cópialas tal cual, sin barra al final):

| Dónde | Valor |
|---|---|
| Meta: «URI de redireccionamiento de OAuth válidos» | `https://growth.imagina.cloud/oauth/meta/callback` |
| Meta: «Dominios permitidos» del SDK de JavaScript | `growth.imagina.cloud` |
| Meta: URL de devolución de llamada del webhook | `https://growth.imagina.cloud/webhooks/meta` |
| Google: «URI de redireccionamiento autorizados» | `https://growth.imagina.cloud/oauth/google/callback` |
| TikTok: «Advertiser redirect URL» | `https://growth.imagina.cloud/oauth/tiktok/callback` |

> Si ya tenías Google Calendar conectado con `IGR_GOOGLE_CALENDAR_CLIENT_ID`, deja también
> `https://growth.imagina.cloud/p/_google/calendar/callback` en ese cliente: las personas que ya lo conectaron
> siguen funcionando. Las conexiones nuevas usan la app de Google de esta guía.

---

## 1. Meta (WhatsApp, anuncios, Messenger e Instagram)

Una sola app de Meta sirve para todo. Imagina Growth se registra como **proveedor de tecnología** (Tech Provider):
cada cliente conecta **su propio** número y paga sus mensajes de WhatsApp a Meta desde su cuenta
(`docs/cumplimiento.md` §5).

### 1.1 Portafolio comercial y verificación del negocio

1. Entra a [business.facebook.com](https://business.facebook.com) y crea (o usa) el portafolio comercial de
   Imagina La Web.
2. En **Configuración del negocio → Centro de seguridad**, inicia la **verificación del negocio** con el nombre
   legal, la dirección, el teléfono, el sitio web y, si Meta no encuentra la empresa, los documentos que pida.
   Es obligatoria antes de la revisión de la app. Meta no publica un tiempo fijo; hay reportes de días a semanas.

### 1.2 Crear la app

1. En [developers.facebook.com](https://developers.facebook.com) → **Mis apps → Crear app**.
2. Conéctala al portafolio de 1.1.
3. Elige los casos de uso: **WhatsApp** (conectar con clientes por WhatsApp), **Marketing API / anuncios**
   (conversiones y audiencias), **Messenger** e **Instagram** (mensajes). La app debe ser de tipo **Business**
   (o creada con un caso de uso que permita *Facebook Login for Business*).
4. En **Configuración de la app → Básica**: icono, categoría, correo de contacto, URL de la política de
   privacidad, de los términos y de la eliminación de datos, y el dominio `growth.imagina.cloud`.
5. Copia el **identificador de la app** y la **clave secreta de la app** a Plataforma → Ajustes → Meta
   («Identificador de la app» y «Clave secreta de la app»). La clave secreta se guarda cifrada y el panel nunca la
   vuelve a mostrar; nunca va al repositorio ni a un correo.

### 1.3 Proveedor de tecnología y revisión de los permisos de WhatsApp

1. En el panel de la app: **Casos de uso → Personalizar** (WhatsApp) → **Tech Provider onboarding**.
2. Paso 1: verificación del negocio (1.1).
3. Paso 2: **revisión de la app**. Pide **acceso avanzado** a:
   - `whatsapp_business_management` (leer y administrar las cuentas de WhatsApp de los clientes, sus números y
     plantillas);
   - `whatsapp_business_messaging` (enviar mensajes y registrar números).
   Para cada permiso: una explicación escrita y **un video por permiso** (no capturas). Para el de mensajes, el
   video muestra un mensaje enviado desde Imagina Growth que llega al WhatsApp de un celular; para el de
   administración, cómo se crea una plantilla. Meta indica que la revisión de estos permisos tarda **en promedio
   unas 24 horas**.
4. Mientras tanto, la app ya puede probarse con números de tu propio negocio: las apps de tipo Business reciben
   acceso estándar a todos los permisos para quien tiene un rol en la app.

Fuentes: [Become a Tech Provider](https://developers.facebook.com/docs/whatsapp/solution-providers/get-started-for-tech-providers),
[App Review de WhatsApp](https://developers.facebook.com/documentation/business-messaging/whatsapp/solution-providers/app-review.md).

### 1.4 Facebook Login for Business: dominio y retorno

En **Facebook Login for Business → Configuración → Configuración de OAuth del cliente**:

1. Activa (**Sí**): *Inicio de sesión de OAuth del cliente*, *Inicio de sesión de OAuth web*, *Forzar HTTPS*,
   *Inicio de sesión de OAuth en el navegador integrado*, *Usar modo estricto para los URI de redireccionamiento*
   e *Iniciar sesión con el SDK de JavaScript*.
2. **Dominios permitidos para el SDK de JavaScript:** `growth.imagina.cloud`.
3. **URI de redireccionamiento de OAuth válidos:** `https://growth.imagina.cloud/oauth/meta/callback`.

La ventana de WhatsApp solo devuelve los datos si el dominio del panel está en **los dos** campos.

Fuente: [Embedded Signup — Implementation](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/implementation.md).

### 1.5 Las dos configuraciones (`config_id`)

En **Facebook Login for Business → Configuraciones** crea dos:

**a) WhatsApp con un clic (Embedded Signup)** → «Configuración de WhatsApp con un clic» en Ajustes → Meta

1. **Crear configuración** → nombre «Imagina Growth — WhatsApp» → variación **WhatsApp Embedded Signup**.
2. Tipo de token: **token de acceso de usuario del sistema**. Si el panel deja elegir el vencimiento, elige
   **Nunca**: la plantilla «WhatsApp Embedded Signup Configuration With 60 Expiration Token» entrega tokens de 60
   días y el cliente tendría que volver a conectar cada dos meses (la plataforma avisa por correo a dueños y
   administradores 7 días y 1 día antes, y en el Inicio con «Reconectar»). **[verificar en el panel]**
3. Productos: **WhatsApp Cloud API** (y, si se va a usar, Marketing Messages). Elige solo lo necesario: pedir
   activos que no se usan (por ejemplo catálogos) hace que la gente abandone.
4. Permisos: `whatsapp_business_management` y `whatsapp_business_messaging`.
5. Guarda y copia el **ID de la configuración**.

La plataforma usa **Embedded Signup v5** (la recomendada desde el 20 de octubre de 2026; v2 y v3 se retiraron el
15 de octubre de 2026). En la v5 la **coexistencia** viene encendida: el cliente puede conectar un número que ya
usa en la app de WhatsApp Business y lo sigue usando ahí (queda con 20 mensajes por segundo y sin grupos ni
listas de difusión). Fuentes: [versiones](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/versions/),
[coexistencia](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users).

**b) Anuncios, píxel, Messenger e Instagram** → «Configuración de anuncios y mensajes» en Ajustes → Meta

1. **Crear configuración** → nombre «Imagina Growth — Anuncios y mensajes» → variación **General**.
2. Tipo de token: **token de acceso de usuario del sistema**, vencimiento **Nunca** (para trabajar desde el
   servidor sin que el cliente tenga que volver).
3. Activos: **cuentas publicitarias**, **píxeles / conjuntos de datos**, **páginas** y **cuentas de Instagram**.
4. Permisos: `ads_management`, `ads_read`, `business_management`, `pages_show_list`, `pages_manage_metadata`,
   `pages_messaging`, `instagram_basic` e `instagram_manage_messages`, y para los clientes potenciales de los anuncios
   (Meta Lead Ads) `leads_retrieval`, `pages_read_engagement` y `pages_manage_ads`. Sin estos tres, el panel del cliente
   dice qué le falta a su conexión y le pide volver a conectar; si la configuración no los pide, volver
   a conectar no basta: agrégalos aquí. **[verificar en el panel]**
5. Guarda y copia el **ID de la configuración**.

Fuente: [Facebook Login for Business](https://developers.facebook.com/docs/facebook-login/facebook-login-for-business).

### 1.6 Revisión de los permisos de anuncios y mensajes

Pide **acceso avanzado** a los permisos de 1.5 b) y a `public_profile` (Meta lo exige antes de publicar una app
con Login for Business). Cada permiso necesita su explicación, su video y **al menos una llamada exitosa** a la
API con ese permiso en los 30 días anteriores (conecta tu propio negocio desde **Conexiones** antes de pedirlo).
Meta no publica un tiempo fijo para estos permisos; los reportes de 2026 van de unos días a casi tres semanas.
Para `ads_management` y `business_management` Meta puede pedir además la **verificación de acceso** de proveedor
de tecnología; hay reportes de octubre de 2026 de que dejó de pedirse **[verificar en el panel]**.
Para `leads_retrieval`, `pages_read_engagement` y `pages_manage_ads` (Meta Lead Ads) el video muestra elegir la
página en el panel y un lead de prueba de la [herramienta de prueba de anuncios para clientes potenciales](https://developers.facebook.com/tools/lead-ads-testing)
llegando como lead con su campaña; la llamada exitosa es leer ese lead.

### 1.7 Webhooks

1. En **WhatsApp → Configuración → Webhook**: URL `https://growth.imagina.cloud/webhooks/meta` y como token de
   verificación el de Plataforma → Ajustes → Meta → «Token de verificación del webhook»: usa **Generar**, **Copiar**,
   pégalo en Meta y **Guardar** en el panel (después de guardar no se vuelve a mostrar; si lo pierdes, genera otro y
   cámbialo también en Meta).
2. Suscribe los campos: `messages`, `message_template_status_update`, `phone_number_quality_update`,
   `account_update`, `history`, `smb_app_state_sync` y `smb_message_echoes` (los tres últimos son de la
   coexistencia). **Este paso es de la app y se hace una sola vez en el panel de Meta** (App Dashboard → WhatsApp →
   Configuración): Meta no deja suscribir esos campos al conectar cada cliente; al conectar, la plataforma solo
   suscribe la app a la cuenta de WhatsApp del cliente (`POST /{waba}/subscribed_apps`) y pide la copia de contactos
   e historial (`POST /{número}/smb_app_data`, una vez y dentro de 24 h). Sin estos campos, lo que el negocio escribe
   desde el celular no aparece en la bandeja, no se copia el historial ni la libreta, y un número que Meta desconecta
   no se marca ni se avisa. Qué hace la plataforma con cada campo: `hub/CLAUDE.md` §8 («Coexistencia de WhatsApp»).
   Fuentes: [coexistencia](https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users),
   [`smb_message_echoes`](https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/reference/smb_message_echoes),
   [`account_update`](https://developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/reference/account_update).
3. En **Messenger** (objeto `page`) e **Instagram** (objeto `instagram`), la misma URL y el mismo token, con los
   campos `messages`, `messaging_postbacks`, `message_deliveries`, `message_reads` y `messaging_referrals`. En el objeto
   `page` suscribe además el campo **`leadgen`** (Meta Lead Ads): al elegir la página en el panel, la plataforma la
   suscribe a ese campo (`POST /{página}/subscribed_apps`, conservando los de Messenger), pero los avisos solo llegan si
   la app también lo tiene suscrito aquí.
4. La firma de cada aviso se comprueba con la clave secreta de la app (Ajustes → Meta): sin ella el hub responde 403.

### 1.8 Publicar

Pasa la app a modo **Activo / En vivo**. Mientras esté en desarrollo, solo quienes tengan un rol en la app pueden
conectarse.

---

## 2. Google (Google Ads, Data Manager, Analytics y Calendar)

Un solo cliente OAuth de la plataforma. El cliente autoriza una vez y elige después su cuenta de Google Ads, sus
acciones de conversión y su propiedad de Analytics.

### 2.1 Proyecto y APIs

1. En [console.cloud.google.com](https://console.cloud.google.com) crea el proyecto «Imagina Growth».
2. **APIs y servicios → Biblioteca**, habilita: **Google Ads API**, **Data Manager API**, **Google Analytics
   Admin API** y **Google Calendar API**.

### 2.2 Pantalla de consentimiento (Google Auth Platform)

1. **Marca (Branding):** nombre «Imagina Growth», correo de soporte, logo, página principal, política de
   privacidad y términos, y el **dominio autorizado** `imagina.cloud`. Verifica el dominio en
   [Search Console](https://search.google.com/search-console) con una cuenta que sea propietaria o editora del proyecto.
2. **Público (Audience):** tipo **Externo**. Pásalo a **En producción**: mientras esté en «Prueba», solo entran
   los usuarios de prueba y **Google entrega refresh tokens que vencen a los 7 días** (las conexiones se caerían
   cada semana).
3. **Acceso a los datos (Data access):** agrega los alcances:

| Alcance | Para qué |
|---|---|
| `openid`, `email` | mostrar con qué cuenta se conectó |
| `https://www.googleapis.com/auth/adwords` | listar cuentas de Google Ads y acciones de conversión, gasto y listas de Customer Match |
| `https://www.googleapis.com/auth/datamanager` | enviar ventas, leads ganados y audiencias (Data Manager API) |
| `https://www.googleapis.com/auth/analytics.edit` | listar propiedades de Analytics y crear el secreto del Measurement Protocol |
| `https://www.googleapis.com/auth/calendar.events` y `.../calendar.freebusy` | la agenda de citas |

### 2.3 Cliente OAuth

**Clientes → Crear cliente → Aplicación web**, con el URI de redireccionamiento autorizado
`https://growth.imagina.cloud/oauth/google/callback`. Copia el **ID de cliente** y el **secreto** a Plataforma →
Ajustes → Google.

### 2.4 Verificación de Google

Los alcances de Ads, Analytics y Calendar son **sensibles** (el panel de *Data access* los marca): hace falta la
**verificación de la marca** y la **de alcances sensibles** antes de abrir la app a todos (sin verificar, la
pantalla muestra una advertencia y hay un tope de usuarios). Necesitas:

- página principal y política de privacidad en el dominio verificado, con el mismo enlace en la pantalla de
  consentimiento;
- un **video** (en inglés) del flujo completo: el botón «Conectar con un clic», la pantalla de consentimiento con
  los alcances exactos y lo que hace la plataforma con cada uno;
- la justificación de cada alcance (por qué no sirve uno más limitado).

Tiempos que publica Google: la verificación de marca, **2 a 3 días hábiles**; la de alcances sensibles, **3 a 5
días hábiles** según la guía para desarrolladores y **10 días hábiles** según la ayuda de Google Cloud. Planea con
el más largo. Los alcances **restringidos** (no se usan aquí) piden además una auditoría de seguridad anual.

Fuentes: [OAuth 2.0 para apps web](https://developers.google.com/identity/protocols/oauth2/web-server),
[vencimiento de refresh tokens](https://developers.google.com/identity/protocols/oauth2),
[verificación de alcances sensibles](https://developers.google.com/identity/protocols/oauth2/production-readiness/sensitive-scope-verification),
[requisitos](https://support.google.com/cloud/answer/13464321).

### 2.5 Acceso a la Google Ads API

**El developer token ya no hace falta:** Google lo retiró el 9 de septiembre de 2026 y el nivel de acceso queda en
el proyecto de Google Cloud. En **Google Ads API → Descripción general** (dentro de la consola de Cloud) pide el
nivel **Explorer** (aprobación automática, hasta 2.880 operaciones al día en cuentas reales) o **Basic** (requiere
la verificación de la marca; se revisa en minutos). Con el nivel **Test** solo funcionan cuentas de prueba.
La plataforma usa la **Google Ads API v26** (la vigente; la v21 ya no existe).

Fuentes: [developer token](https://developers.google.com/google-ads/api/docs/api-policy/developer-token),
[versiones y fechas de cierre](https://developers.google.com/google-ads/api/docs/sunset-dates),
[Data Manager API: acceso](https://developers.google.com/data-manager/api/get-started/set-up-access).

> Para Customer Match, el cliente debe aceptar en su cuenta de Google Ads los términos de Customer Match y cumplir
> sus requisitos. Eso lo hace cada cliente, no la plataforma.

---

## 3. TikTok for Business

1. Entra a [business-api.tiktok.com/portal](https://business-api.tiktok.com/portal) con una cuenta de TikTok for
   Business y regístrate como desarrollador (datos de la empresa).
2. **My Apps → Create New App:** nombre, descripción, **Advertiser redirect URL**
   `https://growth.imagina.cloud/oauth/tiktok/callback` y los permisos: administración e información de la cuenta
   de anuncios, reportes (gasto), administración de audiencias y medición / administración de píxeles (Events API).
3. Envía la app a revisión. TikTok no publica un tiempo oficial en las páginas que se pudieron leer; integradores
   reportan de **2 a 7 días hábiles**. Mientras tanto se puede probar con una cuenta de anuncios de prueba (sandbox).
4. Copia el **App ID** y el **Secret** a Plataforma → Ajustes → TikTok.

La plataforma usa la API v1.3: el anunciante autoriza en `/portal/auth`, la vuelta trae `auth_code`, se canjea en
`/oauth2/access_token/` por un token de largo plazo que no vence hasta que se revoca (`/oauth2/revoke_token/`).
Fuentes: [obtener el token](https://business-api.tiktok.com/portal/docs/obtain-a-long-term-access-token/v1.3),
[revocarlo](https://business-api.tiktok.com/portal/docs/revoke-a-long-term-access-token/v1.3).

**[verificar con la app real]** La documentación de TikTok se carga con JavaScript y no se pudo leer completa: los
nombres exactos de los permisos y los campos de `pixel/list` y `advertiser/get` se confirman al crear la app.

---

## 4. Lo que ve el cliente

- **Configuración → Conexiones** (`/w/<espacio>/conexiones`): una tarjeta por proveedor con su estado, la cuenta
  conectada, para qué sirve y qué la usa, y los botones **Conectar con un clic**, **Elegir cuentas**,
  **Comprobar** y **Desconectar**.
- **Canales → WhatsApp → Conectar WhatsApp con un clic:** abre la ventana de Meta; si el número ya está en la app
  de WhatsApp Business, el cliente lo elige ahí y la sigue usando.
- **Medición** y **Canales → Messenger / Instagram** llevan a Conexiones; los campos para pegar los datos a mano
  siguen ahí.
- **Agenda → Personas → Conectar Google Calendar** usa la misma app de Google.

---

## 5. Dónde se pega cada dato

Todo va en **Plataforma → Ajustes** (en el menú, en el grupo de la agencia y la plataforma; solo para quien administra la plataforma
y con la verificación en dos pasos). Se guarda con **Guardar** en cada sección y se usa enseguida, sin reiniciar nada.

| Lo que copiaste | Sección | Campo |
|---|---|---|
| Identificador de la app de Meta (1.2) | Meta | Identificador de la app |
| Clave secreta de la app de Meta (1.2) | Meta | Clave secreta de la app |
| Token del webhook (1.7) | Meta | Token de verificación del webhook («Generar» y «Copiar») |
| ID de la configuración de WhatsApp (1.5 a) | Meta | Configuración de WhatsApp con un clic |
| ID de la configuración de anuncios y mensajes (1.5 b) | Meta | Configuración de anuncios y mensajes |
| ID de cliente de Google (2.3) | Google | ID de cliente |
| Secreto del cliente de Google (2.3) | Google | Secreto del cliente |
| App ID de TikTok (3) | TikTok | App ID |
| Secret de TikTok (3) | TikTok | Secret |

Los secretos se guardan cifrados y no se vuelven a mostrar: el panel solo dice si están y sus últimos 4 caracteres.
Si una instalación anterior los tenía en el `.env` del servidor (`IGR_META_*`, `IGR_GOOGLE_*`, `IGR_TIKTOK_*`), siguen
sirviendo de respaldo y el panel dice «Valor del servidor»; lo que guardes en el panel manda. Las versiones de las API
(Graph v27.0, Embedded Signup v5, Google Ads v26) vienen fijadas en cada versión de la plataforma y se actualizan con
el botón de Actualizaciones. El developer token de Google Ads ya no se exige (2.5); si Google te lo pide, va en Ajustes
→ Google.

Abre **Conexiones** en el panel: cada tarjeta debe decir «Sin conectar» (lista para usar) en lugar de «No
disponible». Prueba primero con tus propias cuentas.

---

## 6. Si algo falla

| Lo que ves | Qué revisar |
|---|---|
| «No disponible» y «Falta: …» | Falta ese dato en Plataforma → Ajustes (el enlace «Configurarla en Plataforma → Ajustes» de la tarjeta lleva a la sección). |
| Meta dice «URL bloqueada» o «redirect_uri no coincide» | El URI de 1.4 debe ser exacto (https, sin barra final) y el modo estricto exige que coincida letra por letra. |
| La ventana de WhatsApp se cierra sin conectar | El dominio no está en «Dominios permitidos» y «URI de redireccionamiento válidos», o el navegador bloquea ventanas emergentes. |
| Google dice «Esta app no está verificada» | La verificación de 2.4 sigue en curso; mientras tanto solo entran usuarios de prueba (y sus tokens vencen a los 7 días). |
| Las conexiones de Google se caen cada semana | La app de Google sigue en «Prueba»: pásala a «En producción» (2.2). |
| WhatsApp conectado pero no llegan los mensajes a la bandeja | Webhook de 1.7 sin suscribir o la clave secreta de Ajustes → Meta distinta a la de la app. |
| En coexistencia no aparece lo que el negocio escribe desde el celular ni el historial | Faltan `smb_message_echoes`, `history` o `smb_app_state_sync` en 1.7. La copia del historial y de los contactos se pide una sola vez: si pasaron más de 24 h, el cliente tiene que desconectar y volver a conectar. Si Conexiones dice que no se compartió el historial, el cliente lo apagó en la app. |
| Llegan correos de «vence el…» o «Vuelve a conectar» | El acceso tiene fecha de vencimiento (por ejemplo, la configuración de 1.5 con token de 60 días) o el proveedor lo invalidó: el cliente usa «Reconectar» en el Inicio o en Conexiones. |
| «Meta no activó el número… PIN» | El número tiene verificación en dos pasos con otro PIN: el cliente la quita en el Administrador de WhatsApp y usa «Reintentar». |
