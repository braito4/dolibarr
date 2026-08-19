--
-- This file is executed by calling /install/index.php page
-- when current version is higher than the name of this file.
-- Be carefull in the position of each SQL request.
--
-- To restrict request to Mysql version x.y minimum use -- VMYSQLx.y
-- To restrict request to Pgsql version x.y minimum use -- VPGSQLx.y
-- To rename a table:       ALTER TABLE llx_table RENAME TO llx_table_new;
--                          Note that "RENAME TO" is both compatible with mysql/postgesql, not the "RENAME" alone.
--                          Also you must complete with renaming the sequence for PGSQL with -- VPGSQL8.2 ALTER SEQUENCE llx_table_rowid_seq RENAME TO llx_table_new_rowid_seq;
-- To add a column:         ALTER TABLE llx_table ADD COLUMN newcol varchar(60) NOT NULL DEFAULT '0' AFTER existingcol;
-- To rename a column:      ALTER TABLE llx_table CHANGE COLUMN oldname newname varchar(60);
-- To drop a column:        ALTER TABLE llx_table DROP COLUMN oldname;
-- To change type of field: ALTER TABLE llx_table MODIFY COLUMN name varchar(60);
-- To drop a foreign key or constraint:   ALTER TABLE llx_table DROP FOREIGN KEY fk_name;
-- To create a unique index:              ALTER TABLE llx_table ADD UNIQUE INDEX uk_table_field (field);
-- To drop an index:        -- VMYSQL4.1 DROP INDEX nomindex ON llx_table;
-- To drop an index:        -- VPGSQL8.2 DROP INDEX nomindex;
-- To make pk to be auto increment (mysql):
-- -- VMYSQL4.3 ALTER TABLE llx_table ADD PRIMARY KEY(rowid);
-- -- VMYSQL4.3 ALTER TABLE llx_table CHANGE COLUMN rowid rowid INTEGER NOT NULL AUTO_INCREMENT;
-- To make pk to be auto increment (postgres):
-- -- VPGSQL8.2 CREATE SEQUENCE llx_table_rowid_seq OWNED BY llx_table.rowid;
-- -- VPGSQL8.2 ALTER TABLE llx_table ADD PRIMARY KEY (rowid);
-- -- VPGSQL8.2 ALTER TABLE llx_table ALTER COLUMN rowid SET DEFAULT nextval('llx_table_rowid_seq');
-- -- VPGSQL8.2 SELECT setval('llx_table_rowid_seq', MAX(rowid)) FROM llx_table;
-- To set a field as NULL:                     -- VMYSQL4.3 ALTER TABLE llx_table MODIFY COLUMN name varchar(60) NULL;
-- To set a field as NULL:                     -- VPGSQL8.2 ALTER TABLE llx_table ALTER COLUMN name DROP NOT NULL;
-- To set a field as NOT NULL:                 -- VMYSQL4.3 ALTER TABLE llx_table MODIFY COLUMN name varchar(60) NOT NULL;
-- To set a field as NOT NULL:                 -- VPGSQL8.2 ALTER TABLE llx_table ALTER COLUMN name SET NOT NULL;
-- To set a field as default NULL:             -- VPGSQL8.2 ALTER TABLE llx_table ALTER COLUMN name SET DEFAULT NULL;
-- Note: fields with type BLOB/TEXT can't have default value.
-- To rebuild sequence for postgresql after insert, by forcing id autoincrement fields:
-- -- VPGSQL8.2 SELECT dol_util_rebuild_sequences();


--noqa:disable=LT09
--noqa:disable=RF03


-- V24 forgotten


-- v25 migration

-- Add per entity payment terms/modes and bank account (issue #39146)
ALTER TABLE llx_societe_perentity ADD COLUMN fk_account integer DEFAULT NULL;
ALTER TABLE llx_societe_perentity ADD COLUMN mode_reglement integer DEFAULT NULL;
ALTER TABLE llx_societe_perentity ADD COLUMN cond_reglement tinyint DEFAULT NULL;
ALTER TABLE llx_societe_perentity ADD COLUMN mode_reglement_supplier tinyint DEFAULT NULL;
ALTER TABLE llx_societe_perentity ADD COLUMN cond_reglement_supplier tinyint DEFAULT NULL;

-- extrafields for links
CREATE TABLE llx_links_extrafields
(
  rowid                     integer AUTO_INCREMENT PRIMARY KEY,
  tms                       timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fk_object                 integer NOT NULL,
  import_key                varchar(14)                             -- import key
) ENGINE=innodb;
ALTER TABLE llx_links_extrafields ADD UNIQUE INDEX uk_links_extrafields (fk_object);

-- Add user/tms information to element_element
ALTER TABLE llx_element_element ADD COLUMN fk_user_creat integer;
ALTER TABLE llx_element_element ADD COLUMN date_creation datetime;
ALTER TABLE llx_element_element ADD COLUMN fk_user_modif integer;
ALTER TABLE llx_element_element ADD COLUMN tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

ALTER TABLE llx_c_action_trigger ADD COLUMN enabled varchar(255);

-- Fix #37658 - subprice_ttc (pu_ttc for supplier invoices) now flags a line entered including tax (0 when
-- entered excluding tax). Supplier lines used to store it unconditionally (even for lines entered excluding
-- tax), so reset it on existing supplier lines to avoid them being wrongly treated as entered including tax
-- on clone/edit/bulk actions. A line can be re-entered including tax to set the value again.
-- Guarded on the upgrade source version (MAIN_VERSION_LAST_UPGRADE is still the source version at this point,
-- updated only at the end of step5) so re-running the migration on a 25.x base does NOT wipe values set since.
UPDATE llx_commande_fournisseurdet SET subprice_ttc = 0 WHERE subprice_ttc <> 0 AND EXISTS (SELECT c.rowid FROM llx_const as c WHERE c.name = 'MAIN_VERSION_LAST_UPGRADE' AND c.value < '25.0.0');
UPDATE llx_facture_fourn_det SET pu_ttc = 0 WHERE pu_ttc <> 0 AND EXISTS (SELECT c.rowid FROM llx_const as c WHERE c.name = 'MAIN_VERSION_LAST_UPGRADE' AND c.value < '25.0.0');
UPDATE llx_supplier_proposaldet SET subprice_ttc = 0 WHERE subprice_ttc <> 0 AND EXISTS (SELECT c.rowid FROM llx_const as c WHERE c.name = 'MAIN_VERSION_LAST_UPGRADE' AND c.value < '25.0.0');

-- Explicit contact address mode flag. NULL keeps the legacy resolution for existing records,
-- so an existing alternative contact address stays independent from its thirdparty address.
-- VMYSQL4.1 ALTER TABLE llx_socpeople ADD COLUMN use_thirdparty_address smallint DEFAULT NULL AFTER fk_soc;
-- VPGSQL8.2 ALTER TABLE llx_socpeople ADD COLUMN use_thirdparty_address smallint DEFAULT NULL;

-- Resource characteristics and type capabilities
ALTER TABLE llx_resource ADD COLUMN allow_overflow smallint NOT NULL DEFAULT 0;
-- VMYSQL4.1 ALTER TABLE llx_resource MODIFY COLUMN fk_statut smallint NOT NULL DEFAULT 1;
-- VPGSQL8.2 ALTER TABLE llx_resource ALTER COLUMN fk_statut SET DEFAULT 1;
ALTER TABLE llx_c_type_resource ADD COLUMN capacity_mode varchar(16) NOT NULL DEFAULT 'none';
ALTER TABLE llx_c_type_resource ADD COLUMN metric_label varchar(128) DEFAULT NULL;
ALTER TABLE llx_c_type_resource ADD COLUMN metric_unit varchar(32) DEFAULT NULL;
ALTER TABLE llx_c_type_resource ADD COLUMN supports_cooldown smallint NOT NULL DEFAULT 0;
ALTER TABLE llx_resource ADD COLUMN metric_value real DEFAULT NULL;
ALTER TABLE llx_resource ADD COLUMN cooldown_minutes integer NOT NULL DEFAULT 0;
UPDATE llx_c_type_resource SET capacity_mode = 'users' WHERE code = 'RES_ROOMS';
UPDATE llx_c_type_resource SET capacity_mode = 'volume', metric_label = 'Load volume', metric_unit = 'm3' WHERE code = 'RES_CARS';
INSERT INTO llx_c_type_resource (code, label, capacity_mode, supports_cooldown, active) SELECT 'RES_MACHINES', 'Machinery', 'none', 1, 1 WHERE NOT EXISTS (SELECT 1 FROM llx_c_type_resource WHERE code = 'RES_MACHINES');

-- Resource requirements attached to products and services
ALTER TABLE llx_element_resources ADD COLUMN position integer DEFAULT 0;
ALTER TABLE llx_element_resources ADD COLUMN relation_kind varchar(16) NOT NULL DEFAULT 'requirement';
ALTER TABLE llx_element_resources ADD COLUMN resource_role varchar(16) NOT NULL DEFAULT 'capacity';
ALTER TABLE llx_element_resources ADD COLUMN requirement_group varchar(32) DEFAULT NULL;
ALTER TABLE llx_element_resources ADD COLUMN quantity_required real DEFAULT 1;
ALTER TABLE llx_element_resources ADD COLUMN users_per_service_unit real DEFAULT NULL;
ALTER TABLE llx_element_resources ADD COLUMN duration_base integer DEFAULT 0;
ALTER TABLE llx_element_resources ADD COLUMN duration_per_unit integer DEFAULT 0;
ALTER TABLE llx_element_resources ADD COLUMN setup_duration integer DEFAULT 0;
ALTER TABLE llx_element_resources ADD COLUMN cleanup_duration integer DEFAULT 0;
ALTER TABLE llx_element_resources ADD COLUMN scheduling_mode varchar(16) NOT NULL DEFAULT 'same_as_parent';
ALTER TABLE llx_element_resources ADD COLUMN start_input_mode varchar(16) NOT NULL DEFAULT 'none';
ALTER TABLE llx_element_resources ADD COLUMN end_input_mode varchar(16) NOT NULL DEFAULT 'none';
ALTER TABLE llx_element_resources ADD COLUMN time_precision varchar(16) NOT NULL DEFAULT 'minute';
ALTER TABLE llx_element_resources ADD COLUMN simultaneous smallint NOT NULL DEFAULT 1;
ALTER TABLE llx_element_resources ADD COLUMN allow_split smallint NOT NULL DEFAULT 0;
ALTER TABLE llx_element_resources ADD COLUMN context_scope varchar(16) NOT NULL DEFAULT 'service_line';
ALTER TABLE llx_element_resources ADD COLUMN demand_source varchar(16) NOT NULL DEFAULT 'service_quantity';
ALTER TABLE llx_element_resources ADD COLUMN capacity_metrics varchar(32) NOT NULL DEFAULT 'units';
ALTER TABLE llx_element_resources ADD COLUMN selection_policy varchar(24) NOT NULL DEFAULT 'preference_order';
UPDATE llx_element_resources SET requirement_group = 'legacy_default', mandatory = 1 WHERE element_type IN ('product', 'service');
ALTER TABLE llx_element_resources ADD INDEX idx_element_resources_requirement (element_type, element_id, relation_kind, position);

-- Common resource reservation ledger
ALTER TABLE llx_bookcal_calendar ADD COLUMN timezone varchar(64) NOT NULL DEFAULT 'UTC';
ALTER TABLE llx_element_resources ADD COLUMN service_quantity real DEFAULT NULL;
ALTER TABLE llx_element_resources ADD COLUMN service_duration varchar(16) DEFAULT NULL;
ALTER TABLE llx_element_resources ADD COLUMN capacity_used real DEFAULT NULL;
ALTER TABLE llx_element_resources ADD COLUMN date_start datetime DEFAULT NULL;
ALTER TABLE llx_element_resources ADD COLUMN date_end datetime DEFAULT NULL;
ALTER TABLE llx_element_resources ADD COLUMN reservation_status varchar(16) DEFAULT NULL;
UPDATE llx_element_resources SET relation_kind = 'assignment' WHERE reservation_status IS NOT NULL;
ALTER TABLE llx_element_resources ADD INDEX idx_element_resources_booking (resource_type, resource_id, relation_kind, reservation_status, date_start, date_end);

CREATE TABLE llx_resource_time_slot
(
  rowid integer AUTO_INCREMENT PRIMARY KEY,
  entity integer DEFAULT 1 NOT NULL,
  fk_resource integer NOT NULL,
  label varchar(255) DEFAULT NULL,
  slot_type varchar(16) NOT NULL DEFAULT 'absolute',
  date_start datetime DEFAULT NULL,
  date_end datetime DEFAULT NULL,
  weekday smallint DEFAULT NULL,
  time_start integer DEFAULT NULL,
  time_end integer DEFAULT NULL,
  capacity real DEFAULT NULL,
  active smallint NOT NULL DEFAULT 1,
  fk_user_create integer DEFAULT NULL,
  fk_user_modif integer DEFAULT NULL,
  date_creation datetime DEFAULT NULL,
  tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
ALTER TABLE llx_resource_time_slot ADD INDEX idx_resource_time_slot_resource (fk_resource);
ALTER TABLE llx_resource_time_slot ADD INDEX idx_resource_time_slot_absolute (fk_resource, active, date_start, date_end);
ALTER TABLE llx_resource_time_slot ADD INDEX idx_resource_time_slot_weekly (fk_resource, active, weekday, time_start, time_end);

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
ALTER TABLE llx_resource_supply_request ADD UNIQUE INDEX uk_resource_supply_request_assignment (fk_element_resource);
ALTER TABLE llx_resource_supply_request ADD INDEX idx_resource_supply_request_resource_dates (fk_resource, date_start, date_end);
ALTER TABLE llx_resource_supply_request ADD INDEX idx_resource_supply_request_supplier_order (fk_supplier_order, fk_supplier_order_line);
ALTER TABLE llx_resource_supply_request ADD INDEX idx_resource_supply_request_status (request_status);

-- end of migration
