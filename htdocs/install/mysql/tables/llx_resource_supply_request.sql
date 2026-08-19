-- Resource availability requests awaiting an owner or supplier response.

CREATE TABLE llx_resource_supply_request
(
	rowid integer AUTO_INCREMENT PRIMARY KEY,
	entity integer DEFAULT 1 NOT NULL,
	fk_element_resource integer NOT NULL,
	fk_resource integer NOT NULL,
	request_type varchar(16) NOT NULL DEFAULT 'owner',
	fk_soc_supplier integer DEFAULT NULL,
	fk_product_supplier integer DEFAULT NULL,
	fk_supplier_order integer DEFAULT NULL,
	fk_supplier_order_line integer DEFAULT NULL,
	quantity_requested real NOT NULL DEFAULT 1,
	quantity_confirmed real DEFAULT NULL,
	date_start datetime NOT NULL,
	date_end datetime NOT NULL,
	timezone varchar(64) NOT NULL DEFAULT 'UTC',
	request_status varchar(24) NOT NULL DEFAULT 'unknown',
	supplier_order_status varchar(32) DEFAULT NULL,
	external_reference varchar(128) DEFAULT NULL,
	date_creation datetime NOT NULL,
	date_request datetime DEFAULT NULL,
	date_confirmation datetime DEFAULT NULL,
	fk_user_create integer NOT NULL,
	fk_user_modif integer DEFAULT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
