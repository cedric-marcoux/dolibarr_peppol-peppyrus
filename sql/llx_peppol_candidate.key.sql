ALTER TABLE llx_peppol_candidate ADD UNIQUE INDEX uk_peppol_candidate (entity, fk_soc, participant_id);
ALTER TABLE llx_peppol_candidate ADD INDEX idx_peppol_candidate_status (status);
ALTER TABLE llx_peppol_candidate ADD INDEX idx_peppol_candidate_date_found (date_found);
