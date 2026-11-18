-- Historial de entregas a plataformas de anuncios (Fase 1).
CREATE TABLE IF NOT EXISTS conversion_deliveries (
  workspace_id UInt32, event_id UUID, platform LowCardinality(String), status LowCardinality(String),
  http_status UInt16, error String, match_keys Array(LowCardinality(String)), attempts UInt8,
  delivered_at DateTime64(3, 'UTC')
)
ENGINE = MergeTree
PARTITION BY toYYYYMM(delivered_at)
ORDER BY (workspace_id, delivered_at);
