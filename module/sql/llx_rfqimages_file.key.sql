ALTER TABLE llx_rfqimages_file ADD UNIQUE INDEX uk_rfqimages_file (entity, fk_product, filename);
ALTER TABLE llx_rfqimages_file ADD INDEX idx_rfqimages_file_fk_product (fk_product);
