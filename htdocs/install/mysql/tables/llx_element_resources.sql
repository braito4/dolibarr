--
-- Copyright (C) 2013	Jean-François Ferry	<jfefe@aternatik.fr>
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
-- along with this program.  If not, see <https://www.gnu.org/licenses/>.
-- ============================================================================
-- Table used to link an element actioncomm with a resource or user (llx_resource or llx_user)
-- ============================================================================

CREATE TABLE llx_element_resources
(
  rowid           integer AUTO_INCREMENT PRIMARY KEY,
  element_id	  integer,
  element_type    varchar(64),
  resource_id     integer,			-- id of resource or id of user
  resource_type	  varchar(64),		-- resource or user
  busy			  integer,
  mandatory		  integer,
	position		  integer DEFAULT 0,
	relation_kind	  varchar(16) NOT NULL DEFAULT 'requirement',
	resource_role	  varchar(16) NOT NULL DEFAULT 'capacity',
	requirement_group varchar(32) DEFAULT NULL,
	quantity_required real DEFAULT 1,
	users_per_service_unit real DEFAULT NULL,
	duration_base	  integer DEFAULT 0,
	duration_per_unit integer DEFAULT 0,
	setup_duration	  integer DEFAULT 0,
	cleanup_duration  integer DEFAULT 0,
	scheduling_mode  varchar(16) NOT NULL DEFAULT 'same_as_parent',
	start_input_mode varchar(16) NOT NULL DEFAULT 'none',
	end_input_mode   varchar(16) NOT NULL DEFAULT 'none',
	time_precision   varchar(16) NOT NULL DEFAULT 'minute',
	simultaneous	  smallint NOT NULL DEFAULT 1,
	allow_split		  smallint NOT NULL DEFAULT 0,
	context_scope	  varchar(16) NOT NULL DEFAULT 'service_line',
	demand_source	  varchar(16) NOT NULL DEFAULT 'service_quantity',
	capacity_metrics varchar(32) NOT NULL DEFAULT 'units',
	required_location varchar(255) DEFAULT NULL,
	selection_policy varchar(24) NOT NULL DEFAULT 'preference_order',
	service_quantity real DEFAULT NULL,
	service_duration varchar(16) DEFAULT NULL,
	capacity_used real DEFAULT NULL,
	load_volume_used real DEFAULT NULL,
	payload_weight_used real DEFAULT NULL,
	date_start datetime DEFAULT NULL,
	date_end datetime DEFAULT NULL,
	reservation_status varchar(16) DEFAULT NULL,
  duree				real,               -- total duration of using ressource
  fk_user_create  integer,
  tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)ENGINE=innodb;
