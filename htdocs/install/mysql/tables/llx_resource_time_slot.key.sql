-- Module to manage resources into Dolibarr ERP/CRM
-- Copyright (C) 2026 Dolibarr contributors

ALTER TABLE llx_resource_time_slot ADD INDEX idx_resource_time_slot_resource (fk_resource);
ALTER TABLE llx_resource_time_slot ADD INDEX idx_resource_time_slot_absolute (fk_resource, active, date_start, date_end);
ALTER TABLE llx_resource_time_slot ADD INDEX idx_resource_time_slot_weekly (fk_resource, active, weekday, time_start, time_end);
ALTER TABLE llx_resource_time_slot ADD CONSTRAINT fk_resource_time_slot_resource FOREIGN KEY (fk_resource) REFERENCES llx_resource (rowid);
