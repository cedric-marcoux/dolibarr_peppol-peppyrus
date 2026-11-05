-- SPDX-License-Identifier: GPL-3.0-or-later
-- Last check of a third party in the Peppol directory, so the weekly check does not repeat itself
CREATE TABLE llx_peppol_lookup
(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_soc integer NOT NULL,
	date_checked datetime NOT NULL,
	nb_found integer DEFAULT 0 NOT NULL
) ENGINE=innodb;
