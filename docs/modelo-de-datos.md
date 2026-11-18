# Modelo de datos

> **MariaDB** guarda lo transaccional y los perfiles: lo que se lee y modifica fila a fila.
> **ClickHouse** guarda los eventos y la analítica: lo que se agrega sobre millones de filas.
> **Redis** guarda colas, caché del mapa de identidad, contadores de frecuencia, límites y bloqueos.

Convenciones para MariaDB:
- IDs ULID con prefijo legible en la API (`ct_`, `ws_`, `st_`…). En la base son `CHAR(26)`.
- `workspaces` y `sites` tienen además un `ch_id` numérico (`INT UNSIGNED`, autoincremental y único) que es el `workspace_id` y `site_id` de ClickHouse.
- Toda tabla de negocio lleva `workspace_id` (índice compuesto como primera columna) y el trait `BelongsToWorkspace`.
- Montos en `DECIMAL(18,2)` con su `currency`. Cuando hace falta comparar se agrega `*_ws` (convertido a la moneda del espacio de trabajo con la tasa del día, tabla `fx_rates`).
- PII cifrada con los casts `encrypted` de Laravel más un **índice ciego** (`*_bidx` = HMAC-SHA256 con una clave por espacio de trabajo) para buscar por igualdad.
- Fechas en UTC; la zona horaria del espacio de trabajo y la del contacto se guardan aparte.

---

## 1. Plataforma y acceso (MariaDB)

| Tabla | Columnas clave |
|---|---|
| `accounts` | id, type (`agency`, `direct`), name, legal_name, tax_id, country, billing_email, plan_id, status |
| `workspaces` | id, ch_id, account_id, name, slug, country, timezone, currency, locale, industry, legal_name, privacy_policy_url, settings (json: estados pagados, ventanas de atribución, límites de frecuencia, captura temprana, modo de consentimiento), brand_kit (json: logo, colores, tono de voz), status |
| `users` | id, name, email, password, two_factor_secret, locale, last_login_at |
| `workspace_user` | workspace_id, user_id, role (`owner`, `admin`, `marketing`, `agent`, `viewer`), notifications (json) |
| `account_user` | account_id, user_id, role (`agency_owner`, `agency_member`) |
| `site_connect_codes` | id, workspace_id, code_hash, site_url?, nonce?, return_url?, platform?, name?, created_by, expires_at (10 min), used_at? |
| `sites` | id, ch_id, workspace_id, platform (`woocommerce`, `wordpress`), url, domains (json), public_key, secret (cifrado), secret_rotated_at, previous_secret (cifrado)?, previous_secret_expires_at?, rest_url, name, status (`pending`, `connected`, `degraded`, `disconnected`), timezone, currency, plugin_version, wp_version, wc_version, php_version, capabilities (json: formularios detectados, HPOS, checkout de bloques, COGS), backfill_state (json: progreso y conciliación que informa el plugin), settings (json: estados pagados, contraentrega, pasarelas offline; ver `api-connector.md` §5.1), health (json: último heartbeat), connected_at, last_seen_at, last_event_at, tracking_domain? |
| `api_keys` | id, workspace_id, name, token_hash, scopes (json), last_used_at |
| `audit_logs` | id, workspace_id, user_id?, action, subject_type, subject_id, changes (json), ip_hash, created_at |
| `feature_flags` | Laravel Pennant por espacio de trabajo y plan |
| `ingest_rejections` | id, workspace_id, site_id, event_id, event, reason, sample (json truncado y sin PII), created_at. Se purga a los 7 días |
| `workspace_invitations` | id, workspace_id, email, role, token_hash, invited_by, expires_at, accepted_at? |

## 2. Contactos e identidad

| Tabla | Columnas clave |
|---|---|
| `contacts` | id, workspace_id, email (cifrado), email_bidx, phone (cifrado), phone_bidx, first_name (cifrado), last_name (cifrado), name_prefix_bidx (json: índices ciegos de los prefijos del nombre, para la búsqueda por prefijo de M03), birthday?, city, region_code, country, locale, timezone, lifecycle_stage (`visitor`, `subscriber`, `lead`, `customer`, `repeat`, `vip`, `at_risk`, `lost`), first_seen_at, last_seen_at, first_source (json), first_order_source (json), hdyhau (respuesta a «¿cómo nos conociste?»), orders_count, revenue_total_ws, aov_ws, first_order_at, last_order_at, avg_days_between_orders, refunds_count, coupon_orders_ratio, rfm_r, rfm_f, rfm_m, rfm_segment, predicted_clv_12m_ws, churn_risk (0-1), churn_band, expected_next_order_at, engagement_score, lead_score, preferred_hour, preferred_channel, category_affinity (json top 5), price_band, points_balance, tier_id, wallet_balance_ws, referral_code, custom_attributes (json), tags (json), merged_into_id?, deleted_at |
| `contact_identities` | id, workspace_id, contact_id, type (`anonymous_id`, `customer_ref`, `email_hash`, `phone_hash`, `wa_id`, `link_token`, `external`), value, site_id?, first_seen_at, last_seen_at. Único (workspace_id, type, value). **`email_hash` y `phone_hash` se guardan como HMAC con la clave del espacio de trabajo** (el mismo índice ciego de `contacts`), no como SHA-256 plano: un SHA-256 plano de un correo se puede revertir con un diccionario. El SHA-256 que piden Meta y Google se calcula al enviar, desde el dato cifrado |
| `contact_merges` | id, workspace_id, winner_id, loser_id, reason, snapshot (json), reverted_at? |
| `contact_notes` | id, workspace_id, contact_id, user_id, body, created_at |
| `custom_attribute_defs` | id, workspace_id, key, label, type (`text`, `number`, `date`, `bool`, `enum`), options (json), source |

**Índices:** (workspace_id, email_bidx), (workspace_id, phone_bidx), (workspace_id, rfm_segment), (workspace_id, last_order_at), (workspace_id, expected_next_order_at), (workspace_id, lifecycle_stage).

## 3. Consentimiento y supresión

| Tabla | Columnas clave |
|---|---|
| `contact_consents` | id, workspace_id, contact_id, channel (`email`, `whatsapp`, `sms`, `push`, `ads`), purpose (`marketing`, `transactional`), status (`granted`, `revoked`, `pending_double_optin`), source (`checkout`, `form:<id>`, `widget:<id>`, `import`, `whatsapp_reply`, `admin`, `api`), legal_text_version, granted_at, revoked_at. Único (workspace_id, contact_id, channel, purpose) |
| `consent_events` | historial inmutable (solo inserción): id, workspace_id, contact_id, channel, purpose, action, source, legal_text_version, evidence (json: page_url, ip_hash, ua_hash, form_snapshot), occurred_at |
| `legal_texts` | id, workspace_id, kind (`marketing_consent`, `privacy_notice`), version, body, published_at |
| `suppressions` | id, workspace_id, channel, value_bidx, reason (`bounce`, `complaint`, `unsubscribe`, `rne`, `repep`, `manual`, `wa_block`), created_at |
| `excluded_registry_imports` | id, country (`CO`: RNE de la CRC; `MX`: REPEP), file_hash, rows, imported_at |

## 4. Comercio

| Tabla | Columnas clave |
|---|---|
| `orders` | id, workspace_id, site_id, external_id, number, contact_id?, status, is_paid, is_cod (contraentrega: «venta por confirmar» hasta cobrarse), paid_at, currency, subtotal, discount, shipping, tax, total, refunded, total_ws, net_ws, cost_ws?, payment_method_id, payment_method_title, is_offline_payment, shipping_city, shipping_region_code, shipping_country, coupon_codes (json), items_count, is_first_order, order_index (n.º de compra del contacto), created_via, channel_first, channel_last, utm_last (json), wc_attribution (json), attributed_message_id?, attributed_flow_id?, attributed_campaign_id?, attributed_referral_id?, attributed_affiliate_id?, customer_ref?, created_at y updated_at (**fechas de la tienda**: creación y última modificación del pedido), projected_at (cuándo lo escribió el hub), snapshot_hash (hash de la última foto aplicada: la misma foto no se reescribe) |
| `order_items` | id, workspace_id, order_id, product_id (interno), external_product_id, external_variation_id?, sku, name, category_ids (json), qty, unit_price, line_total, cost? |
| `products` | id, workspace_id, site_id, external_id, parent_external_id?, type, sku, name, slug, permalink, image_url, brand?, price, regular_price, sale_price?, stock_status, stock_qty?, rating_avg, rating_count, cost?, status, content_hash, views_30d, purchases_30d, view_to_purchase_30d, updated_at |
| `product_categories` | id, workspace_id, site_id, external_id, parent_external_id?, name, slug |
| `carts` | id, workspace_id, site_id, token, contact_id?, anonymous_id, items (json), subtotal, currency, coupons (json), status (`active`, `abandoned`, `recovered`, `converted`, `expired`), checkout_started_at?, abandoned_at?, last_activity_at, restore_url_token, recovered_order_id? |
| `coupons_issued` | id, workspace_id, site_id, code, contact_id?, source_type (`flow`, `campaign`, `loyalty`, `referral`, `widget`, `wallet`), source_id, kind (`percent`, `fixed_cart`, `fixed_product`, `free_shipping`), amount, expires_at, external_coupon_id, redeemed_order_id?, status |
| `subscriptions_mirror` | id, workspace_id, external_id, contact_id, status, next_payment_at, total, billing_period |
| `fx_rates` | date, base, quote, rate |

## 5. Segmentos, flujos, campañas y mensajes

| Tabla | Columnas clave |
|---|---|
| `segments` | id, workspace_id, name, description, definition (json AST, ver `hub/CLAUDE.md` §6), kind (`dynamic`, `static`), is_system, member_count, revenue_ws, last_computed_at, folder |
| `segment_memberships` | segment_id, contact_id, workspace_id, entered_at. PK (segment_id, contact_id) |
| `flows` | id, workspace_id, name, status (`draft`, `active`, `paused`, `archived`), trigger (json), current_version_id, template_key?, goal (json), reentry_policy (json), stats (json) |
| `flow_versions` | id, flow_id, version, graph (json: nodos y aristas), published_at, published_by |
| `flow_runs` | id, workspace_id, flow_id, flow_version_id, contact_id, status (`active`, `waiting`, `completed`, `exited`, `failed`), current_node_id, next_run_at, context (json: datos del disparador), is_holdout, started_at, ended_at, exit_reason. Índice (status, next_run_at) |
| `flow_run_steps` | id, run_id, node_id, outcome, details (json), executed_at. Particionada por mes; se purga a los 6 meses |
| `campaigns` | id, workspace_id, name, channel, status (`draft`, `scheduled`, `sending`, `sent`, `cancelled`), audience (json: segmentos incluidos, excluidos y reglas), template_id, variants (json A/B), holdout_pct, send_at, send_mode (`now`, `scheduled`, `contact_timezone`, `best_time`), cost_estimate (json), compliance_check (json), stats (json) |
| `templates` | id, workspace_id, channel, name, subject?, preheader?, body_mjml?, body_html?, body_text?, wa_template_id?, variables (json), category (`marketing`, `utility`, `transactional`) |
| `wa_rates` | country, category (`marketing`, `utility`, `authentication`, `service`), rate_usd, effective_from, source_url. Se actualiza cada trimestre según la tabla de Meta |
| `wa_templates` | id, workspace_id, channel_account_id, name, language, category, status, quality, components (json), meta_template_id, synced_at |
| `channel_accounts` | id, workspace_id, channel (`email`, `whatsapp`, `sms`), provider (`ses`, `smtp`, `meta_cloud`, `zender`, `infobip`, `twilio`), display_name, config (cifrado: tokens, waba_id, phone_number_id, dominio y estado de DKIM), quality_rating?, messaging_limit_tier?, is_default, status |
| `messages` | id, workspace_id, contact_id, channel_account_id, channel, provider, provider_message_id, direction (`out`, `in`), kind (`marketing`, `utility`, `service`, `authentication`, `transactional`), source_type (`flow`, `campaign`, `inbox`, `api`, `system`), source_id, template_id?, status, cost_estimated, cost_currency, queued_at, sent_at, delivered_at, read_at, opened_at, clicked_at, replied_at, failed_reason?, conversation_id?. Particionada por mes; después de 13 meses se archiva en ClickHouse |
| `tracked_links` | code, workspace_id, message_id, contact_id, url, created_at |
| `conversations` | id, workspace_id, contact_id, channel_account_id, status (`open`, `pending`, `closed`), assignee_id?, labels (json), ai_mode (`off`, `assist`, `auto`), window_expires_at, last_inbound_at, last_outbound_at, first_response_seconds? |
| `conversation_messages` | se reutiliza `messages` con `conversation_id`; los adjuntos van en `message_attachments` |
| `quick_replies` | id, workspace_id, shortcut, body |

## 6. Captación, fidelización y reseñas

| Tabla | Columnas clave |
|---|---|
| `onsite_widgets` | id, workspace_id, site_id?, kind (`popup`, `embedded_form`, `bar`, `spin_wheel`, `quiz`, `wa_widget`, `banner`, `recommendations`, `reviews`, `loyalty_launcher`), status, targeting (json), design (json), content (json), variants (json), consent_config (json), stats (json) |
| `quizzes` | id, workspace_id, widget_id, questions (json con lógica), outcomes (json → reglas de productos y atributos que se guardan) |
| `survey_definitions` | id, workspace_id, kind (`hdyhau`, `nps`, `csat`, `custom`), questions (json), trigger (json) |
| `survey_responses` | id, workspace_id, survey_id, contact_id, order_id?, answers (json), score?, created_at |
| `loyalty_programs` | id, workspace_id, status, currency_name («Puntos Pintor»), earn_rules (json), redeem_rules (json), expiry_policy (json), pending_days (días antes de confirmar los puntos) |
| `loyalty_tiers` | id, program_id, name, rank, threshold_type (`spend_12m`, `points_12m`, `orders_12m`), threshold, benefits (json) |
| `loyalty_ledger` | id, workspace_id, contact_id, kind (`points`, `wallet`), delta, balance_after, status (`pending`, `confirmed`, `reversed`, `expired`), reason, ref_type, ref_id, expires_at?, created_at. **Solo inserción** |
| `referral_programs` | id, workspace_id, referrer_reward (json), friend_reward (json), rules (json: compra mínima, límites, antifraude) |
| `referrals` | id, workspace_id, referrer_contact_id, code, friend_contact_id?, status (`clicked`, `signed_up`, `ordered`, `qualified`, `rewarded`, `rejected`), order_id?, fraud_signals (json) |
| `affiliates` | id, workspace_id, contact_id?, name, kind (`influencer`, `affiliate`, `partner`), coupon_codes (json), link_code, commission (json), payout_info (cifrado), status |
| `affiliate_conversions` | id, affiliate_id, order_id, amount_ws, commission_ws, status (`pending`, `approved`, `paid`, `void`) |
| `reviews` | id, workspace_id, site_id, product_id?, contact_id?, order_id?, rating, title?, body, media (json), status (`pending`, `published`, `rejected`), verified_buyer, source (`request`, `widget`, `import`, `woocommerce`), external_review_id?, owner_reply?, ai_flags (json), created_at |
| `review_requests` | id, workspace_id, order_id, contact_id, channel, status, sent_at, completed_at? |
| `product_watches` | id, workspace_id, contact_id, product_id, kind (`back_in_stock`, `price_drop`), threshold?, status (`active`, `notified`, `cancelled`), notified_at? |

## 7. Medición, anuncios e integraciones

| Tabla | Columnas clave |
|---|---|
| `ad_destinations` | id, workspace_id, platform (`meta`, `google`, `tiktok`, `pinterest`), config (cifrado: pixel o dataset id, tokens, cuenta de Data Manager, ids de conversión), events_enabled (json), status, last_error? |
| `ad_spend_daily` | workspace_id, platform, date, campaign_id, campaign_name, adset_id?, ad_id?, spend, currency, impressions, clicks |
| `audience_syncs` | id, workspace_id, segment_id, platform, external_audience_id, status, last_synced_at, uploaded, matched? |
| `integrations` | id, workspace_id, kind (`imagina_base`, `imagina_crm`, `imagina_search`, `imagina_reports`, `google_calendar`, `clarity`, `webhook`), config (cifrado), status |
| `webhook_subscriptions` | id, workspace_id, url, events (json), secret (cifrado), status |

## 8. Servicios

| Tabla | Columnas clave |
|---|---|
| `lead_pipelines` | id, workspace_id, stages (json) |
| `leads` | id, workspace_id, contact_id, stage, value_ws?, owner_id?, source (json), score, last_activity_at, won_at?, lost_at?, lost_reason? |
| `booking_services` | id, workspace_id, name, duration_min, buffer_min, price?, location (`onsite`, `online`), staff_ids (json) |
| `booking_availability` | id, workspace_id, staff_id?, weekday, start_time, end_time, exceptions (json) |
| `appointments` | id, workspace_id, contact_id, service_id, staff_id?, starts_at, ends_at, status, external_calendar_event_id?, reminders (json) |

## 9. Inteligencia, uso y privacidad

| Tabla | Columnas clave |
|---|---|
| `insights` | id, workspace_id, detector_key, severity (`info`, `opportunity`, `warning`, `critical`), title, facts (json), estimated_impact_ws?, impact_assumptions (json), recommended_action (json), status (`new`, `seen`, `actioned`, `dismissed`, `expired`), period_start, period_end, created_at |
| `ai_reports` | id, workspace_id, kind (`weekly`, `monthly`), facts (json), body_markdown, model, tokens_in, tokens_out, validation (json), delivered_at |
| `ai_usage` | workspace_id, date, feature, model, tokens_in, tokens_out, cost_usd |
| `prediction_runs` | id, workspace_id, model (`edno`, `churn`, `clv`, `affinity`), trained_at, metrics (json: precisión contra el periodo de reserva), params (json), status |
| `usage_counters` | workspace_id, period (YYYY-MM), contacts, orders, events, emails, wa_marketing, wa_utility, wa_service, sms, ai_tokens |
| `data_requests` | id, workspace_id, contact_id?, kind (`access`, `rectify`, `delete`, `revoke`), requester (cifrado), status, due_at, completed_at, evidence (json) |

---

## 10. ClickHouse

```sql
-- Eventos crudos. Una fila por evento.
CREATE TABLE events (
  workspace_id   UInt32,                 -- id numérico interno del workspace (mapa en MariaDB)
  site_id        UInt32,
  event_id       UUID,
  event          LowCardinality(String),
  occurred_at    DateTime64(3, 'UTC'),
  received_at    DateTime64(3, 'UTC'),
  source_type    LowCardinality(String),
  anonymous_id   String,
  session_id     String,
  contact_id     String,                 -- '' si no se conocía al recibirlo
  page_type      LowCardinality(String),
  path           String,
  referrer_host  LowCardinality(String),
  channel        LowCardinality(String), -- agrupación de canal (inteligencia.md §2)
  utm_source     LowCardinality(String),
  utm_medium     LowCardinality(String),
  utm_campaign   String,
  click_id_kind  LowCardinality(String),
  device_type    LowCardinality(String),
  os             LowCardinality(String),
  browser        LowCardinality(String),
  country        LowCardinality(String),
  region         LowCardinality(String),
  city           LowCardinality(String),
  product_id     String,
  order_id       String,
  revenue        Decimal(18,2),
  currency       LowCardinality(String),
  properties     JSON                    -- tipo JSON nativo (ClickHouse ≥ 25.x)
)
ENGINE = ReplacingMergeTree(received_at)
PARTITION BY toYYYYMM(occurred_at)
ORDER BY (workspace_id, event, occurred_at, event_id)
TTL toDateTime(occurred_at) + INTERVAL 25 MONTH DELETE WHERE event IN ('page.viewed','page.engaged');

-- Mapa de identidad (anónimo → contacto), para atribuir eventos pasados sin reescribirlos.
CREATE TABLE identity_map (
  workspace_id UInt32, anonymous_id String, contact_id String, linked_at DateTime64(3,'UTC')
) ENGINE = ReplacingMergeTree(linked_at) ORDER BY (workspace_id, anonymous_id);
-- Se expone como DICTIONARY para resolver contact_id en las consultas: dictGet(...)

-- Sesiones (job cada 10 min sobre las sesiones cerradas, más de 30 min sin actividad).
CREATE TABLE sessions (
  workspace_id UInt32, site_id UInt32, session_id String, anonymous_id String, contact_id String,
  started_at DateTime64(3,'UTC'), ended_at DateTime64(3,'UTC'), pageviews UInt16,
  landing_path String, exit_path String, channel LowCardinality(String),
  utm_source LowCardinality(String), utm_medium LowCardinality(String), utm_campaign String,
  device_type LowCardinality(String), city LowCardinality(String),
  viewed_product UInt8, added_to_cart UInt8, started_checkout UInt8, purchased UInt8,
  revenue Decimal(18,2)
) ENGINE = ReplacingMergeTree(ended_at) PARTITION BY toYYYYMM(started_at)
  ORDER BY (workspace_id, started_at, session_id);

-- KPIs diarios precalculados (vista materializada + recálculo nocturno de los últimos 3 días).
CREATE TABLE kpi_daily (
  workspace_id UInt32, date Date, channel LowCardinality(String), device_type LowCardinality(String),
  sessions UInt32, users UInt32, product_views UInt32, add_to_carts UInt32, checkouts UInt32,
  orders UInt32, revenue Decimal(18,2), new_customers UInt32, returning_customers UInt32
) ENGINE = SummingMergeTree() ORDER BY (workspace_id, date, channel, device_type);

-- Archivo de mensajes de más de 13 meses e historial de entregas a plataformas de anuncios.
CREATE TABLE message_archive (...) ENGINE = MergeTree ORDER BY (workspace_id, sent_at);
CREATE TABLE conversion_deliveries (
  workspace_id UInt32, event_id UUID, platform LowCardinality(String), status LowCardinality(String),
  http_status UInt16, error String, match_keys Array(LowCardinality(String)), attempts UInt8,
  delivered_at DateTime64(3,'UTC')
) ENGINE = MergeTree PARTITION BY toYYYYMM(delivered_at) ORDER BY (workspace_id, delivered_at);
```

`conversion_deliveries` suma (migración `0201`): `destination_id`, `target` (endpoint), `conversion` (evento estándar de la plataforma), `source_event`, `error_code`, `final` (1 = resultado definitivo) y `test` (1 = modo de prueba). `events` tiene índices bloom en `contact_id` y `anonymous_id` (migración `0006`) para el perfil 360.

**Reglas para ClickHouse**
- Toda consulta pasa por `App\Analytics\Ch::forWorkspace($ws)`, que inyecta `workspace_id` y no permite consultas sin él.
- Las inserciones usan `async_insert=1, wait_for_async_insert=1` o lotes desde el worker (cada 1 s o cada 5.000 filas).
- Para borrar a un contacto (habeas data) se usa `DELETE FROM events WHERE workspace_id = ? AND (contact_id = ? OR anonymous_id IN (...))` (lightweight delete) y se registra en `data_requests`.

---

## 11. Columnas agregadas al implementar la Fase 1

Se agregaron en `2026_11_19_000100_create_phase1_tables` junto con las tablas de §2, §3, §5, §6, §7 y §9:

| Tabla | Columna | Para qué |
|---|---|---|
| `contacts` | `rfm_computed_at` | cuándo se calculó el RFM (el job nocturno lo actualiza) |
| `custom_attribute_defs` | `is_sensitive` | un atributo sensible no se usa en segmentos de marketing (`cumplimiento.md` §2) |
| `legal_texts` | `channel` | el texto de consentimiento es por canal (WhatsApp, correo) |
| `segments` | `system_key`, `channel_counts`, `created_by`, `deleted_at` | segmentos de la biblioteca, conteo por canal disponible y borrado lógico |
| `ad_destinations` | `name`, `mode` (`server_only`, `with_pixel`, `full`), `test_event_code`, `last_delivered_at` | modos de M06 y modo de prueba de Meta |
| `insights` | `fingerprint`, `dismissed_at` | no repetir un hallazgo descartado durante 30 días salvo que empeore (`inteligencia.md` §5) |
| `report_deliveries` (nueva) | id, workspace_id, kind (`weekly`, `monthly`), period_start, period_end, facts, recipients, delivered_at | registro de los informes enviados al dueño (M05) |

## 12. Tablas y columnas de la Fase 2

Migración `2026_11_20_000100_create_phase2_tables`. Diferencias con §3 a §5, con su motivo:
- `carts`: `order_id` (el pedido que lo cerró, convertido o recuperado) además de `recovered_order_id` (solo si se recuperó tras abandonarse), `items_count` y `subtotal_ws` (moneda del espacio de trabajo, para sumar entre sitios), y `restored_from_id`: el enlace de restauración arma un carrito nuevo en el sitio (otro `cart_token`) y esta columna lo une al abandonado, que queda recuperado cuando llega el pedido.
- `coupons_issued`: `rules` (mínimo, productos, categorías, uso individual), `redeemed_at` y `last_error`; estado `pending` mientras el sitio lo crea y `failed` si no pudo.
- `channel_accounts`: `identity` (dominio o número visible, no secreto), `last_error` y `risk_accepted_at`/`risk_accepted_by` (Zender: el `owner` aceptó el aviso de riesgo, cumplimiento.md §5.2).
- `templates`: `editor_state` (el proyecto de GrapesJS para volver a editar), `library_key`, `status` y `created_by`.
- `wa_templates`: `rejected_reason`.
- `messages`: `flow_id` y `campaign_id` (para la atribución sin unir con `source_id`), `idempotency_key` única por espacio de trabajo (un reintento nunca reenvía), `policy_reason` y `deferred_until` (lo que dijo SendPolicy), `error_code` y `payload` cifrado (asunto y vista previa para auditoría). **Sin partición mensual por ahora**: MariaDB exige la clave de partición en cada clave única y aquí son ULID; la purga a los 13 meses la hace un job.
- `flow_runs`: `trigger_key` única por flujo (el mismo evento nunca crea dos runs) y `waiting_for` (el evento que espera un nodo «esperar hasta»).
- `flow_run_steps`: `attempt` (intento lógico) en la clave única (run_id, node_id, attempt). Sin partición mensual, por el mismo motivo que `messages`.
- `flows`: `exclusions`, `holdout_pct`, `test_recipients` y `activated_at` como columnas propias en lugar de dentro de `trigger`.
- `campaigns`: `ab_config`, `winner_variant`, `started_at` y `finished_at`.
- Nuevas:
  - `campaign_recipients`: un destinatario por campaña, con variante, grupo de control, estado y mensaje. Sirve para el envío por lotes, la idempotencia y el incremento.
  - `contact_preferences`: temas y frecuencia del centro de preferencias.
  - `holiday_overrides`: excepciones de festivos que edita el admin de la plataforma (cumplimiento.md §4.2).
  - `conversations`: se adelanta de la Fase 4 porque la ventana de 24 h de WhatsApp la necesita SendPolicy.

## 13. Tablas y columnas de la Fase 3

La base es la migración `2026_11_21_000100_create_phase3_tables`; cada módulo agrega lo suyo en su rango (`1xxxxx` captación, `2xxxxx` fidelización, `3xxxxx` referidos, `4xxxxx` reseñas, `5xxxxx` comercio en el sitio y audiencias). Diferencias con §6 y §7, con su motivo:

**Captación (M12)**
- `onsite_widgets`: `name`, `priority`, `coupon_config` (el regalo del widget, o los premios de la ruleta con sus probabilidades, que nunca llegan al navegador), `starts_at`/`ends_at` y `created_by`. `site_id` es opcional: sin sitio, el widget aplica a todos los del espacio de trabajo.
- `widget_captures` (nueva): quién dejó sus datos en qué widget y variante, con el cupón y las respuestas del quiz. Sirve para las estadísticas, el «un cupón por persona» y la atribución de captación (hub/CLAUDE.md §9, paso 4). `was_known` separa a quien ya era contacto. `updated_at` (`2026_11_21_100100`) es el último paso o reenvío; `captured_at` queda como la primera captura.
- `orders.attributed_widget_id` y `attributed_widget_variant` (`2026_11_21_100100`): el widget y la variante del paso 4 de la atribución; los leen las estadísticas del widget.

**Fidelización (M13)**
- `loyalty_programs`: uno por espacio de trabajo, con `wallet_rules` (cashback y vencimiento del saldo).
- `loyalty_tiers`: `wc_role`, el rol de WooCommerce del nivel para los precios VIP con el comando `customer.set_tier`.
- `loyalty_rewards`: el catálogo de canje (cupón fijo o porcentual, envío gratis, producto gratis o saldo) con su costo en puntos.
- `loyalty_ledger`:
  - Solo inserción: confirmar, revertir o vencer agrega una entrada nueva enlazada con `parent_id`.
  - `delta` y `balance_after` son decimales para servir a puntos (enteros) y a billetera (dinero, con `currency`).
  - `idempotency_key` es única: nunca se acredita dos veces lo mismo.
- `loyalty_settlements` (nueva, `2026_11_21_200100`): cuándo se confirman los puntos pendientes de una referencia (pedido completado + días de devolución). Como el libro es solo de inserción, la fecha prevista vive aquí y un job la revisa cada 15 minutos.
- `contacts.points_expiring` y `points_expiring_at` (`2026_11_21_200100`): cuántos puntos vencen y cuándo es el próximo vencimiento. Los usa el disparador por fecha del flujo 15 «Puntos por vencer».

**Referidos y afiliados (M14)**
- `referral_programs`: uno por espacio de trabajo, con `share_text`.
- `referrals`:
  - `friend_coupon_id`, `reward_ledger_id` y `review_status` (revisión manual de los sospechosos).
  - Único (workspace_id, friend_contact_id): una persona solo puede ser referida una vez.
  - `2026_11_21_300100`: `site_id`, `ordered_at`, `order_amount_ws`, `qualifies_at` (pedido completado + días de devolución), `rewarded_at`, `reward` y `reward_cost_ws` (la recompensa entregada y su costo) y `rejected_reason`.
- `affiliates`: `email` cifrado con índice ciego, para el enlace mágico del panel del creador; `material`, y `portal_nonce`/`portal_sent_at` (`2026_11_21_300100`: cambiar el nonce invalida los enlaces anteriores).
- `affiliate_conversions`: `via` (cupón o enlace), `approved_at` y `paid_at`; `qualifies_at` y `void_reason` (`2026_11_21_300100`).
- `contacts.referral_code`: único por espacio de trabajo (`2026_11_21_300100`). Se crea la primera vez que se pide.
- `referral_fingerprints` (nueva, `2026_11_21_300100`): HMAC (con la clave del índice ciego) de la IP y del destinatario de envío de quienes refieren, para las señales de fraude. Nunca guarda el dato en claro.

**Reseñas y NPS (M15)**
- `reviews`:
  - `product_id` es el interno y `external_product_id` el de la tienda (null = reseña de la tienda).
  - `author_name` es lo que se publica (nombre e inicial).
  - Columnas de moderación y respuesta; `media` con la ruta, la URL, el tipo y la miniatura.
  - `2026_11_21_400100`: `request_id`, `published_at`, `reject_reason`, `external_reply_id`, `sync_status`/`sync_error` (la publicación en WooCommerce) e `import_key` (CSV y Judge.me no se duplican).
- `review_requests`: `scheduled_for` y `message_id`, único por pedido; `site_id`, `kind` (clase legal del mensaje: marketing por defecto, cumplimiento.md §4.1), `skip_reason`, `attempts` y `expires_at` (`2026_11_21_400100`).
- `survey_requests` (nueva, `2026_11_21_400100`): los envíos de NPS y CSAT por pedido.
- `survey_responses.comment` (`2026_11_21_400100`): el comentario libre del NPS, cifrado porque puede tener datos personales.

**Comercio en el sitio y audiencias (M16 y M06)**
- `product_watches`: `site_id`, `external_product_id`, `channel` y `message_id`; `price_at_subscribe`, `notified_price`, `next_attempt_at`, `attempts` y `last_reason` (`2026_11_21_500100`: un aviso aplazado por la política de envío se reintenta a la hora que esta indica).
- `product_associations` (nueva): las reglas de «comprados juntos» (soporte, confianza y *lift*), que calcula un job nocturno.
- `products.purchases_7d`, `purchases_90d`, `trend_score` y `stats_computed_at` (`2026_11_21_500100`): ventas por ventana y tendencia para las recomendaciones (`views_30d` y `purchases_30d` ya existían y ahora se llenan).
- `commerce_settings` (nueva, `2026_11_21_500100`): ajustes de recomendaciones y avisos por espacio de trabajo.
- `onsite_banners` (nueva, `2026_11_21_500100`): banners por audiencia (segmento, puntos, carrito pendiente, en riesgo). No van en `onsite_widgets` porque se resuelven por visitante en `/banner`, sin caché; `text` puede usar nombre y puntos solo con token, `text_public` nunca.
- `audience_syncs` y `audience_members` (nuevas): la sincronización de un segmento con un destino de anuncios y el último conjunto enviado, para mandar solo las altas y bajas. `2026_11_21_500100`: `purpose` (público, exclusión o semilla de similares), `last_added`, `last_removed`, `skipped_no_consent`, `last_attempt_at` y `created_by` en las sincronizaciones; `identifiers` (los hashes enviados, cifrados) en los miembros, para sacar a alguien aunque cambie o se borre su perfil.

**Supresión (cumplimiento.md §9):** al borrar un contacto se borran sus mensajes, conversaciones, recorridos de flujos, destinatarios de campañas, preferencias, libro de puntos, referidos, huellas, solicitudes de reseña y NPS, avisos y capturas; se desvinculan los carritos, cupones, afiliados y reseñas publicadas (sin nombre del autor), y sale de las audiencias con los hashes guardados.

## 14. Tablas y columnas de la Fase 4

La base es la migración `2026_11_22_000100_create_phase4_tables`; cada módulo agrega lo suyo en su rango (`1xxxxx` predicciones, `2xxxxx` hallazgos, `3xxxxx` analista y «Pregúntale», `4xxxxx` copiloto y contenido, `5xxxxx` bandeja, `6xxxxx` agente de ventas). Diferencias con §1 y §5, con su motivo:

**IA**
- `ai_usage`: además de tokens y costo, `requests`, `cache_read` y `cache_write` (la caché de prompts cambia el costo). Clave (workspace_id, date, feature, model).
- `ai_reports`: `period_start`/`period_end` (único por espacio de trabajo, tipo e inicio del periodo), `actions` (las 3 acciones con su botón), `source` (`ai` o `fallback`: el texto sin IA cuando la validación numérica falla dos veces).
- `ai_drafts` (nueva): las propuestas del copiloto (campaña o flujo) y del contenido, que siempre esperan aprobación humana; `applied_type`/`applied_id` enlazan con lo que se creó.
- `ask_data_queries` (nueva): cada pregunta de «Pregúntale a tus datos» con la consulta estructurada que armó el modelo, la respuesta y si se guardó como segmento o en el tablero.

**Predicciones**
- `contacts`: `p_alive`, `expected_orders_90d`, `expected_orders_365d` (BG/NBD), `propensity_30d`, `brand_affinity` (top 5, como `category_affinity`) y `predictions_at`.
- `product_transitions` (nueva): «siguiente producto», la probabilidad de pasar de un producto o categoría del pedido N a otro del pedido N+1.

**Bandeja**
- `conversations`: `unread_count`, `last_message_at`, `resolved_at`, `intent` (la del último entrante) y `handoff_reason` (por qué la IA pasó a un humano).
- El texto de los mensajes entrantes va cifrado en `messages.payload.text`.
- Nuevas: `conversation_notes` (notas internas, cifradas), `conversation_events` (asignaciones, estados, etiquetas, modo de IA, traspasos y ventas, para las métricas por agente), `quick_replies`, `message_attachments` y `push_subscriptions` (notificaciones de la PWA, cifradas).

**Agente de ventas**
- `ai_agent_settings` (una fila por espacio de trabajo): alcance del negocio, tono, políticas (envíos, cambios, medios de pago, horarios), reglas de traspaso y de descuentos (el agente nunca promete otros) y el límite de pasos.
- `ai_agent_turns`: cada turno del agente con las herramientas que usó, la validación de sus cifras contra lo que devolvieron, el modelo, los tokens y la revisión humana.

**Migraciones de los módulos**
- `2026_11_22_100100` (predicciones): `prediction_runs.status` de 24 caracteres (`insufficient_data` no cabía) e índice por espacio de trabajo, modelo y fecha.
- `2026_11_22_200100` (hallazgos): `insight_assumptions` (nueva), los supuestos editables del impacto por espacio de trabajo (los valores por defecto viven en `config/igr-insights.php`); `insights.valid_until` (vigencia de 7 días los nocturnos y 14 los semanales) e índice por huella.
- `2026_11_22_500100` (bandeja): `conversations.opened_at` (inicio del ciclo actual, para la primera respuesta y la resolución) y `awaiting_since` (el primer entrante sin responder), e índice por persona asignada y estado.
- `2026_11_22_600100` (agente): `ai_agent_turns.conversation_id` opcional (los turnos de «Probar el agente»), `is_test`, `handoff_reason` y `cart_id` (el carrito que armó el agente, para medir sus ventas).

## 15. Tablas y columnas de la Fase 5

La base es la migración `2026_11_23_000100_create_phase5_tables` (más `2026_11_23_000050_create_features_table`, la tabla de Laravel Pennant); cada módulo agrega lo suyo en su rango (`1xxxxx` leads, `2xxxxx` agenda y cotizador, `3xxxxx` agencia y marca blanca, `4xxxxx` planes y facturación, `5xxxxx` API, MCP y webhooks, `6xxxxx` Instagram y Messenger). Diferencias con §8, con su motivo:

**Leads (M18)**
- `lead_pipelines`: las etapas son JSON con su tipo (`open`, `won`, `lost`), para pipelines propios además del simple.
- `leads`: además de §8, `pipeline_id`, `site_id`, `service` (lo que pidió), `temperature` (frío, tibio o caliente), `score_reasons` (cada señal con sus puntos; la de IA con su razón visible), `origin` y `origin_ref` (formulario, WhatsApp, cotización, cita, panel o API: la entrada es idempotente por ese par), `first_response_at` y `external_crm_id` (Imagina CRM, Base o Proposals).
- `lead_activities` (nueva): la historia del lead (creado, etapa, nota, puntaje, aviso, llamada, mensaje, vendedor).

**Agenda y cotizador (M18)**
- `booking_staff` (nueva): las personas que atienden, con o sin usuario del panel; `calendar` guarda cifrada la conexión con Google Calendar.
- `booking_services`: `slug` (la página de reservas), `min_notice_hours`, `max_days_ahead` y `active`.
- `booking_availability`: el día es ISO (1 = lunes); sin `staff_id`, es el horario del negocio.
- `appointments`: `lead_id`, `channel` (página, bandeja, agente, panel o API), `manage_token` (reprogramar o cancelar desde el enlace) y `notes` cifradas.
- `quote_calculators` y `quotes` (nuevas): el cotizador genérico y cada estimado que se pidió, con su rango de precio.

**Agencia y marca blanca (M22)**
- `account_branding` (nueva): nombre, logo, color, remitente y si oculta «Imagina Growth».
- `custom_domains` (nueva): dominios propios del panel, de los enlaces, de la página de reservas o de seguimiento, con su verificación (los atiende Caddy con TLS bajo demanda).
- `agency_templates` (nueva): las plantillas maestras de la agencia (flujos, segmentos, widgets, plantillas y campañas) en forma portable.
- `monthly_reports` (nueva): el reporte mensual con marca (hechos, resumen, PDF y envío).
- `support_sessions` (nueva): cada entrada de soporte o de la agencia a un espacio de trabajo, con su motivo; además queda en `audit_logs`.

**Planes, uso y facturación (M21)**
- `plans` (nueva): por pedidos al mes o contactos activos, con los módulos (Pennant) y los límites.
- `usage_counters`: además de §9, `api_requests`, `computed_at` y `alerts_sent` (avisos al 80 % y al 100 %).
- `billing_invoices` (nueva): la facturación manual o con enlace de pago de la v1.
- `accounts.billing_status` (`trial`, `active`, `grace`, `suspended`), `trial_ends_at` y `grace_ends_at`: sin cortes bruscos.

**API y webhooks (M19)**
- `webhook_subscriptions`: además de §7, `consecutive_failures`, `last_success_at` y `created_by`.
- `webhook_deliveries` (nueva): cada intento de entrega con su código, duración y siguiente intento (registro visible).
- `api_clients` (nueva): los clientes de la API y del servidor MCP con sus alcances; los tokens son de Sanctum.
