# Cumplimiento legal y políticas de plataformas

> ⚠️ **No es asesoría legal.** Este documento traduce a requisitos de software una lectura prudente de las normas. Antes del lanzamiento comercial, un abogado de protección de datos en Colombia (y otro en México) debe validar los textos legales, los contratos y los valores por defecto marcados con **[validar]**.
> Regla de diseño: **el valor por defecto siempre es el más conservador**. El cliente puede relajarlo solo si la norma lo permite y dejando constancia (auditoría).

---

## 1. Roles y principios

- **El cliente (la tienda o empresa) es el Responsable del tratamiento.** Decide para qué usa los datos de sus clientes.
- **Imagina La Web S.A.S. es Encargada del tratamiento:** trata los datos por cuenta del cliente. Se necesita un **contrato de transmisión y encargo** firmado en el alta de cada espacio de trabajo (aceptación electrónica con registro).
- Los servidores del hub están en Hetzner (UE o EE. UU.), así que hay **transferencia o transmisión internacional**. Hay que revisar que el país de alojamiento tenga nivel adecuado según la SIC o cubrirlo en el contrato **[validar]**.
- Principios aplicados en el software: finalidad (cada consentimiento tiene una finalidad), minimización (no se piden cédulas ni datos sensibles), seguridad (cifrado, accesos por rol, auditoría), temporalidad (retención configurable) y prueba (evidencia de cada autorización).

---

## 2. Colombia: Ley 1581 de 2012 y Decreto 1377 de 2013 (habeas data)

Requisitos que se convierten en funciones:

| Requisito | Implementación |
|---|---|
| Autorización previa, expresa e informada | casillas **sin marcar**, una por canal y finalidad, con el texto legal versionado (`legal_texts`). Nada se infiere de «al comprar aceptas…» |
| Prueba de la autorización | `consent_events` (solo inserción): fecha, fuente, versión del texto, URL, IP y user agent con hash, y copia del formulario |
| Informar finalidades y derechos | aviso corto junto a la casilla, con enlace a la política de privacidad del cliente (generador de cláusulas, §11) |
| Derechos del titular: conocer, actualizar, rectificar, suprimir, revocar | centro de preferencias + `data_requests` con plazos (§9) |
| Revocar en cualquier momento | enlace de baja en cada mensaje, palabras clave en WhatsApp, centro de preferencias |
| Seguridad | §10 |
| Datos sensibles | **no se recogen.** Si un atributo personalizado se marca como sensible, se bloquea su uso en segmentos de marketing |

---

## 3. Consentimiento en la práctica

### 3.1 Dónde se captura
- **Checkout de WooCommerce:**
  - En el checkout clásico se usa `woocommerce_review_order_before_submit`; en el de bloques, la API de campos adicionales del checkout.
  - Hay dos casillas sin marcar: «Quiero recibir ofertas por WhatsApp» y «Quiero recibir ofertas por correo».
  - El texto es editable y queda versionado.
- **Registro de cuenta, formularios de terceros** (mapeo de la casilla de consentimiento) y **widgets del hub**.
- **WhatsApp:** cuando la persona inicia la conversación y acepta por un botón («¿Quieres recibir novedades por aquí?»), con evidencia del mensaje.
- **Importación CSV:** es obligatorio declarar el origen y la fecha del consentimiento. Sin esos datos, los contactos solo pueden recibir mensajes transaccionales.

### 3.2 Captura temprana del checkout (correo o teléfono antes de pagar)
- Viene **desactivada por defecto**.
- Para activarla, el espacio de trabajo tiene que mostrar un aviso visible junto al campo («Usaremos tu correo o WhatsApp para ayudarte a terminar tu compra») y aceptar la responsabilidad en la configuración (queda en la auditoría) **[validar]**.
- El dato capturado solo se usa para recuperar *ese* carrito. No crea consentimiento de marketing.

### 3.3 Cookies y seguimiento
- **Integración con banners:** WP Consent API (estándar, prioridad), Complianz, CookieYes, Cookiebot y Real Cookie Banner. Para Google se envía Consent Mode v2.
- **Modos por espacio de trabajo** (se eligen en el panel, Consentimiento; el cambio queda en la auditoría):

| Modo | Comportamiento |
|---|---|
| **Según el sitio** (`auto`, por defecto) | estricto en los sitios que tienen banner de cookies e informado en los que no |
| **Estricto** | sin consentimiento de analítica no se guarda `_igr_aid`; solo se cuentan vistas agregadas sin identificador. Sin consentimiento de marketing no se envían conversiones a anuncios. Si el sitio no tiene banner, el plugin muestra uno ligero propio, porque si no nadie podría aceptar |
| **Informado** | se mide desde la primera visita con el identificador propio, avisando en la política, y se respeta el rechazo explícito. Los píxeles y las conversiones de anuncios siguen necesitando consentimiento de marketing. Con la WP Consent API manda la configuración del banner |

---

## 4. Colombia: Ley 2300 de 2023 («Dejen de fregar») → motor de política de envío

La ley aplica a los mensajes **comerciales o publicitarios** por SMS, mensajería por aplicaciones o web, correo y llamadas:
- **Horario:** lunes a viernes de 7:00 a. m. a 7:00 p. m. y sábados de 8:00 a. m. a 3:00 p. m. **Nunca domingos ni festivos.**
- **Canales:** solo los que el consumidor autorizó.
- **Promociones a una base de datos:** consentimiento explícito.
- **Darse de baja:** un mecanismo sencillo, en cualquier momento.
- **Excepciones (art. 8):** confirmación de transacciones realizadas, información solicitada por el consumidor y alertas de fraude.

### 4.1 Clasificación de mensajes

| Tipo | Ejemplos | ¿Aplica la ventana? |
|---|---|---|
| `transactional` | confirmación de pedido, envío, factura, OTP, respuesta a una pregunta del cliente, confirmar contraentrega, recordatorio de una cita agendada por la persona | No (art. 8) **[validar el caso de la cita]** |
| `marketing` | promociones, campañas, carrito abandonado, navegación abandonada, recuperación, cumpleaños, puntos, reseñas, referidos | **Sí** |
| Ambiguo → **marketing por defecto** | recordatorio de pago pendiente, aviso de stock solicitado por la persona | Sí por defecto; el cliente puede reclasificarlo con una justificación (auditoría) **[validar]** |

### 4.2 Reglas del motor (`SendPolicy`)
1. **Ventana legal por país del contacto** (con su zona horaria; Colombia usa `America/Bogota`). Fuera de la ventana, el mensaje se **aplaza** al siguiente momento permitido, que el flujo recalcula; nunca se descarta en silencio.
2. **Festivos de Colombia:** se calculan por año.
   - Fijos: 1 ene, 1 may, 20 jul, 7 ago, 8 dic, 25 dic.
   - Trasladables al lunes por la Ley 51 de 1983 (Ley Emiliani): 6 ene, 19 mar, 29 jun, 15 ago, 12 oct, 1 nov, 11 nov.
   - Ligados a la Pascua: Jueves y Viernes Santo; Ascensión, Corpus Christi y Sagrado Corazón (trasladados al lunes).
   - Prueba con los calendarios oficiales 2026 a 2030 y una tabla de excepciones editable por el admin de la plataforma.
3. **Frecuencia:** el artículo 3 limita las gestiones de **cobranza** a un canal por semana y un contacto por día. Para los mensajes comerciales, el artículo 5 remite a las mismas reglas.

   | Configuración | Valor por defecto en Colombia |
   |---|---|
   | Mensajes comerciales por contacto | máximo **1 al día** sumando todos los canales; un segundo mensaje el mismo día se aplaza |
   | Modo «Un canal por semana» | opcional, más estricto |
   | Tope semanal | configurable, por defecto 3 |

   **[validar]** el alcance exacto de la frecuencia para mensajes comerciales.
4. **Solo canales autorizados** con consentimiento `granted` para `marketing`.
5. **Registro de Números Excluidos (RNE) de la CRC:** antes de cada envío comercial se cruza contra la lista importada en `suppressions` (reason `rne`). Como no tenemos confirmado un mecanismo de consulta masiva por API, la v1 importa el archivo que el cliente descarga **[validar el mecanismo vigente]**.
6. **Pruebas obligatorias:** suite `SendPolicyColombiaTest` con domingos, festivos, sábados a las 15:01, cambio de día y reintentos.

---

## 5. WhatsApp: políticas de Meta, precios y conexión no oficial

### 5.1 Conexión oficial (recomendada)
- **Cada cliente conecta su propio número** con Embedded Signup y asocia **su propio método de pago** en su cuenta de WhatsApp Business. Imagina Growth no revende mensajes. Hay que darse de alta como proveedor de tecnología de Meta o integrarse a través de un BSP (por ejemplo 360dialog o Infobip) **[decidir]**.
- **Opt-in:** Meta exige consentimiento antes de mensajes iniciados por la empresa. Se cumple con §3.
- **Categorías de plantilla:** `marketing`, `utility` y `authentication`. Meta revisa y puede reclasificar. **Prohibido** disfrazar marketing de utilidad para pagar menos: el editor lo advierte.
- **Precios:**
  - Desde el 1 de julio de 2025 Meta cobra por mensaje entregado, según la categoría y el país.
  - Desde el 1 de octubre de 2026 también cobra los mensajes de servicio y las plantillas de utilidad dentro de la ventana de 24 h. Hay 1.000 mensajes de servicio gratis al mes por número.
  - La tabla `wa_rates` se actualiza cada trimestre (Meta cambia las tarifas el 1 de enero, abril, julio y octubre). Sirve para estimar costos antes de cada campaña.
- **Límites de frecuencia de Meta por usuario** para marketing: si un mensaje no se entrega por ese motivo (código 131049), se registra con su código y no se reintenta de inmediato.
- **La persona detiene el marketing desde WhatsApp** (código 131050): se revoca el consentimiento de marketing de WhatsApp de ese contacto, como una baja. Los mensajes transaccionales siguen.
- **Marketing Messages API:** se usa para el marketing por su entrega optimizada y sus métricas. La Cloud API queda para servicio, utilidad y conversaciones.
- **IA en WhatsApp:** desde el 15 de enero de 2026 Meta prohíbe los asistentes de IA de propósito general en la plataforma empresarial. Los agentes limitados a la operación del negocio (ventas, soporte, citas, pedidos) están permitidos. El agente de M11 debe quedarse **siempre dentro del alcance del negocio**, por diseño y por prompt.
- **Calidad del número:** se monitorea. Si baja a «media», se frenan las campañas de marketing de ese número y se avisa.

### 5.1.1 Messenger e Instagram Direct (Fase 5)
- **Solo conversaciones.** Meta no permite mensajes promocionales por Messenger ni por Instagram Direct desde la plataforma de mensajería: `SendPolicy` niega todo marketing en estos canales (`channel_no_marketing`) y no hay plantillas.
- **Ventana de 24 h** desde el último mensaje del cliente para responder **[verificar]** la política vigente de Meta.
- **Messenger, etiqueta `HUMAN_AGENT`:** entre 24 h y 7 días después del último mensaje, solo una persona del equipo puede responder un asunto del cliente. La IA y los envíos automáticos nunca la usan; la bandeja la pone sola cuando escribe una persona **[verificar]** que la app tenga aprobada la función de agente humano.
- **Instagram:** fuera de la ventana no sale nada hasta que el cliente vuelva a escribir (la bandeja muestra «Ventana cerrada»).
- Estos mensajes no tienen costo de Meta. Las palabras de baja («baja», «STOP») se procesan igual que en WhatsApp.

### 5.2 Zender (no oficial)
- Usa WhatsApp Web, que **no está autorizado** por los términos de WhatsApp para automatizar, así que el número puede ser **bloqueado**.
- En el producto queda limitado a conversaciones 1:1 y mensajes transaccionales de bajo volumen, con topes fijos por hora y por día (30 y 200, definidos por la plataforma en `config/igr-messaging.php`; el cliente no los puede subir), y **sin campañas ni flujos masivos de marketing** (el driver rechaza todo mensaje de marketing).
- La activación exige que el `owner` acepte un aviso de riesgo (auditoría).
- No se implementan técnicas para evadir la detección de WhatsApp.

---

## 6. México

- **Nueva Ley Federal de Protección de Datos Personales en Posesión de los Particulares**, vigente desde el 21 de marzo de 2025. La autoridad pasó del INAI a la Secretaría Anticorrupción y Buen Gobierno.
- En el software:
  - Aviso de privacidad (enlace obligatorio junto a cada captura).
  - Consentimiento por finalidad, con las finalidades secundarias de marketing separadas.
  - Derechos ARCO (§9).
- **REPEP (PROFECO):** se importa la lista para excluir números de la publicidad **[validar el alcance y el mecanismo de consulta]**.
- **Ventana horaria:** no tenemos identificada una ley equivalente a la colombiana para mensajes comerciales. Por defecto: lunes a sábado de 9:00 a 20:00 en la hora local del contacto, configurable **[validar]**. Festivos oficiales de México en el calendario.

---

## 7. Venezuela y otros países

Venezuela no tiene una ley general de protección de datos personales equivalente. **Se aplica el mismo estándar que en Colombia** (consentimiento, baja y horarios razonables): es lo más simple y lo más seguro. Para otros países se usa ese estándar hasta tener una configuración específica.

---

## 8. Correo

- **Requisitos de Gmail y Yahoo para envíos masivos:** SPF, DKIM y DMARC alineados, `List-Unsubscribe` + `List-Unsubscribe-Post` (un clic), tasa de spam por debajo de 0,3% y bajas procesadas en menos de 2 días (el producto lo hace en menos de 1 minuto).
- Supresión automática por rebote duro y por queja.
- Pie de cada correo: identificación del remitente, dirección física o de contacto del negocio, motivo del mensaje y enlace de baja. Si la plantilla de un correo de marketing no trae el enlace de baja, el despachador agrega un pie mínimo con la baja y el centro de preferencias.

---

## 9. Derechos de los titulares (habeas data / ARCO)

- **Solicitudes:** formulario público por espacio de trabajo y registro manual (`data_requests`), con plazo visible (15 días hábiles en Colombia para consultas; los reclamos tienen sus propios plazos) **[validar los plazos por tipo y país]**.
- **Acceso o exportación:** un JSON y un CSV con el perfil, los eventos, los pedidos espejo, los mensajes, los consentimientos y la fidelización.
- **Supresión de punta a punta:**
  1. Marcar el contacto y bloquear envíos.
  2. Borrado físico en MariaDB (contacto, identidades, mensajes, consentimientos excepto la evidencia mínima de la revocación).
  3. Borrado en ClickHouse por `contact_id` y sus `anonymous_id`.
  4. Quitarlo de las audiencias de anuncios, con los hashes que se le enviaron (las audiencias los guardan cifrados para esto).
  5. Opcionalmente, anonimizarlo en WooCommerce con las herramientas de privacidad de WordPress: el connector registra *exporters* y *erasers*, así que el flujo nativo de WordPress también llega al hub.
  6. Registro en `audit_logs`.
- **Revocación:** inmediata en todo el sistema (< 1 min).

---

## 10. Seguridad y retención

- **Cifrado:** TLS 1.2+ en todo. Correo, teléfono y nombres cifrados en reposo (Laravel encrypted casts con una clave por entorno) con índice ciego HMAC por espacio de trabajo. Secretos de terceros cifrados. Copias de seguridad cifradas.
- **Accesos:** 2FA obligatoria para los administradores, roles mínimos, sesiones revocables, acceso de soporte de la agencia auditado y datos enmascarados según el rol.
- **Conexión de sitios:** HMAC con marca de tiempo (±300 s) y nonce contra repetición, rotación de secretos, IPs del hub publicadas para listas blancas.
- **Retención por defecto (configurable):**

| Dato | Retención |
|---|---|
| Vistas de página crudas | 25 meses |
| Mensajes | 24 meses (después, archivo agregado) |
| Pasos de flujos | 6 meses |
| Evidencia de consentimiento | mientras exista la relación + el periodo que defina el abogado **[validar]** |
| Datos de un espacio de trabajo cancelado | exportación disponible 30 días y borrado completo a los 60 |

- **Incidentes:** procedimiento documentado de notificación al cliente (Responsable) para que notifique a la autoridad en los plazos de ley **[validar los plazos]**.

---

## 11. Documentos legales a preparar (con abogado)

1. Términos y condiciones de Imagina Growth.
2. Política de privacidad de Imagina Growth (para los usuarios del panel).
3. **Contrato de transmisión y encargo del tratamiento** (Imagina ↔ cliente).
4. **Generador de cláusulas** para la política de privacidad del cliente: finalidades de marketing por WhatsApp y correo, analítica, publicidad, encargado (Imagina Growth) y transferencia internacional.
5. Textos por defecto de consentimiento (checkout, widgets, WhatsApp) en `es-CO` y `es-MX`.
6. Aviso de riesgo de Zender.
7. Política de uso aceptable (prohíbe spam, listas compradas, contenido ilegal).
