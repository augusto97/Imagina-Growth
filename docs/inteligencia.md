# Inteligencia: métricas, segmentos, predicciones, hallazgos e IA

> Objetivo: que cada espacio de trabajo **conozca a fondo a sus clientes** y reciba **qué hacer**, no solo gráficos.
> Toda definición de este documento se muestra en la interfaz como tooltip («¿Cómo se calcula?»).

---

## 1. Métricas base (definiciones únicas)

| Métrica | Definición |
|---|---|
| Pedido válido | pedido en estado pagado (configurable por sitio, ver `eventos.md` §5); excluye `failed`, `cancelled`, `pending` y `on-hold` hasta que se paga |
| Ingresos brutos | Σ `total` de los pedidos válidos |
| Ingresos netos | brutos − reembolsos − envío − impuestos (el informe aclara cuál se muestra) |
| Ticket promedio (AOV) | ingresos brutos ÷ pedidos válidos |
| Cliente | persona única (por contacto resuelto, también invitados) con ≥ 1 pedido válido |
| Cliente nuevo en el periodo | su primer pedido válido cae dentro del periodo |
| Tasa de recompra | clientes con ≥ 2 pedidos ÷ clientes con ≥ 1 pedido (ventana de 12 meses por defecto) |
| Tiempo hasta la 2ª compra | mediana de días entre el pedido 1 y el pedido 2 |
| LTV histórico | Σ ingresos del cliente en la ventana (90 días, 365 días o total) |
| Conversión | sesiones con compra ÷ sesiones |
| Ingreso por visitante | ingresos ÷ visitantes únicos |
| Abandono de carrito | carritos con ≥ 1 producto y sin pedido en 24 h ÷ carritos creados |
| Recuperación | carritos abandonados que terminan en pedido en 7 días ÷ abandonados |
| Ingresos atribuidos a Imagina Growth | pedidos con `attributed_message_id`, de un widget, de referidos o de afiliados según las ventanas configuradas (`modulos.md` M06) |
| Incremento | (conversión del grupo expuesto − conversión del grupo de control) × expuestos × ticket |

**Contraentrega:** la venta se cuenta como «por confirmar» hasta que se cobra (ver `eventos.md` §5).

---

## 2. Agrupación de canales

Se evalúa en orden y gana la primera regla que coincide, sobre la sesión de entrada:

| # | Canal | Regla |
|---|---|---|
| 1 | Anuncio de clic a WhatsApp | conversación entrante con `ctwa_clid` o `referral` de anuncio |
| 2 | Imagina Growth: WhatsApp / correo / SMS | parámetro `_igr` o `utm_source=igr` en un enlace de mensaje |
| 3 | Pago Meta | `fbclid` con `utm_medium` en (cpc, paid, ads, paid_social) o `utm_source` en (facebook, instagram, meta) con medium pago |
| 4 | Pago Google | `gclid`, `gbraid` o `wbraid`, o `utm_source=google` con medium pago |
| 5 | Pago TikTok | `ttclid`, o `utm_source=tiktok` con medium pago |
| 6 | Otros pagos | `utm_medium` en (cpc, ppc, paid, display, cpm) |
| 7 | Correo (otros) | `utm_medium=email` o referrer de webmail |
| 8 | WhatsApp orgánico | referrer `l.wl.co`, `wa.me`, `whatsapp.com`, o `utm_source=whatsapp` |
| 9 | **Asistentes de IA** | referrer en (chatgpt.com, chat.openai.com, perplexity.ai, claude.ai, gemini.google.com, copilot.microsoft.com, meta.ai) o `utm_source` equivalente |
| 10 | Búsqueda orgánica | referrer de un buscador (google.*, bing, duckduckgo, yahoo, ecosia) sin click ID |
| 11 | Social orgánico | referrer en (facebook, l.facebook.com, lm.facebook.com, instagram, t.co, x.com, tiktok, linkedin, pinterest, youtube) |
| 12 | Afiliados y referidos | código o enlace de referido o afiliado |
| 13 | Referido (sitios) | cualquier otro dominio externo |
| 14 | Directo | sin referrer ni UTM |

La lista de dominios vive en `hub/config/channels.php` y se actualiza sin desplegar (en caché, editable por el admin de la plataforma).

---

## 3. RFM adaptado a LATAM

En el ecommerce de la región, la mayoría de los clientes compra **una sola vez**. Por eso F no se calcula por quintiles: con tantos empates en 1, los quintiles se rompen.

**Población:** clientes con ≥ 1 pedido válido en los últimos 24 meses (configurable). Se recalcula cada noche.

| Dimensión | Cálculo del puntaje (1 a 5) |
|---|---|
| **R** (recencia) | quintiles de días desde la última compra (5 = más reciente). Con menos de 200 clientes se usan cortes fijos según el ciclo del negocio: múltiplos 0,5×, 1×, 2× y 4× del tiempo mediano entre compras |
| **F** (frecuencia) | absolutos: 1 pedido = 1; 2 = 2; 3–4 = 3; 5–9 = 4; 10+ = 5 (configurables por industria) |
| **M** (monto) | quintiles del gasto total en la ventana |

**Segmentos** (se evalúan en orden; gana la primera regla):

| # | Segmento | Regla | Acción sugerida |
|---|---|---|---|
| 1 | Campeones | R ≥ 4 y F ≥ 4 | premiar, pedir reseñas y referidos, acceso anticipado; **no regalar descuentos** |
| 2 | Leales | R ≥ 3 y F ≥ 3 | subir de nivel VIP, venta cruzada |
| 3 | No podemos perderlos | R ≤ 2 y F ≥ 4 | contacto personal y oferta fuerte; aviso al dueño |
| 4 | En riesgo | R ≤ 2 y (F = 3, o F = 2 y M ≥ 4) | recuperación escalonada |
| 5 | Potencialmente leales | R ≥ 4 y F = 2 | invitar al programa de puntos, recomendaciones |
| 6 | Nuevos | R = 5 y F = 1 | bienvenida y empujón a la segunda compra |
| 7 | Prometedores | R = 4 y F = 1 | flujo de segunda compra |
| 8 | Necesitan atención | R = 3 y F ≤ 2 | oferta por tiempo limitado, novedades |
| 9 | A punto de dormirse | R = 2 y F ≤ 2 | reactivación con contenido y beneficio moderado |
| 10 | Hibernando | R = 1 y F = 2 | campaña puntual de temporada |
| 11 | Perdidos | resto (R = 1 y F = 1) | excluir de campañas caras; incluir solo en grandes fechas |

Claves internas, en el mismo orden (`contacts.rfm_segment` y el segmento de sistema `rfm_<clave>`): `champions`, `loyal`, `cant_lose`, `at_risk`, `potential_loyal`, `new`, `promising`, `need_attention`, `about_to_sleep`, `hibernating`, `lost`. El cálculo corre cada noche (`igr:nightly`, o `igr:rfm --workspace=` a mano) y deja una fila en `prediction_runs` con el modelo `rfm`.

Cada cambio de segmento genera `segment.entered` y `segment.exited` (sirven como disparadores de flujos) y queda en el perfil.

---

## 4. Predicciones y puntajes

Regla general: **se muestra una predicción solo si hay datos suficientes**. Si no, aparece «Datos insuficientes» con el motivo. Cada modelo guarda en `prediction_runs` su precisión contra un periodo de reserva, y la interfaz la muestra («qué tan confiable es»).

### 4.1 Fecha esperada de próxima compra (Fase 4; versión simple en la Fase 2)
- Clientes con ≥ 2 pedidos: `intervalo = w × mediana_personal + (1 − w) × mediana_de_referencia`, con `w = min(1, (n_pedidos − 1) / 4)`. La mediana de referencia es la de los clientes con la misma categoría principal.
- Clientes con 1 pedido: la mediana de días hasta la segunda compra de quienes compraron la misma categoría la primera vez.
- `expected_next_order_at = last_order_at + intervalo`.
- **Umbral mínimo:** 300 clientes con ≥ 2 pedidos en el espacio de trabajo.

### 4.2 Riesgo de abandono
- **Fase 2 (heurística):** `ratio = días_desde_la_última / intervalo_esperado`. Bandas: < 1 = bajo, 1–2 = medio, > 2 = alto. Las bandas se calibran por espacio de trabajo con datos históricos (qué ratio predijo de verdad que no volvieron en 12 meses).
- **Fase 4 (modelo):** BG/NBD (Pareto/NBD si ajusta mejor) que da P(activo) y las compras esperadas en 90 y 365 días. `churn_risk = 1 − P(activo)`.

### 4.3 CLV previsto (Fase 4)
- BG/NBD (número de compras) × Gamma-Gamma (valor por compra) = **CLV a 12 meses**. CLV total = histórico + previsto.
- Se ejecuta en un **servicio Python** aparte (`hub/ml/`, FastAPI + `pymc-marketing` o `lifetimes`) que el hub llama por cola. Reentrena cada semana y valida con un periodo de reserva (últimos 90 días).
- **Umbral:** 500 clientes con compra y 12 meses de historial.

### 4.4 Propensión de compra a 30 días (Fase 4+, solo con volumen)
- Gradient boosting (LightGBM) con estas variables: recencia, frecuencia, monto, interacción con mensajes, sesiones y productos vistos en los últimos 7 y 30 días, carritos, puntos y RFM.
- Sirve para elegir a quién enviar una campaña cara: el 20% superior.
- **Umbral:** 2.000 compradores y 50.000 sesiones al mes.

### 4.5 Afinidad de productos y «siguiente producto»
- **Reglas de asociación** (soporte, confianza y *lift*) sobre los pedidos de 12 meses, calculadas cada noche. Alimentan «Comprados juntos frecuentemente» y la venta cruzada.
- **Siguiente producto:** matriz de transición categoría o producto del pedido N → pedido N+1. Alimenta el flujo de segunda compra.
- **Afinidad personal por categoría y marca:** puntaje con decaimiento. Vista = 1, carrito = 3, compra = 5, reseña positiva = 2. Vida media de 30 días. Se guarda el top 5 en `contacts.category_affinity`.

### 4.6 Sensibilidad a descuentos y rango de precio
- `coupon_orders_ratio` = pedidos con cupón ÷ pedidos. Segmento «Solo compra con descuento» (≥ 0,8 con ≥ 2 pedidos) y «Nunca usa cupón» (0 con ≥ 2 pedidos). **El uso clave es no regalar margen a quien compra a precio completo.**
- `price_band` = terciles del precio mediano de los productos comprados frente al catálogo.

### 4.7 Mejor hora y canal
- Por contacto: histograma de horas (en su zona horaria) de lecturas, clics, respuestas y compras, ponderado por recencia. La hora de mayor peso que caiga dentro de la ventana legal.
- Mínimo 5 interacciones; si no, se usa la mejor hora del espacio de trabajo para ese canal.
- `preferred_channel` = el canal con mayor tasa de interacción en 90 días.

### 4.8 Puntaje de interacción (0 a 100)
Suma con decaimiento: visita al sitio, clic en un mensaje, lectura en WhatsApp, respuesta, apertura de correo (sin MPP) y compra, con pesos configurables. Sirve para **suprimir a los que no interactúan** y proteger la entrega y la calidad del número.

### 4.9 Puntaje de lead (servicios)
- **Reglas por defecto:**

| Señal | Puntos |
|---|---|
| Visitó la página de precios o servicios | +10 |
| Visita recurrente en 7 días | +5 |
| Envió un formulario | +30 |
| Clic en WhatsApp o llamada | +15 |
| Correo de dominio corporativo | +10 |
| Ciudad dentro del área de servicio | +5 |
| Decaimiento semanal | −5 |

- **IA opcional:** clasifica el mensaje del formulario (intención, urgencia, presupuesto mencionado) y suma o resta hasta 20 puntos, con la razón visible.
- Umbrales «frío», «tibio» y «caliente» configurables. Un lead caliente avisa al vendedor al instante.
- **Estado (Fase 5):** `LeadScorer`. El puntaje es la suma de las razones guardadas en `leads.score_reasons` (de 0 a 100): cada señal suma **una vez por lead** con los puntos vigentes cuando ocurrió (las vistas de precios llevan la cuenta en `detail.views`); al crear el lead se leen 30 días de navegación en ClickHouse (vistas de precios, días con visita en los últimos 7, clics en WhatsApp o llamada), con los visitantes del contacto de MariaDB (`contact_identities`) además de `identity_map`, y **90 s después se repite esa lectura** (`RescoreLeadHistory`): quien mira precios y llena el formulario en el mismo minuto deja su visita en el lote de la ingesta que todavía no llegó a ClickHouse; como cada señal suma una vez, repetirla no infla el puntaje. Después suman las señales en vivo. Páginas de precios o servicios por prefijo de ruta (`/precios`, `/tarifas`, `/planes`, `/servicios`, `/cotiza`, `/pricing` por defecto); correo corporativo = dominio fuera de la lista de correos personales (gmail, hotmail, outlook, yahoo, icloud, live…, más los que agregue el negocio); área de servicio = lista de ciudades del negocio (vacía, no aplica). Umbrales por defecto: tibio 30, caliente 60. **Decaimiento:** −5 por cada semana completa sin actividad desde la última señal; una señal nueva lo reinicia (`igr:leads:decay` cada noche, idempotente). **IA:** el mensaje del formulario va al modelo sin correos ni teléfonos (cola `ai`, job cifrado) y devuelve puntos de −20 a +20, la razón y el servicio si faltaba; el mensaje nunca se guarda. Sin IA o si falla, quedan las reglas. Función de IA: `lead_qualifier` (Haiku, esfuerzo bajo).

### 4.10 Estado (Fase 4)

- **Servicio `hub/ml`** (FastAPI, numpy y scipy, sin `lifetimes`, que ya no se mantiene): ajusta BG/NBD **y** su versión modificada MBG/NBD (en la clásica, quien compró una sola vez siempre está «activo»; en la modificada también puede irse) y se queda con la que mejor predice el periodo de reserva; Gamma-Gamma para el valor por compra (promedio de las compras repetidas). Compras agrupadas por día; unidades en semanas.
- **`igr:predictions:train`** (domingos; `--nightly` cada noche sin reentrenar; `--only=` por paso; `--queue`): ajusta con la historia hasta hace 90 días, compara con lo que pasó, vuelve a ajustar con todo y escribe `p_alive`, `expected_orders_90d/365d`, `churn_risk = 1 − P(activo)`, `churn_band`, `predicted_clv_12m_ws` y `predictions_at`. Cada modelo deja su corrida en `prediction_runs` con la precisión; si el servicio no responde, las predicciones anteriores se quedan.
- **Bandas de abandono calibradas** con la reserva: de los deciles de P(activo), «alto» los que volvieron a comprar a la mitad de la tasa general o menos y «medio» los que quedaron por debajo de la tasa general. Si el decil más bajo vuelve más que el promedio (no ordena), se usan los cortes 0,3 y 0,7 de `config/igr-predictions.php`.
- **Sin datos suficientes** (umbrales de este documento) la corrida queda `insufficient_data` con el motivo, el perfil muestra «Datos insuficientes: …» y el abandono usa la heurística de §4.2 (días sin comprar frente al intervalo esperado de §4.1).
- **Próxima compra (§4.1)** con la categoría principal (la más comprada) y la del primer pedido; se valida con el mismo cálculo hecho hace 90 días (cuántos de los que compraron lo hicieron a 15 días o menos de la fecha esperada, error mediano).
- **Afinidad (§4.5)** con vistas y carritos de ClickHouse (anónimos vinculados por `identity_map`), compras y reseñas de 4 o 5 estrellas, vida media de 30 días, top 5 en `category_affinity` (`[{category_id, score}]`, lo que lee el motor de recomendaciones) y `brand_affinity`. **Siguiente producto** en `product_transitions` (producto y categoría, soporte mínimo 3); «comprados juntos» sigue en `product_associations` (M16).
- **Rango de precio (§4.6)** contra los terciles del catálogo; `coupon_orders_ratio` lo mantiene `RecalculateContactStats`.
- **Mejor hora y canal (§4.7)**: lecturas, clics, respuestas y compras en la zona del contacto, con vida media de 60 días; solo horas de la ventana legal de lunes a viernes. `BestHour::forContact()` da la del contacto o la mejor del negocio por canal; las campañas «a la mejor hora» la usan cuando el contacto no tiene una.
- **Interacción (§4.8)**: visitas, aperturas sin MPP, lecturas, clics, respuestas y compras con vida media de 30 días; `100 × (1 − e^(−suma/15))`.
- **Propensión (§4.4)**: el modelo no está activo; la corrida dice por qué (umbrales o «todavía no está activo»).
- **Medido con la tienda demo** (3.660 clientes, 10.000 pedidos, reserva de 90 días): MBG/NBD elegido; predijo 634 compras y hubo 727 (−12,7 %, correlación 0,65 por cliente); ingresos −10,7 %; AUC de P(activo) 0,67 (BG/NBD clásico: 0,21). Próxima compra: 13,9 % a 15 días o menos, error mediano de 65 días (confiabilidad baja con estos datos sintéticos).

---

## 5. Motor de hallazgos

Los detectores se ejecutan cada noche (o cada semana, según el detector). Cada hallazgo guarda `facts` (JSON con todas las cifras), severidad, **impacto estimado en dinero con sus supuestos explícitos**, acción recomendada (enlace o «crear con 1 clic») y vigencia. No se repite un hallazgo descartado durante 30 días salvo que empeore más de un 50%.

### 5.1 Detectores

**Ventas y embudo**
1. **Caída de conversión** del checkout o del carrito frente a las 4 semanas anteriores, con una prueba z de proporciones (p < 0,05 y caída > 15%), segmentada por dispositivo, navegador y método de pago. **Se cruza con los eventos `site.changed`**: «La conversión del checkout en móvil bajó de 2,1% a 1,3% desde el 10 de noviembre; ese día se actualizó el plugin X».
2. **Método de pago problemático:** tasa de fallos o de pedidos pendientes nunca pagados muy por encima de lo normal (PSE, transferencia, Efecty).
3. **Productos muy vistos que poco se venden:** decil inferior de conversión vista → compra con tráfico alto. Revisar precio, fotos, stock y descripción.
4. **Productos con alto abandono en el carrito.**
5. **Búsquedas frecuentes sin resultados**, con la propuesta de crear un sinónimo en Imagina Search o el producto.
6. **Productos estrella con stock bajo:** días de inventario menores a 14 al ritmo actual de ventas.
7. **Páginas lentas que pierden ventas:** si hay Web Vitals (Imagina Turbo), conversión por banda de LCP o INP en páginas con tráfico.

**Clientes**

8. **Clientes valiosos en riesgo:** cuántos son «No podemos perderlos» o «En riesgo» y cuánto dinero histórico representan.
9. **Primera compra estancada:** clientes de 1 pedido que superaron el tiempo mediano hasta la segunda compra sin flujo activo.
10. **Concentración:** el 10% de los clientes genera el X% de los ingresos, con el riesgo y la sugerencia de programa VIP.
11. **Canal con el mejor LTV frente al de menor CAC:** «Los clientes de Google valen 2,1× más a 180 días que los de Meta».
12. **Producto de entrada ganador:** el que crea los clientes de mayor LTV, con la sugerencia de usarlo en los anuncios.
13. **Ciudad con potencial:** mucho tráfico y baja conversión (¿envío caro o lento?) o ventas creciendo rápido.
14. **Dependencia de descuentos en aumento:** el porcentaje de pedidos con cupón sube y el margen baja.

**Mensajería**

15. **Flujo esencial inactivo:** por ejemplo, sin carrito abandonado activo, con el dinero estimado que se pierde = carritos abandonados de 30 días × tasa de recuperación de referencia conservadora (configurable; 5% por defecto) × ticket.
16. **Calidad del número de WhatsApp bajando**, o una campaña con bloqueos y bajas altos.
17. **Reputación del correo:** rebotes > 2% o quejas > 0,1%.
18. **Fatiga:** contactos con más de N mensajes por semana y bajas en aumento.

**Medición**

19. **Conversiones desde el servidor fallando**, o con baja proporción de datos coincidentes.
20. **Desfase de datos:** pedidos de WooCommerce frente a pedidos recibidos > 2% (problema de conexión).

**Servicios**

21. **Leads sin respuesta en 15 minutos.**
22. **Formulario con baja conversión**: vistas de la página frente a envíos.
23. **Cierre por fuente:** la fuente con más leads frente a la fuente con más leads ganados.

### 5.2 Impacto estimado
Siempre conservador, con el supuesto visible: «Estimación: 37 clientes × 18% de reactivación de referencia × $185.000 de ticket = **$1.232.100**. Supuesto editable». Nunca se presenta como promesa.

### 5.3 Estado (Fase 4)
- **Detectores:** los 23 de §5.1 funcionan (`App\Domain\Intelligence\Insights\DetectorCatalog`, con número, categoría y cadencia). Los de servicios (21 a 23) llegaron en la Fase 5 con los leads: **#21** (nocturno) leads de los últimos 7 días que esperaron más de 15 minutos, con el dinero en juego = abiertos sin respuesta × tasa de cierre de 180 días × valor promedio ganado; **#22** (semanal) la página con formulario que convierte menos de la mitad del promedio de las páginas con formulario (28 días, al menos 200 vistas); **#23** (semanal) la fuente con más leads frente a la que más leads ganados deja (90 días, al menos 5 leads por fuente), si la de volumen cierra a la mitad o menos.
  - **Nocturnos:** 1, 2, 6, 8, 9, 15, 16, 17, 19 y 20.
  - **Semanales:** 3, 4, 5, 7, 10, 11, 12, 13, 14 y 18. Corren los lunes, o si nunca corrieron (`igr:insights --all` los fuerza; `--detector=` corre solo los que se nombren).
- **Umbrales** (`config/igr-insights.php`):
  - **#3:** tráfico en el cuartil superior y conversión en el decil inferior, con al menos 30 vistas.
  - **#4:** abandono de 70 % o más y 20 puntos sobre la mediana, con al menos 20 veces agregado.
  - **#5:** al menos 5 búsquedas por término. Se descartan las que parecen correos o teléfonos.
  - **#9:** la mediana propia de días hasta la segunda compra (45 días con menos de 30 casos), clientes del último año y sin el flujo 9 activo.
  - **#10:** el 10 % de los clientes genera el 50 % o más.
  - **#11 y #12:** 1,5 veces o más el valor a 180 días, con 30 clientes por canal o 20 por producto.
  - **#13:** al menos 200 sesiones y el 5 % del tráfico, con la mitad o menos de la conversión del sitio.
  - **#14:** sube 10 puntos o más.
  - **#16:** calidad amarilla o roja, o una campaña con 2 % de bloqueos (131050) y bajas.
  - **#17:** rebotes sobre 2 % o quejas sobre 0,1 % en 14 días.
  - **#18:** más de 3 mensajes de marketing por semana y bajas que suben un 20 %.
  - **#19:** fallos sobre 10 %, o menos de 2 claves coincidentes en promedio.
- **#7 Páginas lentas:** necesita Web Vitals (`page.vitals` con `lcp_ms`, que enviará Imagina Turbo). Sin ellos se salta, y la pantalla de ajustes muestra el motivo.
- **#11 Canal con mejor LTV:** compara el valor a 180 días por canal; el costo de adquisición entra cuando haya gasto en anuncios por canal **[verificar]**.
- **Impacto:** es un producto de factores (`Impact`/`Term`) guardado en `insights.impact_assumptions.formula`. Los supuestos editables por espacio de trabajo viven en `insight_assumptions`; sin fila, aplica el valor por defecto de `config/igr-insights.php`:
  - reactivación: 18 %;
  - recuperación de pedidos sin pagar: 30 %;
  - recuperación de carritos: 5 %;
  - segunda compra con un flujo: 10 %;
  - brecha cerrada al mejorar un producto: 25 %;
  - abandono de más recuperado: 20 %;
  - búsquedas sin resultado que comprarían: 25 %;
  - brecha cerrada en una ciudad: 25 %.

  Cambiar un supuesto recalcula al instante los hallazgos abiertos que lo usan.
- **Vigencia y repetición:** un hallazgo vale 7 días (nocturno) o 14 (semanal) y vence aunque su detector no corra. Uno descartado no vuelve en 30 días salvo que su impacto, o su magnitud si no tiene impacto en dinero (#10 a #12, #16 a #20), empeore más de un 50 %.
- **«Hacer ahora» con 1 clic:**
  - activa el flujo de la biblioteca (#4 y #15 carrito abandonado, #9 segunda compra);
  - o crea la campaña con el segmento del sistema ya elegido (#8, «En riesgo»);
  - y marca el hallazgo como atendido.

  Los demás llevan a la pantalla indicada.
- **Para la IA:** `TopInsights::facts(Workspace, int $limit = 5)` da los hallazgos abiertos por impacto como hechos, sin datos personales, con la fórmula escrita. Lo usa el analista semanal.

---

## 6. IA

### 6.1 Principios
- Proveedor: Anthropic con su SDK oficial para PHP (`anthropic-ai/sdk`; se eligió en lugar de Laravel AI SDK porque es el que documenta Anthropic y trae salidas estructuradas, herramientas estrictas, caché de prompts y respaldo ante rechazos). Por defecto `claude-sonnet-5-5` para análisis, copiloto y agente, y `claude-haiku-5-5` para clasificación, moderación y resúmenes cortos. Se configura por función (`config/igr-ai.php`).
- **Mínimo de datos personales:** al analista se le envían agregados y hechos, no perfiles. Al agente de la bandeja, solo la conversación y los datos que necesita, consultados con herramientas.
- **Validación numérica:** todo número del texto generado (regex con miles, decimales y porcentajes) debe existir en `facts`, con tolerancia por redondeo. Si falla, se regenera una vez; si vuelve a fallar, se usa la plantilla sin IA.
- Costo registrado en `ai_usage`. Límites por plan y caché de prompts.
- Todo texto generado se marca como tal en la interfaz y se puede editar antes de usar.

### 6.2 Analista semanal
- Lunes a las 7:00 en la zona horaria del espacio de trabajo.
- Entrada: KPIs de la semana contra la anterior y contra el promedio de 4 semanas, más los 5 hallazgos principales (`facts`).
- Salida en español, de 250 palabras como máximo: qué pasó, por qué (solo si los hechos lo respaldan) y **3 acciones** con botón para ejecutarlas.
- Se entrega por correo y opcionalmente por WhatsApp al dueño, y queda en `ai_reports`.

### 6.3 «Pregúntale a tus datos»
- Pregunta en lenguaje natural → el modelo devuelve una **consulta estructurada** sobre la capa semántica por *tool use*: métricas del catálogo §1, dimensiones (fecha, canal, ciudad, producto, categoría, segmento, dispositivo, método de pago), filtros y rango.
- **El hub ejecuta la consulta**; el modelo nunca escribe SQL. Siempre se aplica `workspace_id`.
- Responde con una tabla o gráfico y 2 a 3 líneas de explicación. Ofrece «Guardar como segmento» o «Agregar al tablero».

### 6.4 Copiloto de campañas y flujos
Ver `modulos.md` M10. Genera una propuesta completa (segmento, canales, textos, horario, cupón, costo y cumplimiento). **Requiere aprobación humana.**

### 6.5 Contenido
Asuntos, textos de WhatsApp (respetando las reglas de plantillas), variantes A/B, tono de marca aprendido de la portada, la página «Nosotros» y textos aprobados del cliente.

### 6.6 Clasificación y moderación
- **Mensajes entrantes:** intención (compra, soporte, queja, **baja**) y sentimiento. La baja revoca el consentimiento de inmediato.
- **Reseñas:** lenguaje ofensivo, spam y datos personales en el texto; resumen por producto.

### 6.7 Agente de ventas (bandeja)
- **Herramientas:** `search_products`, `get_product` (stock y precio en vivo por comando firmado), `get_order_status`, `create_cart_link`, `get_policies`, `get_loyalty_balance` (solo para el contacto verificado del chat), `book_appointment`, `handoff_to_human`.
- **Instrucciones de sistema** con el alcance del negocio, el tono de la marca y las políticas. Prohibido: temas fuera del negocio, inventar datos y prometer descuentos fuera de las reglas.
- **Registro completo** y panel de revisión con conversaciones marcadas, tasa de traspaso a humano, ventas y satisfacción.
- **Estado (Fase 4):** `App\Domain\Intelligence\Ai\Agent\SalesAgent` implementa `ConversationAgent` (lo registra `App\Domain\SalesAgent\ModuleServiceProvider`).
  - **Herramientas** con esquemas estrictos. `search_products` busca en el catálogo del hub **sin precio ni stock**, para obligar a consultar. `get_product` trae precio, oferta, stock y variaciones en vivo con el comando `product.lookup`. `get_order_status` usa `order.status` con el teléfono del contacto del chat, nunca uno que escriba la persona. `create_cart_link` arma un carrito con estado `agent`, precios en vivo y subtotal, con el enlace restaurable; el cupón solo va si `discount_rules` lo autoriza y la compra llega al mínimo. Además: `get_policies`, `get_loyalty_balance` (solo el contacto del chat), `book_appointment` (no disponible hasta la Fase 5) y `handoff_to_human`.
  - **«No inventa precios ni stock» en código (`ReplyGuard`).** Toda cifra de la respuesta debe salir de lo que devolvieron las herramientas en ese turno, de las políticas o (enteros de hasta 999) de lo que escribió la persona. Hablar de un precio de producto o de stock exige una consulta en vivo exitosa en el turno. Si falla, se corrige una vez diciéndole al modelo qué falta; si persiste, en `auto` pasa a una persona sin enviar la cifra y en `assist` deja la sugerencia marcada como «revisar».
  - **Guardas antes de llamar al modelo:** mensaje sin texto, baja (clasificador), más de 30 turnos por conversación en una hora, palabras de traspaso, intenciones de traspaso (quejas por defecto), horario del equipo (`hours.mode = out_of_hours`: dentro del horario atienden las personas) y, en `auto`, ventana de 24 h cerrada.
  - **Traspaso:** la conversación queda con `ai_mode = off` y `handoff_reason`, se registra un `conversation_events` (`handoff`) y, en `auto` con la ventana abierta, se avisa a la persona.
  - **Envío:** mensaje de servicio por `MessageDispatcher` con la clave `agent:{turno}`, enlazado a la conversación. «Probar el agente» usa las mismas herramientas sobre la tienda real, sin enviar nada, y deja los turnos como prueba (`is_test`).
  - **Revisión** (`bandeja/agente`): turnos con herramientas, validación y tokens; marcados; tasa de traspaso; ventas de los carritos del agente que terminaron en pedido (carrito `agent_…` recuperado). Se marca cada turno como correcto o error. La satisfacción por conversación queda pendiente: hoy no hay NPS ni CSAT por conversación.
  - **Evaluación con el modelo real:** `igr:agent:eval --workspace=ws_…` hace 10 preguntas (precio, stock, variaciones, envío, pagos, pedido, carrito y fuera de alcance) y falla si alguna respuesta trae cifras sin respaldo, habla de precio o stock sin consultar en vivo o usa el catálogo fuera de alcance. Necesita `IGR_ANTHROPIC_API_KEY` **[verificar con la clave]**.
