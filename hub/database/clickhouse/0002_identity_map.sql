-- Mapa anónimo → contacto para atribuir eventos pasados sin reescribirlos.
CREATE TABLE IF NOT EXISTS identity_map (
  workspace_id UInt32,
  anonymous_id String,
  contact_id   String,
  linked_at    DateTime64(3, 'UTC')
)
ENGINE = ReplacingMergeTree(linked_at)
ORDER BY (workspace_id, anonymous_id);
