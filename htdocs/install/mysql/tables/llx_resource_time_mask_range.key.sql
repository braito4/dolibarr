ALTER TABLE llx_resource_time_mask_range ADD INDEX idx_resource_time_mask_range_mask (fk_time_mask, active, position);
ALTER TABLE llx_resource_time_mask_range ADD CONSTRAINT fk_resource_time_mask_range_mask FOREIGN KEY (fk_time_mask) REFERENCES llx_resource_time_mask (rowid);
