-- Reusable calendar masks for resources and structured calendars.

CREATE TABLE llx_resource_time_mask
(
  rowid             integer AUTO_INCREMENT PRIMARY KEY,
  entity            integer DEFAULT 1 NOT NULL,
  ref               varchar(128) NOT NULL,
  label             varchar(255) NOT NULL,
  timezone          varchar(64) DEFAULT NULL,
  active            smallint NOT NULL DEFAULT 1,
  fk_user_create    integer DEFAULT NULL,
  fk_user_modif     integer DEFAULT NULL,
  date_creation     datetime DEFAULT NULL,
  tms               timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
