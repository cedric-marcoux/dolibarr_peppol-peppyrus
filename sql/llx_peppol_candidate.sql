-- Peppol IDs found in the Peppol directory for third parties without a Peppol ID (weekly check)
CREATE TABLE llx_peppol_candidate
(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_soc integer NOT NULL,
	participant_id varchar(255) NOT NULL,	-- e.g. 0106:12345678
	participant_scheme varchar(64),			-- e.g. iso6523-actorid-upis
	dir_name varchar(255),					-- name of the participant in the directory
	matched_field varchar(16),				-- tva_intra, idprof1, ...: field of the third party with the same number
	accepts_invoice smallint,				-- 1 yes, 0 no, NULL unknown
	reg_date varchar(32),					-- registration date in the directory
	status smallint DEFAULT 0 NOT NULL,		-- 0 new, 1 linked, 2 ignored
	date_found datetime NOT NULL,
	date_checked datetime,
	fk_user_modif integer,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
