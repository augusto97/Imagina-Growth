# Análisis de competencia y decisiones de producto

> Investigación de noviembre de 2026. Los precios y funciones cambian seguido: revisa las fuentes (§8) antes de usarlos en material comercial.

---

## 1. Mapa del mercado

Lo que necesita una tienda o una empresa de servicios en LATAM hoy está repartido en seis categorías de herramientas:

| Categoría | Ejemplos | Lo que resuelven |
|---|---|---|
| Plataformas de retención todo en uno | Klaviyo, Omnisend, Brevo | correo, SMS, segmentación, automatizaciones; las grandes ya tienen predicciones e IA |
| Nativas de WooCommerce | Metorik, FluentCRM, MailPoet, AutomateWoo | analítica y correo pegados a WooCommerce |
| WhatsApp para LATAM | Treble, Leadsales, Kommo, B2Chat, Mercately, Cliengo, Wati, SleekFlow | bandeja multiagente, CRM tipo kanban, campañas por WhatsApp, bots |
| Fidelización y referidos | Smile.io, Yotpo, LoyaltyLion, ReferralCandy | puntos, VIP, referidos |
| Reseñas | Judge.me, Yotpo Reviews | solicitud y publicación de reseñas, UGC |
| Medición desde el servidor | PixelYourSite, Conversios, PixelFlow, Stape | Conversions API de Meta, eventos a Google y TikTok |

**Ninguna** une las seis categorías con foco en WooCommerce, WhatsApp oficial y la realidad legal y de pagos de LATAM.

---

## 2. Competidores analizados

### Klaviyo: el referente (Shopify primero, WooCommerce después)
- **Fuerte en:**
  - Predicciones en cada perfil: **CLV previsto, fecha esperada de la próxima compra y riesgo de abandono**. Su modelo de abandono pide al menos 500 clientes con compra.
  - IA agéntica: **Composer** (marzo de 2026) arma campañas y flujos completos a partir de un solo prompt. **Customer Agent** atiende a los clientes.
  - Hora de envío personalizada, optimización de audiencia y «siguiente mejor producto».
  - En septiembre de 2026 anunció experimentos contra grupos de control reales, SQL dentro de su plataforma de datos y un **Compliance Hub** (próximamente).
- **WooCommerce:** el Customer Hub (personalización en el sitio, banners personalizados) llegó a WooCommerce apenas en el primer trimestre de 2026. Shopify sigue siendo la prioridad.
- **Débil para nuestro mercado:** cobra en USD por contactos, WhatsApp no es su eje, no conoce los horarios legales de Colombia ni los pagos pendientes de LATAM, y no es para sitios de servicios.

### Omnisend: el retador para pymes
- **Fuerte en:**
  - Integración nativa con WooCommerce (productos, pedidos, clientes y carritos en tiempo real) y recomendador de productos.
  - **Brand Assets AI**: toma logo, colores y fuentes de la tienda y los aplica a plantillas y formularios.
  - **Envío en el mejor momento** según clics, aperturas o pedidos (agosto de 2026).
  - Atribución configurable que **excluye las aperturas de Apple MPP**, y **conector para Claude y MCP** con permisos granulares (agosto de 2026).
- **Precio:** gratis hasta 250 contactos; Standard desde USD 16 al mes.
- **Débil:** el canal fuerte es correo y SMS (pensado para EE. UU.), sin WhatsApp como eje ni CDP profundo.

### Brevo: todo en uno barato
- Correo, SMS, WhatsApp, push, chat, CRM, CDP y fidelización en una sola suscripción.
- **Pero WhatsApp y otras funciones solo están en el plan Professional**, que cuesta USD 499 al mes.

### Metorik: el mejor en analítica de WooCommerce
- Segmentación con más de 500 filtros, cohortes, seguimiento de carritos y correo («Engage») sobre los mismos segmentos.
- **Rentabilidad:** costos por producto, envío y transacción, con el gasto en anuncios sincronizado desde Google, Meta, TikTok y Pinterest. También pronóstico de inventario.
- **Precio:** desde USD 25 al mes (hasta 100 pedidos mensuales).
- **Débil:** en inglés, solo correo, sin WhatsApp, fidelización ni reseñas, y pensado para un analista, no para el dueño de una pyme.

### FluentCRM: CRM dentro de WordPress
- Automatizaciones con disparadores de WooCommerce, carrito abandonado, SMS e integración con todo el ecosistema WordPress.
- **Débil:** corre dentro de WordPress (la carga cae en el servidor de la tienda), con analítica limitada y sin WhatsApp oficial ni predicciones.

### WhatsApp en LATAM: Treble, Leadsales, Kommo, B2Chat, Mercately, Cliengo
- **Treble.ai** se presenta como «el Klaviyo de WhatsApp en LATAM». Se integra con Shopify, VTEX y plataformas locales, y tiene una capa de IA real: respuestas frecuentes, calificación de leads y resúmenes.
- **Leadsales y Kommo:** CRM tipo kanban fuertes en pipeline; su IA es más débil. Leadsales arranca alrededor de USD 83 al mes.
- **Mercately:** bandeja de WhatsApp, Instagram y Messenger con IA y **cobros dentro del chat**.
- **Débil para nuestro caso:**
  - Sus datos de ecommerce son superficiales: no hay RFM, cohortes, LTV por canal ni predicciones.
  - WooCommerce suele quedar como integración secundaria.
  - No miden las conversiones del sitio ni manejan fidelización.

### Fidelización: Smile.io y Yotpo
- **Smile.io:** puntos, VIP y referidos. Gratis hasta 200 pedidos al mes; el plan Growth, con niveles VIP, cuesta USD 199 al mes (2.500 pedidos).
- **Yotpo:** une fidelización y reseñas. Dar recompensas por reseñas alimenta las dos cosas.

### Reseñas: Judge.me
- Solicitudes ilimitadas, fotos y videos, formulario dentro del correo, resúmenes y respuestas con IA.
- Envía las reseñas a Google Shopping, Meta y TikTok Shop, y sincroniza las reseñas de Google Business Profile.
- **Precio:** gratis o USD 15 al mes.

### Medición desde el servidor: PixelYourSite, Conversios, Stape
- **PixelYourSite:** Meta CAPI incluido en la versión gratis; los Events API de TikTok son Pro (USD 199 al año).
- **Conversios:** Meta, TikTok y Snapchat por servidor más feeds de productos, por USD 199 al año.
- **Stape:** infraestructura de GTM de servidor para equipos técnicos.
- **Ninguno** conecta esa medición con lo que pasa después: retención, mensajes, LTV por canal.

---

## 3. Huecos del mercado (nuestra oportunidad)

1. **No hay un producto que una datos, WhatsApp oficial, correo, fidelización, reseñas y medición para WooCommerce en español.** Hoy una tienda tendría que juntar Metorik + Omnisend + Smile.io + Judge.me + PixelYourSite + una herramienta de WhatsApp, y no se hablan entre sí.
2. **WhatsApp es el canal que vende en LATAM, pero las herramientas de WhatsApp no tienen inteligencia de ecommerce**, y las de ecommerce tratan WhatsApp como algo secundario.
3. **Ventas que se cierran por WhatsApp sin atribuir:** casi nadie conecta el clic al botón de WhatsApp del sitio, la conversación y la venta, ni le devuelve esa conversión a Meta (anuncios de clic a WhatsApp con Conversions API for Business Messaging).
4. **Cumplimiento local de serie:** no encontramos herramientas que traigan por defecto los horarios de la Ley 2300 de Colombia (sin domingos ni festivos), el Registro de Números Excluidos ni el REPEP de México.
5. **Pagos de LATAM:** PSE pendiente, Efecty, transferencias y **contraentrega** (confirmación y ventas «por confirmar») no existen en las herramientas globales.
6. **Sitios de servicios:** las plataformas de retención están hechas para tiendas, y los CRM de WhatsApp no miden el sitio. El sitio corporativo queda sin herramientas para convertir visitas en clientes.
7. **Capa de agencia:** una agencia que administra 30 sitios no tiene un panel único con reporte mensual de valor por cliente.
8. **Rendimiento:** las herramientas que corren dentro de WordPress cargan el servidor. Nuestra arquitectura de plugin ligero + hub, junto con la experiencia en rendimiento (Imagina Turbo), es una ventaja real.

---

## 4. Qué tomamos de cada uno (trazabilidad hacia los módulos)

| Función | Inspirada en | Módulo |
|---|---|---|
| CLV previsto, fecha de la próxima compra, riesgo de abandono en cada perfil, con umbrales de datos mínimos | Klaviyo | M17, `inteligencia.md` §4 |
| Copiloto que arma campañas y flujos completos desde un prompt (con aprobación humana) | Klaviyo Composer | M10, M17 |
| Agente de atención y ventas con guía de marca y traspaso a humano | Klaviyo Customer Agent, Treble | M11 |
| Grupos de control para medir el incremento real | Klaviyo (experimentos con grupo de control) | M09, M10 |
| Banners personalizados en el sitio por segmento | Klaviyo Customer Hub | M16 |
| Kit de marca extraído solo del sitio | Omnisend Brand Assets AI | M01, M08 |
| Mejor hora de envío por contacto | Omnisend, Klaviyo | M10, `inteligencia.md` §4.7 |
| Atribución configurable que excluye aperturas de MPP | Omnisend | M06 |
| Conector MCP para consultar los datos desde Claude | Omnisend, Klaviyo (headless) | M19 |
| Segmentación profunda + rentabilidad con costos y gasto en anuncios | Metorik | M04, M05 |
| Pronóstico de inventario contra ventas | Metorik | M05, hallazgo 6 |
| Disparadores nativos de WordPress y WooCommerce | FluentCRM | M09 |
| Bandeja multiagente, kanban de leads, cobros en el chat | Leadsales, Kommo, Mercately | M11, M18 |
| WhatsApp como canal de marketing con IA | Treble | M07, M09, M11 |
| Puntos, VIP y referidos con recompensas por reseña | Smile.io, Yotpo | M13, M14, M15 |
| Reseñas con foto o video, resumen con IA, reseñas de Google | Judge.me | M15 |
| Conversiones desde el servidor a Meta, Google y TikTok con deduplicación | PixelYourSite, Conversios | M06 |
| Marketing Messages API (hasta +9% de entrega frente a la Cloud API, según Meta) | Meta | M07 |
| **Propias (no las vimos en la competencia)** | — | — |
| Atribución de clics a WhatsApp con token en el mensaje | — | M12, `eventos.md` §6.3 |
| Motor de horarios legales con festivos por país | — | M20 |
| Flujos de pago pendiente y confirmación de contraentrega | — | M09 (5 y 6) |
| Hallazgos cruzados con los cambios del sitio (plugin actualizado → caída de conversión) | — | `inteligencia.md` §5 |
| Canal «Asistentes de IA» en la atribución | — | `inteligencia.md` §2 |
| Conversiones offline de leads ganados para optimizar anuncios de servicios | — | M18 |
| Panel de agencia con reporte mensual de valor | — | M22 |

---

## 5. Precios de referencia y posicionamiento

**Costo de armar el stack equivalente con herramientas sueltas** (tienda pequeña o mediana, precios de lista de 2026):

| Herramienta | USD al mes |
|---|---|
| Metorik (desde) | 25 |
| Omnisend Standard (desde) | 16 |
| Smile.io Growth (VIP) | 199 |
| Judge.me Awesome | 15 |
| PixelYourSite Pro (199 al año) | ~17 |
| **Total aproximado, sin herramienta de WhatsApp** | **~272** |

Si se agrega WhatsApp con Brevo Professional (USD 499 al mes) o un CRM de WhatsApp (desde unos USD 83 al mes), el total pasa con facilidad de USD 350 a 750 al mes.

**Sugerencia de estructura** (a validar con clientes reales):
- **Planes por pedidos al mes** (tiendas) o **contactos activos** (servicios), cobrados en COP o MXN.
- **Datos + Medición:** perfil 360, analítica, RFM, conversiones desde el servidor y hallazgos. Es la entrada: se paga sola con la mejora de los anuncios.
- **Crecimiento:** lo anterior + correo, WhatsApp, flujos y campañas.
- **Pro:** lo anterior + fidelización, referidos, reseñas, bandeja e IA avanzada.
- **Ancla de precio:** entre el 25% y el 40% del stack equivalente, con el reporte mensual mostrando un ingreso atribuido varias veces mayor que la suscripción.
- **Los mensajes de WhatsApp los paga el cliente directamente a Meta:** no son nuestro costo ni nuestro riesgo.

---

## 6. Diferenciadores (cómo lo contamos)

1. **Hecho para cómo se vende en LATAM:** WhatsApp primero, pagos pendientes, contraentrega, festivos, horarios de ley, español y pesos.
2. **Una sola plataforma en vez de seis**, y los datos se hablan: la reseña da puntos, los puntos activan el VIP, el VIP sale de las audiencias de descuento.
3. **Te dice qué hacer:** hallazgos con dinero estimado y botón para actuar, más el analista semanal.
4. **No pone lento tu sitio:** el plugin es ligero y el trabajo pesado corre fuera de WordPress.
5. **Tiendas y empresas de servicios** con el mismo producto.
6. **Cumplimiento incluido:** consentimiento con evidencia, horarios legales y registros de exclusión.

---

## 7. Riesgos competitivos y cómo responder

| Riesgo | Respuesta |
|---|---|
| Klaviyo y Omnisend invierten más en WooCommerce y WhatsApp | profundidad local (pagos, leyes, idioma, soporte por WhatsApp), precio en moneda local, capa de agencia y servicios |
| Meta lanza herramientas propias de IA y marketing en WhatsApp | integrarse a ellas (Marketing Messages API, CAPI); nuestro valor está en los datos de la tienda, que Meta no tiene |
| Suben los costos de WhatsApp (desde octubre de 2026 también se cobran los mensajes de servicio) | estimación de costo antes de cada envío, combinación inteligente de canales (correo cuando alcanza) y supresión de quienes no interactúan |
| Bloqueos de números no oficiales | el producto empuja hacia la API oficial; Zender queda limitado por diseño |
| Construir todo es mucho para una sola persona | fases que se venden solas (`CLAUDE.md` §5); la Fase 1 genera ingresos antes de la mensajería |

---

## 8. Fuentes

- Klaviyo, Composer y Customer Agent (24 de marzo de 2026): https://investors.klaviyo.com/news/news-details/2026/Klaviyo-Expands-AI-Agents-to-Power-the-Autonomous-B2C-CRM/default.aspx
- Klaviyo, actualizaciones del primer trimestre de 2026 (Customer Hub para WooCommerce): https://community.klaviyo.com/product-updates-announcements-51/klaviyo-q1-2026-product-updates-composer-launch-key-feature-releases-19146
- Klaviyo, K:BOS 2026: https://www.klaviyo.com/blog/kbos-2026-autonomous-platform-updates
- Klaviyo, analítica predictiva: https://help.klaviyo.com/hc/en-us/articles/360020919731-Understanding-Klaviyo-s-predictive-analytics · https://www.klaviyo.com/blog/predicting-churn-risk-our-new-model
- Omnisend, reseñas y novedades de 2026: https://www.saashero.net/competitor/omnisend-ecommerce-automation-review-2026/ · https://thecmo.com/nl/tools/omnisend-beoordeling/ · https://toolradar.com/tools/omnisend
- Brevo: https://pipeline.zoominfo.com/sales/brevo-features · https://www.toolsforhumans.ai/ai-tools/brevo
- Metorik: https://wordpress.org/plugins/metorik-helper/ · https://toolradar.com/tools/metorik-woocommerce
- FluentCRM: https://fluentcrm.com/automation/
- Treble.ai: https://dupple.com/tools/trebleai · https://www.hackceleration.com/labs/review/treble
- CRM de WhatsApp en LATAM: https://guiawabusiness.cliengo.com/mejores-crm-whatsapp · https://blog.mercately.com/?p=31946
- Smile.io frente a Yotpo: https://smile.io/learn/smile-vs-yotpo
- Judge.me: https://ecommerceguide.com/apps/judge-me/
- PixelYourSite y Conversios: https://www.pixelyoursite.com/free-versus-pro · https://www.conversios.io/blog/conversios-vs-pixelyoursite/
- Marketing Messages API de Meta: https://developers.facebook.com/documentation/business-messaging/whatsapp/marketing-messages/overview · https://docs.360dialog.com/docs/waba-messaging/sending-marketing-messages
- Precios de WhatsApp (1 de octubre de 2026): https://help.sleekflow.io/en_US/pricing · https://developers.facebook.com/docs/whatsapp/pricing
- Política de IA de WhatsApp (15 de enero de 2026): https://respond.io/blog/whatsapp-ai-chatbot-policy
- Conversions API for Business Messaging: https://stape.io/news/whatsapp-conversion-api
- Google Customer Match → Data Manager API (1 de abril de 2026): https://searchengineland.com/google-to-disable-customer-match-uploads-in-ads-api-470796
- Ley 2300 de 2023: https://normograma.mintic.gov.co/docs/pdf/ley_2300_2023.pdf
- Nueva LFPDPPP de México (2025): https://www.garrigues.com/es_ES/noticia/mexico-nueva-ley-federal-proteccion-datos-personales-posesion-particulares-introduce
