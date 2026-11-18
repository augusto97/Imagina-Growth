# hub/ — App central (Laravel 13 + React)

> Lee antes: `/CLAUDE.md`, `/docs/eventos.md`, `/docs/modelo-de-datos.md`, y el documento del módulo en el que trabajas (`/docs/modulos.md`).
> Dominio: `growth.imagina.cloud` (panel y API), `in.growth.imagina.cloud` (ingesta), `go.growth.imagina.cloud` (enlaces rastreados y centro de preferencias, o el dominio propio del cliente).

---

## 1. Responsabilidades

Recibir y validar eventos, resolver identidades, guardar en ClickHouse y MariaDB, calcular segmentos, métricas y predicciones, ejecutar flujos y campañas, enviar mensajes, enviar conversiones a plataformas de anuncios, servir el panel (SPA), la API pública, el servidor MCP y los endpoints públicos de los widgets.

## 2. Stack y paquetes

- PHP 8.4, Laravel 13, MariaDB 11, ClickHouse 25.x, Redis 7.
- **Laravel:** Horizon (colas), Reverb (WebSockets), Sanctum (autenticación de la SPA y tokens de la API), Pennant (feature flags por plan), Scout no (la búsqueda es propia), Laravel MCP. La IA usa el SDK oficial de Anthropic para PHP (`anthropic-ai/sdk`, §12).
- **Terceros (verifica la versión vigente al instalar):**
  - `spatie/laravel-data` (DTOs). La auditoría usa la tabla propia `audit_logs` de `modelo-de-datos.md` §1 (acción `RecordAuditLog`, cambios enmascarados e IP con hash) en lugar de `spatie/laravel-activitylog`, porque su esquema no coincide con el del modelo de datos.
  - Autenticación de la SPA: Laravel Fortify (rutas JSON bajo `/auth`, 2FA TOTP con códigos de recuperación) sobre la sesión de Sanctum.
  - Validación de JSON Schema: `opis/json-schema` (draft 2020-12), con los esquemas compilados una vez por proceso.
  - `giggsey/libphonenumber-for-php` (E.164).
  - `keepsuit/liquid` (plantillas Liquid en sandbox).
  - `matomo/device-detector` (user agent) y `geoip2/geoip2` con la base GeoLite2 de MaxMind (geo por IP; la IP se descarta después).
  - Cliente de ClickHouse: `App\Analytics\Ch`, un cliente propio y mínimo sobre la interfaz HTTP nativa (parámetros `{nombre:Tipo}` enlazados, sin SQL concatenado). Se eligió en lugar de `smi2/phpclickhouse` para controlar del todo el candado de `workspace_id`.
  - `aws/aws-sdk-php` (SES y SNS), `league/csv`, `spatie/laravel-pdf` (reporte mensual), `dedoc/scramble` (OpenAPI).
  - `ramsey/uuid` (UUID v5 y v7).
- **Calidad:** Pest, Larastan nivel 8, Pint, Rector.

## 3. Estructura

```
hub/
├── app/
│   ├── Domain/                     ← un directorio por módulo de negocio
│   │   ├── Workspaces/  Sites/  Contacts/  Identity/  Consent/
│   │   ├── Commerce/    (orders, carts, products, coupons)
│   │   ├── Segments/    Flows/   Campaigns/  Messaging/  Templates/
│   │   ├── Inbox/       Onsite/  Loyalty/    Referrals/  Reviews/
│   │   ├── Measurement/ (ad destinations, conversions, audiences, spend)
│   │   ├── Intelligence/ (rfm, predictions, insights, ai)
│   │   ├── Services/    (leads, bookings, quotes)
│   │   └── Billing/
│   │       Cada módulo: Actions/ Models/ Data/ Jobs/ Events/ Listeners/ Policies/ Queries/
│   ├── Ingest/                     ← endpoints y pipeline de ingesta (§5)
│   ├── Analytics/                  ← Ch (cliente ClickHouse), capa semántica (§10)
│   ├── Support/                    ← Pii, Money, Tenancy, Hmac, Holidays, Phone
│   └── Http/ (Controllers/Api/V1, Controllers/Spa, Controllers/Public, Middleware)
├── resources/
│   ├── js/                         ← SPA React (§14)
│   └── schemas/events/*.json       ← JSON Schema de cada evento (compartido con connector y tracker)
├── ml/                             ← servicio Python (FastAPI) de predicciones (Fase 4)
├── database/ (migrations, clickhouse/*.sql, seeders)
└── tests/ (Feature, Unit, Contract, Tenancy)
```

**Reglas:** la lógica de negocio va en `Actions` (una clase, un `handle()`), nunca en controladores ni en modelos. Las consultas complejas van en `Queries`. Los DTOs se hacen con `laravel-data`.

## 4. Multi-cliente (tenancy)

- `App\Support\Tenancy\CurrentWorkspace` es un singleton por request o job. El middleware `ResolveWorkspace` lo toma de la ruta `/api/w/{workspace}/...` y verifica la membresía.
- El trait `BelongsToWorkspace` agrega un *global scope* y llena `workspace_id` al crear. **Todos** los modelos de negocio lo usan.
- Los jobs implementan `WorkspaceAware` y el middleware de job `SetWorkspaceContext` restaura el contexto.
- Las claves de Redis y la caché llevan el prefijo `ws:{id}:`.
- ClickHouse: `Ch::forWorkspace($ws)->select(...)`. No existe un método que consulte sin workspace.
- **Pruebas `tests/Tenancy`:** recorren todos los modelos y endpoints con dos espacios de trabajo y verifican que no hay fugas. Corren en CI y son obligatorias.

## 5. Pipeline de ingesta

```
POST /v1/b (tracker) ─┐
POST /v1/s (connector)┴→ IngestController (≤ 50 ms p95)
      1. autenticar (clave pública + Origin | HMAC + tiempo + nonce)
      2. validar el sobre (barato) y el tamaño
      3. XADD al Redis Stream  ingest:{shard}   → 202
                    │
       IngestWorker (consumer group; `php artisan igr:ingest:work --shard=N`, un proceso por shard)
      4. dedupe: ws:{id}:evt:{event_id} en Redis con TTL 7 días
      5. validar el esquema del evento (JSON Schema compilado en caché)
      6. enriquecer: geo (y descartar la IP), dispositivo, canal (§2 de inteligencia), moneda → ws
      7. identidad: IdentityResolver (caché Redis del mapa anónimo→contacto; crea o une contactos)
      8. escribir: buffer → ClickHouse cada 1 s o 5.000 filas
      9. proyectar en MariaDB según el evento: orders, carts, products, contacts (contadores), consents
     10. publicar el evento de dominio → TriggerRouter (flujos, segmentos en tiempo real, conversiones)
```

- **Particionado por `workspace_id`** en shards del stream para mantener el orden por contacto.
- **Implementación (Fase 0):** el worker no corre dentro de Horizon (Horizon solo supervisa colas de jobs, no consumidores de Streams): son procesos propios supervisados por systemd (`growth-ingest@1..4`), uno por shard; con SIGTERM terminan el lote en curso antes de salir. Cada lote va en **una transacción de MariaDB con un savepoint por evento**: un evento con datos inválidos se rechaza sin tumbar el lote, y un error transitorio revierte el lote completo, que se reintenta. La clave de dedupe y el `XACK` se escriben **después** de que ClickHouse confirma la inserción, así un fallo a mitad de camino nunca pierde eventos (entrega «al menos una vez» con escrituras idempotentes). Los contadores del contacto se recalculan con un job por contacto y lote.
- **Rendimiento medido** (un proceso, MariaDB y ClickHouse locales): ~117 eventos/s por shard con 6 consultas por evento. El objetivo de carga de §17 (500 eventos/s) se reparte entre shards; la prueba con k6 queda para la Fase 1.
- Los eventos `source.type=import` saltan el paso 10 (no disparan nada).
- Los errores de esquema van a la tabla `ingest_rejections` (muestra de 7 días) y se ven en el panel del sitio.
- **Proyección de pedidos:** `order.*` hace *upsert* en `orders` y `order_items` por (site_id, external_id). Recalcula los contadores del contacto en un job con *debounce* (5 s) por contacto.
- **Carritos:** `cart.*` hace *upsert* en `carts`. Un job cada minuto marca `abandoned` a los carritos sin actividad durante N min (configurable, 45 por defecto) con contacto conocido o identificable, y emite `cart.abandoned` (evento interno del hub).

## 6. Motor de segmentos

**AST (JSON) guardado en `segments.definition`:**

```jsonc
{
  "op": "and",
  "conditions": [
    { "type": "attribute", "field": "city", "operator": "in", "value": ["Manizales", "Pereira"] },
    { "type": "purchase", "scope": "category", "ids": ["12"], "operator": "count_gte", "value": 2,
      "window": { "kind": "last_days", "days": 365 } },
    { "op": "or", "conditions": [
      { "type": "event", "event": "product.viewed", "filters": [{ "prop": "product_id", "operator": "eq", "value": "88" }],
        "operator": "count_gte", "value": 2, "window": { "kind": "last_days", "days": 7 } },
      { "type": "prediction", "field": "churn_band", "operator": "eq", "value": "high" }
    ]},
    { "type": "consent", "channel": "whatsapp", "purpose": "marketing", "status": "granted" }
  ]
}
```

- **Compilador** `SegmentCompiler`:
  - Las condiciones de atributo, compra, consentimiento, fidelización y predicción se convierten en SQL de MariaDB (sobre `contacts`, `orders`, `order_items`, `contact_consents`…).
  - Las de evento o comportamiento se convierten en SQL de ClickHouse que devuelve un conjunto de `contact_id` (usando `identity_map`).
  - El resultado final es la combinación de ambos conjuntos según los operadores. Para conjuntos grandes se usan tablas temporales en Redis (SINTER/SUNION) o en MariaDB.
- **Recálculo:**
  - *En tiempo real:* cuando llega un evento de un contacto, se evalúan solo los segmentos que referencian ese tipo de evento o atributo, y solo para ese contacto.
  - *Completo:* cada noche para todos, y bajo demanda al editar.
  - Las diferencias de membresía generan `segment.entered` y `segment.exited`.
  - *Implementación:* el recálculo en tiempo real corre en la cola `triggers` **15 s después del evento** (`EvaluateContactSegmentsJob`, único por contacto, así una ráfaga de eventos no encola trabajo repetido). El **primer cálculo** de un segmento nuevo no emite `segment.entered`, para no disparar flujos con toda la base de golpe; los siguientes sí.
- **Vista previa:** consulta de conteo y muestra de 20 contactos, con *timeout* de 10 s y mensaje claro si se excede. Los miembros se calculan una sola vez en una tabla temporal de MariaDB que reutilizan el conteo, la muestra, el alcance por canal y el valor del segmento (el peor caso medido bajó de 3,1 s a menos de 1,5 s).
- **Segmentos del sistema:** `system_key` = `rfm_<clave>` con las claves de `inteligencia.md` §3 (`App\Domain\Intelligence\Rfm\RfmSegment`, amarradas por una prueba) y las comunes de la biblioteca. Se crean con cada espacio de trabajo (`igr:segments:install-library` para los existentes) y `?system=rfm_<clave>` en `/segmentos` abre el segmento.

## 7. Motor de flujos

- **Grafo** en `flow_versions.graph`: nodos `{id, type, config}` y aristas `{from, to, branch?}`. Se valida al publicar: sin ciclos sin espera, todas las ramas terminan y no hay nodos de envío sin plantilla.
- **Entrada:** `TriggerRouter` mantiene en Redis un índice `ws:{id}:triggers:{event}` → flujos activos. Al llegar un evento:
  1. Se evalúan los filtros del disparador.
  2. Se revisa la política de reentrada y la exclusión mutua.
  3. Se crea el `flow_run` con un sorteo de grupo de control (`is_holdout`).
- **Ejecución:**
  - El *scheduler* corre cada minuto y busca `flow_runs` con `status in (active, waiting)` y `next_run_at <= now()`, por lotes con `SKIP LOCKED`, y despacha `AdvanceFlowRun`.
  - Cada tipo de nodo implementa `NodeHandler::handle(FlowRun, Node): NodeOutcome`. Los resultados posibles son `Continue(next)`, `Wait(until)`, `WaitForEvent(event, timeout)`, `Exit(reason)` y `Deferred(until)` (cuando la política de envío lo pide).
  - **Idempotencia:** cada paso se registra en `flow_run_steps` con la clave única (run_id, node_id, intento lógico). Un reintento del job nunca reenvía un mensaje.
- **Metas:** al llegar un evento que cumple la meta, el run termina con `goal_reached` y se marca la atribución.
- **Los grupos de control** recorren el flujo sin envíos (los nodos de envío se marcan `skipped_holdout`) para medir el incremento.
- **Versionado:** los runs activos se quedan en su versión. Opción «migrar los runs en curso» solo si los nodos son compatibles.
- **Implementación (Fase 2, `app/Domain/Flows`):**
  - **Índice de disparadores:** `ws:{id}:triggers:{event}` guarda JSON `[{flow, filters}]` de los flujos activos. Además existen `@wake` (eventos que despiertan runs: metas, nodos `wait_event` y `waiting_for` de runs en espera), `@events` y `@built`. `TriggerIndex::lookup` lo reconstruye si falta `@built`, y activar, pausar o editar el disparador o la meta lo invalida. `RouteFlowTriggers` escucha `EventIngested` (salta los `flow.*` y los eventos sin contacto) y encola `EnterFlowJob` en `triggers` con las propiedades limpias de datos personales (`EventProps::sanitize`).
  - **Entrada:** `EnterFlow` toma el candado `flowenter:{flow}:{contacto}` y nunca crea un segundo run vivo para el mismo contacto y flujo. `trigger_key` es único por flujo: `event:{event_id}`, `date:{contacto}:{día}`, `threshold:{contacto}`, `{fuente}:{lote}:{contacto}` o `test:{ulid}`. Emite `flow.entered` y `FinishRun` emite `flow.exited`; ninguno de los dos en modo prueba.
  - **Ejecución:** `igr:flows:tick` (cada minuto, en una transacción con `FOR UPDATE SKIP LOCKED`, 500 por vuelta) alquila cada run 5 min (`next_run_at`) y despacha `AdvanceFlowRun` en `flows` (único hasta procesarse, 3 intentos). `FlowEngine::advance` toma el candado `flowrun:{id}` y avanza hasta 50 pasos seguidos. Los resultados son `continue(branch)`, `wait(until)`, `wait_event(event, timeout)`, `deferred(until)` y `exit(reason)`. Un paso ya registrado se reutiliza con su rama (idempotencia), y el intento lógico de un nodo (`context._attempt`) solo sube cuando el run vuelve a pasar por él en un ciclo.
  - **Esperas de evento y metas:** `FlowSignals` despierta los runs cuyo `waiting_for` coincide y cuyos filtros cumple el evento (`context._woken` → `context.last_event`), y termina con `goal_reached` los runs vivos cuyo flujo tiene esa meta. Guarda `_goal` con el evento, el pedido y `revenue_ws`.
  - **Flujo pausado:** sus runs se posponen 15 min por vuelta. **Archivado:** salen con `flow_paused`/`manual`.
  - **Versionado:** con «migrar», `PublishFlow` mueve a la versión nueva los runs vivos de cualquier versión anterior cuyo nodo actual existe en la nueva con el mismo tipo. Al publicar, cada `template_key` queda fijado como `template_id`.
  - **Plantillas por clave:** `TemplateRef::resolve` busca por ID o por `library_key` en el canal del nodo. `CreateFlowFromLibrary` instala las de la biblioteca de M08 que falten y `LibraryAdapter` quita los pasos de WhatsApp si el espacio de trabajo no tiene una cuenta de WhatsApp activa.
  - **Variables de plantilla del run** (`TemplateContext`): `carrito` (con `url_restaurar` de `CartRestoreLink` y el cupón del run si ya existe), `pedido`, `producto`, `cupon` y, en los avisos al equipo, `aviso` (`titulo`, `resumen`, `detalle` y `url` del perfil).
  - **Webhook saliente:** POST JSON firmado con `X-IGR-Signature` (HMAC-SHA256 con el secreto del flujo, `whsec_…`) y `X-IGR-Delivery` = la clave de idempotencia del paso. Tiene un *timeout* de 5 s y solo acepta HTTPS (salvo en local y pruebas).

## 8. Mensajería

- **Interfaz `ChannelDriver`** (`App\Domain\Messaging\Channels\ChannelDriver`): `send(ChannelAccount, OutgoingMessage): SendResult`, `handleWebhook(Request, ?ChannelAccount): WebhookBatch` (estados, entrantes, plantillas y calidad), `syncTemplates(ChannelAccount): int`, `estimateCost(ChannelAccount, país, MessageKind): ?float` (USD) y `capabilities(): array`. El driver recibe la cuenta en cada llamada porque un mismo driver atiende a todas las cuentas de ese proveedor. `DriverRegistry` resuelve el driver por proveedor.
- **Drivers:**

| Canal | Drivers |
|---|---|
| Correo | `SesDriver`, `SmtpDriver` |
| WhatsApp | `MetaCloudDriver` (con la ruta `/marketing_messages` para marketing), `ZenderDriver` (con topes fijos y bloqueo de marketing masivo), `InfobipDriver` (pendiente) |
| SMS | `TwilioSmsDriver` |
| Messenger (Fase 5) | `MetaMessengerDriver` (`meta_messenger`; base `MetaSocialDriver`): Send API de la página, `RESPONSE` dentro de 24 h y `MESSAGE_TAG` + `HUMAN_AGENT` solo para personas hasta 7 días |
| Instagram (Fase 5) | `MetaInstagramDriver` (`meta_instagram`): Instagram Direct con inicio de sesión de Facebook, solo dentro de 24 h, sin etiquetas |

- **Todo envío pasa por `MessageDispatcher`:**
  1. Resolver el canal y la cuenta.
  2. **`SendPolicy::check()`**: consentimiento, ventana legal por país y festivos, frecuencia (contadores en Redis por contacto, día y semana), supresión, registros de exclusión, ventana de 24 h de WhatsApp para mensajes libres, presupuesto de la campaña y salud del número o dominio. Devuelve `Allow`, `Defer(until, reason)` o `Deny(reason)`.
  3. Renderizar la plantilla (Liquid en sandbox, con un *timeout*).
  4. Reescribir los enlaces.
  5. Guardar el mensaje en `messages` (con `idempotency_key` única) y encolar `SendMessageJob` en `messages:{channel}` con *rate limit* por cuenta (Redis).
  6. El job revisa otra vez `SendPolicy::windowAllows()` y los topes de la cuenta (calentamiento de SES, topes de Zender). Si ya no aplica, el mensaje queda `deferred` con `deferred_until`, y `igr:messages:requeue` (cada minuto) lo vuelve a encolar a su hora: no se usan jobs con retraso largo. Luego llama al driver, registra el estado y emite `message.sent` o `message.failed`. Si el envío nunca llegó al proveedor, se libera el cupo de frecuencia.
- **Contratos internos (Fase 2):**
  - `App\Domain\Messaging\Contracts\MessageDispatcher::dispatch(OutboundMessage): DispatchResult`. Los estados son `queued`, `deferred(until, reason)`, `denied(reason)` y `duplicate(message_id)`. Un aplazamiento **no crea mensaje**: quien llamó vuelve a llamar con la **misma** `idempotencyKey` en `until`. Las claves son `run:{run_id}:{node_id}:{attempt}` y `campaign:{campaign_id}:{contact_id}`. `FakeMessageDispatcher::install()` es el doble para las pruebas.
  - `App\Domain\Consent\Policy\SendPolicy`:
    - `check()` revisa sin reservar (vista previa y revisión de cumplimiento).
    - `checkAndReserve()` reserva el cupo de frecuencia de forma atómica en Redis; la usa el despachador.
    - `release()` devuelve el cupo si el envío no llegó al proveedor.
    - `windowAllows()` la usa el job de envío justo antes de llamar al proveedor.
    - `LegalWindow` da la franja por país en la hora local del contacto, con el final exclusivo.
  - `App\Domain\Templates\Contracts\TemplateRenderer::render(templateId, RenderContext): RenderedMessage` (Liquid en sandbox).
    - **Sandbox:** sin sistema de archivos (`render` falla), límites de recursos en lugar de tiempo máximo, filtros desconocidos como error y variables desconocidas vacías.
    - **Escape:** en el HTML del correo los textos de las variables se escapan; en el asunto, el texto, el SMS y WhatsApp no.
    - **Preheader:** se inyecta oculto al inicio del cuerpo.
    - **WhatsApp:** devuelve `template.components` en el formato de la Cloud API. Un parámetro vacío es error.
    - **Biblioteca:** los flujos piden las plantillas por clave con `TemplateLibrary::install($workspace, 'abandoned_cart_1')`, que es idempotente. Los bucles de los bloques van dentro de `<div>`, nunca entre etiquetas de tabla, porque el parser del navegador del editor sacaría el `{% for %}` fuera de la tabla.
  - `App\Domain\Commerce\Contracts\CouponIssuer::issue(CouponSpec): CouponIssued` (comando `coupon.create`) y `CartRestoreLink::for(Cart)` (api-connector.md §6.1).
- **Contratos internos (Fase 3):**
  - `App\Domain\Contacts\Actions\CaptureContact::handle(Workspace, Site, CaptureInput): CaptureResult`: crea o une el contacto con lo que la persona dejó en el sitio (misma identidad que la ingesta) y registra cada casilla marcada con su evidencia; con `doubleOptInEmail` el correo queda `pending_double_optin`. La usan los widgets, «avísame», las reseñas y los referidos.
  - `App\Domain\Loyalty\Contracts\LoyaltyLedger`: `earnPoints` (idempotente; pendiente hasta `settle`; con `points <= 0` usa la regla del programa), `rulePoints(reason)`, `creditWallet` y los saldos. Lo implementa `DatabaseLoyaltyLedger` (M13).
  - `App\Domain\Loyalty\Contracts\MeSection`: secciones que otros módulos agregan a `GET /p/{key}/me` (p. ej. `referral` de M14) con `$this->app->tag([MiSeccion::class], MeSection::TAG)`; `key(): string` y `forContact(Site, ?Contact): ?array`.
  - `App\Domain\Referrals\Contracts\ReferralRewards::onFriendCaptured(contactId, code, anonymousId, wasKnown, siteId?, ip?)`: el regalo del amigo referido, que llama la captación (`FriendRewards`). `siteId` es donde se crea el cupón; la `ip` solo se compara por hash con las huellas de quien refiere. Repetir la captura devuelve el mismo cupón.
  - `App\Domain\Commerce\Contracts\RecommendationService::recommend(kind, RecommendationContext, limit)`, con los tipos de M16.
  - `App\Domain\Reviews\Contracts\ReviewClassifier::classify(title, body, author)`: banderas de moderación (`offensive`, `spam`, `personal_data`). Hoy `HeuristicReviewClassifier`; en la Fase 4 se registra aquí el `ReviewModerator` de IA.
  - Reseñas (M15): `ReviewToken` firma los enlaces `/r/{token}` y `/r/nps/{token}` (propósito incluido, vencen); `ReviewMediaStore` guarda fotos sin metadatos y videos sin `udta`/`meta` en el disco `igr-reviews.disk` (privado) y los sirve por `/r/m/{reseña}/{nombre}` solo si la reseña está publicada; `igr:reviews:send` (cada 5 minutos) envía solicitudes y encuestas con `ChannelCascade`.
- **Enlaces rastreados:** `https://go.<dominio>/c/{code}` → registra el clic → redirige agregando `utm_source=igr`, `utm_medium={canal}`, `utm_campaign={flujo o campaña}` y `_igr={code}` (identifica en el sitio, ver `eventos.md` §6.2). Los códigos son aleatorios de 8 caracteres, no secuenciales. El `_igr` es el propio código del enlace: el hub lo resuelve con `tracked_links` para vincular el `anonymous_id` con el contacto del mensaje, solo para analítica y atribución, sin fusionar perfiles.
- **Webhooks entrantes** (`routes/public/messaging.php`): Meta (`/webhooks/meta`, verificación `X-Hub-Signature-256` con el secreto de la app; la cuenta se encuentra por `channel_accounts.external_id` = ID del número), SES por SNS (`/webhooks/ses`, verificación de firma y del host del certificado), Zender (`/webhooks/zender/{cuenta}`, secreto por cuenta) y Twilio (`/webhooks/twilio/{cuenta}`, `X-Twilio-Signature`). `WebhookProcessor` aplica los estados solo hacia adelante y emite cada `message.*` una vez; los entrantes los procesa `InboundHandler` (contacto por teléfono, ventana de 24 h, `message.replied`, `ref:` del widget y palabras de baja) y emite `whatsapp.inbound` sin el texto.
- **Baja y preferencias:** `/u/{token}` (página de baja y `POST` de un clic de RFC 8058, sin cookies ni CSRF) y `/preferencias/{token}`. El token (`ContactLinkToken`) va firmado con la clave de la app y sigue al contacto si se fusionó. Toda baja pasa por `RecordConsent` con `authoritative`.
- **Aperturas de Apple MPP:** las aperturas de SES desde las IP de Apple (17.0.0.0/8) se marcan `is_mpp=true` y no cuentan para la atribución **[verificar]**.
- **Palabras de baja** en WhatsApp y SMS: «baja», «no más», «stop», «cancelar suscripción», «no me escriban» y variantes (`igr-messaging.unsubscribe_keywords`, comparadas sin tildes ni signos), o «baja» dentro de un mensaje de hasta 4 palabras, más el clasificador de IA (Fase 4). Revocan el consentimiento de marketing del canal y responden con una confirmación (mensaje de servicio).

## 9. Atribución

- Cuando llega `order.paid`, el job `AttributeOrder`:
  1. Calcula los canales de primer y último contacto con las sesiones de ClickHouse del contacto o anónimo (30 días antes del pedido; el último contacto es el último **no directo**). **Respaldo** cuando no hay datos del tracker (por ejemplo, los 24 meses importados): primero las pistas del propio pedido (`context.last_touch`/`first_touch` de la cookie `_igr_src`), luego la atribución nativa de WooCommerce (`wc_attribution`: `utm` → canal por las reglas de `inteligencia.md` §2, `organic` → búsqueda orgánica, `referral` → referido, `typein` → directo; `admin` y `mobile_app` quedan sin canal). `igr:attribution:backfill` lo corre para los pedidos importados.
  2. Busca **mensajes con clic** en la ventana (5 días por defecto). Si no hay, busca lecturas o aperturas sin MPP dentro de 1 día (si está activo). Gana el más reciente → `attributed_message_id`, `flow_id` o `campaign_id`.
  3. Revisa el cupón de referido o afiliado y la cookie de referido → `attributed_referral_id` o `attributed_affiliate_id` (`ReferralAttributor`, M14). Gana el cupón de un afiliado, luego el cupón de amigo, luego el `referral_code` del último (o primer) contacto (enlace de afiliado o código de cliente) y por último un referido ya registrado de esa persona; nunca ambos. Un referido solo cuenta con la primera compra pagada del amigo. Los cambios de estado del pedido (completado, cancelado, reembolsado) los sigue `SyncReferralOrderJob`, y `igr:referrals:qualify` (cada hora) califica y recompensa pasado el periodo de devolución.
  4. Revisa el widget que capturó al contacto en los últimos 7 días (atribución de captación, `attribution.widget_days`): `WidgetAttributor` llena `orders.attributed_widget_id` y `attributed_widget_variant` con la captura más reciente.
- **Incremento:** el informe compara la conversión y el ingreso por contacto del grupo expuesto frente al de control, con intervalo de confianza.

## 10. Analítica y capa semántica

- `App\Analytics\Semantic\Catalog` define **métricas** (fórmula en SQL de ClickHouse o MariaDB, unidad, formato) y **dimensiones** (columna, joins permitidos). Lo usan los tableros, la exportación, «Pregúntale a tus datos» (IA) y la API.
- `MetricQuery` (DTO): métricas[], dimensiones[], filtros[], rango, comparación, granularidad. `MetricQueryRunner` lo compila, aplica el workspace, consulta `kpi_daily` cuando puede y cae a `events`/`sessions` cuando no, y guarda el resultado en caché (5 min, invalidado por ingesta).
- **Cohortes, RFM y afinidad** se calculan en jobs nocturnos y se guardan en tablas o columnas, no en cada request.

- **Predicciones (Fase 4, `app/Domain/Intelligence/Predictions`):** `RunPredictions` orquesta próxima compra, BG/NBD o MBG/NBD con Gamma-Gamma en el servicio `hub/ml` (`MlClient`, `IGR_ML_URL` e `IGR_ML_TOKEN`), la heurística de abandono, afinidad, siguiente producto, rango de precio, mejor hora y canal e interacción (inteligencia.md §4.10). Cada paso deja su corrida en `prediction_runs` (`PredictionRun::latestByModel`, `Reliability::level`); un paso que falla no detiene los demás. `igr:predictions:train` corre los domingos y con `--nightly` cada noche, en la cola `analytics`.

## 11. Medición (conversiones hacia anuncios)

- `ConversionRouter` escucha los eventos mapeados por destino. Arma el payload por plataforma (con hash según `eventos.md` §7) y lo encola en `conversions:{platform}` con reintentos exponenciales (1 m, 5 m, 30 m, 2 h; máximo 24 h, porque las plataformas descartan conversiones viejas).
- **Meta:** `/{dataset_id}/events`, con `action_source=website` o `business_messaging` (más `messaging_channel=whatsapp` y `ctwa_clid`). Solo se usan los nombres de evento que Meta acepta para mensajería. Se respeta el modo de prueba (`test_event_code`).
- **Google:** GA4 Measurement Protocol para el análisis, y **Data Manager API** para las conversiones mejoradas, las conversiones offline de leads y Customer Match.
- **TikTok:** Events API.
- Cada entrega se registra en `conversion_deliveries` (ClickHouse). El panel muestra los errores con un mensaje humano.
- **Sincronización de audiencias** (Fase 3): un job cada 12 h calcula las diferencias del segmento y envía altas y bajas por lotes. `App\Domain\Measurement\Audiences\SyncAudience` compara el segmento (con la regla de consentimiento de `AudienceEligibility`) contra `audience_members`, que guarda cifrados los hashes enviados, y llama a `AudiencePlatform` (Meta, Google Data Manager, TikTok). `RemoveContactFromAudiences` saca a un contacto de todas las audiencias (revocación de `ads`; sirve para la supresión de cumplimiento.md §9).

- **Conversión offline de leads (Fase 5):** `lead.won` no pasa por el ConversionRouter (es un evento del hub): `Services\Leads\Jobs\SendLeadConversion` arma el `ConversionInput` desde el lead y su contacto y encola `DeliverConversion` solo para `meta_capi` y `google_data_manager`, una vez por lead y con el consentimiento de las audiencias (`AudienceEligibility`). En `config/igr-measurement.php` el evento lleva `meta_action_source` y `google_event_source`; la acción de Google es `conversion_action_lead_won`.

## 12. IA

- `App\Domain\Intelligence\Ai\` tiene servicios por función: `WeeklyAnalyst`, `AskYourData`, `CampaignCopilot`, `ContentWriter`, `InboundClassifier`, `ReviewModerator`, `SalesAgent`.
- **SDK:** el oficial de Anthropic para PHP (`anthropic-ai/sdk`), en lugar de Laravel AI SDK: es el que documenta Anthropic y trae salidas estructuradas, herramientas con `strict`, caché de prompts y el respaldo del servidor ante rechazos. La clave y la URL son propias del hub (`IGR_ANTHROPIC_API_KEY`, `IGR_ANTHROPIC_BASE_URL`): nunca se leen las `ANTHROPIC_*` del sistema. Sin clave, la IA queda apagada y cada función usa su plantilla sin IA.
- **Contratos (Fase 4):**
  - `App\Domain\Intelligence\Ai\Contracts\AiGateway` (`AnthropicAiGateway`; `FakeAiGateway::install()` en las pruebas, con guion: `reply`, `replyJson`, `callTool`, `refuse`, `failWith`):
    - `available(workspaceId)`, `generate(AiRequest)` y `withTools(AiRequest, list<AiTool>, maxSteps)`.
    - Modelo, esfuerzo y `max_tokens` por función en `config/igr-ai.php` (`claude-sonnet-5-5` para análisis, copiloto, contenido y agente; `claude-haiku-5-5` para clasificación, moderación y resúmenes).
    - El `system` va con `cacheControl` (estable por función; lo variable va en los mensajes). Salidas estructuradas con `outputConfig.format` (JSON Schema) y herramientas con `strict`. Respaldo del servidor (`fallbacks: "default"`) ante rechazos por política.
    - Cada llamada suma tokens y costo en `ai_usage` (`AiMeter`) y respeta el tope mensual del espacio de trabajo (`igr-ai.monthly_budget_usd`). Si no se puede llamar, lanza `AiUnavailable` (`disabled`, `budget`, `provider`, `refusal`, `invalid`).
  - `NumberValidator` y `NarrativeWriter` (inteligencia.md §6.1): toda cifra de un texto generado debe estar en los hechos (formato colombiano, porcentajes, escalas, redondeo por los ceros finales; sin fechas, años, horas, viñetas ni los enteros 1 a 3). Si falla, se regenera **una** vez diciéndole al modelo qué cifras no están; si vuelve a fallar, plantilla sin IA. El agente de ventas valida sus cifras contra lo que devolvieron sus herramientas en ese turno (`AiResult::toolFacts()`).
  - `PiiScrubber`: quita correos y teléfonos de los hechos de análisis.
  - `App\Domain\Inbox\Contracts\ConversationAgent` (`onInbound`, `suggest`; por defecto `NullConversationAgent`) e `InboundClassifier` (`classify(workspaceId, text)`; por defecto `HeuristicInboundClassifier`, por palabras).
  - `App\Domain\Inbox\Events\InboundMessageReceived` (después del commit): lo emite `InboundHandler` con cada entrante, que ya guarda el texto cifrado en `messages.payload.text`. Lo escuchan la bandeja, el clasificador y el agente.
  - Bandeja (M11): `OnInboundMessage` (no leídos, ciclo, intención, adjuntos, asignación, `InboxUpdated`, push y `RunConversationAgent` en la cola `ai`), `AssignConversation` (reglas y turnos entre disponibles), `SendInboxMessage` (todo envío de la bandeja; clave `inbox:{conversación}:{uuid}`), `UpdateConversation` (estado, etiquetas, modo de IA, `handoff(conversación, motivo, actor)` para el agente, leído y notas) y `PushNotifier` (`LogPushNotifier` mientras no haya Web Push; `FakePushNotifier::install()` en las pruebas).
- `AskYourData` usa *tool use* con una sola herramienta, `run_metric_query(MetricQuery)`. El modelo nunca recibe credenciales ni escribe SQL.
- **Funciones (Fase 4, `App\Domain\Intelligence\Ai\Features`):** `Analyst\WeeklyAnalyst` (dentro de `SendWeeklyReport`), `AskData\AskYourData` + `MetricQueryTool` (la herramienta `run_metric_query`, con el catálogo como enumeraciones) + `SegmentFromQuery`, `Summaries\ProfileSummary` y `ReviewSummary` (caché por versión de los datos), `Classifiers\AiInboundClassifier` (ligado como `InboundClassifier`; cachea 10 min por texto para que la bandeja y el oyente compartan una llamada), `ClassifyInboundMessage` (oyente de `InboundMessageReceived` en la cola `ai`) y `AiReviewModerator` (ligado como `ReviewClassifier`). Rutas en `routes/api/ai.php` (permiso `useAi` para preguntar y generar).
- `SalesAgent` (`App\Domain\Intelligence\Ai\Agent`, registrado como `ConversationAgent` por `App\Domain\SalesAgent\ModuleServiceProvider`) usa herramientas que llaman comandos firmados al sitio (`product.lookup`, `order.status`; `connector/CLAUDE.md` §8) y datos del hub. Tiene un límite de pasos por turno (`ai_agent_settings.max_steps`) y un registro completo (`ai_agent_turns`, con `is_test` y `cart_id`). `ReplyGuard` valida cada respuesta contra lo que devolvieron las herramientas antes de enviarla. API: `routes/api/agent.php`; evaluación con el modelo real: `igr:agent:eval`.

- **Contratos (Fase 5):**
  - `App\Domain\Workspaces\Contracts\Branding::for(Workspace): BrandingData` (nombre, logo, color, remitente, soporte, dominio del panel y marca blanca). Por defecto `DefaultBranding` (Imagina Growth); la agencia la reemplaza (M22). La usan el reporte mensual, los correos del sistema, la página de reservas y el portal de creadores.
  - `App\Domain\Billing\Contracts\Entitlements`: `allows(Workspace, módulo)`, `limit(Workspace, clave)` (null = sin límite) y `planFor(Workspace)`. Lo implementa `PlanEntitlements` (M21: plan de la cuenta + Pennant `module.<clave>` por espacio + ajustes de la plataforma; sin plan, todo permitido). Además responde `marketing_sends` e `imports`, que solo se apagan con la cuenta suspendida tras la gracia: quien envía marketing o importa debe preguntarlo antes. Nunca corta de golpe: gracia y aviso.
  - `App\Domain\Services\Contracts\LeadIntake::capture(LeadIntakeInput): ?string`: crea o actualiza el lead de un contacto, idempotente por (origen, referencia). La usan el cotizador, la agenda, la API y la bandeja; los formularios entran solos por `form.submitted`. Por defecto `NullLeadIntake`.
  - Paquetes de la fase: Laravel Pennant (tabla `features`), Scramble (solo `/api/v1`), Laravel MCP y `spatie/laravel-pdf` con el driver **dompdf** (sin Chromium en la imagen).
- **Agencia (M22, `App\Domain\Agency`):** `AgencyAccess` (cuentas de agencia de la persona; `viewAgency` solo por `account_user`, nunca por el rol del espacio de trabajo), `SupportSessions` (desde `ResolveWorkspace`: quien entra por la agencia sin ser miembro directo deja sesión y auditoría), `Queries\MoneyGenerated` (**la** consulta del dinero generado por módulo: úsala, no la repitas), `Queries\AgencyOverview`, `Portable\PortableExporter`/`PortableImporter` (plantillas maestras sin IDs ni datos personales; todo llega en borrador), `Reports\MonthlyReports` (hechos → resumen con `NarrativeWriter`, función `monthly_report` → PDF dompdf → correo a los dueños; `igr:agency:monthly-reports`), `AgencyBranding` (ligado como `Branding`) y `Domains\CustomDomains` (CNAME + TXT, `DnsResolver` inyectable, `servable()` para el «ask» de Caddy). Rutas: `routes/api/agency.php` y `routes/public/agency.php`.

## 13. API, webhooks, MCP y tiempo real

- **API pública `/api/v1`** (`app/Domain/Api`, `routes/public/api-v1.php`; guía en `docs/api-publica.md`): token `igr_…` de un `ApiClient` (Sanctum) por espacio de trabajo con alcances (`Scopes::ALL`). Pila: `FormatApiErrors` → `AuthenticateApiClient` (plan `api`/`mcp`, fija el espacio de trabajo, suma `ApiMeter`) → `ThrottleApiClient` (`api_requests_per_minute`) → `RequireScope:…` e `Idempotent` en las escrituras. Errores `{error: {code, message, fields?}}`, cursor `?cursor=`/`next_cursor`. OpenAPI con Scramble en `/api/docs` y `/api/docs.json` (gate `viewApiDocs`: dueño o administrador).
- **API interna de la SPA:** `/api/w/{workspace}/...`, con sesión de Sanctum.
- **API del connector** (firmada con HMAC, ver `connector/CLAUDE.md` §4): `POST /api/connect/exchange` (handshake), `GET /v1/s/catalog/hashes` (conciliación), `POST /v1/s/wallet/redeem` (canje de saldo en el checkout) y `GET /v1/s/config` (textos de consentimiento, estados pagados y ajustes del sitio).
- **Endpoints públicos de widgets:** `/p/{site_public_key}/widgets.json` (configuración, en caché de CDN 5 min) y `/p/{site}/reco?kind=…&product_id=…&cart=…&aid=…` (recomendaciones: caché pública de 60 s para los tipos no personales y privada para los personales; api-connector.md §8.3).
- **Webhooks salientes** (`app/Domain/Webhooks`): `FanOutWebhooks` escucha `EventIngested` (sitio y hub) y consulta `SubscriptionIndex` en caché; cada intento es una fila de `webhook_deliveries` (cuerpo cifrado) que envía `DeliverWebhook`. Firma `X-IGR-Signature` con `App\Support\Hmac\Signature`. Reintentos programados por `igr:webhooks:retry` (cada minuto; 1 min → 12 h), pausa tras `igr.webhooks.pause_after_failures` (15) fallos con correo a los dueños, `igr:webhooks:prune` borra entregas de más de 30 días. URL solo HTTPS públicas (`SafeUrl`; `igr.webhooks.allow_private_urls` en local y pruebas).
- **MCP** (`laravel/mcp`, `app/Domain/Mcp`, `routes/ai.php` → `POST /api/mcp`): herramientas de lectura `get_kpis`, `run_metric_query`, `list_segments`, `get_contact_summary` y `list_insights`, y de escritura `draft_campaign` y `draft_segment`, que crean **borradores** que alguien debe aprobar en la app (campaña en borrador; segmento como `ai_drafts` `kind = segment`, `model = mcp`). Autenticación con token del espacio de trabajo con alcance `mcp` (misma pila que la API, módulo `mcp` del plan); cada herramienta revisa su alcance con `McpContext::deny()`. [verificar] OAuth.
- **Reverb:** canales privados `workspace.{ws_…}.inbox` y `workspace.{ws_…}.live` (eventos en vivo para el tablero «En este momento»), con el id público del espacio de trabajo.
  - **Autorización** en `routes/channels.php`: la bandeja exige `useInbox` y el tablero en vivo `viewDashboards` en ese espacio de trabajo. La SPA autoriza con su sesión de Sanctum en `POST /api/broadcasting/auth` (`withBroadcasting` con `api` + `auth:sanctum`).
  - **Eventos solo con IDs** (`InboxUpdated` → `inbox.updated`): la SPA vuelve a pedir los datos por la API, que aplica los permisos de cada rol.
  - **Configuración:** el hub emite con `BROADCAST_CONNECTION=reverb` y `REVERB_APP_ID/KEY/SECRET`, `REVERB_HOST/PORT/SCHEME` (en producción `127.0.0.1:8085`, servicio `growth-reverb`). La SPA recibe la clave y el host público en tiempo de ejecución (`window.igrRuntime`, desde `IGR_REVERB_PUBLIC_*`), porque el paquete de versión se compila una sola vez para todos los servidores; en desarrollo sigue sirviendo `VITE_REVERB_*`. Caddy pasa `/app/*` y `/apps/*` del dominio del panel a Reverb.
  - **Sin Reverb** (`BROADCAST_CONNECTION=log`, el valor por defecto, o la SPA sin `VITE_REVERB_APP_KEY`, o la conexión caída) la bandeja consulta cada 5 s; con Reverb conectado, cada 30 s (`resources/js/lib/realtime.ts`).

## 14. Frontend (SPA)

- `resources/js/` con React, TypeScript `strict`, Vite, React Router, TanStack Query (todo el estado del servidor), Zustand (UI), shadcn/ui con prefijo `igr-`, Tailwind, Recharts, React Flow (constructor de flujos), GrapesJS + MJML (correo) y la fuente Inter.
- **Estética Linear/Vercel:** densidad media, modo oscuro, ⌘K para navegar y buscar contactos, atajos de teclado, estados vacíos que enseñan («Activa tu primer flujo: carrito abandonado») y skeletons en lugar de spinners.
- **Pantallas principales:** Inicio · Contactos (lista y perfil) · Segmentos · Analítica (12 tableros) · Flujos (lista, constructor, analítica por nodo) · Campañas · Bandeja · En el sitio (widgets) · Fidelización · Referidos y afiliados · Reseñas · Medición (destinos, entregas, audiencias, gasto) · Hallazgos · Configuración (sitios, canales, consentimiento, textos legales, equipo, facturación) · Agencia.
- **i18n** con `i18next`; los textos en `resources/js/locales/es-CO.json` (base), `es-MX.json` y `es-VE.json`.
- **Formato:** moneda con `Intl.NumberFormat` según la moneda y la configuración regional del espacio de trabajo (COP sin decimales en la interfaz).

## 15. Colas y tareas programadas

| Cola | Uso | Workers |
|---|---|---|
| `ingest` (stream) | eventos | dedicados, escalables |
| `projections` | upserts en MariaDB, contadores | 4 |
| `triggers` | flujos y segmentos en tiempo real | 4 |
| `flows` | `AdvanceFlowRun` | 4 |
| `messages:email`, `messages:whatsapp`, `messages:sms`, `messages:messenger`, `messages:instagram` | envíos con rate limit | 2 a 4 c/u |
| `conversions:*` | anuncios | 2 |
| `analytics` | jobs pesados nocturnos | 2 |
| `ai` | llamadas a modelos | 2 |
| `default` | resto | 2 |

Los jobs largos de `analytics` (importación de contactos, recálculo completo de segmentos, inteligencia nocturna) los atiende la conexión `redis-long` de `config/queue.php`: es la misma cola de Redis con `retry_after` de 1.900 s, mayor que su `timeout`, para que ningún worker los vuelva a tomar mientras siguen corriendo. Los segmentos en tiempo real (`EvaluateContactSegmentsJob`, 15 s después del evento) van por `triggers`.

**Programadas:** flujos y campañas (cada minuto), carritos abandonados (cada minuto), sesiones (cada 10 min), segmentos completos, RFM, afinidad, predicciones y hallazgos (cada noche, escalonadas por workspace), gasto en anuncios (cada día), audiencias (cada 12 h), tarifas de WhatsApp (cada trimestre, manual con aviso), analista semanal (los lunes por zona horaria), reporte mensual (el día 1), uso de los planes con avisos al 80/100 % (`igr:billing:usage`, cada 5 min), facturas vencidas y fin de la gracia (`igr:billing:status`, cada hora), decaimiento del puntaje de los leads (`igr:leads:decay`, cada noche a las 03:40) y purga por retención (cada noche). La calificación con IA de los leads (`QualifyLeadWithAi`) va por `ai` y la conversión offline de un lead ganado (`SendLeadConversion`) por `default`.

## 16. Rendimiento y observabilidad

- **Objetivos:**

| Operación | Objetivo |
|---|---|
| Ingesta | p95 < 50 ms |
| Evento → ClickHouse | < 5 s |
| Evento → disparo de flujo | < 60 s |
| Tableros | p95 < 1,5 s |
| Perfil | < 800 ms |
| Vista previa de segmento | < 3 s |

- **Observabilidad:** Sentry (o GlitchTip autoalojado) para errores; Horizon, Telescope solo en local; Better Stack para *uptime* y logs; `/health` con chequeos de MariaDB, ClickHouse, Redis, colas y retraso del stream.
- **Panel interno de la plataforma** (solo superadmin): retraso de ingesta por shard, rechazos, entregas fallidas por proveedor, costo de IA y uso por espacio de trabajo. API en `/api/platform/*` (`routes/platform.php`, middleware `EnsurePlatformAdmin`, cargada por el proveedor de Billing); en la SPA vive en `/w/:ws/plataforma`. Ahí también se asignan planes, se fuerzan módulos por espacio, se emiten facturas y se atienden los pedidos de cambio de plan; todo queda en `audit_logs`.

## 17. Pruebas

- **Contrato:** cada JSON Schema tiene ejemplos válidos e inválidos. El connector y el tracker generan eventos reales en sus pruebas y se validan contra los mismos esquemas.
- **Tenancy:** §4.
- **`SendPolicy`:** suites por país (`docs/cumplimiento.md` §4.2).
- **Flujos:** de punta a punta con el reloj simulado (`travel()`), reintentos e idempotencia.
- **Atribución:** escenarios con clics, lecturas, MPP, control y referidos.
- **Carga:** k6 con 500 eventos/s sostenidos en ingesta, en un entorno parecido a producción.

## 18. Despliegue

- **Servicios nativos de systemd** agrupados en `growth.target` (contrato completo en `docs/despliegue.md`): `growth-app` (FrankenPHP, panel y API), `growth-ingest-api@1/2` (ingesta, dos réplicas), `growth-ingest@1..4` (workers por shard), `growth-horizon`, `growth-reverb`, `growth-scheduler` y `growth-ml`; MariaDB 11.4, ClickHouse 25.8, Redis 7 para colas (`noeviction` + AOF) y otro para caché (`allkeys-lru`), y Caddy. Sin Docker.
- **Caddy** con TLS bajo demanda para los dominios de seguimiento de los clientes (`t.cliente.com`) y para los dominios propios de enlaces y preferencias, con un endpoint `ask` que valida que el dominio pertenece a un sitio.
- **Versiones:** cada release de GitHub publica `growth-<v>.zip` firmado (ed25519) con su `.sha256`. El administrador de la plataforma instala con un clic desde `/plataforma`: el panel deja un pedido y `growth-update` (lanzado con `systemd-run`) revisa espacio, verifica la firma, extrae, cachea, copia la base, migra (compatible hacia atrás), cambia el symlink `current`, reinicia de a uno y vuelve atrás si el health-check falla. En la app: `app/Domain/Updates` (`growth:updates:check` cada hora, rutas `/api/platform/updates`, pestaña «Actualizaciones» de `/plataforma`); la app solo deja el pedido en `shared/state/`, nunca actualiza.
- **Copias de seguridad:** `mariadb-backup` cada día + binlogs; `BACKUP` nativo de ClickHouse completo los domingos e incremental los demás días, al Storage Box con `rclone`; una prueba semanal restaura las dos en bases temporales y compara conteos; **simulacro de restauración cada mes** documentado.
- **Entornos:** `local` (MariaDB, ClickHouse y Redis nativos: `infra/dev/`), `staging` (datos de prueba) y `production`.
