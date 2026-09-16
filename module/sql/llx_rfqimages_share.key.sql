ALTER TABLE llx_rfqimages_share ADD UNIQUE INDEX uk_rfqimages_share_fk_ecm_files (fk_ecm_files);
ALTER TABLE llx_rfqimages_share ADD INDEX idx_rfqimages_share_date_last_sent (entity, date_last_sent);
