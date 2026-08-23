-- Lab module — add 'status' column type for auto-computed result flags
-- Database: kasher_platform

ALTER TABLE `lab_test_columns`
  MODIFY `col_type` ENUM('result', 'reference', 'unit', 'text', 'status') NOT NULL DEFAULT 'text';
