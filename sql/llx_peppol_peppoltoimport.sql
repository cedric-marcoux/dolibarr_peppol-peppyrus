-- Copyright (C) ---Put here your own copyright and developer email---
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

-- DROP TABLE llx_peppol_peppolimport;

CREATE TABLE llx_peppol_peppolimport(
	-- BEGIN MODULEBUILDER FIELDS
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1,
	ref varchar(128) DEFAULT '(PROV)' NOT NULL,
	filename varchar(255),
	sha1 varchar(40),
	peppolid varchar(255) UNIQUE,
	internalNumber  integer DEFAULT 0,
	senderScheme varchar(255) DEFAULT NULL,
	senderID varchar(255) DEFAULT NULL,
	receiverScheme varchar(255) DEFAULT NULL,
	receiverID varchar(255) DEFAULT NULL,
	c1CountryCode varchar(255) DEFAULT NULL,
	c2Timestamp varchar(255) DEFAULT NULL,
	c2SeatID varchar(255) DEFAULT NULL,
	c2MessageID varchar(255) DEFAULT NULL,
	c3IncomingUniqueID varchar(255) DEFAULT NULL,
	c3MessageID varchar(255) DEFAULT NULL,
	c3Timestamp varchar(255) DEFAULT NULL,
	conversationID varchar(255) DEFAULT NULL,
	sbdhInstanceID varchar(255) DEFAULT NULL,
	processScheme varchar(255) DEFAULT NULL,
	processValue varchar(255) DEFAULT NULL,
	documentTypeScheme varchar(255) DEFAULT NULL,
	documentTypeValue varchar(255) DEFAULT NULL,
	tms TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_supplier integer,
	fk_invoice integer,
	fk_user_creat integer NOT NULL,
	fk_user_modif integer,
	import_key varchar(14),
	status smallint DEFAULT '0'
	-- END MODULEBUILDER FIELDS
) ENGINE=innodb;
