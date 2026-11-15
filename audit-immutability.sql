-- Run once against the active Smart Blood Bank database to make audit rows append-only.
-- Keep database administrator credentials private; a database owner can still remove triggers.
DELIMITER $$

CREATE TRIGGER audit_logs_reject_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log entries are append-only';
END$$

CREATE TRIGGER audit_logs_reject_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log entries are append-only';
END$$

DELIMITER ;
