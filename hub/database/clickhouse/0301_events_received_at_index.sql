-- R12 de la auditoría: «eventos de las últimas 24 horas» (inicio y ficha del sitio) filtra por received_at, que no
-- está en la clave de orden: sin índice leía todas las particiones del espacio de trabajo. Un minmax por gránulo
-- descarta lo que llegó antes (la importación histórica trae occurred_at viejo pero received_at reciente: se lee).
ALTER TABLE events ADD INDEX IF NOT EXISTS idx_received_at received_at TYPE minmax GRANULARITY 1;
ALTER TABLE events MATERIALIZE INDEX idx_received_at
