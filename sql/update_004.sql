-- SPDX-License-Identifier: GPL-3.0-or-later
-- Sinds 2.18.0: het antwoord (Invoice Response) op een inkomende factuur: stand, datum en toelichting
ALTER TABLE llx_peppol_peppolimport ADD COLUMN response_code varchar(4) DEFAULT NULL;
ALTER TABLE llx_peppol_peppolimport ADD COLUMN response_date datetime DEFAULT NULL;
ALTER TABLE llx_peppol_peppolimport ADD COLUMN response_note varchar(255) DEFAULT NULL;
