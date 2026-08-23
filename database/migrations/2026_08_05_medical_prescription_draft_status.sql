-- Add a 'draft' state to medical_prescriptions.status.
--
-- A draft is an in-progress consultation the doctor is still authoring on the
-- patient History page. It is deliberately INVISIBLE to the pharmacy and to the
-- staff / secretary / doctor lists (all of those only surface 'pending' and
-- 'completed'). A prescription only becomes visible to the pharmacy once the
-- doctor writes at least one medication and presses "Save & send to Pharmacy",
-- which flips the row from 'draft' to 'pending'.
--
-- Widening an ENUM is a safe, non-destructive change (existing rows keep their
-- value). The column default stays 'pending' so the standalone create.php flow
-- is unaffected; drafts set status = 'draft' explicitly. Idempotent.

SET @db_name := DATABASE();

SET @status_type := (
    SELECT COLUMN_TYPE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'medical_prescriptions'
      AND COLUMN_NAME = 'status'
);

SET @needs_draft := IF(@status_type IS NOT NULL AND LOCATE('draft', @status_type) = 0, 1, 0);

SET @alter_sql := IF(
    @needs_draft = 1,
    'ALTER TABLE `medical_prescriptions` MODIFY COLUMN `status` ENUM(''draft'', ''pending'', ''completed'') NOT NULL DEFAULT ''pending''',
    'SELECT ''medical_prescriptions.status already supports draft'' AS info'
);
PREPARE status_stmt FROM @alter_sql;
EXECUTE status_stmt;
DEALLOCATE PREPARE status_stmt;
