# Rediseño v2 del panel: que se entienda para qué sirve desde el primer minuto

> Documento rector del rediseño (versión 0.2.0). Todo cambio de interfaz de este lote sigue lo que dice aquí; si algo no
> encaja, se cambia este documento en el mismo commit con el motivo. Complementa `hub/CLAUDE.md` §14.

## 1. Diagnóstico (noviembre 2026)

Recorrido con un espacio de trabajo nuevo («Tienda Nueva») y con la demo («Pinturas Demo»), en escritorio y celular:

1. **No se entiende qué hace.** El Inicio dice «Hola, Super · Lo que ya sabemos de…» y una lista de 5 pasos hacia «la
   primera venta recuperada». Nada explica qué logra el negocio con la plataforma ni qué más puede hacer.
2. **El menú es un catálogo de módulos técnicos:** 35 entradas (Contactos, Segmentos, Hoy, Leads, Agenda, Bandeja,
   Flujos, Campañas, Plantillas, En el sitio, Cotizador, Fidelización, Referidos y afiliados, Reseñas, Analítica,
   Hallazgos, Inteligencia, Medición, Reportes mensuales, Encuesta, Sitios, Canales, Conexiones, Equipo, Ajustes,
   Consentimiento, Integraciones, Facturación, Auditoría…), con jerga («segmentos», «flujos», «hallazgos», «medición»).
   Un cliente nuevo ve todo eso aunque todavía nada funciona porque no conectó su web.
3. **Organizado por función, no por resultado.** Para «recuperar carritos» hay que saber que existen los flujos, que
   el flujo 2 de la biblioteca hace eso, que necesita un canal conectado y una plantilla aprobada, y dónde ver cuánto
   vendió. El valor está repartido en 6 pantallas.
4. **Los resultados están dispersos:** el dinero generado en el Inicio, 14 pestañas de Analítica, Reportes, Hallazgos,
   Inteligencia y Medición.
5. **Primera vez sin guía:** una lista de pasos, sin explicar qué desbloquea cada conexión ni qué va a pasar después.
6. **Se ve como un panel de administración:** poca jerarquía, todo gris y pequeño, el contenido encerrado en
   `max-w-6xl` con márgenes vacíos, sin personalidad de marca, encabezados distintos en cada pantalla, filtros densos.
7. **Tienda y servicios mezclados** en la misma navegación (el menú ya oculta según el tipo, pero los textos no).

## 2. Qué es Imagina Growth, dicho para el cliente

**Promesa:** «Imagina Growth vende más por ti. Conecta tu tienda o tu web y la plataforma recupera carritos, trae de
vuelta a tus clientes, responde por WhatsApp y te muestra cada mes cuánto dinero generó.»

Tres pilares, que la interfaz repite en todas partes con el mismo lenguaje:

| Pilar | Qué siente el cliente | Lo que hay detrás |
|---|---|---|
| **Conoce a tus clientes** | «Sé quién me compra, quién se fue y quién está por volver» | perfil 360, RFM, grupos, predicciones |
| **Vende en automático** | «Prendí estrategias y trabajan solas, respetando la ley» | estrategias (flujos, campañas, widgets, fidelización, reseñas, agente IA) |
| **Mira lo que ganaste** | «Cada peso que generó la plataforma, explicado» | atribución, dinero generado, recomendaciones, informes |

Para servicios (sin WooCommerce) la promesa cambia a: «convierte más contactos en clientes: responde al instante,
haz seguimiento sin olvidar a nadie y agenda citas solo».

## 3. Arquitectura de información

Ocho secciones en el menú principal (siete para quien no es administrador), con nombres de resultado. Cada sección
tiene su página de entrada y una navegación secundaria propia (subnavegación a la izquierda del contenido en
escritorio, control segmentado desplazable en el celular). **Las URL de hoy no cambian** (las secciones agrupan rutas
existentes); las rutas nuevas se agregan sin romper las viejas.

| Sección | Para qué (texto bajo el título) | Contiene (rutas actuales) |
|---|---|---|
| **Inicio** | Lo que generó la plataforma y qué hacer hoy | `/` |
| **Estrategias** (nueva) | Prende lo que te hace vender más | `/estrategias` (catálogo) |
| **Clientes** | Quién te compra y quién está por volver | personas `contactos`, grupos `segmentos`; servicios: `leads`, `hoy`, `agenda` |
| **Conversaciones** | Atiende WhatsApp, Messenger e Instagram en un solo lugar | `bandeja` |
| **Mensajes** | Automatizaciones, campañas y plantillas | `flujos` (Automatizaciones), `campanas`, `plantillas` |
| **En tu web** | Formularios, reseñas, puntos y referidos en tu sitio | `en-el-sitio`, `resenas`, `fidelizacion`, `referidos`, cotizador |
| **Resultados** | Cuánto vendiste y cuánto generó la plataforma | `analitica` (con Resumen primero), `reportes`, `hallazgos` (Recomendaciones), `inteligencia` (Predicciones), `medicion` (Anuncios), `encuesta` |
| **Configuración** | Conexiones, equipo y tu negocio | `conexiones` (centro único: web/plugin, WhatsApp, correo, anuncios; absorbe `sitios` y `canales`), `equipo`, `ajustes`, `consentimiento`, `facturacion`, `integraciones`, `auditoria` |
| Agencia / Plataforma | (solo con ese permiso) | `agencia`, `plataforma` |

**Renombres de cara al cliente** (las llaves de i18n y las rutas pueden seguir igual): Flujos → **Automatizaciones**;
Segmentos → **Grupos de clientes**; Hallazgos → **Recomendaciones**; Inteligencia → **Predicciones**; Medición →
**Anuncios y conversiones**; En el sitio → **En tu web**; Contactos → **Clientes** (la persona es «cliente» o
«contacto» según si compró); Reportes mensuales → **Informes**; Sitios y Canales → dentro de **Conexiones**.

**Bloqueos que enseñan:** una sección o estrategia que necesita algo que falta (web sin conectar, sin canal, módulo
fuera del plan) no se oculta ni falla: muestra qué hace, qué hace falta y el botón para resolverlo («Conecta tu web
para activar esto»). Los módulos fuera del plan muestran el plan que los incluye.

**Cómo quedó en el sistema base** (frente 1, `hub/CLAUDE.md` §14; cambios al plan con su motivo):

- **La subnavegación va dentro de la barra lateral**, debajo de la sección actual (como Polaris y Stripe), y no en una
  segunda columna junto al contenido: en 1366 y 1440 px esa columna le quitaba unos 230 px al contenido y las tablas
  (personas, pedidos) no cabían. Por debajo de 1024 px o con la barra contraída pasa a un control segmentado
  desplazable arriba del contenido, que empieza por «Resumen» (la página de entrada).
- **Nombres dentro de cada sección:** como la sección ya se llama Clientes, la lista de personas se llama «Personas»
  (y su página también); «Analítica» es «Tableros» dentro de Resultados; Inteligencia se reparte en «Predicciones»
  (`inteligencia/predicciones`) y «Analista con IA» (`inteligencia`, pregúntale e informes semanales); los widgets de
  `en-el-sitio` son «Popups y formularios»; Fidelización, «Puntos y recompensas»; Ajustes, «Tu negocio»; Auditoría,
  «Registro de actividad»; Consentimiento, «Privacidad y consentimiento»; Encuesta, «¿Cómo nos conociste?». Las llaves
  de i18n y las rutas siguen iguales; ⌘K encuentra también por los nombres de antes.
- **Ubicación de lo que el plan no nombraba:** el copiloto va en Mensajes; productos recomendados, avisos de stock y
  precio y banners, en En tu web; las plantillas de WhatsApp (`canales/plantillas-whatsapp`), en Mensajes ›
  Plantillas. Conexiones muestra sus pantallas hermanas (WhatsApp y anuncios, tu web, canales) hasta que el frente de
  Configuración las una en una sola.
- **Qué bloquea qué:** fuera del plan, todo lo que tiene módulo; sin web conectada, lo que vive en la web o sale de sus
  datos (En tu web y Resultados); sin canal de conversación (WhatsApp, Messenger o Instagram), la bandeja y el agente.
  Una web que se conectó y hoy está desconectada no bloquea (sus datos siguen; el aviso de salud dice qué hacer).
  Personas, grupos, mensajes y configuración nunca se bloquean.
- **Tipo de negocio y vertical (§11.1):** el menú oculta lo que no aplica según `business_type` (lo elegido en el
  asistente; si no, lo que implica la vertical; si no, lo que dicen los sitios): un negocio sin tienda no ve carritos,
  pedidos ni catálogo, y una tienda no ve leads, agenda ni cotizador. Sin ninguna señal se muestra todo. La sección
  Clientes se llama «Pacientes» en salud y «Estudiantes» en educación; las demás palabras quedan iguales.

**Cómo quedó En tu web** (frente B5, fase B; `docs/modulos.md` M12, M13 a M16 y M18):

- **Captación sin web, en el menú:** «Páginas de captura» (`paginas-de-captura`), «WhatsApp con QR» (`whatsapp-qr`) y
  «Leads de anuncios» (`leads-de-anuncios`, solo servicios) no piden la web conectada, así que la sección ya no se
  bloquea entera sin web: cada entrada que la necesita lleva su candado. Las rutas son de primer nivel (no bajo
  `en-el-sitio/`) para no chocar con `en-el-sitio/:widgetId`.
- **Popups y formularios:** se crean desde una galería de plantillas por objetivo (`WidgetTemplates` en el hub, un clic:
  queda en borrador para revisarla en la vista previa) y no desde un tipo técnico (sigue como «empezar en blanco»). Un
  negocio sin tienda ve primero el formulario de contacto y el botón de WhatsApp, nunca un cupón. El editor dice
  «Cuándo se muestra» en palabras (`features/onsite/rules.ts`) y la lista da vistas, datos, tasa y ventas atribuidas
  (o contactos nuevos sin tienda). Las pestañas internas de «En el sitio» se quitaron: el menú de la sección ya lleva a
  recomendaciones, avisos, banners y cotizador.
- **Página de captura:** editor de dos columnas con la vista previa en vivo (escritorio o celular, formulario o gracias;
  `LandingPreview` replica la página Blade sin cargarla); en el celular, «Editar / Vista previa». Los cambios se
  guardan con una barra fija abajo; publicar muestra los avisos del hub con el enlace para arreglarlos y lleva a
  Compartir (enlace, WhatsApp y QR en SVG o PNG). La conversión es envíos ÷ visitas en la lista y en el detalle.
- **Reseñas en un negocio sin tienda:** sin pedidos no hay solicitud automática, así que la pantalla es su enlace de
  reseñas de Google (copiar, enviar por WhatsApp) y cómo pedirlas sin filtrar a los satisfechos.
- **Piezas nuevas del sistema** (guía de estilo, «Compartir y vista previa»): `CopyField`/`CopyButton`,
  `SegmentedControl` (periodos y dispositivos), `DeviceFrame` y `ConfirmDialog` (reemplaza `window.confirm`). Las
  cifras de Fidelización, Avisos, Referidos, Reseñas y Cotizador pasaron a `StatCard`, y las gráficas de Fidelización
  y Avisos, a las perezosas de `charts.tsx`.

**Cómo quedó Clientes** (frente B2, `docs/modulos.md` M03, M04 y M18). Cada pantalla decide tienda o servicios con
`useBusiness()` (`features/contacts/business.ts`, la misma regla del menú) y nombra a la persona con los `terms` de la
vertical (`GET verticals`):

- **Personas:** búsqueda al instante, filtros rápidos en chips según el negocio (compraron, en riesgo; calientes, tibios)
  y el resto en un cajón «Filtros» (grupo de clientes, canal con permiso, temperatura, ciudad, etapa, etiqueta);
  columnas de compras con tienda y de lead y próxima cita sin ella; acciones masivas (agregar a un grupo fijo y
  exportar) para las elegidas o para todas las que cumplen los filtros. En el celular, tarjetas en lugar de tabla.
- **Perfil 360:** el encabezado dice en palabras su valor, su grupo y lo que viene (o, sin tienda, en qué va su proceso,
  su valor y su próxima cita), cómo llegó y por qué canales se le puede escribir, con las acciones a la vista (escribir,
  agendar, crear lead; agregar a un grupo en el menú cuando hay servicios). Sin tienda no hay pestañas de compras: está
  «Citas y cotizaciones». *Cambio:* el resumen con IA del perfil se oculta sin tienda porque su texto habla de compras;
  ahí lo reemplaza el proceso (lead) con su ficha automática.
- **Grupos de clientes:** tarjetas por familia (tuyos, listos para tu negocio, según cómo compran, listos para usar) con
  qué es cada grupo y qué hacer, el tamaño con su tendencia de 30 días (`segment_daily_counts`) y «Enviar campaña» y
  «Crear automatización» directos. *Cambio:* se suman 7 grupos del sistema para servicios (leads y citas) y cada grupo
  del sistema dice para qué negocio es (`audience`); sin tienda no se muestran los RFM ni los de compras.
- **Leads:** tablero con las etapas del embudo del espacio, 12 tarjetas por columna y «Ver más»; en el celular, una
  etapa a la vista con chips y «Mover a». La ficha es un cajón con las acciones arriba y sus citas y cotizaciones.
- **Hoy:** chips por motivo con su conteo, 5 por grupo con «Ver más» y «Enviar por WhatsApp» a todo el ancho en el
  celular. **Agenda:** semana o día (el celular empieza en el día con la tira de la semana), chips por estado y
  «Confirmar», «Asistió» y «No asistió» a un toque; «Agendar cita» del perfil o del lead llega con la persona elegida.

**Cómo quedó Resultados** (frente B6; `docs/modulos.md` M05, M06, M17 y M22):

- **Resumen** (`resultados`, la entrada de la sección; ya no es la página de tarjetas): el dinero que generó la plataforma
  en una tarjeta héroe con la frase que lo explica (sin IA: frases fijas con las cifras del servidor), las ventas del
  periodo, el desglose por estrategia y por canal (WhatsApp, correo, SMS, Messenger e Instagram, chats del agente, tu web,
  referidos, anuncios) con la comparación, la tendencia, las 3 recomendaciones con más dinero en juego y los tableros que
  aplican. Lee `GET analytics/summary` (mismo periodo y comparación que los tableros). Sin tienda: el embudo de
  contactos, primera respuesta, citas y asistencia, cotizaciones y lo ganado con lo que trajo cada parte de la
  plataforma (`GET services/results`), con la palabra de la vertical («pacientes»).
- **Tableros:** agrupados (ventas, clientes, tráfico, contactos, mensajes; un selector en el celular), solo los que
  aplican al negocio (una clínica ve «Contactos y formularios» y «Mensajería»), mismo filtro de periodo en todos (los
  demás filtros en un panel inferior en el celular), cada gráfica con su conclusión como título y el nombre del dato
  arriba, estados vacíos que dicen qué falta (sin las visitas del navegador, el embudo y las búsquedas lo explican con
  el botón para arreglarlo) y, en el celular, las cifras en una lista compacta (`StatList`).
- **Informes** (`reportes`; `inteligencia/informes` abre la misma pantalla en «Semanal»): el mensual con su vista previa
  (los hechos del mes anterior aunque todavía no se haya generado), el PDF, reenviar y a quién llega; el semanal del
  analista con sus 3 acciones. La pantalla vieja de la agencia (`ClientReportsPage`) se retiró.
- **Recomendaciones:** tarjetas con el dinero en juego, «Por qué» (la cuenta), el botón con lo que hace (la etiqueta de
  la acción, también «Crear la campaña de …» del calendario comercial con un clic) y **posponer** 1, 3, 7 o 30 días
  (vuelve sola) junto a descartar.
- **Predicciones y Analista con IA:** cada predicción dice en palabras qué significa, para qué usarla y qué tan
  confiable es; solo tiendas (sin compras no hay qué predecir). «Pregúntale a tus datos» queda arriba del Analista con
  ejemplos según el negocio. Las gráficas de Predicciones y de las encuestas NPS/CSAT pasan a los componentes perezosos.
- **Anuncios y conversiones:** el estado de cada plataforma en palabras, qué se envía con el nombre que recibe cada
  plataforma y el interruptor de los «clics que valen» por plataforma; conectar cuentas es de Conexiones (se enlaza).
- **¿Cómo nos conociste?:** las respuestas arriba con su conclusión y el enlace al tablero que las cruza; la
  configuración abajo, sin claves internas. Predicciones y la encuesta se ocultan en un negocio sin tienda.

**Cómo quedaron las páginas de entrada** (fase B, frente B1): cada sección explica qué es en una línea, muestra lo que
importa con cifras (`GET sections/{section}`, `Home\Queries\SectionSummary`: solo números y claves; los textos de cada
sección van en `locales/es-CO/section-entry.json`, que cada frente ajusta para la suya), «Empieza por aquí» con lo
primero que conviene hacer y su botón, y debajo lo que hay dentro. Las tarjetas ya no piden un conteo cada una (eran 5
consultas por sección): todo llega en una sola llamada, que el menú precarga al pasar el puntero. En un negocio sin
tienda, En tu web cuenta los contactos captados y las páginas de captura publicadas y empieza por crear la página (no
pide la web); con la web conectada y sin formularios, por poner uno de contacto.

**Cómo quedaron Conversaciones y Configuración** (frente B3, nov. 2026; cambios al plan con su motivo):

- **Conexiones es una sola pantalla** con tarjetas agrupadas (tu web, conversaciones, correo y SMS, anuncios, agenda),
  cada una con su estado en palabras, qué permite y su botón; el detalle se abre en una hoja lateral para no perder el
  contexto. Sitios y Canales dejaron de ser pantallas: sus URL abren la tarjeta. La lista de supresión pasó a
  Privacidad y consentimiento (es una regla de privacidad, no un canal). El resumen de arriba cuenta solo lo esencial
  (web, WhatsApp, correo y Meta) para no castigar lo opcional.
- **Un WhatsApp desconectado con historial no bloquea la bandeja**, igual que una web desconectada: se lee todo y un
  aviso lleva a reconectarlo (antes la clínica de la demo, con 74 conversaciones, veía el bloqueo).
- **El agente con IA** es una sola pestaña «Configurar y probar» con la prueba en vivo al lado (en el celular, debajo)
  y otra con lo que respondió. «Cuándo atiende» usa el **horario de atención de Tu negocio** (nuevo, en
  `workspaces.settings.business_hours`), así el horario se define una vez.
- **Tu negocio** suma a qué te dedicas (tipo de negocio y actividad, con el aviso de lo que cambia), el horario de
  atención y la marca y el contacto del pie de los correos; cada bloque se guarda con su botón.

## 4. Primera vez (onboarding)

1. **Bienvenida** (una sola pantalla, no un carrusel largo): la promesa, los tres pilares con un ejemplo concreto cada
   uno y el botón «Configurar en 10 minutos». Se ve una vez por persona y espacio de trabajo; se puede volver a ver
   desde la ayuda.
2. **Asistente de configuración** a pantalla completa, con barra de progreso, que se puede pausar y retomar (estado en
   el servidor, no en el navegador):
   1. **Tu negocio:** tipo (tienda online / servicios / ambos), país, moneda y zona horaria precargados.
   2. **Conecta tu web:** descargar el plugin, el código de conexión con copiar, y una espera en vivo que celebra cuando
      el sitio se conecta. Explica qué empieza a pasar.
   3. **Trae tu historial:** progreso de la importación; mientras tanto muestra lo que ya se aprendió (clientes,
      pedidos, mejores productos).
   4. **Conecta tus canales:** WhatsApp con un clic (o «después»), correo; qué permite cada uno.
   5. **Prende tus primeras estrategias:** 3 recomendadas según el negocio (tienda: carrito abandonado, pago pendiente
      o contraentrega, segunda compra; servicios: respuesta inmediata a leads con su seguimiento, recordatorio de cita
      y calificación en el chat) con una vista previa del mensaje y un interruptor cada una. *Cambio (backend, nov.
      2026): la respuesta inmediata y el seguimiento son el mismo flujo 18 (una sola estrategia, `service_lead`), así
      que la tercera de servicios es la calificación en el chat; si el plan no la incluye, sigue la siguiente de la
      lista (`StrategyRegistry::RECOMMENDED`). En tienda va contraentrega si el negocio tiene pedidos contraentrega y
      pago pendiente si no.*
   6. **Listo:** «qué va a pasar ahora» en una línea de tiempo (hoy, esta semana, este mes) y al Inicio.
3. Mientras la configuración no termine, el Inicio muestra la tarjeta de progreso arriba; nada bloquea explorar.

**Cómo quedó** (fase B, frente B1): la bienvenida (`/w/:ws/bienvenida`) y el asistente (`/w/:ws/configurar`) van a
pantalla completa, fuera del menú (`features/onboarding/OnboardingShell.tsx` da el mismo contexto del espacio de
trabajo). El Inicio lleva a la bienvenida la primera vez de cada persona (`home.setup.welcome_seen`); se vuelve a ver
desde el menú de la persona («Ver la bienvenida», junto a «Asistente de configuración»). La bienvenida habla a una
tienda, a servicios o neutra si todavía no se sabe, con un ejemplo por pilar en la moneda del negocio. El asistente
guarda el paso en cada cambio («Seguir después» solo sale) y `?paso=` abre uno directo (lo usa «Para hoy»). Paso 1:
tipo de negocio y, si no es solo tienda, la vertical (con la sugerida); país, moneda y zona horaria se cambian ahí
mismo. Paso 2: descarga del plugin, el código con «Copiar» y su vencimiento, y la espera en vivo que celebra al
conectarse (`features/connect/ConnectCodePanel.tsx`); «No tengo WordPress» publica la página de contacto alojada con su
enlace y su QR. Paso 3: el avance y lo aprendido (para servicios: personas, contactos, citas y de dónde llegan). Paso 4:
WhatsApp con el mismo «Conectar con un clic» de Conexiones y el correo con su diálogo de Canales. Paso 5: las 3
recomendadas con su vista previa e interruptor; las que esperan algo (un canal, la web) lo dicen antes de intentar y su
botón lleva al paso del asistente que lo resuelve. Paso 6: lo que quedó y la línea de tiempo.

## 5. Inicio (el tablero del dueño)

De arriba abajo, en una sola columna amplia en el celular y en rejilla en escritorio:

1. **Encabezado**: saludo + nombre del negocio + periodo («Este mes»).
2. **Dinero generado** (tarjeta héroe): «Imagina Growth generó $X este mes», el desglose por estrategia y la
   comparación; en cero, el **potencial** con datos reales («Este mes hubo 37 carritos abandonados por $4,2 M»)
   y el botón para prender la estrategia que lo recupera.
3. **Para hoy** (máximo 5): acciones priorizadas que mezclan recomendaciones (hallazgos), configuración pendiente,
   conversaciones sin responder y leads por contactar; cada una con su botón.
4. **Tus estrategias**: las activas con su resultado del mes y las 2 o 3 sugeridas siguientes.
5. **Tu negocio**: ventas, pedidos, clientes nuevos y recurrentes con una minigráfica; enlace a Resultados.
6. Avisos de salud del sitio compactos (solo si hay algo).

**Cómo quedó** (fase B, frente B1): una sola llamada (`GET home`) y la de avisos de conexiones; `GettingStarted` y
`GET overview` ya no se usan en la SPA (los endpoints siguen). En el escritorio el dinero generado y «Para hoy» van
lado a lado; en cero, la tarjeta héroe muestra la cifra que se puede recuperar (`potential.headline` y `estimated`) y el
botón abre el detalle de la estrategia; sin web, invita a conectarla. Mientras el asistente no termine, conectar la web
o un canal en «Para hoy» abre su paso del asistente. «Tu negocio» pinta los KPI de `kpis.applicable` con su ayuda, su
variación (la primera respuesta, mejor cuanto menor) y la minigráfica, y para servicios de dónde llegan los contactos.

## 6. Estrategias (la pieza nueva)

Un catálogo por objetivo que traduce los módulos a resultados. Cada **estrategia** tiene: nombre de resultado, la
promesa en una línea, «cómo funciona» en 3 pasos, una vista previa (el mensaje o el widget), **requisitos** con su
estado (web conectada, canal, plantilla aprobada, módulo del plan), el **potencial** calculado con sus datos cuando se
puede, y un botón **Activar** que la deja funcionando con valores recomendados (se ajusta después). Activa: estado,
resultado (dinero generado, conversiones, mensajes) y «Ajustar» que lleva a la pantalla avanzada (el flujo, el widget,
el programa).

| Objetivo | Estrategias (bloques existentes que usa) |
|---|---|
| Recuperar ventas | Carritos abandonados (flujo 2), Checkout abandonado (3), Pago pendiente (5), Confirmar contraentrega (6), Navegación abandonada (4) |
| Volver a vender | Segunda compra (9), Reposición (10), Recuperar clientes en riesgo (11), Reactivar con IA (nodo `ai_reactivate`), Volvió el stock / bajó de precio (14) |
| Fidelizar | Puntos y niveles (programa de fidelización + flujos 12, 15), Cumpleaños (13), Referidos (programa + flujo 16) |
| Captar contactos | Popup de bienvenida con cupón (widget + flujo 1), Botón de WhatsApp con atribución (widget), Quiz de productos |
| Reputación | Pedir reseñas con foto (flujo 8), NPS y detractores (17), Reseñas de Google |
| Atención | Agente de ventas con IA en WhatsApp, Bandeja con asignación, Respuestas fuera de horario |
| Anuncios | Conversiones a Meta, Google y TikTok desde el servidor; audiencias sincronizadas |
| Servicios | Respuesta inmediata y seguimiento de leads (18), Calificación en el chat, Agenda con recordatorios (19), Renovación (20), Cotizador |

**Backend:** `App\Domain\Strategies` con un registro declarativo (clave, objetivo, audiencia tienda/servicios,
módulo del plan, requisitos verificables, cómo calcular el potencial, cómo activar y pausar reutilizando las acciones
existentes —`CreateFlowFromLibrary`, widgets, programas—, y cómo medir su resultado con `MoneyGenerated`). Nada de
lógica duplicada: la estrategia es una capa de orquestación y presentación. API: `GET strategies` (catálogo con
estado, requisitos y potencial por espacio de trabajo), `POST strategies/{key}/activate`, `POST strategies/{key}/pause`.

**Cómo quedó el backend (nov. 2026, `docs/modulos.md` M23, `hub/CLAUDE.md` §20)** y lo que cambió frente a este plan,
con el motivo:

- Se suma `GET strategies/{key}` (cómo funciona, vista previa y partes) para la pantalla de detalle, y el Inicio y el
  asistente tienen su API propia (`GET home`, `GET/PUT onboarding`, `POST onboarding/strategies`).
- **NPS y Reseñas de Google son de tienda:** la encuesta sale tras la entrega de un pedido y el enlace de Google va en
  la página de gracias de WooCommerce; un sitio de servicios sin pedidos nunca los mostraría.
- **«Reactivar con IA»** usa un flujo listo nuevo (`FlowLibrary::extras`, RFM «Hibernando») porque el nodo
  `ai_reactivate` no tenía flujo en la biblioteca; no aparece entre los 20 de Flujos.
- **«Respuestas fuera de horario»** es el mismo agente de ventas con el horario del equipo (`handoff_rules.hours`): el
  agente todo el día y fuera de horario son excluyentes.
- **Avisos de stock y asignación de la bandeja** vienen prendidos por defecto en sus módulos: cuentan como activas,
  pero no completan el paso 5 del asistente.
- Con WhatsApp conectado pero sin las plantillas aprobadas por Meta, las estrategias de mensajes se activan solo por
  correo (si hay correo) en lugar de quedar en borrador; nunca sale un WhatsApp con una plantilla sin aprobar.
- El potencial del Inicio se muestra con el dinero generado en cero o por debajo del 2 % de las ventas del mes.

**Cómo quedó la pantalla** (fase B, frente B1): arriba lo que ya generan (prendidas, dinero del mes y listas para
prender) y «Recomendadas para ti» según la vertical; después todo el catálogo por objetivo con pestañas de objetivo y
filtro de estado en la URL (`?objetivo=&estado=&todas=1`); las que no aplican al tipo de negocio se ocultan con «Ver
también…». El detalle se abre en una hoja en `/estrategias/:clave` (a pantalla completa en el celular) con lo que logró o
lo que hay en juego, cómo funciona, la vista previa real, lo que necesita (cada cosa con su botón), lo que va a crear y
prender o pausar con confirmación; si falta algo, el botón principal lleva a resolverlo. Después de prender: qué pasa
ahora y dónde verlo.

## 7. Sistema visual

- **Personalidad:** claro, cálido y confiable; premium sin ser frío. Referentes: Linear, Stripe, Shopify Polaris.
- **Color:** marca índigo (`--brand` ~#4F46E5) con un acento violeta para degradados suaves en tarjetas héroe; neutros
  pizarra; semánticos éxito/aviso/peligro/información con contraste AA en claro y oscuro; paleta de gráficas de 6
  colores. Tokens en CSS (variables) y en Tailwind (`igr:`), modo oscuro cuidado.
- **Tipografía (Inter):** título de página 24/32 semibold, sección 18/28, cuerpo 14/22, apoyo 13/20, cifras héroe
  36–44 con `tabular-nums`. Máximo dos pesos por bloque.
- **Espaciado y forma:** escala de 4 px; tarjetas `rounded-xl` con borde sutil y sombra mínima; respiración generosa
  (24–32 px entre bloques); contenido hasta 1200–1280 px de ancho, sin márgenes vacíos enormes.
- **Íconos e ilustración:** Lucide en «mosaicos» de color (ícono dentro de un cuadro redondeado con tinte de su
  categoría); ilustraciones SVG livianas propias en estados vacíos y en el onboarding (sin dependencias ni imágenes
  pesadas).
- **Movimiento:** 150–200 ms en hover, apertura de paneles y cambios de estado; respetar `prefers-reduced-motion`;
  esqueletos en lugar de spinners; nada de saltos de diseño.
- **Plantilla de página:** `PageHeader` (miga de la sección, título, una línea de para qué sirve, acción principal y
  secundarias), luego contenido. Mismo patrón en todas las pantallas.
- **Estados vacíos que venden:** ilustración + beneficio concreto + acción principal + «Cómo funciona».
- **Textos:** segunda persona, resultados antes que funciones, sin jerga ni siglas sin explicar, cifras con el formato
  del país. Una ayuda corta (ícono «?») en cada métrica.

## 8. Diseño adaptable

Se mantiene la regla de `hub/CLAUDE.md` §14 (barra superior con menú debajo de 1024 px, barra lateral contraíble,
nada desborda). La subnavegación de cada sección pasa a un control segmentado desplazable en el celular (también en
la tableta y en el escritorio con la barra contraída; ver §3). Acciones principales al alcance del pulgar en el
celular (barra inferior fija en formularios largos).

## 9. Rendimiento y calidad (metas)

- Inicio útil en < 2 s en 4G con caché fría; < 1 s al navegar entre pantallas (datos precargados al pasar el puntero
  o al enfocar).
- Paquetes por ruta < 150 kB gzip; nada de librerías pesadas en el arranque (gráficas, editor, constructor y PDF solo
  donde se usan).
- API del Inicio y de cada pantalla principal p95 < 300 ms con la demo (2.000 clientes, 10.000 pedidos); sin N+1;
  caché donde aplique; ClickHouse con las agregaciones correctas.
- Sin errores en consola, sin saltos de diseño (CLS < 0,1), accesible (AA, teclado, lectores de pantalla).

## 10. Entregables del lote

1. Sistema de diseño v2 y navegación nueva (base para todo lo demás).
2. Estrategias (backend y pantalla) e Inicio nuevo; bienvenida y asistente de configuración.
3. Rediseño de Clientes y Conversaciones; de Mensajes y En tu web; de Resultados y Configuración (Conexiones única).
4. Auditoría y arreglos de rendimiento.
5. `docs/producto/presentacion.md`: qué es, para quién, cómo se presenta en 5 minutos, cómo empieza un cliente y
   preguntas frecuentes (para el equipo comercial).

## 11. Negocios sin tienda (no todo es ecommerce)

Hoy casi todo está pensado para WooCommerce. La plataforma tiene que servir igual de bien a una web de servicios,
corporativa o de captación de clientes, con su propio lenguaje, sus estrategias, sus métricas y su demo.

### 11.1 Tipos de negocio y verticales

El asistente pregunta **qué tipo de negocio** es y, si no es tienda, **a qué se dedica**. Eso cambia los textos, las
estrategias recomendadas, el embudo de leads por defecto, las métricas del Inicio y los ejemplos.

| Tipo / vertical (clave) | Ejemplos | Embudo por defecto | Lo que más le importa |
|---|---|---|---|
| `store` Tienda online | moda, belleza, hogar, ferretería | — (pedidos) | carritos, recompra, reseñas |
| `professional` Servicios profesionales | abogados, contadores, consultores, agencias | Nuevo → Contactado → Reunión → Propuesta → Ganado/Perdido | responder rápido, cotizar, seguimiento |
| `health` Salud y bienestar | clínicas, odontología, estética, spa, gimnasios | Nuevo → Contactado → Cita agendada → Asistió → Paciente | citas, inasistencias, controles periódicos, reseñas |
| `education` Educación | academias, cursos, colegios, idiomas | Interesado → Info enviada → Visita/clase de prueba → Inscrito | inscripciones, fechas límite, recordatorios |
| `real_estate` Inmobiliaria | venta y arriendo | Nuevo → Contactado → Visita agendada → Oferta → Cerrado | interés por propiedad, visitas, seguimiento largo |
| `hospitality` Turismo, hoteles y restaurantes | hoteles, tours, restaurantes | Consulta → Cotizado → Reservado | reservas, reseñas de Google, temporadas |
| `automotive` Automotriz | talleres, concesionarios | Nuevo → Cotizado → Cita de taller/prueba → Cerrado | mantenimientos periódicos, cotizaciones |
| `b2b` Empresas B2B / industrial | distribuidores, manufactura, software | Nuevo → Calificado → Reunión → Propuesta → Negociación → Ganado | ciclos largos, cotizaciones, nutrición por correo |
| `other` Otro | — | Nuevo → Contactado → Ganado/Perdido | leads y seguimiento |

Un espacio de trabajo puede ser «tienda + servicios» (p. ej. una ferretería que también instala).

### 11.2 Qué cambia para un negocio sin tienda

- **Promesa en el Inicio:** «Convierte más contactos en clientes» con: leads del mes y de dónde vienen, tiempo de
  primera respuesta, citas agendadas y asistidas, tasa de cierre y **valor ganado** (de los leads ganados), más el
  dinero generado por las estrategias (leads ganados atribuidos a un mensaje, una campaña o un formulario).
- **Clientes:** el tablero de leads (embudo de su vertical) es la vista principal; «Hoy» del vendedor arriba.
- **Resultados:** embudo, fuentes de leads (formularios, WhatsApp, anuncios de Facebook/Instagram, Google), tiempo de
  respuesta, citas y asistencia, cotizaciones y valor ganado. Las métricas de pedidos se ocultan si no hay tienda.
- **Textos:** «cliente», «paciente», «estudiante» o «prospecto» según la vertical donde haga falta; nunca «carrito» ni
  «pedido» en un negocio sin tienda.
- **Demo:** un espacio de trabajo de demostración de servicios (por ejemplo una clínica odontológica o una
  inmobiliaria) con leads, citas, conversaciones, cotizaciones y formularios creíbles (`igr:seed-demo --tipo=…`).

### 11.3 Captación para negocios sin tienda (funcionalidades nuevas)

- **Formularios de cualquier plugin:** además de Contact Form 7 y WPForms, Elementor Forms, Gravity Forms, Fluent
  Forms y Ninja Forms (plugin del connector), con el mismo evento `form.submitted`.
- **Clics que valen:** llamar (`tel:`), escribir (`mailto:`), WhatsApp y agendar cuentan como señales de intención y,
  con datos, como conversiones hacia los anuncios.
- **Leads de anuncios:** formularios de Facebook e Instagram (Meta Lead Ads, con la conexión de Meta) y de Google Ads
  (formularios de clientes potenciales por webhook) entran como leads, con su origen y la campaña.
- **Página de captura y QR:** una página propia en el dominio de la plataforma (o el del cliente) con un formulario y
  las casillas de consentimiento, para negocios sin buena web o para el local físico: un QR para imprimir que abre la
  página o un chat de WhatsApp con un mensaje de alta.
- **Estado (hub):** páginas de captura, QR y chats de WhatsApp con atribución en modulos.md M12 («Páginas de captura y
  QR»); leads de Meta Lead Ads y de Google Ads en M18 («Leads de anuncios»); clics que valen en M06; orígenes `landing`,
  `meta_lead_ads` y `google_lead_form` de `lead.created` en el contrato v8 (eventos.md §8). Las pantallas del panel
  están en En tu web (`hub/resources/js/features/capture`; ver «Cómo quedó En tu web» en §3).

### 11.4 Biblioteca ampliada (flujos 21 en adelante, claves estables)

| # | Clave | Para | Qué hace |
|---|---|---|---|
| 21 | `lead_nurture` | servicios | Nutrición por correo: 4–5 mensajes de valor en 3 semanas; sale al agendar, cotizar o comprar |
| 22 | `quote_followup` | servicios, B2B | Cotización sin respuesta: WhatsApp al día 2, correo al 5, último aviso al 10; alerta al vendedor |
| 23 | `appointment_confirmation` | salud, servicios | Pide confirmar la cita 48 h antes con botones; si no confirma, avisa al equipo |
| 24 | `no_show_recovery` | salud, servicios | No asistió: mensaje amable con enlace para reagendar el mismo día |
| 25 | `post_service` | servicios | Después del servicio: cómo te fue, reseña en Google y enlace de referido |
| 26 | `periodic_service` | salud, automotriz, belleza | Control o mantenimiento cada N meses: recordatorio con enlace para agendar |
| 27 | `event_registration` | educación, B2B | Inscripción a evento o webinar: confirmación, recordatorio 1 día y 1 h antes, grabación después |
| 28 | `enrollment_followup` | educación | Interesado en un curso: información, testimonios y fecha límite de inscripción |
| 29 | `property_interest` | inmobiliaria | Interés en una propiedad: similares, agendar visita y seguimiento |
| 30 | `lost_lead_revival` | servicios | Lead perdido hace 90 días: volver a intentar con una novedad |
| 31 | `new_client_welcome` | servicios | Bienvenida a un cliente nuevo: qué esperar, contacto y próximos pasos |
| 32 | `services_reactivation` | servicios | Cliente sin servicio en N meses: mensaje personal con una razón para volver |
| 33 | `cross_sell` | tienda | Compró X: complementos de la categoría a los 7 días |
| 34 | `delivery_check` | tienda | 2 días después de entregado: ¿llegó bien? Si hay problema, alerta al dueño |
| 35 | `first_purchase_anniversary` | tienda | Aniversario de la primera compra con un detalle |

Cada flujo trae sus plantillas de WhatsApp (marketing o utilidad, según corresponda) y de correo, con variables
para su vertical, y su estrategia en el catálogo (§6).

### 11.5 Calendario comercial y plantillas de campaña

- **Calendario comercial por país** (CO, MX, VE): fechas que mueven ventas o consultas (Día de la Madre, del Padre,
  Amor y Amistad (CO), San Valentín, Buen Fin y Hot Sale (MX), Black Friday, Cyber Lunes, regreso a clases, primas de
  junio y diciembre (CO), Navidad, Año Nuevo, Halloween, Día del Niño, temporadas de vacaciones), cada una con la
  campaña sugerida (grupo de clientes, canal, plantilla lista, cuándo enviarla) y un aviso en «Para hoy» unas semanas
  antes. También para servicios (p. ej. «chequeo antes de vacaciones», «inscripciones de enero»).
- **Galería de plantillas** por objetivo y vertical (bienvenida, promoción, novedad, recordatorio, invitación a evento,
  saludo de temporada, encuesta, reseña), en WhatsApp y correo, que se instalan con un clic.

### 11.6 Cómo quedó en el backend (nov. 2026)

- La vertical se guarda en `workspaces.vertical` (`config/igr-verticals.php`), la elige el paso 1 del asistente
  (`PUT onboarding {vertical}`) y se cambia en Configuración (`PATCH` del espacio de trabajo). Sin elegir, la sugiere
  la industria; si no hay tipo de negocio, toma el que implica la vertical.
- Cada estrategia declara `business_types`, `verticals` y `recommended_for` por vertical; «Recomendadas para ti» y
  las 3 del paso 5 siguen la vertical. Las 15 de §11.4 están en el catálogo con su clave (la del flujo) y quedan
  «Próximamente» hasta que su flujo exista en la biblioteca: mientras tanto el asistente toma la siguiente de la lista
  de la vertical (p. ej. salud: recordatorios de la agenda y contactos al instante).
- El Inicio de un negocio sin tienda devuelve sus KPI (contactos del mes y de dónde vienen, primera respuesta, citas
  agendadas y cumplidas, tasa de cierre y valor ganado) con `applicable` y `definitions`, sin pedidos ni carritos.
- La estrategia del flujo 18 se llama `service_lead`, como el flujo (antes `lead_response` en el borrador del
  backend), para que todas las claves de estrategias de flujos coincidan con las de la biblioteca.

## 12. Mensajes: cómo quedó (fase B)

Pantallas de la sección Mensajes (automatizaciones, campañas, calendario comercial, plantillas y copiloto), en tienda y
en servicios. Lo que cambió frente al plan y por qué:

- **Automatizaciones** (`flujos`): cada una dice qué hace en una frase, su estado, lo que logró este mes (en pesos en
  una tienda; en un negocio de servicios, cuántos agendaron, confirmaron o avanzaron, con la palabra de su meta) y cómo
  va contra su grupo de control (sin grupo, «midiendo N de 30» o la diferencia en puntos de los últimos 90 días). Las
  que vienen de la biblioteca enlazan a su estrategia (`/estrategias/<clave>`, que abre su hoja) en lugar de repetirla.
- **«Nueva automatización» abre la biblioteca** (`?tab=biblioteca`) como galería: objetivos propios de la galería
  (recuperar ventas, que vuelvan, fidelizar, captar, reseñas, convertir contactos en clientes, citas y eventos; en
  servicios primero los dos últimos), «Para tu negocio / Ver todas», «Recomendadas para tu negocio» con el lugar de su
  estrategia para la vertical y la tarjeta «Desde cero». «Ver recorrido» abre el recorrido paso a paso (la misma lista
  del celular) con cada mensaje como le llega al cliente, con datos de ejemplo de su país, antes de crearla.
- **Constructor:** los pasos tienen nombres sin jerga («Cuándo empieza», «Preguntar algo», «Esperar a que haga algo»,
  «Avisar a otro sistema») y una línea de ayuda arriba de cada formulario; `wait` con `mode: until_date` y `condition`
  con `mode: date` tienen su formulario (fecha de referencia: la cita o una fecha del disparador; cuánto antes o
  después; qué hacer si ya pasó) y su resumen («Espera hasta 48 horas antes de la cita», «¿Falta más de 49 horas para la
  cita?», ramas «Sí, falta más» / «No, es muy pronto»).
- **Campañas:** el asistente pasa de 5 a 4 pasos (a quién → qué → cuándo → revisar; el canal se elige en «¿Qué les
  dices?» con cuántos aceptaron cada uno). «¿A quién?» muestra el alcance real por canal y consentimiento y el costo
  aproximado de WhatsApp; «¿Qué les dices?» la vista previa en el celular o en la bandeja; «¿Cuándo?» explica la
  ventana legal en una frase («Se enviará el lunes, 7 de diciembre a las 7:00 a. m. porque el sábado solo se puede
  enviar de 8:00 a 15:00 y el domingo no se envían mensajes comerciales»). El informe empieza por el dinero generado.
  Sin tienda no aparecen las reglas de compras.
- **Calendario comercial** (`campanas/calendario`, entrada propia en Mensajes): la próxima fecha en grande y las
  demás por mes, cada una con cuándo enviarla («cuanto antes» si ya pasó el día ideal), canal, plantilla, a quién y
  sus ideas (las de servicios cuando las de tienda hablan de regalos o envíos), y «Crear la campaña» en un clic, que la
  deja en borrador y abre la revisión. Las 3 próximas salen también arriba de Campañas. `?vista=calendario` lleva ahí.
- **Plantillas:** «Tus plantillas» por canal con el estado de Meta en palabras (aprobada, en revisión, rechazada con
  el motivo, falta conectar el número, lista para enviar a Meta con su botón) y «Galería» (antes «Biblioteca»;
  `?tab=biblioteca` sigue funcionando) por objetivo y tipo de negocio, con la vista previa realista e instalar en un
  clic. El selector de variables muestra solo los grupos del tipo de negocio, explica cada grupo (cita, lead,
  cotización, evento, enlaces para agendar y reseña en Google) y su ejemplo, sin «Fase N».
- **Copiloto:** «Cómo funciona» en tres pasos y ejemplos de pedidos por vertical (salud, educación, inmobiliaria,
  servicios profesionales, servicios en general o tienda) que abren el copiloto con el pedido escrito.

