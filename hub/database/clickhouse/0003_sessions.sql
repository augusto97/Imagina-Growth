-- Sesiones (job cada 10 min sobre sesiones cerradas, Fase 1).
CREATE TABLE IF NOT EXISTS sessions (
  workspace_id UInt32, site_id UInt32, session_id String, anonymous_id String, contact_id String,
  started_at DateTime64(3, 'UTC'), ended_at DateTime64(3, 'UTC'), pageviews UInt16,
  landing_path String, exit_path String, channel LowCardinality(String),
  utm_source LowCardinality(String), utm_medium LowCardinality(String), utm_campaign String,
  device_type LowCardinality(String), city LowCardinality(String),
  viewed_product UInt8, added_to_cart UInt8, started_checkout UInt8, purchased UInt8,
  revenue Decimal(18, 2)
)
ENGINE = ReplacingMergeTree(ended_at)
PARTITION BY toYYYYMM(started_at)
ORDER BY (workspace_id, started_at, session_id);
