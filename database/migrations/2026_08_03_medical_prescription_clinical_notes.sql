-- Add clinical note fields (History + Examination) to medical_prescriptions.
-- These join the existing `diagnosis` column so a single prescription now carries
-- the full consultation record: History, Examination, Diagnoses, Treatment.
-- All three note fields are authored/stored as Markdown. Idempotent.

SET @db_name := DATABASE();

-- medical_prescriptions.history
SET @history_col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'medical_prescriptions'
      AND COLUMN_NAME = 'history'
);
SET @history_sql := IF(
    @history_col_exists = 0,
    'ALTER TABLE `medical_prescriptions` ADD COLUMN `history` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `patient_id`',
    'SELECT ''medical_prescriptions.history already exists'' AS info'
);
PREPARE history_stmt FROM @history_sql;
EXECUTE history_stmt;
DEALLOCATE PREPARE history_stmt;

-- medical_prescriptions.examination
SET @exam_col_exists := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db_name
      AND TABLE_NAME = 'medical_prescriptions'
      AND COLUMN_NAME = 'examination'
);
SET @exam_sql := IF(
    @exam_col_exists = 0,
    'ALTER TABLE `medical_prescriptions` ADD COLUMN `examination` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `history`',
    'SELECT ''medical_prescriptions.examination already exists'' AS info'
);
PREPARE exam_stmt FROM @exam_sql;
EXECUTE exam_stmt;
DEALLOCATE PREPARE exam_stmt;
