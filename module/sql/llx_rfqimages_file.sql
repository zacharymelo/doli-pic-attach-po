-- Per-file choice for products flagged with rfqimages_send.
-- A missing row means the file is included; rows only store explicit choices.
CREATE TABLE llx_rfqimages_file(
    rowid       INTEGER       AUTO_INCREMENT PRIMARY KEY,
    entity      INTEGER       NOT NULL DEFAULT 1,
    fk_product  INTEGER       NOT NULL,
    filename    VARCHAR(255)  NOT NULL,
    selected    SMALLINT      NOT NULL DEFAULT 1,
    tms         TIMESTAMP,
    import_key  VARCHAR(14)
) ENGINE=innodb;
