# Auditoría integral — noviembre de 2026

Revisión de la primera instalación real (Ubuntu 26.04, 1,6 GB de RAM, un solo dominio) en cinco frentes:
seguridad, rendimiento, funcionalidades, interfaz y estrategia, más las conexiones con terceros. Este documento es la
lista de trabajo: cada hallazgo tiene prioridad, dónde está y su estado. Se actualiza en el mismo commit que corrige
cada punto.

**Prioridades:** P0 = puede perder datos, dinero o la confianza del cliente; corregir ya. P1 = rompe una función
prometida o el servidor pequeño. P2 = mejora clara de uso o de costo. P3 = pulido.

**Estado:** ✅ corregido (versión) · 🔧 en curso · ⏳ pendiente.

---

## 1. Seguridad

| # | P | Hallazgo | Dónde | Estado |
|---|---|---|---|---|
| S1 | P0 | La ruta pública del tracker (`/v1/b`, con la clave pública que está en el HTML) aceptaba cualquier evento con `source.type = browser`: pedidos, clientes, formularios y reseñas falsas que se proyectaban como reales (ventas, contactos con consentimiento, reseñas «verificadas», puntos y conversiones a Meta). | `Ingest/Http/EnvelopeChecker.php`, `Ingest/Pipeline/IngestProcessor.php` | ✅ 0.1.6: solo eventos de navegación (`BrowserEvents`), segunda barrera en el worker, fechas futuras corregidas y del navegador nada de hace más de 7 días |
| S2 | P1 | El actualizador corre como root y usa `shared/tmp` y `shared/state`, que también escribe el usuario `growth` (carrera de enlaces simbólicos y `chown` por ruta); acepta bajar de versión sin avisar. | `infra/bin/growth-update` | ✅ (lote 3): descargas en `/var/lib/growth-update` (solo root), estado e historial de root con temporal + rename en un `shared/state` sticky, sin `chown` por ruta, y rechaza versiones anteriores |
| S3 | P1 | Registro abierto y «sin plan = todo»: cualquiera crea una cuenta con todos los módulos, IA (tope de USD 25) y envío por SES a cargo de la plataforma. | `config/fortify.php`, `PlanResolver`, `AiMeter` | ✅ (lote 3): 0.1.7 dejó el registro solo por invitación; ahora sin plan rige el plan gratuito «Diagnóstico» (`IGR_DEFAULT_PLAN`, por defecto `diagnostico`: analítica, segmentos, RFM y hallazgos, IA de USD 1 y sin correo por el SES de la plataforma, `emails = 0`), editable en Plataforma → Planes. Las cuentas propias del superadmin tienen el plan `plataforma` (todo, sin topes): la migración `2026_11_25_300100` lo asigna a las existentes, `CreateWorkspace` a las que crea y `igr:admin:create` al promover |
| S4 | P1 | Envenenamiento del encabezado `Host` en el restablecimiento de contraseña: `trustProxies('*')`, sin `trustHosts`, y Caddy responde cualquier nombre. | `bootstrap/app.php`, `infra/caddy/Caddyfile` | ✅ 0.1.7: el enlace de restablecer usa siempre `APP_URL` |
| S5 | P2 | SSRF: URLs de webhooks, `rest_url` del sitio, Zender y SMTP sin bloquear redes internas; `SafeUrl` solo mira IPv4. | `WebhookHandler`, `SafeUrl` | ✅ (lote 3): `App\Support\Net` (`IpAddress` con rangos explícitos de IPv4 e IPv6, mapeadas, NAT64, 6to4 y Teredo; `SafeUrl`; `SafeHttp`): el DNS se resuelve al conectar, todas las IP deben ser públicas y se conecta a la IP revisada (CURLOPT_RESOLVE; en SMTP, a la IP con `peer_name`), sin redirecciones. Webhooks, nodo webhook de flujos, `rest_url` y `site_url` del handshake, comandos al sitio, Zender, SMTP y el tono de marca |
| S6 | P2 | Webhooks de SES sin verificar `TopicArn`. | `Messaging/.../SesWebhook` | ✅ (lote 3): `SnsMessageVerifier` exige que `TopicArn` sea uno de `IGR_SES_SNS_TOPIC_ARN` (varios separados por comas; sin tema configurado se rechaza todo), que la región del tema sea la del certificado, `SignatureVersion` 1 o 2 y un tipo conocido; el certificado solo se guarda en caché si la descarga salió bien. La firma ya se verificaba |
| S7 | P2 | Los registros de Caddy guardan tokens de consulta y `Set-Cookie`. | `infra/caddy/Caddyfile` | ✅ (lote 4): la consulta se borra entera, los tokens de las rutas públicas quedan como `***` y sin `Set-Cookie`, `Location`, `Referer`, `CF-Connecting-IP` ni `X-Forwarded-For`; `growth-system` pone el Caddyfile al día en cada actualización si nadie lo editó a mano (despliegue.md §7) |
| S8 | P2 | Rangos de Cloudflare fijos en la configuración de proxies de confianza. | `bootstrap/app.php` | ⏳ |
| S9 | P2 | El release se publica desde la rama sin esperar a CI. | `.github/workflows/release.yml` | ✅ (lote 3): el release corre la CI completa (`workflow_call`) y solo publica en verde |
| S10 | P2 | Inyección de fórmulas en la exportación CSV de segmentos (`=`, `+`, `-`, `@`). | `SegmentController.php:154-160` | ✅ 0.1.7: `App\Support\Csv` en las dos exportaciones |
| S11 | P2 | Cambio de correo de la cuenta sin pedir la contraseña. | perfil de usuario | ✅ (lote 3): `PUT /auth/user/profile-information` pide `current_password` cuando cambia el correo (422 si falta o no coincide; cambiar solo el nombre no la pide) y deja `user.email_changed` en la auditoría con los correos enmascarados. La SPA hoy no tiene formulario de perfil |
| S12 | P3 | Sin `frame-ancestors` en la CSP del panel. | middleware de encabezados | ✅ 0.1.7: `FrameGuard` en las respuestas web (las páginas con política propia la conservan) |
| S13 | P3 | El usuario `igr` de ClickHouse tiene más privilegios de los que usa. | `infra/install.sh` | ✅ (lote 3): sin `access_management` ni `named_collection_control`; los usuarios los escribe `growth-system` en cada actualización |
| S14 | P3 | La firma HMAC no cubre el método ni la ruta. | `docs/api-connector.md` §2 | ⏳ (cambio de contrato) |
| S15 | P3 | Horizon sin exigir 2FA; IP en el payload de `DeliverConversion` (queda en `failed_jobs`). | `HorizonServiceProvider`, `DeliverConversion` | ✅ (lote 3): `viewHorizon` exige superadmin con 2FA (si `IGR_REQUIRE_2FA`); `DeliverConversion` ya iba cifrado y ahora también `AttributeOrder` (identificadores de clic y anónimo), `FetchSocialProfile` (PSID), `InvitationMail` (token y correo), `MonthlyReportMail` y `WeeklyReportMail` (destinatarios). Una prueba revisa el payload real en Redis y otra que todo trabajo con parámetros sensibles sea `ShouldBeEncrypted` |
| S16 | P3 | `/health` público con detalle de dependencias. | `routes/ingest.php` | ✅ (lote 3): en público `/health` y `/health/ready` solo dicen `{"status":"ok"\|"degraded"}` (200/503, `no-store`); el detalle (dependencias, streams, versión) sale desde la máquina (127.0.0.1 o ::1 sin encabezados de proxy: así lo usa `growth-update`) o con `IGR_HEALTH_TOKEN` en `X-IGR-Health-Token` o `Authorization: Bearer` (`HealthAccess`) |

## 2. Rendimiento y estabilidad

| # | P | Hallazgo | Dónde | Estado |
|---|---|---|---|---|
| R1 | P0 | El stream de ingesta en Redis nunca se recortaba (XACK no borra), con tope de 1 M por shard, dedupe de 7 días, `noeviction` sin `maxmemory` y RDB + AOF: en un servidor de 1,6 GB termina en OOM. | `IngestWorkCommand`, `IngestStream`, `infra/config/redis/redis-queue.conf` | ✅ 0.1.6: el worker recorta lo confirmado cada 5 s, tope de 250 k por shard, dedupe de 4 días, `maxmemory` del 20% de la RAM, sin RDB y aviso de `growth:monitor` al 85% |
| R2 | P0 | Un lote con un evento dañado se reintentaba para siempre: `HY000` (SQLSTATE genérico, también de errores de datos) se trataba como error pasajero. | `IngestProcessor::isTransient` | ✅ 0.1.6: se mira el código del motor; si el lote falla se reintenta uno por uno y el que falla 5 veces con la base disponible va a `ingest_rejections` |
| R3 | P1 | Con ClickHouse caído el worker reprocesaba lo pendiente en bucle cada segundo. | `IngestWorkCommand::flush` | ✅ 0.1.6: espera creciente hasta 30 s |
| R4 | P1 | `HubEvents` inserta en ClickHouse de forma síncrona dentro de peticiones web (webhooks, clics en `/c/`); `DetectAbandonedCarts` marca antes de emitir. | `HubEvents`, `DetectAbandonedCarts` | ✅ (lote 3): `HubEventWriter`: dentro de una petición web las filas van a la cola `projections` (`WriteHubEventRows`, cifrado, reintentos 24 h, lotes de 500); en consola se insertan en el momento y, si ClickHouse falla, pasan a la cola sin tumbar a quien emite (`IGR_HUB_EVENTS_WRITE`). `DetectAbandonedCarts` emite primero, con un event_id determinista (UUID v5 del carrito y su última actividad), y marca después |
| R5 | P1 | Perfil pequeño: siguen 2 réplicas de ingesta, FrankenPHP con hilos por defecto, ML con más de un worker, sin `MemoryHigh` ni `OOMScoreAdjust`. | `infra/install.sh`, unidades systemd | ✅ 0.1.7: 2 hilos de PHP por proceso y un proceso de ML en el perfil pequeño, y prioridades ante falta de memoria (las dos réplicas de ingesta se quedan: permiten reiniciar sin cortar) |
| R6 | P1 | Trabajos de 300 s en la conexión `redis` con `retry_after` 90: se ejecutan dos veces (`SendMonthlyReportJob`, `SendCampaignBatch`, `NotifyProductWatchesJob` y el agente de IA, de 120 s). | `config/queue.php`, jobs | ✅ 0.1.7: `retry_after` de 330 s y una prueba que revisa el timeout de cada job contra su conexión |
| R7 | P2 | `SessionsBuilder` reinserta cada sesión ~15 veces. | `Analytics/SessionsBuilder` | ✅ (lote 3): solo inserta la sesión nueva o cambiada (huella de las columnas contra `sessions`, sin FINAL); con la tienda demo y el programador cada 10 min, de 12,9 a 1,0 escrituras por sesión |
| R8 | P2 | Cada `page.viewed` reevalúa todos los segmentos. | evaluación de segmentos | ✅ (lote 3): cada evento anota solo los segmentos que dependen de él (`PendingSegmentEvaluation`) y el job reevalúa esos; en la demo, de 17 segmentos y 22 consultas por `page.viewed` a 1 segmento y 5 consultas |
| R9 | P2 | La clave de caché del tablero incluye `max(last_event_at)`: con tráfico nunca hay acierto. | `MetricQueryRunner::remember` | ✅ 0.1.7: versión redondeada al minuto |
| R10 | P2 | Informe semanal en serie y `isDue` con `hour === 7` (se salta si el scheduler se atrasa). | `WeeklyReport` | 🔧 0.1.7: sale el lunes desde las 7 en la primera corrida y una sola vez por semana; sigue en serie |
| R11 | P2 | `widgets.json` sin caché. | endpoint público de widgets | ✅ (lote 3): `WidgetsCache` guarda la respuesta 5 min por sitio, tipo de página y ruta (`ws:{id}:widgets:…`), con una versión por espacio de trabajo que se renueva al guardar o borrar un widget, un quiz, una encuesta o un texto legal; si la caché falla, se arma en el momento |
| R12 | P2 | Índices faltantes en consultas frecuentes; paginación por `OFFSET`; escaneos por `received_at`. | varias | ✅ (lote 3): índices para las tareas de cada minuto y cada 5 minutos (carritos, mensajes aplazados, reintentos y purga de webhooks, reseñas, encuestas, citas; con 300.000 filas, de ~90 ms a 0,5 ms), `chunkById` en campañas, audiencias, niveles y la exportación de segmentos, y un índice minmax de `received_at` en ClickHouse |
| R13 | P2 | Uso de facturación cada 5 minutos para todos los espacios. | scheduler de Billing | ✅ (lote 3): cada 5 min `igr:billing:usage --changed` recalcula solo los espacios con eventos nuevos de sus sitios (`sites.last_event_at` posterior a su último cálculo) o con mensajes enviados o IA usada (`UsageActivity`, conjunto de IDs en Redis); cada hora, todos. El cálculo sigue siendo completo desde las tablas de origen, y en los primeros 3 días del mes también se recalcula el mes anterior (sin avisos), así lo facturado incluye los últimos minutos y los pedidos que llegaron tarde |
| R14 | P2 | Sin purga de retención de datos viejos. | — | ✅ (lote 3): `igr:retention:purge` (cada noche, 03:25, por lotes de 5.000; `--dry-run`): `ingest_rejections` 7 días (estaba configurado pero nadie lo purgaba), `failed_jobs` 30, `job_batches` terminados 7, códigos de conexión vencidos o usados 7, invitaciones sin aceptar 30 después de vencer, tokens de restablecimiento 1, `flow_run_steps` de recorridos terminados 180 y `ai_agent_turns` 180 (`igr.retention`); `conversion_deliveries` de ClickHouse con TTL de 90 días. Los mensajes (13 o 24 meses según el documento) quedan pendientes: necesitan antes el archivo agregado |
| R15 | P2 | `RecomputeSegment` y RFM cargan demasiado en memoria. | Segments, Intelligence | ✅ (lote 3): membresía y diferencias en tablas temporales de MariaDB con eventos por páginas de 500; RFM en una tabla temporal y escrito por lotes. Con 100.000 contactos: el segmento de todos pasa de 53 MB a 0,1 MB de pico, y el RFM, que fallaba (más de 65.535 marcadores), termina con 0,3 MB |
| R16 | P3 | Sin Octane / modo worker de FrankenPHP. | `infra` | ⏳ |
| R17 | P1 | En la prueba con 1,6 GB (Ubuntu 26.04), ClickHouse ocupa ~580 MB de RSS en reposo pese al tope del 25 %, y el contenedor queda al 94 %: bajo presión el kernel mató el ML (como se buscaba) y se reinició solo. | `infra/bin/growth-system` (`growth-memory.xml`) | ✅ (lote 3): en el perfil pequeño, sin caché sin comprimir y caché de expresiones de 16 MB. Medido: ClickHouse usa ~165 MB de memoria anónima; el resto de su RSS son páginas del binario que el kernel recupera. Los pools de fondo no se tocan (con menos, la 25.8 no arranca) y si ClickHouse no vuelve con una configuración nueva, se restaura la anterior |

## 3. Funcionalidades

| # | P | Hallazgo | Dónde | Estado |
|---|---|---|---|---|
| F1 | P0 | «Dinero generado» del inicio fijo en $0. | `OverviewDashboard.php:31` | ✅ 0.1.7: usa `MoneyGenerated` (la regla del reporte mensual) con el desglose por módulo y, en cero, el camino al flujo de carrito abandonado |
| F2 | P0 | `MAIL_MAILER=log` en producción: las invitaciones no salen y nadie se entera. | `.env`, plataforma | ✅ 0.1.6: enlace de invitación para copiar, aviso de correo apagado y correo de prueba |
| F3 | P1 | Webhooks de Meta (WhatsApp) responden 403 sin `IGR_META_APP_SECRET`. | `WebhookController` | ✅ (lote 3): `igr:doctor` y Plataforma → Instalación lo marcan como error si hay cuentas de Meta conectadas (aviso si no), con dónde sacar el secreto, y también el token de verificación y la URL del webhook |
| F4 | P1 | Falta la captura temprana del checkout (`checkout.contact_captured`). | tracker, plugin | ✅ (lote 3): la captura el plugin en el servidor (clásico y bloques, aviso junto al campo, debounce, una vez por carrito y dato) y viaja firmada en el contrato v6; el hub la rechaza sin la opción del espacio de trabajo, une el carrito con el contacto y no crea consentimiento. Se activa en Consentimiento aceptando la responsabilidad (plugin 0.1.3) |
| F5 | P1 | SES: error 500 sin credenciales y el configuration set `igr-events` nunca se crea. | canal de correo | 🔧 0.1.7: sin credenciales responde 422 con el motivo y el envío falla sin reintentar; falta crear el configuration set |
| F6 | P1 | La salud del sitio nunca pasa a «degradado». | `SiteHealthProjector` | ✅ (lote 3): `SiteHealth` calcula el semáforo y `igr:sites:health` (cada 10 min) marca `degraded` el sitio que no está al día y lo devuelve a `connected` al recuperarse |
| F7 | P1 | Los contactos importados quedan con 0 consentimientos, sin forma de declarar el origen. | importación | ✅ (lote 3): el CSV exige además cómo se obtuvo la autorización y acepta columnas de consentimiento por fila (solo el «sí» cuenta); el historial del plugin se declara en Contactos → Importar y se aplica solo a los contactos de ese historial. Nunca a quien revocó o se dio de baja |
| F8 | P1 | Inteligencia, RFM y atribución solo corren de noche: después de importar, todo en cero hasta el otro día. | scheduler | ✅ 0.1.7: al terminar la importación, `ProcessImportedHistory` (atribución, KPI, RFM y hallazgos) cuando la ingesta queda al día |
| F9 | P1 | Mensajes que se quedan en `sending`. | `SendMessageJob` | ✅ (lote 3): una excepción del driver deja el mensaje `failed` (`driver_error`); `igr:messages:stuck` (cada 5 min, `ResolveStuckMessages`) toma los que llevan más de 15 min en `sending`: marketing → `failed` con `stuck_sending` (sin reintentar, para no duplicar), el resto → un reintento y luego `failed`; índice `(status, updated_at)` |
| F10 | P1 | Se puede activar un flujo sin canal conectado. | flujos | ✅ 0.1.7: no se activa sin cuenta activa del canal que usa; desde la biblioteca queda publicado con el motivo |
| F11 | P2 | Tablero de mensajería vacío (stub). | mensajería | ✅ (lote 3): `MessagingDashboard` con datos reales de `messages` y sus eventos (enviados, entregados, leídos, aperturas sin Apple MPP, clics, respuestas, bajas, rebotes y fallos, ventas atribuidas e ingreso por mensaje) por canal y por flujo o campaña, con comparación y serie; índice `messages (workspace_id, direction, created_at)`. Falta costo y ROI (el costo está en USD y no hay tasa de cambio) |
| F12 | P2 | `kpi_daily` de sesiones atrasado; conversión por geografía engañosa. | analítica | 🔧 (lote 3): la conversión por ciudad compara las ciudades sin tildes ni «D.C.» (la IP dice «Bogota», el pedido «Bogotá D.C.»), no sale con menos de 100 visitas («Pocas visitas») y avisa qué parte de las visitas no tiene ciudad; los departamentos salen con su nombre; sin compras ligadas a las visitas no muestra 0 % y explica por qué. Falta: `kpi_daily` de sesiones atrasado |
| F13 | P2 | Textos «Fase N» en la interfaz. | SPA | ✅ 0.1.7: textos reescritos y el aviso viejo del copiloto de campañas quitado |
| F14 | P2 | Nodos de flujo bloqueados (`assign_agent`, `lead_stage`, `crm_task`). | constructor de flujos | ✅ (lote 3): los tres funcionan con su validación (persona del equipo, etapa existente, título y vencimiento) y no hacen nada en el grupo de control; `crm_task` crea tareas que salen en «Hoy» |
| F15 | P2 | Avisos de actualización del plugin; `platform doctor`; `igr:admin:create`; tarifas de WhatsApp sin sembrar. | plataforma | ✅ (lote 3): `igr:doctor` (`--json`; código 1 con errores) y la pestaña «Instalación» de Plataforma (`GET /api/platform/doctor`): llaves, correo, SES y su tema de SNS, Meta, Anthropic, Google Calendar, Reverb, ML, Horizon, programador (latido cada minuto), colas, trabajos fallidos, ingesta, tarifas, superadmins sin 2FA, cuentas sin plan y sitios con el plugin viejo (contra el zip del panel). `igr:admin:create` crea o promueve (contraseña pedida dos veces o `--generate`). La migración `2026_11_25_300200` siembra `wa_rates` si está vacía |
| F16 | P2 | «Primeros pasos» no guía a la primera venta recuperada. | inicio | ✅ (lote 3): camino de 5 pasos con datos reales (`GET getting-started`): sitio → importación → canal → flujo de carrito abandonado → primera venta recuperada con el monto (servicios: flujo «Lead de servicios» y primer lead ganado); se oculta con todo listo |

## 4. Interfaz y experiencia

Lo más grave: en el celular el panel no se puede usar (`Sidebar.tsx:171`), el menú tiene 29 entradas, el dinero
generado sale en $0 y hay jerga técnica por todas partes. Las capturas están en el entorno de la auditoría.

| # | P | Hallazgo | Estado |
|---|---|---|---|
| U1 | P1 | Panel inutilizable en el celular (barra lateral fija). | ✅ 0.1.7: barra superior con menú lateral que se cierra al navegar; márgenes más chicos |
| U2 | P1 | Menú de 29 entradas sin agrupar. | ✅ (lote 3): grupos por tarea (Clientes, Vender, Fidelizar, Medir, Configuración) plegables y recordados, el activo abierto; oculta módulos fuera del plan, lo de tiendas sin WooCommerce y lo de servicios en una tienda (`modules` y `capabilities` en `/api/me`); igual en el celular y en ⌘K |
| U3 | P1 | Conectar WhatsApp exige pasos técnicos (ver §6). | ✅ (lote 3): «Conectar WhatsApp con un clic» (Embedded Signup v5, con coexistencia) en Canales y Conexiones; la conexión manual sigue |
| U4 | P2 | KPIs en 0 sin tracker y sitio «Conectado» sin datos: falta un semáforo de salud. | ✅ (lote 3): semáforo al día / con atraso / sin datos con causas concretas (servidor, navegador, WP-Cron, cola del plugin, versión mínima, rechazos, importación) y qué hacer, en Sitios, el detalle, el Inicio y Analítica (explica los KPI en 0 sin tracker) |
| U5 | P2 | Jerga: «Diagnóstico» del plugin, textos de conciliación, códigos en el perfil 360, el texto del código de conexión no coincide. | ✅ (lote 3): «Revisar el plugin» con un resumen en palabras (tareas programadas, datos por enviar, errores; el JSON queda plegado para soporte); conciliación y datos recibidos sin «hub» ni códigos de eventos; departamentos por nombre en el perfil y en Geografía (CO, MX, VE); los pasos del código de conexión dicen lo que muestra el plugin; «el hub» y «tracker» fuera de los textos del dueño |
| U6 | P2 | ⌘K no busca contactos; bandeja y segmentos sin estado vacío útil; errores del servidor en inglés. | ✅ (lote 3): ⌘K busca contactos (nombre, correo o teléfono con el índice ciego); la bandeja vacía explica cómo llegan los mensajes (conectar WhatsApp, botón en el sitio) o propone ver todas o quitar filtros; los segmentos sin calcular no muestran ceros; `lang/es/validation.php` con todas las reglas de Laravel (prueba que lo exige) |
| U7 | P3 | Constructor de flujos apretado; contraste de algunos tokens. | 🔧 (lote 3): los textos de estado (éxito, aviso, error) y las insignias cumplen AA (4,5:1) en claro y en oscuro; `success-foreground` y `warning-foreground` para los rellenos; bordes de los campos más visibles (siguen bajo 3:1 por diseño). Falta: el constructor de flujos apretado |

**Las cinco mejoras de mayor impacto:** dinero generado real; un camino guiado hasta la primera venta recuperada;
WhatsApp sin fricción; celular y menú agrupado; quitar la jerga y mostrar un semáforo de salud.

## 5. Estrategia

- **Momento «wow»:** un diagnóstico en 10 minutos después de importar (atribución → RFM → hallazgos → diagnóstico →
  aviso al dueño), no al otro día.
- **Núcleo a vender:** recuperar ventas en LATAM (WhatsApp + bandeja), conversiones a Meta, el reporte mensual del
  dinero generado, RFM con calendario de campañas, reseñas con foto y leads para servicios.
- **Esconder o aplazar:** afiliados, cotizador, Messenger e Instagram, SMS y lo que no se use en el primer mes.
- **Facturación:** el plan va por cuenta pero cada espacio recibe el volumen completo; la prueba nunca vence; la
  suspensión es por volumen; la métrica cuenta pedidos creados (no pagados); el costo del agente de IA no se cobra.
- **Planes propuestos:** Diagnóstico (gratis), Esencial (COP 199.000), Crece (599.000), Pro (1.399.000) y Servicios.

### 5.1 Del CRM al «ARM» (vendedor con agentes de IA)

El artículo «Del CRM al ARM» (Felipe Roitman, Clienty) describe nueve funciones de un sistema de ventas con agentes.
Casi todas tienen su base en Imagina Growth; lo que falta es que trabajen solas y juntas:

| Función | Qué hay | Qué falta |
|---|---|---|
| 1. Responde al instante | Agente de ventas con IA en WhatsApp, Messenger e Instagram (modo `auto`) | ✅ (lote 3) WhatsApp, Messenger e Instagram con un clic (§6); faltan las apps de Meta reales |
| 2. La ficha se completa sola | Intención del mensaje, lead por intención de compra, calificación con IA del formulario | ✅ (lote 3): la IA (`lead_brief`, en cola) llena qué necesita, cantidad, ciudad, zona, para cuándo, presupuesto y temperatura con la frase de origen, solo con lo que escribió el cliente y sin pisar lo editado; se ve en el chat y en el lead |
| 3. Filtra a los curiosos | Reglas de asignación por intención, traspaso a humano, puntaje y aviso al vendedor «caliente» | Preguntas de calificación configurables y derivación dueño / vendedor / agente / web |
| 4. Atiende el fin de semana | El agente agenda citas; página de reservas | Ofrecer horarios dentro del chat de WhatsApp de punta a punta |
| 5. El seguimiento sale solo | Flujo 18 de seguimiento de leads | ✅ (lote 3): el flujo 18 sigue los días 2, 10, 20 y 40 con el nodo «Seguimiento con IA» (texto en la ventana, plantilla fuera, correo de respaldo); sale si el cliente responde o el lead cambia de etapa |
| 6. Asistente del vendedor | Aviso al vendedor por WhatsApp | ✅ (lote 3): «Hoy» (`/hoy`) con respuestas pendientes, calientes sin contactar, tareas, próximos pasos y leads quietos; mensaje sugerido por la IA, envío con un clic por el despachador, hecho y posponer; resumen matutino opcional al vendedor por WhatsApp o correo |
| 7. Despierta la base dormida | RFM, flujos de reactivación y campañas con copiloto | Mensaje personalizado según lo último que habló cada contacto; solo a quien dio consentimiento y en horario legal |
| 8. Postventa y recompra | Pedido de reseña (también de Google), NPS, predicción de próxima compra | — |
| 9. El dueño ve todo | Informe semanal con IA y hallazgos 21–23 de leads | ✅ (lote 3): embudo del lunes por pipeline (llegaron a cada etapa y pasaron a la siguiente frente a la semana anterior), dónde se cae (90 días), motivos de pérdida, quietos por etapa y antigüedad y primera respuesta; en el informe semanal y en Leads → Indicadores, con conclusión de IA validada |

**Decisión propuesta:** terminar la estabilización y después hacer un bloque «ARM» con las brechas de las funciones
2, 5, 6 y 9, que reutilizan el agente, los flujos, los leads y el informe semanal. **Hecho en el lote 3** (las cuatro funciones; ver
modulos.md M09 y M18): la ficha desde el chat, el seguimiento con IA del flujo 18, «Hoy» con los nodos `crm_task`,
`assign_agent` y `lead_stage` (y el resumen matutino opcional), y el embudo del lunes. La diferencia frente a un ARM
genérico: todo respeta consentimiento, ventana de 24 h y horarios legales de cada país (CLAUDE.md §3.5), y cada venta
cerrada sale atribuida en el dinero generado.

### 5.2 Actualizar sin consola

Hasta la 0.1.7, los cambios de configuración del servidor (memoria de Redis, MariaDB y ClickHouse, prioridades,
perfil de procesos) solo llegaban volviendo a correr el instalador. Desde el lote 3 viven en `infra/bin/growth-system`,
que aplican el instalador, cada actualización desde el panel y una unidad que corre antes de `growth-app` (así llega
incluso cuando actualiza el actualizador anterior). El botón de actualizar es el único paso.

## 6. Conexiones con terceros

Antes solo Google Calendar usaba OAuth de verdad; el resto pedía pegar tokens. Estado:

1. ✅ (lote 3) Módulo común `app/Domain/Connections` (modelo-de-datos.md §16): `oauth_connections` (prefijo `oac`)
   por espacio de trabajo, proveedor y sujeto, con los tokens cifrados; `state` firmado y de un solo uso
   (`oauth_states`), ligado a la persona y al espacio de trabajo; PKCE S256 con Google; refresco automático con
   candado (`ConnectionTokens`); revisión diaria `igr:connections:check` (03:52); desconexión con revocación en el
   proveedor. Rutas `routes/api/connections.php` y la vuelta `GET /oauth/{meta|google|tiktok}/callback` (grupo
   `web`, solo al dominio del panel). Puente: `ad_destinations.oauth_connection_id` y
   `channel_accounts.oauth_connection_id` (trait `UsesOAuthConnection`): Meta CAPI, Data Manager, Google Ads,
   TikTok Events, audiencias, gasto y WhatsApp usan la conexión; los tokens pegados a mano siguen como respaldo.
   Pantalla **Conexiones** (`/w/:ws/conexiones`) con una tarjeta por proveedor, su estado y la elección de cuentas.
2. ✅ (lote 3) WhatsApp por *Embedded Signup* **v5** con coexistencia: `connections/meta_whatsapp/start|complete|abandon`
   y `connections/{id}/whatsapp-number`; canje del código, `debug_token` (app, permisos y WABA autorizada),
   `POST /{waba}/subscribed_apps`, registro del número con PIN (o, en coexistencia, `smb_app_data` de contactos e
   historial sin registrar) y plantillas sincronizadas. Cancelar o un error de la ventana quedan en la auditoría.
3. ✅ (lote 3) Google con un solo OAuth: Ads/Data Manager (cuenta, MCC y acciones de conversión), Analytics (propiedad,
   flujo web y el secreto del Measurement Protocol creado solo) y Calendar (la agenda usa el flujo común si está
   `IGR_GOOGLE_CLIENT_ID`; las conexiones de antes siguen con su cliente).
4. ✅ (lote 3) Meta Login for Business: conjunto de datos (píxel) y cuenta publicitaria para CAPI, audiencias y gasto;
   5. ✅ TikTok for Business (anunciante y píxel); 6. ✅ Messenger e Instagram (eligiendo la página).
7. ⏳ **Imagina Whats** (WhatsApp Web por QR, API REST con token): falta su documentación (URL base, autenticación,
   envío de texto y medios, webhooks de entrada y estados).

**Verificado en la documentación oficial (20 nov 2026):** Meta retiró *Embedded Signup* v2 y v3 el 15 de octubre de
2026 y la v5 (20 oct 2026) es la recomendada; la Marketing API v24.0 venció el 6 de octubre de 2026 (la Graph v24.0
sigue hasta feb 2028): el hub pasa a **Graph/Marketing API v27.0**. Google Ads v21 ya no existe: el hub pasa a
**v26**; el developer token dejó de exigirse el 9 de septiembre de 2026 (ahora es opcional).

Para usar OAuth hacen falta las apps de desarrollador de Meta (Tech Provider), Google (verificación de alcances
sensibles) y TikTok (revisión). Hoy no existe ninguna: la guía paso a paso es `docs/guia-apps-oauth.md`. Todo se
probó con respuestas simuladas; falta el recorrido con las apps reales.
