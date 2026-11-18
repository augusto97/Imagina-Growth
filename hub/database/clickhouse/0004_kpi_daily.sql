-- KPIs diarios precalculados (se llenan en la Fase 1).
CREATE TABLE IF NOT EXISTS kpi_daily (
  workspace_id UInt32, date Date, channel LowCardinality(String), device_type LowCardinality(String),
  sessions UInt32, users UInt32, product_views UInt32, add_to_carts UInt32, checkouts UInt32,
  orders UInt32, revenue Decimal(18, 2), new_customers UInt32, returning_customers UInt32
)
ENGINE = SummingMergeTree()
ORDER BY (workspace_id, date, channel, device_type);
