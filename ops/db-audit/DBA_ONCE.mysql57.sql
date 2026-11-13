-- One-time DBA action on the verified MySQL 5.7.44 server.
-- No existing account replacement, business-row query, legacy schema DDL/DML,
-- new database, password exchange, auth plugin install or shared-service change.
-- Stop on any error. Do not use --force. Existing-name collisions require review.
CREATE USER 'ppb_v2_metadata'@'localhost' ACCOUNT LOCK;
GRANT REFERENCES ON *.* TO 'ppb_v2_metadata'@'localhost';
DELIMITER $$
CREATE DEFINER='ppb_v2_metadata'@'localhost'
PROCEDURE `ppbaos`.`ppb_v2_metadata_audit_v1`()
SQL SECURITY DEFINER
READS SQL DATA
BEGIN
    SELECT CURRENT_USER() AS actor, VERSION() AS server_version;
    SHOW GRANTS FOR CURRENT_USER;
    SELECT SHA2(ROUTINE_DEFINITION,256) AS definition_sha256
      FROM information_schema.ROUTINES
      WHERE ROUTINE_SCHEMA='ppbaos' AND ROUTINE_NAME='ppb_v2_metadata_audit_v1'
        AND ROUTINE_TYPE='PROCEDURE';
    SELECT CONSTRAINT_SCHEMA,CONSTRAINT_NAME,TABLE_NAME,
           UNIQUE_CONSTRAINT_SCHEMA,REFERENCED_TABLE_NAME,UPDATE_RULE,DELETE_RULE
      FROM information_schema.REFERENTIAL_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA<>UNIQUE_CONSTRAINT_SCHEMA
        AND (CONSTRAINT_SCHEMA='ppbaos' OR UNIQUE_CONSTRAINT_SCHEMA='ppbaos')
      ORDER BY CONSTRAINT_SCHEMA,CONSTRAINT_NAME;
    SELECT TABLE_SCHEMA,COUNT(*) AS visible_tables
      FROM information_schema.TABLES GROUP BY TABLE_SCHEMA ORDER BY TABLE_SCHEMA;
END$$
DELIMITER ;
GRANT EXECUTE ON PROCEDURE `ppbaos`.`ppb_v2_metadata_audit_v1`
    TO 'ppb_v2_metadata'@'localhost';
-- ppbaos already has ALL PRIVILEGES ON ppbaos.* including EXECUTE.
-- No additional application privileges and no FLUSH PRIVILEGES needed.
