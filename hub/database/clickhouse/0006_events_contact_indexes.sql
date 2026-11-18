-- Perfil 360 (M03: < 800 ms con 2 años de historial): índices de salto para
-- encontrar rápido los eventos de un contacto o de sus anonymous_id.
ALTER TABLE events ADD INDEX IF NOT EXISTS idx_contact_id contact_id TYPE bloom_filter(0.01) GRANULARITY 4;
ALTER TABLE events ADD INDEX IF NOT EXISTS idx_anonymous_id anonymous_id TYPE bloom_filter(0.01) GRANULARITY 4;
ALTER TABLE events MATERIALIZE INDEX idx_contact_id;
ALTER TABLE events MATERIALIZE INDEX idx_anonymous_id
