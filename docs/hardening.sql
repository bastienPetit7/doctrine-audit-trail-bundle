-- ---------------------------------------------------------------------------
-- Tamper-evidence hardening for the audit_trail table.
--
-- The bundle only ever needs INSERT and SELECT on the audit table. Granting
-- nothing more — and physically rejecting UPDATE/DELETE/TRUNCATE at the
-- database level — is the strongest tamper *prevention* control, and the
-- recommended baseline for regulated workloads (DSP2, SOX, HIPAA, ISO 27001).
--
-- Combine this with the optional application-level HMAC seal
-- (doctrine_audit_trail.integrity) for tamper *evidence* that survives even a
-- privileged DBA, a restored backup, or replication.
--
-- Adjust "audit_trail" if you configured a custom storage.table_name, and
-- "audit_writer" to match the role used by your audit database connection.
-- ---------------------------------------------------------------------------


-- =============================== PostgreSQL ================================

-- 1. Least-privilege grants: the audit writer may only append and read.
REVOKE UPDATE, DELETE, TRUNCATE ON audit_trail FROM PUBLIC;
GRANT INSERT, SELECT ON audit_trail TO audit_writer;

-- 2. Defense in depth: reject any UPDATE/DELETE even from privileged roles.
CREATE OR REPLACE FUNCTION audit_trail_reject_mutation()
    RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'audit_trail is append-only: % is not allowed', TG_OP;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER audit_trail_no_update_delete
    BEFORE UPDATE OR DELETE ON audit_trail
    FOR EACH ROW EXECUTE FUNCTION audit_trail_reject_mutation();


-- ================================= MySQL ==================================
-- (MySQL has no per-statement REVOKE TRUNCATE; restrict privileges at GRANT
--  time and rely on the triggers below.)

-- 1. Least-privilege grants.
REVOKE UPDATE, DELETE ON your_database.audit_trail FROM 'audit_writer'@'%';
GRANT INSERT, SELECT ON your_database.audit_trail TO 'audit_writer'@'%';

-- 2. Defense in depth: reject UPDATE and DELETE.
DELIMITER //

CREATE TRIGGER audit_trail_no_update
    BEFORE UPDATE ON audit_trail
    FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_trail is append-only: UPDATE is not allowed';
END//

CREATE TRIGGER audit_trail_no_delete
    BEFORE DELETE ON audit_trail
    FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_trail is append-only: DELETE is not allowed';
END//

DELIMITER ;
