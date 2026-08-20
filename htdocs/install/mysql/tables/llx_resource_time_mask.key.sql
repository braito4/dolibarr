ALTER TABLE llx_resource_time_mask ADD UNIQUE INDEX uk_resource_time_mask_ref (entity, ref);
ALTER TABLE llx_resource_time_mask ADD INDEX idx_resource_time_mask_active (entity, active);
