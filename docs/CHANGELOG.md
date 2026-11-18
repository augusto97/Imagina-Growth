# Historial de la especificación y de las fases

## 2026-11-18 — Cierre de la Fase 4 (Inteligencia avanzada e IA)

### Criterio «Listo cuando» (medido con `infra/e2e/fase4.sh`)

El recorrido usa un hub aislado (`igr_e2e4`) con la tienda demo sembrada (3.660 clientes y 10.000 pedidos), el servicio de predicciones (`hub/ml`), la tienda WooCommerce real conectada y una API de WhatsApp simulada. **No hay clave de Anthropic en este entorno:** el hub habló con un **simulador de la API de Anthropic** con guion (`infra/e2e/mock-anthropic.php`), por el mismo SDK y la misma `AiGateway` que en producción. Eso prueba los controles del hub y el camino de herramientas; la prueba con el modelo real (`php artisan igr:agent:eval`) queda pendiente de `IGR_ANTHROPIC_API_KEY`.

| Criterio | Resultado |
|---|---|
| Las predicciones se validan contra un periodo de reserva y la app muestra su precisión | **Sí.** Reserva de 90 días. CLV (BG/NBD × Gamma-Gamma): 634 compras predichas frente a 729 reales (−13,0 %), correlación por cliente 0,65, ingreso −11,5 % → «Alta». Abandono: AUC 0,67 → «Media». Próxima compra: el 13,8 % compró a 15 días o menos de la fecha esperada (error mediano 65 días) → «Baja». La página `inteligencia/predicciones` muestra cada modelo con «qué tan confiable es», el gráfico predicho contra real por decil y la distribución de P(activo). Propensión: «datos insuficientes» (33.689 sesiones al mes de 50.000) |
| Ninguna cifra de un texto de IA falta en los hechos (prueba automática) | **Sí.** `NumberValidator` y `NarrativeWriter` en el analista, «Pregúntale», los resúmenes, el copiloto y el contenido, y `ReplyGuard` en el agente. 54 pruebas automáticas (Ai, Intelligence, Agent, Copilot). En el recorrido, el analista que citó los hechos quedó con `source = ai`; con una cifra inventada ($987.654.321) se regeneró una vez y terminó en la plantilla sin IA, sin que la cifra llegara al informe. «Pregúntale» respondió «vendiste $215.286.200», validado contra el resultado de la consulta |
| El agente resuelve preguntas de catálogo sin inventar precios ni stock | **Sí, con el modelo simulado.** WooCommerce tenía «Pintura para Cielos Blanca Galón» a $58.900 con 163 unidades; el agente llamó `search_products` y `get_product` (comando firmado `product.lookup`) y envió «…cuesta $58.900 y tenemos 163 unidades disponibles». Con un precio inventado ($71.900) no se envió nada: el turno pasó a una persona con `unverified_numbers`. Una pregunta fuera del negocio no usó herramientas y recibió la negativa acotada. «Quiero comprarlo» armó el carrito restaurable, y la bandeja creó el enlace de pago de WooCommerce con el producto |

### Qué se entregó
- **Base:** `AiGateway` sobre el SDK oficial de Anthropic para PHP (Sonnet 5.5 para análisis, copiloto y agente; Haiku 5.5 para clasificar y resumir), caché del system, salidas estructuradas, herramientas estrictas, respaldo del servidor ante rechazos, costo en `ai_usage` y tope mensual; `NumberValidator`, `NarrativeWriter` y `PiiScrubber`; contratos de la bandeja (`ConversationAgent`, `InboundClassifier`, `InboundMessageReceived`); permisos `useInbox`, `manageInbox` y `useAi`.
- **Predicciones (M17):** servicio Python `hub/ml` (FastAPI) con BG/NBD, MBG/NBD y Gamma-Gamma en numpy y scipy, validación con reserva; próxima compra por mediana de categoría, P(activo), compras esperadas, CLV a 12 meses, bandas de abandono, afinidad por categoría y marca, siguiente producto, rango de precio, mejor hora dentro de la ventana legal y puntaje de interacción.
- **Hallazgos:** los 20 detectores de `inteligencia.md` §5.1 (21 a 23 de servicios, en la Fase 5), impacto con la fórmula y supuestos editables, vigencia, regla de descarte y «Hacer ahora» en un clic.
- **Analista y datos:** analista de los lunes con 3 acciones; «Pregúntale a tus datos» con una sola herramienta sobre la capa semántica; resúmenes de perfil y de reseñas; clasificador de entrantes y moderador de reseñas con IA.
- **Copiloto:** propuestas de campaña y de flujo validadas (segmento, horario legal, plantillas aprobadas, costo y cumplimiento) que solo crean borradores; redactor de asuntos, variantes y textos de WhatsApp con el tono de la marca.
- **Bandeja (M11):** WhatsApp multiagente con asignación, ventana de 24 h, notas, respuestas rápidas, venta desde el chat (producto en vivo, carrito, enlace de pago, cupón), métricas, PWA con notificaciones push (VAPID) y tiempo real con Laravel Reverb.
- **Agente de ventas:** herramientas con precio y stock siempre en vivo, cifras validadas en código contra lo que devolvieron, modos asistido y automático, traspaso, registro y revisión de cada turno.
- **Connector:** comando `order.create_payment_link`.

### Errores que encontró la integración y el recorrido (corregidos con prueba)
- **El agente bloqueaba preguntas nuevas tras una «BAJA» anterior:** las reglas miraban los entrantes unidos del historial. Además dependía de si caían en el mismo segundo, por eso la prueba fallaba a veces.
- **El validador leía las URL como cifras:** un enlace de carrito o de pago contaba como un número sin respaldo.
- **El abandono con BG/NBD simple daba AUC 0,21:** trata a quien compró una vez como activo. El servicio ajusta también MBG/NBD y se queda con el que mejor predice la reserva.
- **Detector 9 (primera compra estancada):** la consulta de la mediana fallaba con datos reales.
- **Los segmentos confirmaban transacciones abiertas:** `DROP TABLE` en MariaDB hacía commit; ahora es `DROP TEMPORARY TABLE`.
- **La cola `ai` no tenía trabajador en Horizon:** el clasificador, el agente y los resúmenes nunca habrían corrido.
- **El rol agente no veía ningún contacto:** ahora ve los de sus conversaciones asignadas.
- **Faltaba el comando del enlace de pago en el connector.**
- **Las pruebas de contrato fallaban en una base nueva** y dejaban filas entre pruebas.

### Pruebas al cierre
- **Hub:** 767 pruebas Pest (Larastan nivel 8 sin errores, Pint limpio) y 141 de Vitest en la SPA.
- **Servicio `hub/ml`:** 8 pruebas de pytest.
- **Connector:** 15 unitarias y 80 de WordPress; PHPCS 0 errores y 22 advertencias.
- **Tracker:** sin cambios desde la Fase 3 (56 de Vitest y 19 de Playwright).
- **Recorrido:** `infra/e2e/fase4.sh` en verde en dos corridas limpias.

### Cambios a la especificación (con su motivo, en el mismo commit que el código)
- **SDK oficial de Anthropic para PHP en lugar de Laravel AI SDK:** es el que documenta Anthropic y trae salidas estructuradas, herramientas estrictas, caché de prompts y respaldo ante rechazos. La clave es propia del hub (`IGR_ANTHROPIC_API_KEY`) y nunca se leen las `ANTHROPIC_*` del sistema.
- **Predicciones en numpy y scipy, no `lifetimes`:** se eligió el modelo (BG/NBD o MBG/NBD) que mejor predice la reserva.
- **Bandeja:** la intención se calcula por palabras al llegar y la de IA va en cola, para no llamar al modelo dentro del webhook de Meta. Una baja detectada por IA solo revoca con confianza de 0,8 o más; por debajo marca la conversación para revisión humana.
- **«Agregar al tablero»** fija la consulta en la página de Inteligencia, porque los tableros de analítica son fijos.
- **El informe semanal sale por correo:** por WhatsApp necesita una plantilla aprobada y el teléfono del dueño.

### Pendiente y desviaciones conocidas
- **[verificar] con el modelo real:** `php artisan igr:agent:eval --workspace=ws_…` (10 preguntas sobre la tienda conectada) y el resto de las funciones de IA, cuando se configure `IGR_ANTHROPIC_API_KEY`.
- **Predicciones:**
  - La imagen Docker del servicio `ml` no se construyó aquí; corrió con uvicorn.
  - Las bandas de abandono cayeron a los cortes fijos (0,3 y 0,7) con los datos de demo.
  - La propensión no se construyó: está detrás de su umbral.
  - El parámetro `a` de MBG/NBD quedó en su mínimo. [verificar] con datos reales.
- **Hallazgos:**
  - El 11 compara solo el valor a 180 días (falta el gasto en anuncios por canal).
  - El 7 necesita los Web Vitals de Imagina Turbo.
  - El 13 no detecta la variante «ventas creciendo rápido».
  - El 16, [verificar] el código de bloqueo de WhatsApp.
- **Bandeja:**
  - Los adjuntos guardan solo los metadatos (falta descargarlos de Meta).
  - La barra lateral de la app no se adapta al celular (la bandeja ocupa toda la pantalla).
  - No hay satisfacción por conversación.
- **Copiloto:**
  - Las campañas aún no adjuntan un cupón por persona.
  - `WhatsAppTemplateRules` está marcado [verificar] frente a las reglas vigentes de Meta.
- **Manual:**
  - El recorrido grabado de la fase.
  - Widgets de Elementor sin probar (desde la Fase 3).

## 2026-11-18 — Cierre de la Fase 3 (Captación y fidelización)

### Criterio «Listo cuando» (medido con `infra/e2e/fase3.sh`)

El recorrido usa la tienda demo (WooCommerce) conectada a un hub aislado (`igr_e2e3`), con correo por SMTP hacia un buzón local. La comerciante deja activos un popup con WhatsApp y cupón del 10%, la bienvenida de referidos, el programa de puntos, el de referidos y las solicitudes de reseña por correo con publicación automática en WooCommerce.

| Criterio | Resultado |
|---|---|
| Un visitante anónimo deja su WhatsApp en un popup | **Sí.** El popup sale del `widgets.json` real, con la casilla de WhatsApp sin marcar. El consentimiento queda concedido con origen `widget:wg_…` y la versión vigente del texto legal |
| Recibe el cupón | **Sí.** `BIENVENIDA-…` en pantalla, creado en la tienda con `coupon.create`, uno por persona y widget |
| Compra | **Sí.** Paga con ese cupón; el contacto del popup y el del checkout se fusionan. El pedido queda con `attributed_widget_id` (paso 4 de la atribución) |
| Gana puntos | **Sí.** Registro 100, pedido 350 (pendientes al pagar, confirmados al completar), reseña 50, reseña con foto 100 y referido 500: saldo 1.100, igual al libro, sin nada pendiente |
| Deja una reseña con foto | **Sí.** La solicitud llega por correo; desde el celular deja 5 estrellas y un JPEG con GPS en el EXIF. La foto servida ya no tiene EXIF ni GPS. Se publica sola y llega a WooCommerce como reseña nativa «verificada» con la foto (`review.publish`). La página de gracias ofrece Google a todos, sin filtrar por nota |
| Refiere a un amigo | **Sí.** «Mis recompensas» muestra sus puntos y su enlace `?ref=MART…`. Luis lo abre, la bienvenida de referidos le da `AMIGO-…` y compra con él. Su pedido queda con `attributed_referral_id` y canal `affiliates`; el referido termina `rewarded` |
| Todo queda en su perfil y atribuido | **Sí.** La línea de tiempo de Marta tiene `widget.submitted`, `order.paid`, `order.completed`, los `points.earned`, `review.requested`, `review.collected` y `referral.converted`. 0 rechazos en la ingesta y 0 eventos muertos en el outbox |

**La revisión manual del referido:** al probar desde la misma máquina, el referido de Luis sale con la señal antifraude `same_ip` y pasa a revisión. La comerciante lo aprueba en el script y el job de calificación lo premia. Es el comportamiento esperado.

### Qué se entregó
- **Base de la fase:**
  - Contrato v4: `context.referral_code` y `touch.referral_code`, y los esquemas de `points.*`, `tier.changed`, `wallet.*`, `referral.shared`/`converted`, `review.requested`/`collected`, `nps.answered` y `product_watch.*`.
  - 17 tablas nuevas y los endpoints públicos de §8.3.
  - Interfaces compartidas: `CaptureContact`, `LoyaltyLedger`, `ReferralRewards` y `RecommendationService`.
- **M12 Captación:**
  - Popup (centro, esquina, pantalla completa), formulario incrustado, barra, teaser, dos pasos, ruleta (el premio se sortea en el hub), quiz con ramas y recomendaciones, widget de WhatsApp y bienvenida de referidos.
  - Variantes A/B con ganadora automática, segmentación, programación y vista previa.
  - Doble opt-in por correo.
  - Estadísticas por variante con ingresos atribuidos.
- **M13 Fidelización:**
  - Libro de puntos solo de inserción e idempotente: pendientes, confirmados tras la devolución, revertidos en reembolsos y con vencimiento FIFO.
  - Niveles VIP con `customer.set_tier`.
  - Recompensas canjeables por cupón o saldo.
  - Saldo a favor con «Usar saldo» firmado y débito pendiente.
  - `/me`, `/points` y `/program`; los nodos «dar puntos» y «dar saldo» en los flujos.
- **M14 Referidos y afiliados:**
  - Códigos legibles y regalo único para el amigo.
  - Señales de fraude con revisión manual.
  - Recompensa al referidor tras la calificación.
  - Afiliados con comisiones y pagos en CSV, y el portal `/creadores/{token}` con enlace mágico.
  - Paso 3 de la atribución.
- **M15 Reseñas y NPS:**
  - Solicitudes programadas.
  - Formulario público con fotos y video.
  - Moderación con heurística y un punto para conectar la IA de la Fase 4.
  - Publicación, respuesta y retiro en WooCommerce.
  - Importación nativa, de CSV y de Judge.me.
  - Widgets públicos.
  - NPS y CSAT con alertas de detractores.
- **M16 Comercio en el sitio y audiencias (M06):**
  - Seis tipos de recomendación con reglas de asociación nocturnas.
  - «Avísame» por stock y precio, con tope.
  - Banners por audiencia.
  - Audiencias hacia Meta, Google y TikTok cada 12 h, con altas y bajas por hash.
- **En la tienda:**
  - Tracker: runtime de widgets y un paquete por tipo, cada uno con su límite de tamaño; captura de `?ref=`.
  - Plugin: bloques, shortcodes, widgets de Elementor, «Mis recompensas», «Usar saldo» y los comandos de reseñas y niveles.
- **Integración:**
  - Supresión y exportación con los datos de las Fases 2 y 3, y salida de las audiencias.
  - Línea de tiempo del perfil con los eventos nuevos.
  - `contacto.nivel` y `enlaces.referido` en las plantillas.
  - Consentimiento `ads` desde el banner.
  - Segmentos por mensajería (pendiente desde la Fase 2) y por fidelización.
  - Biblioteca de flujos: 14 de 20.
  - Conciliación del contrato con una prueba que fija cada respuesta.

### Errores que encontró la integración y el recorrido (corregidos con prueba)
- **Enlaces de baja y preferencias rotos (Fase 2):** salían envueltos en `/c/`, por `LinkRewriter`.
- **`tienda.url` vacía (Fase 2):** salía vacía en todas las plantillas porque buscaba un estado de sitio que no existe.
- **Generador de la tienda demo no determinista:** según qué pruebas corrieran antes, una segunda corrida salía distinta; ahora usa su propio motor aleatorio.
- **13 diferencias entre la tienda y el hub:** las más graves eran estas.
  - Sin las banderas `onsite` en `/v1/s/config`, el plugin nunca mostraba puntos, «Mis recompensas» ni «Usar saldo».
  - En el formulario de dos pasos, decir que no al WhatsApp perdía el cupón del primer paso.
  - Las fechas de vencimiento salían un día antes en Colombia.
- **Texto para quien refiere:** decía «tu primera compra» en vez de «su».

### Pruebas al cierre
- **Hub:** 649 pruebas Pest (Larastan nivel 8 sin errores, Pint limpio) y 122 de Vitest en la SPA.
- **Tracker:** 56 de Vitest y 19 de Playwright contra la tienda. El tracker pesa 5.733 B gzip; los paquetes de widgets, de 958 a 6.137 B gzip, todos dentro de su límite. El quiz está a 7 B de su límite.
- **Connector:** 15 unitarias y 78 de WordPress. PHPCS: 0 errores y 22 advertencias.
- **Recorrido:** `infra/e2e/fase3.sh` en verde en una corrida limpia.

### Cambios a la especificación (con su motivo, en el mismo commit que el código)
- **api-connector.md §8.3, reescrito con las formas finales:**
  - Llaves de `design` y `content` de los widgets y `consents` arriba en `widgets.json`.
  - Respuesta del envío del widget; `/reco` con `fallback` y `/banner` con `id`.
  - `/watch` con `consent_version` y `available`; `/program`; `/me/redeem` con `wallet`.
  - `/reviews?scope=all`.
  - Confirmación del doble opt-in en `/p/{key}/confirmar/{token}`: `/confirmar/` lo tomaba la SPA.
- **§5 y §6:** `onsite` en `/v1/s/config`; `customer.set_tier` responde `422 role_not_allowed` con roles que dan más que `read`.
- **`?ref=`:**
  - Se deja en la URL: no es un dato personal y otros plugins de afiliados lo leen.
  - `context.referral_code` viaja aunque no haya consentimiento de analítica, porque identifica a quien refiere, no al visitante.
- **Puntos por referir:** se configuran solo en Referidos (M14), para que no haya dos valores que se contradigan.
- **Biblioteca de flujos:**
  - 12 (VIP), 15 (puntos por vencer, con el nuevo disparador por fecha `points_expiring_at`) y 16 (invitar a referir) son flujos.
  - 16 se dispara con los promotores del NPS; la «segunda compra» espera a que el disparador acepte varias condiciones.
  - 8 (pedir reseña), 14 (avisos de stock y precio) y 17 (NPS) los hace su módulo y la tarjeta lleva a su configuración, para no escribirle dos veces a nadie.
- **Consentimiento `ads`:** sale de la decisión del banner de cookies sobre marketing de un visitante identificado. Sin una fuente, el modo estricto dejaba las audiencias vacías.

### Decisiones pendientes del producto
- **[validar con el abogado]** Que aceptar las cookies de marketing valga como consentimiento para subir el correo con hash a audiencias, y la regla de «según el sitio» (todos menos quien revocó).
- **[validar con el abogado]** Que las solicitudes de reseña y los avisos de stock sean de marketing por defecto; se pueden reclasificar con justificación y queda auditado.
- **[validar con el abogado]** Los textos de consentimiento por defecto.

### Pendiente y desviaciones conocidas
- **[verificar]** con cuentas reales:
  - Las API de audiencias de Meta, Google (Ads API + Data Manager) y TikTok.
  - El encabezado `CF-IPCountry` de Cloudflare para el país en `visitor.json`.
  - El enlace de reseñas de Google Business.
  - Las fotos HEIC del iPhone.
- **Tienda:**
  - Widgets de Elementor sin probar (Elementor no está en la tienda de prueba).
  - «Avísame» por variación.
  - Los puntos en el carrito de bloques.
  - Clics de recomendaciones sin medir.
  - `_igr_ct` puede no actualizarse con la Store API.
  - El cuadro de saldo aparece cuando responde `/me` (un pequeño salto en el checkout).
  - El beneficio «envío gratis» del nivel no se aplica en la tienda.
- **Hub:**
  - Vencimiento del saldo.
  - Al fusionar contactos, el libro de puntos se mueve en el job nocturno.
  - «Llegó referido» en segmentos se actualiza con el siguiente evento o el recálculo nocturno.
  - Las reseñas nativas de WooCommerce no traen texto (haría falta un comando `review.get`).
  - Sin consentimiento de analítica, el segundo paso de un formulario de dos pasos se une al primero solo por la identidad normal.
- **Manual:**
  - El recorrido grabado de la fase.
  - Revisar en el navegador las pantallas nuevas de segmentos (tienen pruebas de componentes).

## 2026-11-18 — Cierre de la Fase 2 (Mensajería y automatizaciones)

### Criterio «Listo cuando» (medido con `infra/e2e/fase2.sh`)

El recorrido usa la tienda demo (WooCommerce 11.3) conectada a un hub aislado, con correo por SMTP hacia un buzón local y el flujo «Carrito abandonado» de la biblioteca activado en un clic. Las esperas del flujo (1 h, 4 h y 24 h) se publicaron en 1 minuto desde el constructor, como lo haría la comerciante, para correr la prueba en tiempo real.

Marta compra por primera vez y acepta recibir correos. Vuelve con las mismas cookies, arma un carrito, entra al checkout y se va. El job de carritos lo marca abandonado; `--at` simula los 45 minutos. El flujo le envía el correo «Marta, dejaste algo en tu carrito». Ella lo abre en otro dispositivo, la tienda le restaura el carrito y paga con tarjeta.

| Criterio | Resultado |
|---|---|
| El flujo de carrito abandonado recupera pedidos en la tienda de prueba de punta a punta | **Sí.** El carrito quedó `recovered` con el pedido nuevo: `restored_from` une el carrito del enlace con el abandonado. El run salió por la meta (`goal_reached`), con un paso por nodo. El correo salió por SMTP con baja en un clic (RFC 8058) y se registró el clic del enlace rastreado. ClickHouse tiene `cart.abandoned`, `flow.entered`, `message.queued`, `message.sent`, `message.clicked` y `flow.exited` |
| Ningún mensaje de marketing sale un domingo, un festivo o fuera de horario en Colombia (con prueba automática) | **Sí.** Hay cuatro capas de prueba, detalladas debajo de la tabla |
| Cada pedido recuperado aparece atribuido | **Sí.** `attributed_message_id` apunta al correo y `attributed_flow_id` al flujo. El canal del último toque es `igr_email` |

Las cuatro capas de prueba de la ventana legal:
- **`SendPolicyColombiaTest`** (19 pruebas):
  - De lunes a viernes, de 7:00 a 18:59; desde las 19:00 se aplaza.
  - El sábado se aplaza a partir de las 15:00; a las 15:01, al lunes.
  - Domingos.
  - Festivos fijos, trasladados por la Ley Emiliani y de Pascua.
  - Cambio de día.
  - Frecuencia de 1 al día sumando canales, con dos reservas simultáneas.
  - Excepciones de festivos.
- **Ventana justo antes de enviar:** el job vuelve a mirar la ventana antes de llamar al proveedor.
- **Flujos y campañas:** en sus pruebas, un paso del domingo a las 10:00 sale el lunes a las 7:00 y un reintento nunca reenvía.
- **El recorrido:** domingo, 8 de diciembre y sábado a las 15:01 en Bogotá se aplazan.

**La ventana legal y la hora del recorrido:** el recorrido corrió a la 1:30 de Bogotá, con la ventana cerrada. Por eso la zona horaria de Marta se fijó en una donde estuviera abierta (`fase2-hub.php reloj`): con la de Bogotá, el correo habría salido a las 7:00.

### Qué se entregó
- **Base de la fase:**
  - Contrato v3: `order.cart_token`, `cart.updated.restored_from` y los esquemas de `cart.abandoned`, los 11 `message.*`, `whatsapp.inbound` y `flow.entered`/`flow.exited`.
  - 18 tablas nuevas.
  - **SendPolicy**: ventana legal por país, festivos con excepciones editables (`igr:holidays`), frecuencia atómica en Redis, consentimiento, supresión, registros de exclusión, cuenta del canal y ventana de 24 h de WhatsApp.
  - Interfaces compartidas: despachador, renderizador, emisor de cupones y enlace de carrito.
- **Carritos y cupones:**
  - Proyección de carritos y abandono con `cart.abandoned`, que trae los productos y el enlace de restauración firmado.
  - Recuperación o conversión al llegar el pedido.
  - Cupones del hub creados en el sitio y redimidos.
  - El `_igr` de un enlace vincula el navegador con el contacto, sin fusionar perfiles.
- **M07 Canales:**
  - Correo: SES, con el asistente DKIM/SPF/DMARC y el calentamiento, o SMTP.
  - WhatsApp: oficial (Embedded Signup o conexión manual), con plantillas creadas en Meta desde la app; o Zender, con el riesgo aceptado, topes fijos y sin marketing.
  - SMS por Twilio.
  - El despachador es idempotente y devuelve el cupo de frecuencia si el envío no llega al proveedor.
  - Webhooks con firma verificada.
  - Palabras de baja por WhatsApp; baja en un clic y centro de preferencias.
  - Supresión e importación del RNE y el REPEP.
  - Atribución por mensaje y la pestaña «Mensajes» del perfil.
- **M08 Plantillas:**
  - Liquid en sandbox, con revisiones: un correo de marketing sin enlace de baja no puede quedar listo.
  - Editor de correo GrapesJS + MJML con bloques dinámicos.
  - Biblioteca de 29 correos y 7 SMS por industria.
- **M09 Flujos:**
  - Disparadores por evento, segmento, fecha, umbral, webhook o a mano.
  - Nodos de espera, condición, A/B, canal, envío, cupón, etiqueta, atributo, aviso al equipo y webhook.
  - Metas, grupo de control, versionado, analítica por nodo con incremento y modo prueba.
  - Constructor con React Flow.
  - Biblioteca: 11 flujos funcionan hoy; 9 quedan marcados para su fase (3 o 5).
- **M10 Campañas:**
  - Asistente de 5 pasos con la revisión de cumplimiento y el costo estimado.
  - Envío por lotes idempotente.
  - A/B con ganadora automática, grupo de control e incremento.
  - Calendario comercial de Colombia, México y Venezuela.
- **Connector:**
  - Comandos `coupon.create`, `coupon.disable`, `order.add_note`, `product.lookup` y `order.status`.
  - Carrito restaurable (`?igr_cart=`) sin llamadas al hub.
  - `cart_token` en el pedido, con el contrato v3.
- **Consentimiento:** el modo de cookies se elige en el panel (decisión posterior al cierre de la Fase 1, ver abajo).

### Errores que encontró el recorrido (corregidos con prueba)
- **Carrito recuperado:** el enlace de recuperación arma un carrito nuevo en WooCommerce. Antes el pedido cerraba ese carrito nuevo como convertido y el abandonado nunca quedaba recuperado. Ahora `carts.restored_from_id` los une.
- **Atribución en el mismo segundo:** WooCommerce guarda la fecha del pedido sin milisegundos. La visita que llegó desde el correo en ese mismo segundo quedaba fuera, y el pedido caía al canal de una visita anterior. Ahora cuenta hasta el final de ese segundo.
- **Imagen rota en el correo:** un producto sin imagen dejaba `<img src="">`, porque `item.imagen != ""` se cumple con el valor nulo.
- **Integración entre forks:** funciones auxiliares de las pruebas con el mismo nombre (abortaban la suite completa) y la variable `campana` de las campañas en las plantillas.

### Pruebas al cierre
- **Hub:** 441 pruebas de Pest, Larastan nivel 8 sin errores y Pint.
- **SPA:** 90 pruebas de Vitest, TypeScript estricto, ESLint y build.
- **Tracker:** 22 pruebas de Vitest y 11 de Playwright. Pesa 5.629 B gzip.
- **Connector:** 15 pruebas unitarias y 67 con WordPress y WooCommerce reales. PHPCS sin errores.

### Cambios a la especificación (con su motivo, en el mismo commit que el código)
- `eventos.md` §4.6 y §8: contrato v3.
- `api-connector.md`:
  - §6: los comandos de la Fase 2, con sus errores.
  - §6.1: el enlace de carrito restaurable.
  - §5.1 y §7: el modo de consentimiento elegido en el panel.
- `modelo-de-datos.md` §12: las tablas de la Fase 2 y sus diferencias. `messages` y `flow_run_steps` quedan sin partición mensual por las claves ULID, y la purga la hace un job.
- `cumplimiento.md`:
  - §3.3: los tres modos de cookies.
  - §5 y §8: notas de implementación de WhatsApp y correo.
- `modulos.md`: M04 estado medido, y notas de implementación de M07, M08, M09 y M10.
- `hub/CLAUDE.md`:
  - §6: segmentos.
  - §7: flujos.
  - §8: contratos internos, enlaces rastreados y `_igr`.
  - §15: colas `flows`, `messages:*` y `redis-long`.

### Decisiones pendientes del producto
- **Frecuencia frente al carrito abandonado.** Con el tope colombiano de 1 mensaje comercial al día sumando canales (cumplimiento.md §4.2, **[validar]**), el segundo toque del flujo (correo a las 4 h) se aplaza al día siguiente: no se pierde, pero llega tarde. Hay que decidir si los flujos de recuperación tienen su propio tope o si cambia el valor por defecto.
- **Plantillas de WhatsApp y la biblioteca.** Meta debe aprobar cada plantilla, así que la biblioteca no trae plantillas de WhatsApp listas. Si la tienda tiene WhatsApp activo, la comerciante elige la plantilla aprobada en «Personalizar» antes de activar el flujo; sin WhatsApp, el flujo se activa solo con correo.
- **Carrito y checkout abandonados al mismo tiempo.** Los flujos 2 y 3 no se excluyen por defecto, así que una persona puede entrar a los dos.

### Pendiente y desviaciones conocidas
- **Por verificar con cuentas reales:**
  - Meta: la ruta `/marketing_messages`, la versión de la API, Embedded Signup, que una plantilla creada desde la app quede aprobada y los nombres de los niveles de límite.
  - SES: el conjunto de configuración y SNS, y que un correo pase la revisión de Gmail con DKIM alineado. Es el criterio de M07.
  - La detección de aperturas automáticas de Apple.
  - El formato de Zender y la firma de Twilio.
  - Las tarifas de WhatsApp: valores de la tabla de julio de 2025, de memoria.
  - Las fechas variables del calendario comercial.
- **WhatsApp de punta a punta:** el recorrido usó solo correo. El envío por WhatsApp está cubierto por pruebas con el proveedor simulado.
- **Los 20 flujos de M09:** 11 funcionan; los de fidelización, reseñas, NPS, referidos, avisos de stock, citas, leads y renovaciones llegan con sus módulos (Fases 3 y 5).
- **Otros pendientes:**
  - El driver de Infobip.
  - Avisarle a la dueña cuando baja la calidad del número; hoy solo se registra, y la política ya frena el marketing.
  - Subir la imagen o el video de encabezado de una plantilla de WhatsApp.
  - Las variaciones «cualquiera» en el carrito restaurado.
  - `order.status` con plugins de numeración secuencial.
- **Arrastrados de fases anteriores:** el recorrido manual grabado (lo graba el equipo), la prueba de carga con k6 y `caddy validate`.

## 2026-11-18 — Cierre de la Fase 1 (Conocer al cliente)

### Criterio «Listo cuando» (medido con `infra/e2e/fase1.sh`)

La tienda demo de la Fase 0 (WooCommerce 11.3, 10.000 pedidos en COP de 24 meses) se conecta a un hub aislado e importa su historial en 66 s. Después, en Chromium real, una visitante llega desde un anuncio de Facebook (`utm_source=facebook&utm_medium=paid_social&fbclid=…`), acepta el banner propio, mira un producto, busca, compra con tarjeta por la Store API (la del checkout de bloques) marcando WhatsApp y correo, y responde «¿cómo nos conociste?». Meta se simula: `fbevents.js` en el navegador y la Graph API en el servidor.

| Criterio | Resultado |
|---|---|
| El tablero responde en menos de 1,5 s con un año de datos | **Sí.** Los 12 tableros, pedidos por HTTP con la sesión y el 2FA de la dueña, sin caché y con 12 meses comparados con los 12 anteriores: máximo **649 ms** (Clientes), Inicio 121 ms. Con navegación (`igr:seed-demo --browsing`: 10.000 pedidos, 1,78 millones de eventos de navegación y 342.025 sesiones), el p95 más lento fue Canales con 592 a 820 ms |
| Meta muestra la deduplicación correcta entre píxel y servidor | **Sí, contra Meta simulado.** El `Purchase` del píxel y el de la Conversions API llevan el mismo `event_id` (el UUIDv5 de `order.paid`), con el mismo valor y la misma moneda, `fbc` del clic, `fbp`, y correo y teléfono en SHA-256. El píxel dispara una sola vez aunque se recargue la página, y cada `PageView` tiene su `page.viewed` con el mismo `event_id`. **Falta verlo en el Administrador de eventos con una cuenta real** (ver «Pendiente») |
| El informe semanal por correo llega al dueño | **Sí.** Se entregó por SMTP a la dueña: «Tu semana en Pinturas La 14: $22.267.950 en ventas». Queda registrado en `report_deliveries` y no se envía dos veces la misma semana |

El mismo recorrido comprueba 36 puntos más, todos en verde:
- Identidad: la cookie del navegador queda en el mismo contacto que el pedido.
- Atribución: el pedido queda en `paid_meta`.
- Perfil 360: la línea de tiempo tiene navegación, carrito, checkout, pedido, encuesta y consentimiento.
- Consentimiento: queda con su texto legal versionado y la huella de la IP.
- Datos personales: ningún evento guarda el correo en claro.
- Segmentos: la compradora entra a «Nuevos» con su `segment.entered`.
- RFM: queda calculado para 1.823 clientes.

El perfil abre en 869 ms con la SPA incluida.

### Qué se entregó
- **Contrato v2:**
  - `identify.token` firmado por el sitio.
  - Eventos que solo emite el hub: `survey.answered`, `segment.entered`/`exited`, `consent.granted`/`revoked` y `conversion.delivered`. Si llegan desde un sitio, se rechazan.
  - En el navegador, la identidad solo se acepta con un token firmado.
- **Tracker (5,6 KB gzip):**
  - Registra vistas, UTM y `_igr`, clics a WhatsApp con token, búsquedas e `identify`.
  - Funciona en modo estricto o informado según haya banner o no.
  - Carga aparte, solo cuando hacen falta, el paquete de píxeles (Meta, TikTok y Google con el `event_id` del servidor; la compra solo con `igrOrder.paid`), el banner y los widgets.
- **Connector:** contexto de página, tokens firmados, banner propio y adaptadores de banners, casillas de consentimiento en los checkouts clásico y de bloques, y el espacio para la encuesta.
- **Hub:**
  - Identidad con fusiones auditadas y reversibles por 30 días, perfil 360, importación CSV con declaración del origen del consentimiento, registro de consentimiento con evidencia, encuesta «¿cómo nos conociste?» y solicitudes de acceso y borrado con plazos legales.
  - Segmentación (M04): vista previa entre 137 ms y 1,45 s con 100.000 contactos y 5 millones de eventos, recálculo en tiempo real y nocturno, y biblioteca de 17 segmentos.
  - Analítica (M05): capa semántica y 12 tableros. También RFM, 5 detectores de hallazgos e informe semanal.
  - Medición (M06): Conversions API de Meta, Data Manager de Google y Events API de TikTok con reintentos, atribución con respaldo y gasto en anuncios.
- **SPA:** Analítica, Hallazgos, Contactos, Perfil 360, Importar, Segmentos (constructor visual), Medición, Consentimiento y Encuesta, con es-CO, es-MX y es-VE.

### Errores que encontró el recorrido (corregidos con prueba)
- El perfil de un contacto con RFM daba 500 porque `rfm_computed_at` no tenía cast a fecha.
- `survey.answered` se guardaba sin el `anonymous_id`, así que no salía en el perfil. Además, la respuesta que llega antes que el pedido solo se ligaba por un job que reintenta varios minutos después. Ahora se liga al proyectar el pedido.
- Los comandos `igr:*` leían `--workspace` cada uno a su manera. Con un ID que no reconocían, unos terminaban en 0 sin hacer nada y `install-library` aplicaba a todos los espacios de trabajo. Ahora `WorkspaceOption` es uno solo y falla con un mensaje claro.
- La segmentación usaba `potential_loyalists` y el cálculo RFM `potential_loyal`. Una prueba amarra ahora las claves.
- El perfil mezclaba zonas horarias: la actividad salía en hora de Bogotá y la cabecera en la del navegador. Toda la SPA usa ahora la zona del espacio de trabajo.
- Horizon no atendía las colas `analytics` ni `triggers`, y los jobs largos tenían un `retry_after` de 90 s, menor que su timeout. Ahora hay supervisores para esas colas y la conexión `redis-long`.

### Pruebas al cierre
- **Hub:** 297 pruebas de Pest, Larastan nivel 8 sin errores y Pint.
- **SPA:** 64 pruebas de Vitest, TypeScript estricto y ESLint.
- **Tracker:** 22 pruebas de Vitest, límite de tamaño y 9 pruebas de Playwright contra WooCommerce real y un hub simulado que valida cada evento contra los esquemas.
- **Connector:** 15 pruebas unitarias y 55 con WordPress, WooCommerce, Contact Form 7 y WPForms reales; PHPCS sin errores.
- **CI:** nuevos trabajos para el tracker y su prueba de navegador.

### Cambios a la especificación (con su motivo, en el mismo commit que el código)
- `eventos.md`: contrato v2 (sube `schema_version`), regla de identidad del navegador, esquemas de los eventos del hub y tabla de hash por plataforma.
- `api-connector.md`:
  - §5.1: textos de consentimiento y píxeles en la configuración.
  - §5.4: solicitudes de privacidad desde WordPress.
  - §7: datos de la página, tokens firmados, campos de `igrConfig`, `igrOrder.paid` y modo de consentimiento efectivo.
  - §8: widgets y la respuesta «otro» de la encuesta.
- `modelo-de-datos.md` §11: columnas de la Fase 1, detalle de `conversion_deliveries` e índices bloom.
- `inteligencia.md` §3: claves RFM y segmentos del sistema `rfm_<clave>`.
- `modulos.md` M04: estado medido.
- `hub/CLAUDE.md`:
  - Decisiones de implementación.
  - §6: segmentos (retardo de 15 s, primer cálculo sin eventos y vista previa con tabla temporal).
  - §9: respaldo de la atribución.
  - §15: colas y `redis-long`.

### Pendiente y desviaciones conocidas
- **Modo de consentimiento (decidido después del cierre):** se elige en el panel entre «Según el sitio» (por defecto), «Estricto» e «Informado». El plugin y el hub lo aplican con el mismo criterio (`cumplimiento.md` §3.3, `api-connector.md` §7).
- **Verificar con cuentas reales** (`[verificar]` en `eventos.md` §7 y `config/igr-measurement.php`):
  - La deduplicación en el Administrador de eventos de Meta.
  - Las versiones de las API.
  - Los campos de Data Manager de Google.
  - Los nombres de evento de TikTok.
- **Recorrido manual grabado** (CLAUDE.md §6): lo graba el equipo. El recorrido automático deja video del panel y capturas, pero no reemplaza esa grabación.
- **Lo que llega en otras fases:**
  - Que una entrada o salida de segmento dispare un flujo en menos de 2 minutos se mide en la Fase 2, cuando existan los flujos. Hoy el recálculo corre 15 s después del evento.
  - Las condiciones de mensajería, fidelización y servicios muestran la fase en que llegan.
- **Sin probar:**
  - Gravity Forms y Elementor Pro, que son de pago.
  - Safari con ITP en un navegador real.
  - El espacio de la página de gracias frente al CLS en temas reales.
  - La regla de Cloudflare para el ping en sitios con «cache everything».
- **Otros pendientes:**
  - Agregar o quitar a mano miembros sueltos de una lista fija.
  - El admin del plugin en React.
  - La prueba de carga con k6 (500 eventos/s, pendiente desde la Fase 0).
  - `caddy validate` sobre `infra/Caddyfile`.
- **Limitaciones conocidas:**
  - El filtro por dispositivo solo acota las métricas de tráfico, porque los pedidos no traen dispositivo.
  - La tienda demo no trae historial de navegación. Por eso, en el recorrido, «Sesiones» y «Conversión» de 12 meses salen en 0; el rendimiento con navegación se midió con `igr:seed-demo --browsing`.

## 2026-11-18 — Cierre de la Fase 0 (Cimientos)

### Criterio «Listo cuando» (medido con `infra/e2e/fase0.sh`)

Tienda WooCommerce 11.3 (HPOS) con `wp igr demo-store`: 2.000 clientes (1.296 registrados), 10.000 pedidos en COP de 24 meses, 511 reembolsos, 334 reseñas, pagos PSE, Nequi, contraentrega y transferencia. Hub con MariaDB 11.4, ClickHouse 25.8 y Redis 7.

| Criterio | Resultado |
|---|---|
| Se conecta en menos de 2 minutos | **1 s** (código manual y `wp igr connect`) |
| El backfill completo termina sin errores | **Sí**: el plugin envió todo en 47 s; el hub terminó de procesar 20.917 eventos a los 224 s, con **0 rechazos** |
| Conteos de pedidos y venta total coinciden al 100% | **Sí**, en los 7 estados: 10.000 / 10.000 pedidos y $1.916.485.780 / $1.916.485.780 |

Comprobaciones adicionales: 0 contactos con contadores desfasados, 0 pedidos sin contacto y ninguna propiedad con correos en ClickHouse. La misma prueba con `php artisan igr:seed-demo` (sin WordPress) también concilia al 100%.

### Qué se entregó
- **Contrato v1:** 43 esquemas JSON con ejemplos válidos e inválidos y el contrato en el cable `docs/api-connector.md` (firma, handshake, ingesta, configuración, conciliación, comandos e IDs deterministas).
- **Hub (Laravel 13):** tenancy con pruebas de aislamiento, M01 (cuentas, espacios de trabajo, roles, agencia, 2FA obligatoria, invitaciones, auditoría), M02 (conexión, rotación, desconexión, conciliación), ingesta `/v1/s` y `/v1/b` con Redis Streams, worker con dedupe, esquemas, canal (inteligencia §2), identidad determinista, proyecciones en MariaDB y ClickHouse sin PII, festivos de Colombia y México, `igr:seed-demo`, `/health` y el `ask` de TLS bajo demanda.
- **SPA:** acceso con 2FA, onboarding, `/connect` para el plugin, sitios con progreso y conciliación, Inicio, Contactos, Equipo, ajustes y auditoría.
- **Connector 0.1.0:** conexión, outbox con reintentos, recolectores de WooCommerce, catálogo con conciliación por hash, backfill con conciliación, comandos firmados, admin en PHP, WP-CLI y la tienda demo.
- **Infraestructura:** Docker Compose local y de producción, imagen FrankenPHP, Caddyfile, copias de seguridad con guía de restauración y CI (hub, SPA y connector).

### Pruebas al cierre
Hub: 151 de Pest (unidad, contrato, integración y tenancy) contra bases reales, Larastan nivel 8 y Pint sin errores. SPA: TypeScript estricto, ESLint y 16 de Vitest. Connector: 15 unitarias y 27 con WordPress + WooCommerce reales que validan cada evento contra los esquemas del hub; PHPCS sin errores.

### Cambios a la especificación (con su motivo, en el mismo commit que el código)
- `eventos.md`: `last_touch`, foto opcional del pedido en eventos de estado, `modified_at`, `catalog.category_deleted`, datos de cliente para la importación, `hash` en `catalog.category_upserted` y `items[].cost` como costo unitario. Se hicieron antes de publicar el contrato v1, por eso no sube `schema_version`.
- `modelo-de-datos.md`: `ch_id` numérico para ClickHouse, `site_connect_codes`, `workspace_invitations`, columnas de sitios y pedidos, y `email_hash`/`phone_hash` como HMAC por espacio de trabajo (un SHA-256 plano se revierte con diccionario).
- `hub/CLAUDE.md`: auditoría con tabla propia, Fortify, opis/json-schema, cliente propio de ClickHouse y el worker de ingesta fuera de Horizon, con transacción por lote.

### Pendiente y desviaciones conocidas
- **Recorrido manual grabado** (CLAUDE.md §6): lo debe grabar el equipo con el entorno local de `infra/README.md`. Hay capturas de la SPA de la prueba automática, pero no un video.
- La prueba de punta a punta usa WP-CLI contra WooCommerce real, no Playwright con `wp-env`; la matriz de temas, cachés y pasarelas de `connector/CLAUDE.md` §14 queda para antes de la primera versión pública.
- El admin del plugin es una página PHP; el admin en React (`admin-app/`) llega después.
- `infra/Caddyfile` no se validó con `caddy validate` (no hubo acceso al binario en este entorno).
- Ingesta medida en ~117 eventos/s por shard; la prueba de carga con k6 (500 eventos/s) queda para la Fase 1.
- Fusiones de contactos (auditadas y reversibles), tracker y todo lo de la Fase 1 siguen pendientes.

## 2026-11-17 — Especificación inicial
- Visión, arquitectura (plugin ligero + hub en Laravel + ClickHouse), hoja de ruta en 6 fases.
- Contrato de eventos v1, modelo de datos, 22 módulos, inteligencia (RFM adaptado a LATAM, predicciones, 23 detectores de hallazgos, IA), cumplimiento (Ley 1581, Ley 2300, LFPDPPP de México, políticas de WhatsApp), análisis de competencia.
