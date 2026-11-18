-- Eventos crudos. Una fila por evento (docs/modelo-de-datos.md §10).
-- No guarda datos personales directos: el worker los quita de properties antes de escribir.
CREATE TABLE IF NOT EXISTS events (
  workspace_id   UInt32,
  site_id        UInt32,
  event_id       UUID,
  event          LowCardinality(String),
  occurred_at    DateTime64(3, 'UTC'),
  received_at    DateTime64(3, 'UTC'),
  source_type    LowCardinality(String),
  anonymous_id   String,
  session_id     String,
  contact_id     String,
  page_type      LowCardinality(String),
  path           String,
  referrer_host  LowCardinality(String),
  channel        LowCardinality(String),
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
  revenue        Decimal(18, 2),
  currency       LowCardinality(String),
  properties     JSON
)
ENGINE = ReplacingMergeTree(received_at)
PARTITION BY toYYYYMM(occurred_at)
ORDER BY (workspace_id, event, occurred_at, event_id)
TTL toDateTime(occurred_at) + INTERVAL 25 MONTH DELETE WHERE event IN ('page.viewed', 'page.engaged');
