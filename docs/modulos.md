# Especificación funcional por módulo

> Cada módulo indica su **fase** (ver `CLAUDE.md` §5), su **objetivo de negocio**, sus **funciones**, sus **pantallas** y sus **criterios de aceptación**.
> El origen competitivo de cada función está en `docs/competencia.md` §4.

## Índice

| # | Módulo | Fase |
|---|---|---|
| M01 | Cuentas, espacios de trabajo, usuarios y roles | 0 |
| M02 | Conexión de sitios, catálogo e importación histórica | 0 |
| M03 | Perfil 360 (CDP) | 1 |
| M04 | Segmentación | 1 |
| M05 | Analítica e informes | 1 |
| M06 | Medición, atribución y anuncios | 1 (audiencias en 3) |
| M07 | Canales de mensajería | 2 |
| M08 | Plantillas y contenido | 2 |
| M09 | Automatizaciones (flujos) | 2 |
| M10 | Campañas | 2 |
| M11 | Bandeja de conversaciones y agente de IA | 4 |
| M12 | Captación en el sitio | 3 |
| M13 | Fidelización (puntos, VIP, saldo) | 3 |
| M14 | Referidos, afiliados e influencers | 3 |
| M15 | Reseñas, NPS y prueba social | 3 |
| M16 | Comercio en el sitio (recomendaciones, avisos) | 3 |
| M17 | Inteligencia e IA | 1 → 4 |
| M18 | Servicios y leads (sitios corporativos) | 5 (formularios desde la 1) |
| M19 | Integraciones, API y MCP | 2 → 5 |
| M20 | Cumplimiento y privacidad | 0 → 2 |
| M21 | Planes, uso y facturación | 5 |
| M22 | Panel de agencia | 5 |

---

## M01 — Cuentas, espacios de trabajo, usuarios y roles · Fase 0

**Objetivo:** que Imagina La Web administre a muchos clientes y que cada cliente vea solo lo suyo.

- La jerarquía es cuenta → espacios de trabajo → sitios. Una cuenta de agencia crea espacios de trabajo para sus clientes; un cliente directo tiene su propia cuenta.
- Roles por espacio de trabajo:

| Rol | Puede |
|---|---|
| `owner` | todo, incluido facturación y borrar |
| `admin` | todo menos facturación |
| `marketing` | segmentos, flujos, campañas, widgets y analítica; no ve datos de pago ni exporta contactos sin permiso |
| `agent` | bandeja y perfiles de los contactos que tiene asignados |
| `viewer` | solo lectura de los tableros |

  En la agencia, `agency_owner` y `agency_member` ven todos los espacios de trabajo de la cuenta.
- Invitaciones por correo, 2FA obligatoria para `owner` y `admin`, sesiones activas revocables, registro de auditoría.
- Ajustes del espacio de trabajo: país, zona horaria, moneda, idioma, industria (alimenta plantillas y referencias del sector), datos legales y kit de marca. Al conectar el sitio, el kit de marca se extrae solo: logo, colores y tipografía de la portada, editables.
- Cambio rápido entre espacios de trabajo (⌘K).

**Listo cuando:** un `agent` no puede ver por la API contactos que no tiene asignados (prueba), y la auditoría registra exportaciones y borrados.

---

## M02 — Conexión de sitios, catálogo e importación histórica · Fase 0

**Objetivo:** conectar en menos de 2 minutos sin tocar código.

- **Conexión:** botón «Conectar con Imagina Growth» en el plugin → `growth.imagina.cloud/connect?site=…&nonce=…&return=…` → inicio de sesión → elegir o crear espacio de trabajo → regreso con un código de un solo uso → el plugin lo canjea de servidor a servidor por `site_id`, la clave pública y el secreto. Hay alternativa manual con un código para pegar.
- **Salud:** heartbeat cada hora, alertas si no llegan eventos en 2 h con tráfico esperado, rotación del secreto, desconexión.
- **Detección de capacidades:** WooCommerce y su versión, HPOS, checkout de bloques, WooCommerce Subscriptions, plugins de formularios, banner de cookies, COGS, Imagina Search, plugin de caché y pasarelas de pago. Todo se guarda en `sites.capabilities`.
- **Catálogo:** sincronización completa al conectar, cambios en tiempo real y conciliación nocturna por hash.
- **Importación histórica:** pedidos (24 meses por defecto, se puede ampliar a todo), clientes, productos, reseñas y suscripciones. Va en lotes de 200 con pausas, se reanuda si se corta, muestra progreso en el hub y en el plugin, y no dispara flujos.
- **Varios sitios por espacio de trabajo** (tienda + sitio corporativo + landing): los perfiles se unen por identidad.

**Listo cuando:** se cumple el criterio de la Fase 0 en `CLAUDE.md`.

---

## M03 — Perfil 360 (CDP) · Fase 1

**Objetivo:** conocer a cada persona en una sola pantalla.

- **Encabezado:** nombre, contacto (enmascarado según el rol), ciudad, segmento RFM, nivel VIP, CLV, riesgo de abandono, próxima compra esperada, consentimientos por canal y etiquetas.
- **Línea de tiempo unificada:** visitas, productos vistos, búsquedas, carritos, pedidos, mensajes, conversaciones, reseñas, puntos y encuestas, con filtros por tipo.
- **Pestañas:**

| Pestaña | Muestra |
|---|---|
| Resumen | métricas clave y gráfico de compras |
| Pedidos | historial completo |
| Productos | afinidad por categoría y marca |
| Mensajes | lo enviado, leído y con clic |
| Fidelización | puntos, saldo y referidos |
| Datos | atributos, también los personalizados |
| Identidades | dispositivos e IDs unidos |
| Privacidad | consentimientos, evidencia, exportar y borrar |

- **Resumen con IA** (Fase 4): tres líneas del tipo «Cliente recurrente desde 2024, compra pintura para exteriores cada 3 meses, responde mejor a WhatsApp por la tarde, nunca usa cupones».
- **Acciones:** agregar a un flujo, enviar un mensaje (respetando las políticas), dar puntos, crear cupón, nota interna, fusionar o separar perfiles, exportar o borrar.
- **Lista de contactos:** búsqueda instantánea por nombre, correo o teléfono (índice ciego exacto más búsqueda por prefijo del nombre en el índice), filtros guardados y columnas configurables.
- **Importación CSV** con mapeo de columnas, normalización de teléfonos y registro del origen del consentimiento (obligatorio declararlo).

**Listo cuando:** el perfil carga en menos de 800 ms con 2 años de historial, y una fusión se revierte sin perder eventos.

---

## M04 — Segmentación · Fase 1

**Objetivo:** encontrar a cualquier grupo de clientes en segundos, sin SQL.

- **Constructor visual** con grupos Y/O anidados. Tipos de condición:

| Tipo | Ejemplos |
|---|---|
| Atributo | ciudad, departamento, etapa, etiqueta, cumpleaños este mes, atributo personalizado |
| Compras | compró el producto, la categoría o la marca X (n veces, en una ventana); gasto total, ticket, n.º de pedidos, primera y última compra; usó cupón; método de pago |
| Comportamiento | hizo el evento X al menos n veces en los últimos N días o nunca; vio el producto X sin comprarlo; carrito abandonado |
| Predicción | segmento RFM, CLV previsto, riesgo de abandono, próxima compra en los próximos N días, afinidad, sensibilidad a descuentos |
| Mensajería | recibió, abrió, leyó, hizo clic o respondió; no ha recibido nada en N días; puntaje de interacción |
| Consentimiento | puede recibir WhatsApp o correo de marketing |
| Fidelización | nivel, puntos, saldo, referidos |
| Servicios | etapa del lead, puntaje, cita agendada |

- Tamaño en vivo con conteo por canal disponible, y valor del segmento (ingresos históricos y CLV previsto).
- **Biblioteca** de segmentos listos en español: los 11 de RFM y además «Compraron una sola vez hace más de 60 días», «Visitaron 3+ veces este mes y nunca compraron», «Clientes VIP sin compra en 90 días», «Solo compran con descuento», «Compradores de [categoría] que nunca compraron [categoría complementaria]», «Leads calientes sin respuesta», etc.
- **Uso:** en flujos (entrar o salir del segmento), campañas, widgets (personalización), audiencias de anuncios y exportación CSV.
- **Segmentos estáticos** (lista fija desde CSV o una foto de un dinámico).

**Listo cuando:** la vista previa tarda menos de 3 s con 100.000 contactos y 5 millones de eventos, y las entradas y salidas disparan flujos en menos de 2 minutos.

**Estado (Fase 1):** vista previa medida con 100.000 contactos, 150.000 pedidos y 5 millones de eventos: entre 137 ms y 1,45 s según el segmento (`igr:segments:benchmark`). Las entradas y salidas se calculan 15 s después del evento; el disparo de flujos se mide en la Fase 2, cuando existan los flujos.

**Estado (Fase 3):** se habilitan las condiciones de mensajería y fidelización; las de servicios siguen marcadas para la Fase 5.

- **Mensajería** (sobre la tabla `messages` de MariaDB, solo mensajes salientes):
  - Acciones: recibió (`sent_at`), abrió (correo, `opened_at`), leyó (WhatsApp, `read_at`), hizo clic (`clicked_at`) o respondió (`replied_at`).
  - Filtros: canal, solo marketing o cualquier tipo, un flujo o una campaña, con conteo («al menos N», «nunca»…) y ventana.
  - «No ha recibido nada en N días» = recibió · nunca · últimos N días.
  - **Puntaje de interacción** (0 a 100), calculado al vuelo con los mensajes de los últimos 90 días: por mensaje, abrir o leer suma 1, un clic 3 y una respuesta 5, con tope 100. No necesita un job nocturno y es distinto de la predicción `engagement_score` de la Fase 4.
  - Recalculan en tiempo real con `message.*` y `whatsapp.inbound`.
- **Fidelización:**
  - Nivel VIP: es, no es, «es al menos» (por `rank`), tiene o no tiene nivel.
  - Puntos disponibles y saldo a favor, con operadores numéricos.
  - Puntos por vencer en los próximos N días (`points_expiring` > 0 y `points_expiring_at` en el rango).
  - Referidos que compraron: cuenta los calificados o premiados.
  - Llegó referido: cualquier estado.
  - Recalculan en tiempo real con `points.*`, `wallet.*`, `tier.changed` y `referral.*`. La creación de un referido al registrarse no emite evento, así que «Llegó referido» se actualiza en el recálculo nocturno o con el siguiente evento del contacto.
- `igr:segments:benchmark` incluye ahora un segmento de clics, uno de «sin mensajes en 14 días e interacción alta» y uno de puntos.

---

## M05 — Analítica e informes · Fase 1

**Objetivo:** que el dueño entienda su negocio en 30 segundos y sepa qué hacer.

**Tableros** (todos con comparación de periodos, filtros por sitio, canal, dispositivo y ciudad, y exportación):

1. **Inicio:** ventas, pedidos, ticket, sesiones, conversión, clientes nuevos frente a recurrentes, ingresos atribuidos a Imagina Growth y los 3 hallazgos principales.
2. **Embudo:** sesión → producto → carrito → checkout → compra, con caídas por paso y desglose por canal, dispositivo y método de pago.
3. **Canales:** tráfico, conversión, ingresos, **LTV a 90 y 365 días por canal de adquisición**, CAC y ROAS cuando hay gasto importado, y el canal «Asistentes de IA».
4. **Clientes:** mapa RFM interactivo (clic → segmento), cohortes de retención por mes de primera compra, tasa de recompra, tiempo hasta la segunda compra, concentración de ingresos (top 10% y 20%).
5. **Productos:** vistos frente a vendidos, conversión vista → compra, abandono en carrito, **productos de entrada** (primera compra de los clientes con mayor LTV), combinaciones frecuentes, inventario contra días restantes.
6. **Búsquedas:** principales, sin resultados y conversión por término.
7. **Geografía:** ventas y conversión por departamento, estado o ciudad, en mapa.
8. **Cupones y descuentos:** uso, ingreso incremental estimado y dependencia de descuentos.
9. **Mensajería:** rendimiento por flujo, campaña y canal (enviados, entregados, leídos, clics, ingresos, ingreso por mensaje, costo, ROI y bajas).
10. **Rentabilidad** (si hay costos): margen bruto por producto y pedido; MER y ROAS con gasto en anuncios.
11. **Servicios:** leads por fuente, conversión por formulario y página, clics a WhatsApp o llamada, tiempo de primera respuesta y cierre por fuente.
12. **Encuesta «¿cómo nos conociste?»:** respuestas contra el canal del último clic (muestra lo que la atribución digital no ve: voz a voz, radio, tienda física).

**Informes automáticos:**
- Resumen semanal al dueño por correo o WhatsApp: 5 cifras, 3 hallazgos y 1 acción.
- **Reporte mensual en PDF con marca**, «Lo que Imagina Growth generó para tu negocio»: ingresos atribuidos por módulo, clientes recuperados, carritos recuperados y reseñas obtenidas. Es clave para retener al cliente.
- Exportaciones programadas a CSV o Google Sheets.

**Listo cuando:** las cifras de ventas coinciden con WooCommerce (misma definición, documentada en un tooltip) y cada tablero carga en menos de 1,5 s.

---

## M06 — Medición, atribución y anuncios · Fase 1 (audiencias en Fase 3)

**Objetivo:** que los anuncios del cliente rindan más porque las plataformas reciben conversiones completas, y saber qué canal trae a los mejores clientes.

- **Conversiones desde el servidor:**

| Plataforma | Envío | Eventos |
|---|---|---|
| **Meta** | Conversions API (web) con `event_id` compartido con el píxel | PageView opcional, ViewContent, AddToCart, InitiateCheckout, Purchase, Lead, CompleteRegistration, Subscribe |
| **Meta** | **Conversions API for Business Messaging** (`action_source=business_messaging`, `ctwa_clid`) para ventas iniciadas en anuncios de clic a WhatsApp | Purchase, LeadSubmitted, AddToCart, InitiateCheckout, ViewContent (la lista que Meta acepta para mensajería es más corta) |
| **Google** | GA4 Measurement Protocol, Enhanced Conversions y **Data Manager API** para cargas de datos propios y conversiones offline de leads ganados | — |
| **TikTok** | Events API | — |

  Pinterest y Microsoft quedan opcionales para la Fase 5.
- **Calidad de la señal:** se envían los datos coincidentes con hash (§7 de `eventos.md`), `fbp`, `fbc`, IP y user agent en tránsito. El panel muestra el porcentaje de eventos con correo, teléfono o click ID, el estado de entrega, los errores y los reintentos.
- **Modos:** «solo servidor», compatible con un píxel ya instalado (PixelYourSite u otro) con deduplicación por `event_id` cuando es posible, o «completo», donde el tracker carga los píxeles con IDs compartidos.
- **Gasto en anuncios:** importación diaria desde Meta Marketing API, Google Ads API y TikTok, por campaña.
- **Atribución:**
  - Agrupación de canales fija y documentada (`inteligencia.md` §2).
  - Modelos de último clic, primer clic, lineal y por posición, con ventanas configurables.
  - **Atribución de la plataforma** (mensaje → compra): clic en un mensaje dentro de 5 días; lectura o apertura dentro de 1 día, opcional, sin contar aperturas de Apple MPP. Todo configurable.
  - **Grupos de control** en flujos y campañas para medir el incremento real.
- **Audiencias (Fase 3):** segmento → Meta Custom Audiences, Google Customer Match (por Data Manager API) y TikTok, sincronizadas cada 12 h. Incluye exclusión de compradores recientes, semillas de lookalike con clientes de CLV alto y reactivación de clientes en riesgo. Solo se suben contactos con consentimiento para anuncios cuando el espacio de trabajo exige ese modo.

  **Implementación (Fase 3, `app/Domain/Measurement/Audiences`):** `audience_syncs` (segmento × destino, con `purpose`: público, exclusión o semilla de similares) y `audience_members` con los hashes enviados, cifrados, para poder sacar a alguien aunque cambie de correo o se borre su perfil.
  - `igr:audiences:sync` (02:37 y 14:37) calcula quién debe estar y envía solo bajas y altas por lotes: Meta `/{audiencia}/users` (EMAIL y PHONE en SHA-256, 10.000 por lote), Google crea la lista con la API de Google Ads (`crmBasedUserList`) y sube por la Data Manager API (`audienceMembers:ingest` / `:remove`), TikTok con la Segment API (`segment/audience/` y `segment/mapping/`). Todo **[verificar]** con cuentas reales.
  - **Consentimiento:** con el modo estricto elegido en el panel solo entra quien tiene consentimiento `ads` concedido; en «según el sitio» o informado, todos menos quien lo revocó **[validar]**. Revocar `ads` saca a la persona de todas las audiencias al momento (`RemoveOnAdsConsentRevoked`). Siempre fuera: borrados, fusionados y sin correo ni teléfono.
  - **De dónde sale el consentimiento `ads`:** de la decisión del banner de cookies sobre marketing (`context.consent.marketing` con un banner o CMP real) de un visitante ya identificado: aceptar lo concede (`source = banner:<cmp>`) y rechazar lo revoca. Se escribe solo cuando cambia (`ProjectConsents::projectBanner`) **[validar con el abogado]**.
  - Errores en español (los mismos traductores de las conversiones); un límite temporal no marca la audiencia con error. Atajos en el panel: excluir compradores de 30 días (crea el segmento), semilla con RFM «Campeones» (hasta el CLV de la Fase 4) y reactivar «En riesgo».

**Listo cuando:** en Meta Events Manager el Purchase aparece deduplicado entre navegador y servidor, una compra iniciada desde un anuncio de clic a WhatsApp aparece atribuida a ese anuncio, y el panel explica cada error de entrega.

---

## M07 — Canales de mensajería · Fase 2

**Objetivo:** enviar por el canal correcto, con buena entrega y sin poner en riesgo el número ni el dominio del cliente.

- **Correo:**
  - Amazon SES por defecto; SMTP genérico como alternativa.
  - Asistente de dominio de envío (DKIM, SPF y DMARC con verificación DNS en vivo) y calentamiento gradual para dominios nuevos.
  - Manejo de rebotes y quejas, `List-Unsubscribe` con un clic (RFC 8058) y supresión global.
- **WhatsApp oficial:**
  - Cloud API de Meta con **Embedded Signup**: el cliente conecta su propio número y paga a Meta sus mensajes.
  - Envío de marketing por la **Marketing Messages API**, que optimiza la entrega y da métricas.
  - Sincronización, creación y edición de plantillas desde la app, con vista previa y categoría.
  - Calificación de calidad, nivel de límite de mensajes y webhooks de estado.
- **WhatsApp por Zender:** solo para conversaciones 1:1, mensajes transaccionales de bajo volumen y clientes que todavía no tienen la API oficial. La interfaz advierte que es una conexión no oficial con riesgo de bloqueo y el administrador debe aceptarlo. Tiene un tope por hora y por día que no se puede subir por encima de lo definido por la plataforma. **No se permiten campañas ni flujos de marketing masivos por Zender.**
- **SMS (opcional):** Infobip o Twilio, para respaldo, OTP o clientes que lo pidan.
- **Centro de preferencias del contacto:** página propia con el dominio del cliente. Permite elegir canales, temas y frecuencia, o darse de baja de todo.
- **Supresión:** bajas, rebotes, quejas, bloqueos de WhatsApp y registros de exclusión (RNE en Colombia, REPEP en México).

**Listo cuando:** un correo de prueba sale con DKIM alineado y pasa la revisión de Gmail, una plantilla de WhatsApp creada en la app queda aprobada y se envía, y una baja por cualquier canal se aplica en menos de 1 minuto en todo el sistema.

**Implementación (Fase 2):**
- **Cuentas:** «Canales» (`/canales`) con pestañas Correo, WhatsApp, SMS y Supresión. Los secretos se guardan cifrados y la API nunca los devuelve.
  - **SES:** el asistente crea la identidad del dominio en SES (DKIM Easy DKIM), muestra los registros DKIM, SPF (recomendado) y DMARC con su estado en vivo, y activa la cuenta cuando los obligatorios están y SES confirma DKIM. Desde ese día corre el **calentamiento** (50, 100, 250… 40.000 correos al día en 10 días); lo que pasa del cupo del día se aplaza al siguiente.
  - **SMTP:** queda activo al conectarlo, sin seguimiento de rebotes ni aperturas.
  - **WhatsApp oficial:** Embedded Signup con el SDK de Facebook **[verificar: app y configuración de Meta]** o conexión manual (WABA, ID del número y token permanente). Se suscribe la app a la WABA y se leen la calidad y el nivel de límite.
  - **Zender:** solo el dueño, con la casilla de riesgo aceptada (queda en la auditoría). Topes fijos de 30 por hora y 200 por día (`config/igr-messaging.php`), y el driver rechaza todo mensaje de marketing.
  - **SMS:** Twilio (número o Messaging Service). Infobip queda pendiente.
- **Envío:** `MessageDispatcher` → cuenta por defecto del canal → `SendPolicy::checkAndReserve` → plantilla → enlaces rastreados → fila en `messages` con `idempotency_key` única → cola `messages:{canal}` con límite por segundo por cuenta. El job vuelve a revisar la ventana legal justo antes de llamar al proveedor; si ya no aplica, el mensaje queda `deferred` y `igr:messages:requeue` (cada minuto) lo devuelve a la cola a su hora. Si el envío nunca llega al proveedor, se devuelve el cupo de frecuencia. Los errores transitorios se reintentan hasta 5 veces.
  - Marketing de WhatsApp oficial con plantilla va por `/{phone_id}/marketing_messages` **[verificar con Meta]**; lo demás por `/messages`.
  - Cada correo de marketing lleva `List-Unsubscribe` y `List-Unsubscribe-Post` (RFC 8058) y, si la plantilla no trae enlace de baja, un pie con la baja y las preferencias.
  - Costo estimado por mensaje: WhatsApp con `wa_rates` por país y categoría; correo y SMS con la tarifa de `config/igr-messaging.php` **[verificar]**.
- **Webhooks:** Meta (`X-Hub-Signature-256`, un solo endpoint para todas las cuentas, que se encuentran por el ID del número), SES por SNS (firma verificada y confirmación de la suscripción), Zender (secreto por cuenta) y Twilio (`X-Twilio-Signature`). Los estados solo avanzan y cada evento `message.*` sale una vez aunque el proveedor repita el aviso.
  - Rebote duro y queja → supresión; la queja además revoca el marketing del correo.
  - Error 131050 de Meta (la persona detuvo el marketing de la empresa en WhatsApp) → se revoca el marketing de WhatsApp. El 131049 (tope de Meta por persona) no se reintenta.
  - Apertura de correo desde las IP de Apple Mail Privacy Protection → `message.opened` con `is_mpp=true`, que no cuenta para la atribución **[verificar la lista de rangos]**.
- **WhatsApp entrante:** crea o encuentra el contacto por teléfono (índice ciego), abre la ventana de 24 h en `conversations`, marca `message.replied` en el último mensaje (o en el citado, con el payload del botón) y emite `whatsapp.inbound` sin el texto. Un «ref: CODIGO» del widget vincula al visitante anónimo del clic. Las palabras de baja (lista normalizada, o «baja» dentro de un mensaje corto de hasta 4 palabras) revocan el marketing del canal y responden con una confirmación.
- **Enlaces y baja:** `/c/{code}` (no cuenta las vistas previas de WhatsApp ni de redes, y un doble clic en 10 s emite un solo `message.clicked`), `/u/{token}` (página de baja y baja de un clic sin cookies ni CSRF) y `/preferencias/{token}` (canales, temas, frecuencia «normal», semanal o mensual, o baja de todo). Los tokens van firmados y siguen al contacto si se fusionó.
- **Plantillas de WhatsApp:** `/canales/plantillas-whatsapp` sincroniza, crea, edita y elimina en Meta, con vista previa. Si una plantilla de utilidad o autenticación trae palabras de marketing («descuento», «oferta», «%»…), el editor y la API exigen confirmar la categoría.
- **Supresión:** lista con búsqueda, alta manual, quitar (las quejas no se pueden quitar) e importación del CSV del RNE o del REPEP, que suprime WhatsApp y SMS.
- **Atribución por mensaje:** paso 2 de `AttributeOrder` (`MessageAttributor`), con los contactos fusionados. El perfil tiene la pestaña «Mensajes».

---

## M08 — Plantillas y contenido · Fase 2

- **Editor de correo** de arrastrar y soltar (GrapesJS + MJML) con bloques dinámicos: producto, recomendaciones personalizadas, carrito del contacto, cupón único, puntos y nivel, reseñas, botón de WhatsApp.
- **Editor de plantillas de WhatsApp:** encabezado (texto, imagen, video o documento), cuerpo con variables, pie y botones (URL con seguimiento, respuesta rápida, llamada, copiar cupón). Plantillas de carrusel de productos cuando Meta las permita para la categoría.
- **Personalización** con sintaxis Liquid en sandbox: `{{ contacto.nombre | default: "hola" }}`, `{{ carrito.items }}`, `{{ cupon.codigo }}`, `{{ recomendaciones.para_ti | limit: 3 }}`, con vista previa con un contacto real.
- **Biblioteca en español** por industria (moda, belleza, ferretería y pinturas, mascotas, alimentos, tecnología, servicios profesionales, salud y bienestar, educación).
- **IA:** asuntos y preheaders, variantes para A/B, adaptar el tono de la marca, acortar para WhatsApp, revisar ortografía y advertir posibles problemas de categoría de plantilla.

**Estado (Fase 2):**
- **Plantillas de WhatsApp:** se crean, editan y sincronizan con Meta en M07 (Canales). Aquí una plantilla de WhatsApp apunta a la aprobada y asigna una expresión Liquid a cada parámetro (`{{1}}` o con nombre), a cada botón con URL dinámica o código y al archivo del encabezado.
- **Variables:** `contacto` (sin correo ni teléfono), `tienda`, `carrito`, `cupon`, `pedido`, `producto`, `recomendaciones` y `enlaces`. Además pasa sin cambios cualquier otra clave que mande el flujo o la campaña (por ejemplo `campana`).
- **Filtros propios:** `limit`, `moneda` y `fecha`, además de los de Liquid.
- **Pie legal:** los datos del negocio (nombre, dirección, contacto, WhatsApp y logo) se editan en «Datos del negocio» y llenan `tienda.*`. Un correo de marketing sin `{{ enlaces.baja }}` no se puede marcar como lista; el editor avisa también si falta quién envía, la dirección o el motivo.
- **Biblioteca:** 29 correos y 7 SMS para los 20 flujos, con copia por industria. Se compila a HTML con el mismo MJML del editor (`app/Domain/Templates/Library/build.mjs`) y se versiona, así un flujo la instala sin pasar por el navegador.
- **IA:** llega en la Fase 4.

---

## M09 — Automatizaciones (flujos) · Fase 2

**Objetivo:** que la tienda venda sola mientras el dueño duerme, sin molestar ni romper la ley.

**Disparadores:**
- Evento (cualquiera del catálogo, con filtros sobre propiedades).
- Entrada o salida de un segmento.
- Fecha de un atributo: cumpleaños, aniversario de la primera compra, **fecha esperada de próxima compra − N días**, vencimiento de puntos, renovación.
- Cruce de un umbral (puntaje o CLV).
- Webhook entrante, API o activación manual.

**Nodos:**

| Tipo | Nodos |
|---|---|
| Esperas | tiempo; **esperar hasta un evento** con tiempo máximo; esperar hasta una hora o un día de la semana |
| Lógica | condición por atributo, comportamiento o predicción; división A/B aleatoria; división por canal disponible («si tiene WhatsApp con consentimiento, si no correo») |
| Acciones | enviar WhatsApp, correo o SMS; generar cupón único; dar puntos o saldo; etiquetar; actualizar atributo; mover etapa del lead; asignar a un agente o abrir conversación; notificar al equipo por WhatsApp o correo; webhook saliente; crear tarea en Imagina Base o Imagina CRM |
| Control | meta (si el contacto compra o cumple la condición, sale del flujo y se atribuye), salida |

**Políticas del flujo:**
- Reentrada: una vez, cada N días o siempre.
- Exclusión mutua: no entrar si está en el flujo X.
- Grupo de control (%).
- Horario legal automático y límite de frecuencia global.
- Modo prueba: los mensajes van al usuario que prueba.

**Versionado:** publicar una versión nueva no rompe a quienes ya van por el flujo; siguen en su versión.

**Analítica por nodo:** cuántos entran y salen, conversiones, ingresos e incremento frente al grupo de control.

### Biblioteca de flujos listos (en español, para activar en 1 clic)

| # | Flujo | Disparador | Secuencia sugerida |
|---|---|---|---|
| 1 | Bienvenida de suscriptor | consentimiento nuevo desde un widget | cupón inmediato → historia de la marca (día 2) → más vendidos (día 5) |
| 2 | Carrito abandonado | `cart` abandonado 45 min con contacto conocido | WhatsApp a 1 h → correo a 4 h → WhatsApp a 24 h con incentivo opcional |
| 3 | Checkout abandonado | `checkout.started` sin pedido en 30 min | igual que el 2, más corto |
| 4 | Navegación abandonada | vio el mismo producto 2+ veces en 3 días sin carrito | correo con el producto y alternativas |
| 5 | **Pago pendiente** (LATAM) | `order.payment_pending` | recordatorio con enlace de pago a 2 h y 24 h; aviso antes de que se cancele |
| 6 | **Confirmar contraentrega** (LATAM) | pedido contraentrega creado | plantilla de utilidad con botones «Confirmo» o «Cancelar»; si no responde en 12 h, alerta al dueño |
| 7 | Poscompra | `order.completed` | gracias + cómo usar el producto (contenido por categoría) |
| 8 | Pedir reseña | entrega + N días según la categoría | WhatsApp o correo con estrellas en 1 toque; recompensa por foto |
| 9 | Segunda compra | clientes con 1 pedido que superan el tiempo mediano hasta la segunda compra | recomendación según el «siguiente producto» (`inteligencia.md` §4.5) |
| 10 | Reposición | fecha esperada de próxima compra − 5 días | «se te está acabando» + recompra en 1 clic (carrito restaurado) |
| 11 | Recuperación | entra en «En riesgo» o «No podemos perderlos» | mensaje personal → incentivo escalonado según el CLV |
| 12 | VIP | sube de nivel | bienvenida al nivel + beneficios + acceso anticipado |
| 13 | Cumpleaños | atributo `birthday` | regalo (puntos o cupón) el día, a una hora legal |
| 14 | Volvió el stock / bajó de precio | `product_watch` | aviso inmediato (con tope de frecuencia) |
| 15 | Puntos por vencer | vencimiento − 15 días | recordatorio con el saldo y sugerencias de canje |
| 16 | Invitar a referir | NPS ≥ 9 o segunda compra | enlace de referido para compartir por WhatsApp |
| 17 | NPS y detractores | 14 días tras la entrega | encuesta; si la nota es ≤ 6, alerta al dueño y una tarea |
| 18 | **Lead de servicios** | `form.submitted` o WhatsApp entrante | respuesta por WhatsApp al instante + aviso al vendedor con el resumen del lead + seguimiento de 3 toques |
| 19 | Recordatorio de cita | `appointment.booked` | 24 h y 2 h antes, con reprogramación por botón |
| 20 | Renovación | fecha de renovación − 15 días | recordatorio, oferta y aviso al equipo si no renueva |

**Exclusión por defecto:** los flujos 2 (carrito abandonado) y 3 (checkout abandonado) de la biblioteca se excluyen entre sí al crearse: quien abandona el checkout también abandona el carrito, y no se le escribe dos veces por lo mismo. Se cambia en la configuración del flujo.

**Listo cuando:** los 20 flujos funcionan de punta a punta en la tienda de prueba, y una prueba automática garantiza que ningún paso de marketing se ejecuta fuera de la ventana legal ni dos veces por reintentos.

**Implementación (Fase 2):**
- **Estados:** `draft` → `active` ⇄ `paused` → `archived`. Activar exige una versión publicada. Archivar saca a todos los runs en curso (`manual`). Un borrador que nunca corrió se borra; si ya corrió, se archiva.
- **Una persona nunca va dos veces a la vez por el mismo flujo.** La reentrada (`once`, `every_days` o `always`) solo decide cuándo puede volver a entrar después de terminar. Las salidas por una condición con «salir si no se cumple» (`exit_reason = filter`) y los runs de prueba no cuentan para la reentrada.
- **Disparadores implementados:**
  - Evento con filtros (operadores `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `contains`, `starts_with`, `exists` y `not_exists` sobre rutas con puntos, p. ej. `order.payment_method.id`).
  - Entrada o salida de un segmento (`segment.entered`/`exited`, por ID o por `system_key`).
  - Fecha de un atributo (`birthday`, `first_order_anniversary`, `expected_next_order_at` y `second_purchase_due` = primer pedido + mediana de días hasta la segunda compra del espacio de trabajo, 45 por defecto) a una hora local, con `igr:flows:date-triggers` cada hora. El 29 de febrero se celebra el 28 en los años no bisiestos.
  - Umbral (pedidos, ingresos, ticket promedio, *engagement*, puntaje del lead, CLV o riesgo de abandono), una sola vez por contacto.
  - Manual (inscribir un segmento o contactos) y webhook entrante `POST /webhooks/flows/{token}` con `email`, `phone` o `contact_id`. El token es secreto y va en la URL que muestra el constructor.
  - Puntos, renovación y aniversarios de otras fases llegan con sus módulos.
- **Nodos de fases siguientes** (dar puntos o saldo, asignar agente, etapa del lead, tarea en el CRM) aparecen en la paleta bloqueados, con la fase en que llegan. Publicar con uno de ellos falla con un mensaje claro.
- **Condición:** sobre el AST de segmentos (M04) evaluado para ese contacto, o sobre los datos del run (`props.*` del disparador o `last_event.props.*` del evento que despertó una espera, p. ej. el botón que respondió). Con «salir si no se cumple», la rama «no» termina el run como `filter`.
- **Modo prueba:** corre el **borrador** (o la versión publicada) de punta a punta al instante, sin esperas (las esperas de evento toman la rama «se acabó el tiempo»), con los datos de un contacto real. Los mensajes van al correo y al teléfono de quien prueba, el cupón es ficticio (`PRUEBA-…`), los webhooks no salen y nada cuenta en la analítica.
- **Envíos y política:** el nodo de envío llama a `MessageDispatcher` con la clave `run:{run}:{nodo}:{intento}`. Un aplazamiento por horario o frecuencia deja el run esperando hasta `until` y vuelve a llamar con la misma clave. Una denegación (sin consentimiento, suprimido) sigue por la rama «si no se envía» si está conectada y, si no, por la salida normal. Los clasificados como utilidad o transaccional piden un motivo de al menos 10 caracteres (Ley 2300).
- **Grupo de control:** el sorteo es al entrar. El control recorre el flujo sin envíos, cupones, avisos ni webhooks.
- **Analítica:** «Entraron» y «En curso» cuentan a todos. Convirtieron, tasa e ingresos de la meta son del grupo expuesto. Los números de cada paso excluyen al control. El incremento es la diferencia de tasas (expuesto − control) con un intervalo del 95% (aproximación normal) y pide al menos 30 contactos por grupo. Los ingresos atribuidos por clic salen de `orders.attributed_flow_id` (§9 del hub).
- **Biblioteca:** 11 de los 20 flujos funcionan desde la Fase 2 (1 a 7, 9, 10, 11 y 13). La Fase 3 suma tres flujos y tres tarjetas que llevan a su módulo; los de la Fase 5 (18 a 20) se listan con su fase.
  - **12 VIP:** `tier.changed` con `direction = up`: WhatsApp o correo `vip_welcome` con el nivel (`contacto.nivel`).
  - **15 Puntos por vencer:** disparador por fecha `points_expiring_at` 15 días antes, a las 10:00, solo si de verdad vencen puntos (`contacts.points_expiring > 0`). Canjear (`points.redeemed`) es la meta.
  - **16 Invitar a referir:** promotores del NPS (`nps.answered` con `category = promoter`), una hora después: correo `referral_invite` con `enlaces.referido`, que solo existe con el programa de referidos activo. La «segunda compra» de la especificación queda para cuando el disparador acepte varias condiciones de entrada.
  - **8 Pedir reseña, 14 Volvió el stock o bajó de precio y 17 NPS:** los hace su propio módulo (M15 y M16), con su programador, plantilla y tope. La tarjeta lleva a su configuración para no escribirle dos veces a nadie por lo mismo.
  - Las plantillas de correo son las de la biblioteca de M08 (`welcome_1`…`3`, `abandoned_cart_1` y `_2`, `abandoned_checkout`, `browse_abandonment`, `payment_pending_1` y `_2`, `cod_confirm_email`, `post_purchase`, `second_purchase`, `replenishment`, `winback_1` y `_2`, `birthday`, `team_alert`, y en la Fase 3 `vip_welcome`, `points_expiring` y `referral_invite`) y se instalan solas al crear el flujo.
  - Las de WhatsApp (`wa_*`, aprobadas por Meta en la cuenta del cliente) se piden por `library_key`. Si no hay una, el flujo queda en borrador y el constructor pide elegirla.
  - **Sin WhatsApp conectado**, la biblioteca quita los pasos de WhatsApp: quien llegaba a uno sigue por su respaldo por correo o por su salida normal, y las divisiones por canal dejan de ofrecerlo. Así una tienda que solo tiene correo activa en un clic.
  - Carrito abandonado: WhatsApp a 1 h (si no se puede, sigue igual), correo a 4 h, cupón y WhatsApp a 24 h con respaldo por correo con incentivo. Con el tope de frecuencia por defecto (1 mensaje de marketing al día en Colombia), el segundo y el tercer toque del mismo día se aplazan al día siguiente.
  - Confirmar contraentrega: WhatsApp de utilidad con botones (`cod_confirm` y `cod_cancel`). Si el WhatsApp no sale, envía el correo de respaldo y avisa al equipo.
  - Reposición: el carrito restaurado en 1 clic llega con el comando del sitio en una fase siguiente; por ahora el correo enlaza los productos.

---

## M10 — Campañas · Fase 2

- Asistente en 5 pasos: canal → audiencia (incluir y excluir: «compró en los últimos 7 días», «recibió un mensaje hace menos de 3 días») → contenido → programación → revisión.
- **Programación:** ahora, fecha fija, por zona horaria del contacto o **mejor hora por contacto**, siempre dentro de la ventana legal.
- **A/B** de 2 a 4 variantes con ganador automático por métrica (clics, ingresos) tras una muestra.
- **Grupo de control** opcional para medir el incremento.
- **Estimación de costo antes de enviar:** destinatarios por país × tarifa de Meta por categoría (la tabla `wa_rates` se actualiza cada trimestre), más los correos.
- **Revisión de cumplimiento previa:** cuántos se excluyen y por qué (sin consentimiento, fuera de horario, tope de frecuencia, suprimidos, registro de exclusión).
- **Calendario comercial LATAM** precargado por país: Día de la Madre (mayo; México el 10 de mayo), Amor y Amistad (septiembre, Colombia), Día del Padre, Hot Sale y Buen Fin (México), CyberLunes (Colombia), Black Friday, Navidad, regreso a clases y **pagos de prima en junio y diciembre** (Colombia), con ideas de campaña.
- **Copiloto** (Fase 4): «Haz una campaña de Black Friday para VIP con 20%» propone segmento, canales, textos, horario, reglas del cupón, costo y revisión de cumplimiento. **Nunca envía sin aprobación humana.**

**Implementación (Fase 2):**
- **Estados:** `draft` → `scheduled` (aprobada) → `sending` → `sent`, más `paused` y `cancelled`. La aprobación exige el permiso `manageMarketing`, una cuenta activa del canal y una revisión hecha en ese momento; queda en la auditoría. Editar una campaña aprobada que aún no empieza la devuelve a borrador.
- **Audiencia:** segmentos incluidos (o «todos los contactos») y excluidos, sobre la membresía ya calculada, más reglas.
  - Para excluir: «compró en los últimos N días» y «recibió un mensaje en los últimos N días».
  - Para incluir: «tiene consentimiento del canal» y «nunca compró».
- **Revisión de cumplimiento:** usa `SendPolicy::check` (sin reservar cupos) a la hora planeada de cada contacto y separa a los que **quedan fuera** de los que **se aplazan**. Con más de 3.000 contactos revisa una muestra al azar y extrapola, y lo dice en pantalla.
- **Costo:** WhatsApp se calcula por país del contacto con la tarifa de marketing de `wa_rates` (`WaRateCard`, tabla de Meta vigente desde el 1 de julio de 2025 **[verificar]**). La tabla se carga con `igr:wa-rates --seed` y se revisa el día 2 de enero, abril, julio y octubre (`igr:wa-rates --check` deja un aviso si está desactualizada). El correo se estima con la tarifa de SES **[verificar]**; el SMS depende del proveedor.
- **Envío:** `igr:campaigns:tick` corre cada minuto.
  - Congela la audiencia en `campaign_recipients` al empezar. Con zona del contacto o mejor hora, lo hace 26 h antes.
  - Sortea el grupo de control y la variante de forma determinista por campaña y contacto.
  - Despacha por lotes de 500 con la clave `campaign:{id}:{contacto}`. Lo aplazado se reintenta en la hora que indica la política; lo negado queda con su motivo.
  - **A/B:** la muestra (20% por defecto) recibe las variantes y, pasadas las horas de espera (4 por defecto), el resto recibe la de más clics o ingresos por enviado.
- **Incremento:** compara la conversión (pedido pagado) en los días de la ventana de atribución (`attribution.click_days`, 5 por defecto) entre los destinatarios y el grupo de control, por intención de tratar, con intervalo de confianza del 95%.
- **Calendario comercial:** las fechas se calculan con reglas por año. Hot Sale, Buen Fin y los CyberLunes de Colombia usan la regla de los últimos años **[verificar cada año]**.

---

## M11 — Bandeja de conversaciones y agente de IA · Fase 4

- **Bandeja multiagente** para WhatsApp oficial (Instagram y Messenger en la Fase 5):
  - Asignación por turnos o por etiqueta, estados y notas internas.
  - Respuestas rápidas.
  - Ventana de 24 h visible con un contador; fuera de ella solo se permiten plantillas.
- **Perfil al lado del chat:** pedidos, carrito actual, puntos, segmento, último producto visto y origen (anuncio).
- **Vender desde el chat:** buscar productos y enviarlos como tarjeta, armar un carrito y enviar el **enlace de pago** (crea un pedido pendiente en WooCommerce o un carrito restaurable), y aplicar un cupón.
- Métricas de primera respuesta, tiempo de resolución y ventas por agente.
- **App móvil** como PWA instalable, con notificaciones push.
- **Agente de ventas con IA:**
  - Responde sobre el catálogo, el stock en vivo, envíos, cambios, medios de pago y horarios, a partir de las fuentes que el negocio define.
  - Recomienda productos, arma el carrito o el enlace de pago y agenda citas (servicios).
  - Pasa a un humano por regla o cuando la persona lo pide.
  - **Alcance limitado al negocio**: la política de WhatsApp vigente desde el 15 de enero de 2026 prohíbe asistentes de IA de propósito general.
  - No inventa precios ni stock (siempre consulta) y no promete descuentos fuera de las reglas.
  - Modos `assist` (sugiere y el humano envía) y `auto`.
  - Registro y revisión de cada conversación.
- **Clasificador de mensajes entrantes:** intención (compra, soporte, queja, baja). **Un «no me escriban más», «baja», «STOP» o equivalente revoca el consentimiento de inmediato.**

---

## M12 — Captación en el sitio · Fase 3

- **Tipos de widget:** popup (centro, esquina, pantalla completa), formulario incrustado (bloque de Gutenberg, shortcode y widget de Elementor), barra superior, ruleta o rasca y gana con cupón único, teaser, formulario en 2 pasos (correo → WhatsApp), **quiz o recomendador** y **widget de WhatsApp con atribución**.
- **Disparadores y segmentación:** intención de salida, tiempo, scroll, páginas, dispositivo, UTM o fuente, país, visitante nuevo o recurrente, valor del carrito, segmento conocido (por `anonymous_id`). No se muestra a suscriptores ya identificados ni durante el checkout.
- **Consentimiento por canal** con casillas separadas, sin marcar, y el texto legal versionado. Doble opt-in opcional por correo.
- **Encuesta poscompra «¿Cómo nos conociste?»** en la página de gracias, con opciones configurables. **Se adelanta a la Fase 1** porque alimenta el tablero 12 de M05.
- **Quiz:**
  - Constructor de preguntas con lógica.
  - El resultado son productos recomendados (por reglas sobre categorías, atributos o etiquetas), y las respuestas se guardan como atributos del perfil.
  - Pide el contacto para enviar el resultado.
- **Rendimiento:** el script de widgets carga solo si hay widgets activos, con carga diferida. El widget no causa CLS (se reserva el espacio o es overlay) y respeta el INP.
- A/B por widget, con estadísticas de vistas, envíos, conversión e ingresos atribuidos.

**Cómo quedó implementado (Fase 3):**
- **Hub:** `app/Domain/Onsite` (modelos `OnsiteWidget` `wg_`, `Quiz`, `WidgetCapture`), API de la SPA en `routes/api/onsite.php` y endpoints públicos en `routes/public/onsite.php`; `widgets.json` lo sirve `PublicWidgetsController` junto con la encuesta. Ver: `viewDashboards`; crear, editar y cambiar de estado: `manageMarketing` (con auditoría). Un widget con capturas no se borra: se archiva.
- **Consentimiento:** las casillas salen del texto legal vigente de cada canal (`ConsentTexts`) y se registran con `CaptureContact` (origen `widget:wg_…`, que arranca la bienvenida de la biblioteca). El navegador muestra cada casilla junto al campo de su canal (correo con el correo; WhatsApp y SMS con el teléfono). El doble opt-in envía un correo transaccional por `MessageDispatcher` (plantilla de correo elegida en el widget o texto del sistema, con `{{ enlaces.confirmar }}`); el enlace `/p/{clave}/confirmar/{token}` vence a los 7 días.
- **Cupón:** uno por persona y widget (409 al repetir), creado con `CouponIssuer` (`source_type: widget`). Si el sitio no responde, la captura queda y el cupón se puede pedir otra vez. La ruleta sortea el premio en el hub (las probabilidades nunca salen en `widgets.json`) y se juega una vez por persona.
- **Formulario en 2 pasos:** el segundo paso (mismo widget y navegador, menos de 1 hora) se une al contacto del primero; el cupón sale en el primero.
- **Quiz:** respuestas validadas contra las preguntas; `attribute` de la pregunta y `attributes` de la opción quedan en `contacts.custom_attributes`. Resultado: la primera regla que coincide (IDs, categorías, marcas y precio máximo, solo publicados y con stock, por ventas de 30 días) o el respaldo (`RecommendationService` si M16 lo registró). Con `contact_optional` responde los productos sin pedir datos. **[verificar]** el catálogo no trae etiquetas ni atributos de producto: las reglas no los usan todavía.
- **`visitor.json`:** conocido (por `anonymous_id`), recurrente (perfil de más de 30 minutos, con pedidos, o una sesión anterior en ClickHouse, en caché 10 min), suscrito por canal, segmentos en uso y país del encabezado `CF-IPCountry` de Cloudflare **[verificar]** que la geolocalización de IP esté activa en la zona; sin ella el país es el del perfil o `null`.
- **Límites:** envío 30/min por IP (con hash) y 8/min por navegador; `visitor.json` 240/min por IP y 60 por navegador (`429`).
- **Estadísticas y A/B:** vistas de `widget.shown` (ClickHouse), envíos y contactos nuevos de `widget_captures`, pedidos e ingresos de `orders.attributed_widget_id` (paso 4). Prueba z de dos proporciones sobre la tasa de envío; con «ganadora automática», `igr:onsite:ab-winners` (diario) deja con todo el tráfico a la variante que gana con 95 % de confianza y 100 vistas por variante.

---

## M13 — Fidelización (puntos, VIP, saldo) · Fase 3

- **Reglas para ganar puntos:**
  - Por cada $X gastados, con multiplicadores por nivel o categoría.
  - Por registro, por cumpleaños y por reseña (más con foto).
  - Por referir a alguien.
  - Por completar el perfil (zero-party data).
  - En campañas de puntos dobles.
- **Canje:** cupón de descuento fijo o porcentual, envío gratis, producto gratis, o conversión a saldo. Todo se emite como **cupón único de WooCommerce** por comando firmado.
- **Niveles VIP** por gasto, puntos o pedidos en 12 meses. Beneficios: multiplicador, envío gratis, acceso anticipado, precios por rol de WooCommerce y regalos.
- **Saldo a favor y cashback:** el saldo vive en el hub. Al usarlo, el plugin crea un cupón de un solo uso por `min(saldo, total)`; el saldo se descuenta al pagarse el pedido y se devuelve si se cancela.
- **Antifraude:** los puntos quedan *pendientes* hasta que el pedido se completa y pasa el periodo de devolución; se revierten si hay reembolso.
- **Interfaz en el sitio:** lanzador flotante, sección «Mis recompensas» en Mi cuenta, puntos a ganar en la ficha del producto y el carrito, y una página explicativa del programa generada sola.
- Vencimiento configurable con aviso previo (flujo 15).

**Implementación (Fase 3):**
- **Libro** (`loyalty_ledger`, solo inserción, idempotente por clave): `pending` no cuenta; `confirmed` y `expired` cuentan; una hija `reversed` resta de su pendiente (reembolso o cancelación antes de confirmar, saldo liberado). Confirmar agrega una hija `confirmed` (clave `settle:{id}`). Los puntos se consumen del más viejo al más nuevo (FIFO) y cada abono vence a los N meses de ganado. `contacts.points_balance`, `wallet_balance_ws`, `points_expiring` y `points_expiring_at` quedan al día en cada movimiento.
- **Pedidos:** `order.paid` da puntos pendientes (clave `order:{id}`) sobre el total sin envío (o el total), con multiplicador por categoría, por nivel y por campaña, y en la moneda del espacio de trabajo. Se confirman `pending_days` después de `order.completed` (si la tienda nunca lo completa, 30 días después de pagado más ese periodo); ahí también se acredita el cashback. Un reembolso parcial revierte la parte proporcional; uno total o una cancelación, todo (después de confirmar se resta del saldo y puede quedar en negativo). Los pedidos importados no dan puntos.
- **Niveles:** el de mayor rango cuyo umbral alcanza en 12 meses (gasto neto de reembolsos, puntos ganados o pedidos pagados), recalculado con cada pedido y cada noche (también bajan). Si cambia: `tier.changed` y `customer.set_tier` a cada sitio donde el contacto tiene cuenta. Bajar a «sin nivel» no envía comando (se recomienda un nivel base desde 0).
- **Canje:** `/p/{key}/me/redeem` resta los puntos y crea el cupón (`source_type: loyalty`, código `PUNTOS-…`); si la tienda no lo crea, los puntos vuelven. «Producto gratis» es un descuento fijo hasta su valor, válido solo con ese producto en el carrito. La recompensa de saldo acredita la billetera.
- **Saldo en el checkout** (`/v1/s/wallet/redeem`): cupón `SALDO-…` de un solo uso por `min(saldo, total)`, combinable con otros cupones y restringido al correo del cliente; el mismo carrito recibe el mismo cupón. El débito queda pendiente; se confirma con el `order.paid` que lo usa y se libera a las 24 h (desactivando el cupón con `coupon.disable`) salvo que lo use un pedido aún sin pagar.
- **Flujo 15 «Puntos por vencer»:** disparador por fecha sobre `contacts.points_expiring_at` (la fecha del próximo vencimiento) con `offset_days = −warning_days` (15 por defecto); `points_expiring` trae los puntos que vencen ese día. El job nocturno `igr:loyalty:nightly` vence lo cumplido (`points.expired`), da los puntos de cumpleaños, pasa el saldo de los contactos fusionados y recalcula niveles; `igr:loyalty:settle` (cada 15 min) confirma los periodos cumplidos y libera saldos.
- **Nodos de flujo** «dar puntos» y «dar saldo» (una vez por visita del nodo; el grupo de control no recibe nada).
- Los puntos por referir los configura el programa de referidos (M14), que los acredita con `earnPoints(…, 'referral', …)`: el programa de puntos no tiene un monto propio para eso.

---

## M14 — Referidos, afiliados e influencers · Fase 3

- **Referidos:**
  - Enlace y código únicos por cliente, para compartir por WhatsApp con un mensaje prellenado.
  - Recompensa doble: el amigo recibe un descuento en su primera compra; quien refiere recibe puntos, saldo o cupón cuando la compra del amigo se completa.
  - **Antifraude:** mismo correo, teléfono, dirección o huella de dispositivo con hash; autorreferido; compra mínima; límite mensual; revisión manual de casos sospechosos.
- **Afiliados e influencers:**
  - Código de cupón y enlace propio, con atribución por cualquiera de los dos.
  - Comisión porcentual o fija por pedido completado.
  - **Panel propio del creador** (enlace mágico, sin contraseña) con ventas, comisiones y material.
  - Exportación para pagos (manuales en la v1).
- Reportes: ingresos por referidos y afiliados, costo de adquisición por referido y la mejor campaña de cada creador.

**Cómo funciona (Fase 3):**
- **Programa:** uno por espacio de trabajo (borrador, activo o en pausa).
  - Regalo del amigo: cupón de primera compra de un solo uso, solo con su correo (`AMIGO-…`).
  - Recompensa de quien refiere: puntos (requiere el programa de M13 activo), saldo a favor o cupón (`GRACIAS-…`).
  - Reglas: compra mínima, límite de recompensas al mes por cliente, periodo de devolución (15 días por defecto), calificar al completarse o al pagarse, revisión manual de sospechosos y el texto para compartir con `{regalo}`, `{tienda}`, `{enlace}` y `{codigo}`.
- **Código:** se crea la primera vez que se pide (en «Mis recompensas» o al compartir). Son hasta 4 letras del nombre más 3 caracteres al azar (`ANA7K2`), único entre clientes y afiliados del espacio de trabajo.
- **Estados del referido:** `signed_up` (dejó sus datos con el código) → `ordered` (primera compra pagada, atribuida en el paso 3 de AttributeOrder) → `qualified` (completada y pasó el periodo de devolución; emite `referral.converted`) → `rewarded`.
  - Pasa a `rejected` si el pedido se cancela o reembolsa, si no llega a la compra mínima o si quien refiere ya llegó a su límite del mes.
  - Si el amigo llegó solo con el enlace y compró sin dejar sus datos, el referido se crea al atribuir el pedido.
  - Una persona solo se puede referir una vez y solo cuenta su primera compra.
- **Antifraude:**
  - Autorreferido (mismo contacto, correo o teléfono): ni regalo ni referido.
  - Señales que mandan a revisión: mismo dispositivo (`anonymous_id`), misma IP (con hash, 30 días) y mismo destinatario del pedido (nombre y ciudad con hash: el contrato del pedido no trae la dirección de la calle).
  - En revisión, el amigo conserva su regalo y la recompensa espera la decisión del equipo.
  - Las huellas se guardan solo como HMAC en `referral_fingerprints`.
- **Afiliados:** sus cupones (los crea el comerciante en WooCommerce, o el hub con `coupon.create` de hasta 1.000 usos) y su enlace `?ref=CODIGO`.
  - Atribución: cupón de afiliado > cupón de amigo > `referral_code` del último contacto > referido ya registrado. Nunca a los dos.
  - Comisión porcentual sobre el neto (productos sin envío ni impuestos) o el total, o fija por pedido.
  - Estados: `pending` → `approved` (pasado el periodo de devolución) → `paid` (exportación CSV y «marcar pagadas»), o `void` si se cancela o reembolsa antes de pagarse.
  - Panel del creador `/creadores/{token}`: enlace mágico de 30 días que llega a su correo como mensaje transaccional. Enviar uno nuevo invalida el anterior, y si vence la persona pide otro, que solo llega a su correo registrado. Muestra sus ventas, comisiones por estado, enlaces, cupones, material, campañas y pedidos, sin datos de los compradores.
- **Permisos:** ver, `viewDashboards`. Configurar, revisar y administrar afiliados, `manageMarketing`. El correo del afiliado, `viewAllContacts`. Datos de pago, pagos y exportación, `viewPaymentData`.
- `igr:referrals:qualify` (cada hora) califica, recompensa y aprueba comisiones.

---

## M15 — Reseñas, NPS y prueba social · Fase 3

- **Solicitud automática** tras la entrega o N días después, por WhatsApp o correo. El formulario es de 1 toque (estrellas) y permite subir fotos o video, sin iniciar sesión gracias a un enlace firmado.
- **Recompensa** por reseña y otra mayor por foto o video.
- **Moderación:** cola de pendientes, filtro de lenguaje ofensivo y spam con IA, respuesta del dueño y publicación.
- **Publicación en WooCommerce:** sincroniza con las reseñas nativas (con la meta de comprador verificado y los medios), así el tema y los datos estructurados funcionan sin cambios. Además hay widgets propios: carrusel, galería de fotos, insignia de estrellas y página de todas las reseñas.
- **Resumen con IA** por producto («Lo que dicen los clientes»), visible en la ficha y regenerado cada semana.
- **Reseñas de la tienda** y **solicitud de reseña en Google Business Profile** con un enlace directo. **Se pide a todos los clientes por igual: filtrar a los satisfechos antes de pedir la reseña de Google (review gating) va contra las políticas de Google.**
- **NPS y CSAT** con alertas de detractores (flujo 17).
- **Importación** desde las reseñas de WooCommerce, CSV o exportaciones de Judge.me.

**Cómo quedó (Fase 3):**
- **Solicitud:** se programa al llegar `order.completed` (o `order.paid`, según los ajustes), una por pedido, para dentro de N días; con días por categoría gana la que más espera. Sale por `MessageDispatcher` con canal de respaldo (WhatsApp → correo, en el orden elegido) y emite `review.requested`. Es **marketing** por defecto (cumplimiento.md §4.1): reclasificarla como utilidad exige ser dueño o administrador y una justificación, que queda en la auditoría. No se pide lo que el cliente ya reseñó. La plantilla de correo por defecto es `review_request` de M08; la de WhatsApp la elige el comerciante entre las aprobadas.
- **El flujo 8 «Pedir reseña» es el propio módulo:** su motor es la solicitud de Reseñas → Ajustes (no un flujo del constructor), para no pedir dos veces lo mismo. La tarjeta de la biblioteca abre esos ajustes.
- **Formulario `/r/{token}`:** firmado por solicitud, pedido y contacto (vence con el enlace, 60 días por defecto), sin sesión. Una reseña por producto y otra opcional de la tienda. Fotos (JPG, PNG o WebP de hasta 10 MB) y un video (MP4 o MOV de hasta 20 MB), máximo 5 archivos: las fotos se enderezan, se reducen a 1.600 px y se vuelven a codificar sin metadatos (ni EXIF ni GPS), con miniatura cuadrada; al video se le borran las cajas `udta` y `meta` (ubicación y modelo) sin mover sus bytes. Los archivos viven en un disco privado y se sirven por `/r/m/…` solo si la reseña está publicada (o con URL firmada en la moderación).
- **Recompensa:** los puntos los define el programa de fidelización (`review` y `review_media`, que es el extra por foto o video); quedan pendientes al recibir la reseña, se confirman al publicarla y se revierten si se rechaza. Solo para compradores verificados que llegaron por una solicitud.
- **Moderación:** el filtro por reglas marca lenguaje ofensivo, spam y datos personales (correo, teléfono, documento, dirección); en la Fase 4 se suma la IA en el mismo punto (`ReviewClassifier`). Con moderación automática se publica lo que no tiene banderas, **con cualquier calificación**. Se rechaza solo por el contenido (ofensivo, spam, datos personales, fuera de tema, duplicada, otro), nunca por la nota.
- **WooCommerce:** al aprobarla, `review.publish` (comprador verificado y medios como URL pública) y se guarda el `comment_id`; al rechazar una publicada, `review.unpublish`; la respuesta del dueño va con `review.reply`. Las nativas llegan por `review.submitted` (en vivo, y las importadas con `igr:reviews:project-store`) sin el texto: cuentan para el promedio y las insignias, y el tema las sigue mostrando.
- **Google:** la página de gracias ofrece el enlace de Google Business Profile a **todos**, sin mirar la calificación.
- **NPS y CSAT:** una encuesta de cada tipo, pausadas al empezar, N días después de la entrega por los mismos canales (plantilla `nps_survey` para el NPS; el CSAT necesita una elegida). `/r/nps/{token}` muestra la nota preseleccionada del enlace pero **solo guarda al enviar** (los antivirus del correo abren los enlaces). Emite `nps.answered`; con una nota en el umbral o por debajo (6 en NPS, 2 en CSAT) avisa a los dueños por correo (`team_alert`, utilidad) y deja una tarea en el perfil. El tablero muestra la nota, la distribución, la evolución semanal y las respuestas recientes (los comentarios, solo a quien ve contactos). El flujo 17 queda cubierto por el módulo; `nps.answered` (con `category`) sirve de disparador para el flujo 16 (invitar a referir a los promotores).
- **Importación:** CSV propio (columnas en español o inglés) o exportación de Judge.me (`curated` → estado). El correo solo une la reseña con el contacto y marca la compra verificada; no se guarda. No duplica al reimportar (`import_key`) ni da puntos.
- **Pendiente:** el resumen con IA por producto (Fase 4) y el texto de las reseñas nativas de WooCommerce (el evento no lo trae; ver la propuesta en el cierre de la fase).

---

## M16 — Comercio en el sitio · Fase 3

- **Recomendaciones** (bloque, shortcode y widget de Elementor):

| Bloque | Lógica |
|---|---|
| «Para ti» | personalizado por afinidad e historial |
| «Comprados juntos frecuentemente» | reglas de asociación |
| «Vistos recientemente» | historial del visitante |
| «Más vendidos de esta categoría» | ventas de la categoría |
| «Tendencia esta semana» | ventas recientes |
| «Completa tu compra» | en el carrito |

  Se cargan por JS desde un endpoint que se puede cachear en CDN y no afectan la caché de página.
- **«Avísame cuando vuelva»** en productos agotados y **«Avísame si baja de precio»**: botón en la ficha, con captura de correo o WhatsApp y consentimiento.
- **Banners personalizados por segmento:** VIP («Hola, Ana: tienes 1.250 puntos»), con carrito pendiente o en riesgo. Se cargan por JS y los datos sensibles solo se muestran con sesión iniciada.
- **Carrito restaurable** entre dispositivos (`/igr-cart/{token}`), que usan los flujos y la bandeja.
- (Opcional, Fase 5) Ofertas en el checkout y después de la compra.

**Implementación (Fase 3, `app/Domain/Recommendations`):**
- **Motor** (`RecommendationEngine`, registrado como `RecommendationService`): solo productos publicados, no borrados, con stock o bajo pedido y sin variaciones; nunca el producto de la ficha, lo que ya está en el carrito ni lo excluido.
  - «Para ti»: afinidad por categoría (compra 5, carrito 3, vista 1, vida media de 30 días; si existe `contacts.category_affinity` de la Fase 4, manda esa) + popularidad + «comprados juntos» de lo último que compró o vio, sin lo que ya compró.
  - «Comprados juntos» y «Completa tu compra»: reglas de `product_associations` por lift. «Vistos recientemente»: `product.viewed` de ClickHouse por sus navegadores o su contacto (90 días). «Más vendidos de esta categoría»: unidades de 90 días (sin categoría, la de la ficha o la tienda). «Tendencia»: unidades de 7 días ÷ (promedio semanal de las 4 anteriores + 1), con 2 unidades mínimo.
  - **Sin señal** (sin historial, sin reglas, sin ventas recientes) se usan los más vendidos (de la categoría cuando se conoce) y la respuesta lo dice con `fallback: true`. «Vistos recientemente» sin historial responde vacío y el bloque se oculta.
  - Jobs: `igr:reco:build` cada noche (popularidad y reglas de 12 meses: mínimo 3 pedidos juntos, confianza ≥ 5% y lift > 1, hasta 20 por producto) y `--stats-only` cada 3 horas (`products.purchases_7d/30d/90d`, `views_30d`, `trend_score`). Un sitio que nunca se calculó se calcula en la primera petición.
  - Las plantillas (`recomendaciones.para_ti`, `mas_vendidos` y `tendencia`) usan el mismo motor con el primer sitio conectado.
- **`GET /p/{key}/reco`:** caché pública de 60 s para `bought_together`, `best_sellers_category` y `trending` (y 60 s en el hub) y privada para los personales con `aid`; límite por IP con hash. Responde `{kind, products, fallback}`.
- **«Avísame»** (`POST /p/{key}/watch`): `CaptureContact` con origen `watch:{producto}`; acepta `consent_version` opcional (se registra la versión vigente). El aviso es marketing (cumplimiento.md §4.1, por prudencia): hace falta el consentimiento del canal (marcado ahora o uno que ya tenía) y se elige WhatsApp, correo o SMS en ese orden. Con stock ya disponible responde `available: true` sin suscribir.
  - `QueueWatchNotifications` escucha `catalog.stock_changed`, `catalog.price_changed` y `catalog.product_upserted` y, si alguien espera ese producto, encola `NotifyProductWatchesJob`; `igr:watches:notify` (cada 10 min) retoma lo aplazado. El mensaje sale por `MessageDispatcher` con la clave `watch:{id}` y la variable `producto` (con `precio_anterior` en las bajas de precio); si la política lo aplaza, la suscripción guarda `next_attempt_at`.
  - Una suscripción se avisa una sola vez y a una persona no se le avisa del mismo producto más de una vez cada 7 días. Plantillas: las elegidas en el panel por tipo y canal; sin elegir, `back_in_stock`, `price_drop` y `sms_back_in_stock` de la biblioteca. WhatsApp necesita una plantilla aprobada elegida. Si todos los canales con plantilla niegan para siempre (sin consentimiento, baja, supresión), se cancela; vence a los 120 días.
  - El flujo 14 de la biblioteca no envía el primer aviso (lo hace el módulo, al momento): queda para un recordatorio a quien recibió `product_watch.notified` y no compró.
  - El panel muestra las ventas del producto de la misma persona en los 7 días siguientes al aviso.
- **Banners** (`onsite_banners`, `GET /p/{key}/banner`): audiencias `points` (con `LoyaltyLedger`), `pending_cart` (carrito activo o abandonado de 7 días; sin sesión, solo el del mismo navegador, con el enlace para restaurarlo), `at_risk` (RFM «En riesgo» y «No podemos perderlos») y `segment`. Gana el primero por prioridad. `{{nombre}}` y `{{puntos}}` solo con `igrUser.token` válido; sin sesión se usa `text_public`, que el panel no deja guardar con esas variables.
- **Panel:** «En el sitio → Recomendaciones» (bloques, estado del cálculo, vista previa con un producto, carrito o contacto real y explorador de reglas), «Avisos» y «Banners».

---

## M17 — Inteligencia e IA · Fases 1 → 4

Especificación completa en `docs/inteligencia.md`.

| Fase | Entrega |
|---|---|
| 1 | métricas, canales, RFM y primeros hallazgos (5 detectores) |
| 2 | mejor hora de envío, atribución de mensajes e incremento |
| 4 | predicciones (próxima compra, abandono, CLV), afinidad, todos los detectores, analista semanal con IA, «Pregúntale a tus datos», copiloto, resúmenes de perfil y reseñas |

---

## M18 — Servicios y leads · Fase 5 (captura de formularios desde la Fase 1)

**Objetivo:** que un sitio corporativo convierta visitas en clientes con la misma potencia que una tienda.

- **Captura** de formularios de cualquier plugin con mapeo de campos, y deduplicación con WhatsApp y correo.
- **Puntuación de leads:** reglas más comportamiento, con decaimiento (ver `inteligencia.md` §4.9). Calificación con IA opcional a partir del mensaje del formulario.
- **Aviso al vendedor al instante** por WhatsApp: «Lead nuevo (puntaje 72): Ana Ruiz, Bogotá, interesada en Asesoría laboral. Vino de Google Ads, vio Precios 3 veces», con botón para escribirle.
- **Pipeline simple** (nuevo → contactado → calificado → propuesta → ganado o perdido), o envío a Imagina CRM, Imagina Base o Imagina Proposals.
- **Conversiones offline:** cuando un lead pasa a «ganado», la conversión se envía a Google (Data Manager API) y a Meta con su valor, para que los anuncios optimicen hacia leads que se convierten en clientes, no hacia formularios.
- **Agenda de citas:** servicios, disponibilidad por persona, sincronización con Google Calendar, página de reserva con el dominio del cliente, recordatorios (flujo 19) y no asistencias.
- **Cotizador genérico:** constructor de calculadoras por servicio, metros o paquetes. Muestra un precio estimado a cambio del contacto y genera el evento `quote.requested`. Reutiliza la lógica de la calculadora de pintura.

---

## M19 — Integraciones, API y MCP · Fases 2 → 5

- **Ecosistema Imagina:**

| Producto | Integración |
|---|---|
| Imagina Search | búsquedas, búsquedas sin resultados, sinónimos sugeridos |
| Imagina CRM / Imagina Base | leads y tareas |
| Imagina Reports | KPIs de Growth en el reporte del cliente |
| Imagina Proposals | lead → propuesta |
| Imagina Turbo | Web Vitals por página para el detector de lentitud que hace perder ventas |

- **Microsoft Clarity:** se envía el `contact_id` como ID personalizado para abrir la grabación de la sesión desde el perfil (por ejemplo, del cliente que abandonó el checkout).
- **Webhooks salientes** firmados por evento; compatibles con Zapier, Make y n8n.
- **API pública REST** (`/api/v1`) con tokens por espacio de trabajo y alcances, OpenAPI generado (Scramble) y límites por plan.
- **Servidor MCP** (Laravel MCP) para que el dueño o la agencia consulten sus datos desde Claude u otros clientes MCP. Herramientas de lectura: KPIs, consultas de métricas, segmentos y contacto. Herramientas de escritura limitadas (crear borrador de campaña o segmento) que **siempre requieren confirmación en la app**.

---

## M20 — Cumplimiento y privacidad · Fases 0 → 2

Especificación completa en `docs/cumplimiento.md`. Incluye:
- Registro de consentimiento con evidencia.
- **Motor de política de envío** (consentimiento, ventana legal por país con festivos, frecuencia, supresión y registros de exclusión).
- Centro de preferencias.
- Derechos ARCO y habeas data con exportación y borrado de punta a punta.
- Retención, auditoría y cifrado.
- Integración con banners de cookies y Consent Mode v2.
- Plantillas de cláusulas para la política de privacidad del cliente y contrato de encargo del tratamiento.

---

## M21 — Planes, uso y facturación · Fase 5

- Planes por **pedidos al mes** (tiendas) o **contactos activos** (servicios), con módulos activables por plan (Pennant).
- Contadores de uso en tiempo real, alertas al 80% y 100%, y sin cortes bruscos: periodo de gracia y notificación.
- Los mensajes de WhatsApp los paga cada cliente a Meta en su propia cuenta; el correo va incluido hasta un volumen y después hay paquetes.
- En la v1 la facturación es manual o con un enlace de pago. Se integra con el sistema de cobros que se elija después.

---

## M22 — Panel de agencia · Fase 5

- Vista de todos los clientes: salud de la conexión, ingresos atribuidos del mes, hallazgos críticos, uso y vencimientos.
- **Plantillas maestras:** copiar flujos, segmentos, widgets y plantillas de un cliente a otro, o desde una biblioteca de la agencia.
- **Reporte mensual con marca** (Imagina o marca blanca) enviado solo a cada cliente.
- Acceso de soporte con registro (entrar como admin a un espacio de trabajo deja auditoría).
- Marca blanca: dominio propio, logo y correo remitente de la agencia.
