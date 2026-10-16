-- Multicompany: llx_peppol gets an entity column (existing rows belong to entity 1)
ALTER TABLE llx_peppol ADD COLUMN entity integer DEFAULT 1 NOT NULL AFTER rowid;
ALTER TABLE llx_peppol ADD INDEX idx_peppol_entity (entity);
