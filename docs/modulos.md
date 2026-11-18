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

**Estado (Fase 1):** vista previa medida con 100.000 contactos, 150.000 pedidos y 5 millones de eventos: entre 137 ms y 1,45 s según el segmento (`igr:segments:benchmark`). Las entradas y salidas se calculan 15 s después del evento; el disparo de flujos se mide en la Fase 2, cuando existan los flujos. Las condiciones de mensajería, fidelización y servicios muestran la fase en que llegan.

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

---

## M08 — Plantillas y contenido · Fase 2

- **Editor de correo** de arrastrar y soltar (GrapesJS + MJML) con bloques dinámicos: producto, recomendaciones personalizadas, carrito del contacto, cupón único, puntos y nivel, reseñas, botón de WhatsApp.
- **Editor de plantillas de WhatsApp:** encabezado (texto, imagen, video o documento), cuerpo con variables, pie y botones (URL con seguimiento, respuesta rápida, llamada, copiar cupón). Plantillas de carrusel de productos cuando Meta las permita para la categoría.
- **Personalización** con sintaxis Liquid en sandbox: `{{ contacto.nombre | default: "hola" }}`, `{{ carrito.items }}`, `{{ cupon.codigo }}`, `{{ recomendaciones.para_ti | limit: 3 }}`, con vista previa con un contacto real.
- **Biblioteca en español** por industria (moda, belleza, ferretería y pinturas, mascotas, alimentos, tecnología, servicios profesionales, salud y bienestar, educación).
- **IA:** asuntos y preheaders, variantes para A/B, adaptar el tono de la marca, acortar para WhatsApp, revisar ortografía y advertir posibles problemas de categoría de plantilla.

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

**Listo cuando:** los 20 flujos funcionan de punta a punta en la tienda de prueba, y una prueba automática garantiza que ningún paso de marketing se ejecuta fuera de la ventana legal ni dos veces por reintentos.

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
