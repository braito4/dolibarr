-- Virtual ranges expanded from a reusable resource time mask.
-- weekday_mask: bit 0 is Monday and bit 6 is Sunday.
-- Day offsets allow ranges such as hotel day 0 12:00 to day 1 11:59.

CREATE TABLE llx_resource_time_mask_range
(
  rowid             integer AUTO_INCREMENT PRIMARY KEY,
  fk_time_mask      integer NOT NULL,
  label             varchar(255) DEFAULT NULL,
  weekday_mask      integer NOT NULL DEFAULT 127,
  start_day_offset  smallint NOT NULL DEFAULT 0,
  start_time        integer NOT NULL DEFAULT 0,
  end_day_offset    smallint NOT NULL DEFAULT 0,
  end_time          integer NOT NULL DEFAULT 86399,
  slot_duration     integer NOT NULL DEFAULT 15,
  active            smallint NOT NULL DEFAULT 1,
  position          integer NOT NULL DEFAULT 0,
  tms               timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
