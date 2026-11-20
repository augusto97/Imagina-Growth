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
| S2 | P1 | El actualizador corre como root y usa `shared/tmp` y `shared/state`, que también escribe el usuario `growth` (carrera de enlaces simbólicos y `chown` por ruta); acepta bajar de versión sin avisar. | `infra/bin/growth-update` | ⏳ |
| S3 | P1 | Registro abierto y «sin plan = todo»: cualquiera crea una cuenta con todos los módulos, IA (tope de USD 25) y envío por SES a cargo de la plataforma. | `config/fortify.php`, `PlanResolver`, `AiMeter` | 🔧 0.1.7: registro solo por invitación (la primera persona de una instalación nueva sí; `IGR_OPEN_REGISTRATION=true` lo abre). Falta: sin plan = módulos mínimos |
| S4 | P1 | Envenenamiento del encabezado `Host` en el restablecimiento de contraseña: `trustProxies('*')`, sin `trustHosts`, y Caddy responde cualquier nombre. | `bootstrap/app.php`, `infra/caddy/Caddyfile` | ✅ 0.1.7: el enlace de restablecer usa siempre `APP_URL` |
| S5 | P2 | SSRF: URLs de webhooks, `rest_url` del sitio, Zender y SMTP sin bloquear redes internas; `SafeUrl` solo mira IPv4. | `WebhookHandler`, `SafeUrl` | ⏳ |
| S6 | P2 | Webhooks de SES sin verificar `TopicArn`. | `Messaging/.../SesWebhook` | ⏳ |
| S7 | P2 | Los registros de Caddy guardan tokens de consulta y `Set-Cookie`. | `infra/caddy/Caddyfile` | ⏳ |
| S8 | P2 | Rangos de Cloudflare fijos en la configuración de proxies de confianza. | `bootstrap/app.php` | ⏳ |
| S9 | P2 | El release se publica desde la rama sin esperar a CI. | `.github/workflows/release.yml` | ⏳ |
| S10 | P2 | Inyección de fórmulas en la exportación CSV de segmentos (`=`, `+`, `-`, `@`). | `SegmentController.php:154-160` | ✅ 0.1.7: `App\Support\Csv` en las dos exportaciones |
| S11 | P2 | Cambio de correo de la cuenta sin pedir la contraseña. | perfil de usuario | ⏳ |
| S12 | P3 | Sin `frame-ancestors` en la CSP del panel. | middleware de encabezados | ✅ 0.1.7: `FrameGuard` en las respuestas web (las páginas con política propia la conservan) |
| S13 | P3 | El usuario `igr` de ClickHouse tiene más privilegios de los que usa. | `infra/install.sh` | ⏳ |
| S14 | P3 | La firma HMAC no cubre el método ni la ruta. | `docs/api-connector.md` §2 | ⏳ (cambio de contrato) |
| S15 | P3 | Horizon sin exigir 2FA; IP en el payload de `DeliverConversion` (queda en `failed_jobs`). | `HorizonServiceProvider`, `DeliverConversion` | ⏳ |
| S16 | P3 | `/health` público con detalle de dependencias. | `routes/ingest.php` | ⏳ |

## 2. Rendimiento y estabilidad

| # | P | Hallazgo | Dónde | Estado |
|---|---|---|---|---|
| R1 | P0 | El stream de ingesta en Redis nunca se recortaba (XACK no borra), con tope de 1 M por shard, dedupe de 7 días, `noeviction` sin `maxmemory` y RDB + AOF: en un servidor de 1,6 GB termina en OOM. | `IngestWorkCommand`, `IngestStream`, `infra/config/redis/redis-queue.conf` | ✅ 0.1.6: el worker recorta lo confirmado cada 5 s, tope de 250 k por shard, dedupe de 4 días, `maxmemory` del 20% de la RAM, sin RDB y aviso de `growth:monitor` al 85% |
| R2 | P0 | Un lote con un evento dañado se reintentaba para siempre: `HY000` (SQLSTATE genérico, también de errores de datos) se trataba como error pasajero. | `IngestProcessor::isTransient` | ✅ 0.1.6: se mira el código del motor; si el lote falla se reintenta uno por uno y el que falla 5 veces con la base disponible va a `ingest_rejections` |
| R3 | P1 | Con ClickHouse caído el worker reprocesaba lo pendiente en bucle cada segundo. | `IngestWorkCommand::flush` | ✅ 0.1.6: espera creciente hasta 30 s |
| R4 | P1 | `HubEvents` inserta en ClickHouse de forma síncrona dentro de peticiones web (webhooks, clics en `/c/`); `DetectAbandonedCarts` marca antes de emitir. | `HubEvents`, `DetectAbandonedCarts` | ⏳ |
| R5 | P1 | Perfil pequeño: siguen 2 réplicas de ingesta, FrankenPHP con hilos por defecto, ML con más de un worker, sin `MemoryHigh` ni `OOMScoreAdjust`. | `infra/install.sh`, unidades systemd | ✅ 0.1.7: 2 hilos de PHP por proceso y un proceso de ML en el perfil pequeño, y prioridades ante falta de memoria (las dos réplicas de ingesta se quedan: permiten reiniciar sin cortar) |
| R6 | P1 | Trabajos de 300 s en la conexión `redis` con `retry_after` 90: se ejecutan dos veces (`SendMonthlyReportJob`, `SendCampaignBatch`, `NotifyProductWatchesJob` y el agente de IA, de 120 s). | `config/queue.php`, jobs | ✅ 0.1.7: `retry_after` de 330 s y una prueba que revisa el timeout de cada job contra su conexión |
| R7 | P2 | `SessionsBuilder` reinserta cada sesión ~15 veces. | `Analytics/SessionsBuilder` | ⏳ |
| R8 | P2 | Cada `page.viewed` reevalúa todos los segmentos. | evaluación de segmentos | ⏳ |
| R9 | P2 | La clave de caché del tablero incluye `max(last_event_at)`: con tráfico nunca hay acierto. | `MetricQueryRunner::remember` | ✅ 0.1.7: versión redondeada al minuto |
| R10 | P2 | Informe semanal en serie y `isDue` con `hour === 7` (se salta si el scheduler se atrasa). | `WeeklyReport` | 🔧 0.1.7: sale el lunes desde las 7 en la primera corrida y una sola vez por semana; sigue en serie |
| R11 | P2 | `widgets.json` sin caché. | endpoint público de widgets | ⏳ |
| R12 | P2 | Índices faltantes en consultas frecuentes; paginación por `OFFSET`; escaneos por `received_at`. | varias | ⏳ |
| R13 | P2 | Uso de facturación cada 5 minutos para todos los espacios. | scheduler de Billing | ⏳ |
| R14 | P2 | Sin purga de retención de datos viejos. | — | ⏳ |
| R15 | P2 | `RecomputeSegment` y RFM cargan demasiado en memoria. | Segments, Intelligence | ⏳ |
| R16 | P3 | Sin Octane / modo worker de FrankenPHP. | `infra` | ⏳ |
| R17 | P1 | En la prueba con 1,6 GB (Ubuntu 26.04), ClickHouse ocupa ~580 MB de RSS en reposo pese al tope del 25 %, y el contenedor queda al 94 %: bajo presión el kernel mató el ML (como se buscaba) y se reinició solo. Falta bajar el piso de ClickHouse (cachés y pools de fondo). | `infra/install.sh` (`growth-memory.xml`) | ⏳ |

## 3. Funcionalidades

| # | P | Hallazgo | Dónde | Estado |
|---|---|---|---|---|
| F1 | P0 | «Dinero generado» del inicio fijo en $0. | `OverviewDashboard.php:31` | ✅ 0.1.7: usa `MoneyGenerated` (la regla del reporte mensual) con el desglose por módulo y, en cero, el camino al flujo de carrito abandonado |
| F2 | P0 | `MAIL_MAILER=log` en producción: las invitaciones no salen y nadie se entera. | `.env`, plataforma | ✅ 0.1.6: enlace de invitación para copiar, aviso de correo apagado y correo de prueba |
| F3 | P1 | Webhooks de Meta (WhatsApp) responden 403 sin `IGR_META_APP_SECRET`. | `WebhookController` | ⏳ |
| F4 | P1 | Falta la captura temprana del checkout (`checkout.contact_captured`). | tracker, plugin | ⏳ |
| F5 | P1 | SES: error 500 sin credenciales y el configuration set `igr-events` nunca se crea. | canal de correo | 🔧 0.1.7: sin credenciales responde 422 con el motivo y el envío falla sin reintentar; falta crear el configuration set |
| F6 | P1 | La salud del sitio nunca pasa a «degradado». | `SiteHealthProjector` | ✅ (lote 3): `SiteHealth` calcula el semáforo y `igr:sites:health` (cada 10 min) marca `degraded` el sitio que no está al día y lo devuelve a `connected` al recuperarse |
| F7 | P1 | Los contactos importados quedan con 0 consentimientos, sin forma de declarar el origen. | importación | ⏳ |
| F8 | P1 | Inteligencia, RFM y atribución solo corren de noche: después de importar, todo en cero hasta el otro día. | scheduler | ✅ 0.1.7: al terminar la importación, `ProcessImportedHistory` (atribución, KPI, RFM y hallazgos) cuando la ingesta queda al día |
| F9 | P1 | Mensajes que se quedan en `sending`. | `SendMessageJob` | ⏳ |
| F10 | P1 | Se puede activar un flujo sin canal conectado. | flujos | ✅ 0.1.7: no se activa sin cuenta activa del canal que usa; desde la biblioteca queda publicado con el motivo |
| F11 | P2 | Tablero de mensajería vacío (stub). | mensajería | ✅ (lote 3): `MessagingDashboard` con datos reales de `messages` y sus eventos (enviados, entregados, leídos, aperturas sin Apple MPP, clics, respuestas, bajas, rebotes y fallos, ventas atribuidas e ingreso por mensaje) por canal y por flujo o campaña, con comparación y serie; índice `messages (workspace_id, direction, created_at)`. Falta costo y ROI (el costo está en USD y no hay tasa de cambio) |
| F12 | P2 | `kpi_daily` de sesiones atrasado; conversión por geografía engañosa. | analítica | 🔧 (lote 3): la conversión por ciudad compara las ciudades sin tildes ni «D.C.» (la IP dice «Bogota», el pedido «Bogotá D.C.»), no sale con menos de 100 visitas («Pocas visitas») y avisa qué parte de las visitas no tiene ciudad; los departamentos salen con su nombre. Falta: `kpi_daily` de sesiones atrasado |
| F13 | P2 | Textos «Fase N» en la interfaz. | SPA | ✅ 0.1.7: textos reescritos y el aviso viejo del copiloto de campañas quitado |
| F14 | P2 | Nodos de flujo bloqueados (`assign_agent`, `lead_stage`, `crm_task`). | constructor de flujos | ⏳ |
| F15 | P2 | Avisos de actualización del plugin; `platform doctor`; `igr:admin:create`; tarifas de WhatsApp sin sembrar. | plataforma | ⏳ |
| F16 | P2 | «Primeros pasos» no guía a la primera venta recuperada. | inicio | ✅ (lote 3): camino de 5 pasos con datos reales (`GET getting-started`): sitio → importación → canal → flujo de carrito abandonado → primera venta recuperada con el monto (servicios: flujo «Lead de servicios» y primer lead ganado); se oculta con todo listo |

## 4. Interfaz y experiencia

Lo más grave: en el celular el panel no se puede usar (`Sidebar.tsx:171`), el menú tiene 29 entradas, el dinero
generado sale en $0 y hay jerga técnica por todas partes. Las capturas están en el entorno de la auditoría.

| # | P | Hallazgo | Estado |
|---|---|---|---|
| U1 | P1 | Panel inutilizable en el celular (barra lateral fija). | ✅ 0.1.7: barra superior con menú lateral que se cierra al navegar; márgenes más chicos |
| U2 | P1 | Menú de 29 entradas sin agrupar. | ✅ (lote 3): grupos por tarea (Clientes, Vender, Fidelizar, Medir, Configuración) plegables y recordados, el activo abierto; oculta módulos fuera del plan, lo de tiendas sin WooCommerce y lo de servicios en una tienda (`modules` y `capabilities` en `/api/me`); igual en el celular y en ⌘K |
| U3 | P1 | Conectar WhatsApp exige pasos técnicos (ver §6). | ⏳ |
| U4 | P2 | KPIs en 0 sin tracker y sitio «Conectado» sin datos: falta un semáforo de salud. | ✅ (lote 3): semáforo al día / con atraso / sin datos con causas concretas (servidor, navegador, WP-Cron, cola del plugin, versión mínima, rechazos, importación) y qué hacer, en Sitios, el detalle, el Inicio y Analítica (explica los KPI en 0 sin tracker) |
| U5 | P2 | Jerga: «Diagnóstico» del plugin, textos de conciliación, códigos en el perfil 360, el texto del código de conexión no coincide. | ✅ (lote 3): «Revisar el plugin» con un resumen en palabras (tareas programadas, datos por enviar, errores; el JSON queda plegado para soporte); conciliación y datos recibidos sin «hub» ni códigos de eventos; departamentos por nombre en el perfil y en Geografía (CO, MX, VE); los pasos del código de conexión dicen lo que muestra el plugin; «el hub» y «tracker» fuera de los textos del dueño |
| U6 | P2 | ⌘K no busca contactos; bandeja y segmentos sin estado vacío útil; errores del servidor en inglés. | ✅ (lote 3): ⌘K busca contactos (nombre, correo o teléfono con el índice ciego); la bandeja vacía explica cómo llegan los mensajes (conectar WhatsApp, botón en el sitio) o propone ver todas o quitar filtros; los segmentos sin calcular no muestran ceros; `lang/es/validation.php` con todas las reglas de Laravel (prueba que lo exige) |
| U7 | P3 | Constructor de flujos apretado; contraste de algunos tokens. | ⏳ |

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
| 1. Responde al instante | Agente de ventas con IA en WhatsApp, Messenger e Instagram (modo `auto`) | Conectar WhatsApp sin fricción (§6) |
| 2. La ficha se completa sola | Intención del mensaje, lead por intención de compra, calificación con IA del formulario | Que el agente extraiga durante el chat qué necesita, cuánto, dónde, para cuándo y la temperatura, y lo guarde en el lead |
| 3. Filtra a los curiosos | Reglas de asignación por intención, traspaso a humano, puntaje y aviso al vendedor «caliente» | Preguntas de calificación configurables y derivación dueño / vendedor / agente / web |
| 4. Atiende el fin de semana | El agente agenda citas; página de reservas | Ofrecer horarios dentro del chat de WhatsApp de punta a punta |
| 5. El seguimiento sale solo | Flujo 18 de seguimiento de leads | Cadencia día 2, 10, 20 y 40 con mensajes que aportan (IA con los datos del lead), dentro de la ventana o con plantilla |
| 6. Asistente del vendedor | Aviso al vendedor por WhatsApp | Lista diaria «a quién escribir hoy» con el mensaje sugerido y envío con un clic (nodo `crm_task` hoy bloqueado) |
| 7. Despierta la base dormida | RFM, flujos de reactivación y campañas con copiloto | Mensaje personalizado según lo último que habló cada contacto; solo a quien dio consentimiento y en horario legal |
| 8. Postventa y recompra | Pedido de reseña (también de Google), NPS, predicción de próxima compra | — |
| 9. El dueño ve todo | Informe semanal con IA y hallazgos 21–23 de leads | Embudo de leads del lunes (consultas → calificados → propuestas → ventas) con la etapa donde se cae y por qué |

**Decisión propuesta:** terminar la estabilización y después hacer un bloque «ARM» con las brechas de las funciones
2, 5, 6 y 9, que reutilizan el agente, los flujos, los leads y el informe semanal. La diferencia frente a un ARM
genérico: todo respeta consentimiento, ventana de 24 h y horarios legales de cada país (CLAUDE.md §3.5), y cada venta
cerrada sale atribuida en el dinero generado.

## 6. Conexiones con terceros

Hoy solo Google Calendar usa OAuth de verdad; el resto pide pegar tokens. Plan:

1. Módulo común `app/Domain/Connections` (conexiones OAuth con prefijo `oac`, rutas de inicio y retorno, puente de
   credenciales para destinos de anuncios y canales).
2. WhatsApp por *Embedded Signup* con coexistencia (el número sigue en la app de WhatsApp Business).
3. Google (Ads + Data Manager, Analytics, Calendar) con un solo OAuth de la plataforma.
4. Meta para anuncios y audiencias; 5. TikTok; 6. Messenger e Instagram.
7. **Imagina Whats** (WhatsApp Web por QR, API REST con token): falta su documentación (URL base, autenticación,
   envío de texto y medios, webhooks de entrada y estados).

**Por verificar antes de cambiar código:** la auditoría reporta que Meta retiró las versiones 2 y 3 de *Embedded
Signup*, que la versión por defecto de Graph para la API de marketing venció y que Google Ads v21 ya no existe. Cada
dato se confirma en la documentación oficial antes de tocar el cliente correspondiente.

Para usar OAuth hacen falta las apps de desarrollador de Meta (Tech Provider), Google (verificación de alcances
sensibles) y TikTok (revisión). Hoy no existe ninguna; la guía para crearlas va con esa entrega.
