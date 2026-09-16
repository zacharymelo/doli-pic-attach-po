-- Share links created by rfqimages, so they can expire. Links shared by hand are never recorded here.
CREATE TABLE llx_rfqimages_share(
    rowid           INTEGER       AUTO_INCREMENT PRIMARY KEY,
    entity          INTEGER       NOT NULL DEFAULT 1,
    fk_ecm_files    INTEGER       NOT NULL,
    share           VARCHAR(128)  NOT NULL,
    date_last_sent  DATETIME      NOT NULL,
    tms             TIMESTAMP,
    import_key      VARCHAR(14)
) ENGINE=innodb;
