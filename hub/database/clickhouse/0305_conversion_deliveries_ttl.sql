-- Retención de las entregas de conversiones (R14): 90 días. Los intentos fallidos y su detalle sirven para depurar
-- en el panel; pasado ese plazo las plataformas ya descartaron la conversión.
ALTER TABLE conversion_deliveries MODIFY TTL toDateTime(delivered_at) + INTERVAL 90 DAY;
