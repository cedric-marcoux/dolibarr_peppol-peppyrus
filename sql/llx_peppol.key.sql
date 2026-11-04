-- BEGIN MODULEBUILDER INDEXES
ALTER TABLE llx_peppol ADD INDEX idx_peppol_rowid (rowid);
ALTER TABLE llx_peppol ADD INDEX idx_peppol_fk_object (fk_object);
ALTER TABLE llx_peppol ADD INDEX idx_peppol_object_typeid (object_typeid);
-- END MODULEBUILDER INDEXES
