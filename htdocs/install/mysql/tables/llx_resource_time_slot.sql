-- Module to manage resources into Dolibarr ERP/CRM
-- Copyright (C) 2026 Dolibarr contributors

-- Availability windows defined on a resource.
-- An absolute slot uses date_start/date_end.
-- A weekly slot uses weekday/time_start/time_end (weekday: 1=Monday, 7=Sunday).

CREATE TABLE llx_resource_time_slot
(
  rowid             integer AUTO_INCREMENT PRIMARY KEY,
  entity            integer DEFAULT 1 NOT NULL,
  fk_resource       integer NOT NULL,
  label             varchar(255) DEFAULT NULL,
  slot_type         varchar(16) NOT NULL DEFAULT 'absolute',
  availability_status varchar(16) NOT NULL DEFAULT 'available',
  date_start        datetime DEFAULT NULL,
  date_end          datetime DEFAULT NULL,
  weekday           smallint DEFAULT NULL,
  time_start        integer DEFAULT NULL,
  time_end          integer DEFAULT NULL,
  active            smallint NOT NULL DEFAULT 1,
  fk_user_create    integer DEFAULT NULL,
  fk_user_modif     integer DEFAULT NULL,
  date_creation     datetime DEFAULT NULL,
  tms               timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)ENGINE=innodb;
