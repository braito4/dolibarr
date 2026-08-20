-- A mask may be shared by many resources. Each resource has at most one mask.

CREATE TABLE llx_resource_time_mask_assignment
(
  rowid             integer AUTO_INCREMENT PRIMARY KEY,
  entity            integer DEFAULT 1 NOT NULL,
  fk_time_mask      integer NOT NULL,
  resource_type     varchar(64) NOT NULL,
  resource_id       integer NOT NULL,
  tms               timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
