CREATE TABLE llx_peppol
(
	-- BEGIN MODULEBUILDER FIELDS
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_object integer,
	object_typeid integer, -- CUSTOMER INVOICE (1) OR SUPPLIER INVOICE (2)
	status varchar(16),
	message varchar(255),
	fulldata text,
	ap_name varchar(16),
	tms timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer NOT NULL
	-- END MODULEBUILDER FIELDS
) ENGINE=innodb;
