-- Columnas de sesión que usan los tableros de la Fase 1 (embudo por navegador, geografía, búsquedas, CTA).
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS browser LowCardinality(String) AFTER device_type;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS region LowCardinality(String) AFTER city;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS country LowCardinality(String) AFTER region;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS searched UInt8 AFTER started_checkout;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS cta_clicks UInt16 AFTER searched;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS orders UInt16 AFTER purchased;
ALTER TABLE sessions ADD COLUMN IF NOT EXISTS is_returning UInt8 AFTER orders;
