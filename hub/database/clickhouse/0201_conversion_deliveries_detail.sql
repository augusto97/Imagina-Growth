-- Detalle de cada intento de entrega a plataformas de anuncios (M06): destino, endpoint,
-- evento estándar, evento de origen, código de error de la plataforma y si es el resultado final.
ALTER TABLE conversion_deliveries
  ADD COLUMN IF NOT EXISTS destination_id String,
  ADD COLUMN IF NOT EXISTS target LowCardinality(String),
  ADD COLUMN IF NOT EXISTS conversion LowCardinality(String),
  ADD COLUMN IF NOT EXISTS source_event LowCardinality(String),
  ADD COLUMN IF NOT EXISTS error_code String,
  ADD COLUMN IF NOT EXISTS final UInt8,
  ADD COLUMN IF NOT EXISTS test UInt8
